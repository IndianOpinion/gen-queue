# gen-queue: product requirements

Status: draft for review. Version target: 0.1.0.

gen-queue is a WordPress plugin. It runs paid generation work for other
plugins: it stores jobs, runs them through WP-Cron or WP-CLI, retries them
with backoff, records what each attempt cost, stops at a daily spend cap, and
stops at once when the kill switch is off. It contains no prompts and calls
no provider itself. A job handler, owned by another plugin, does the actual
call and tells the queue what it cost.

Written from scratch; not derived from any third-party plugin.

## 1. Problem

Plugins that call a paid AI API in bulk each reinvent the same loop, and each
gets the same things wrong:

- Rows are selected without being claimed, so a manual backfill that
  overlaps the scheduled run processes, and pays for, the same work twice.
- A runner cannot tell "another runner holds the lock" from "the queue is
  empty". Measured: a long manual drain exited 4.5 hours early the first time
  these two were conflated.
- A fatal error in the middle of a request leaves the row looking untried,
  so the next run tries again, and again, paying each time.
- A daily cap is checked once at the start of a run, or from a value cached
  at process start, so concurrent runners overshoot it.
- An empty account balance or a bad key is treated as a failure of one job,
  so every job in the queue burns its retries on the same account error.
- A transient fault on one provider model gives up on the job at once.
  Measured: one bounded retry on the same model recovered a truncated
  response within a minute, where before it would have failed over to a
  slower, dearer path.
- Nobody can see what ran, what failed and what it cost without reading logs.

gen-queue solves this once, so each generating plugin only writes a handler.

## 2. Goals and non-goals

Goals:

1. One durable job table with atomic claims, safe under any number of
   concurrent runners (WP-Cron and WP-CLI at the same time).
2. A daily spend cap that is never exceeded by more than one job's
   underestimate, checked with a fresh read before every job.
3. A kill switch, global and per job type, read fresh before every job, that
   stops new work within one job.
4. Retries with exponential backoff and jitter, with a clear split between
   retryable, permanent and account errors.
5. Per-resource cooldown (a circuit breaker) after repeated failures.
6. Per-attempt cost records and a status page.
7. Idempotency keys and priority on enqueue.
8. A stable public contract (PHP functions, actions, filters, CLI) that other
   plugins can build on, including a soft integration with spend-meter.

Non-goals:

- No prompts, no provider client, no HTTP calls of its own.
- No publishing. gen-queue stores whatever reference the handler returns. The
  handler writes drafts, pending comments or suggestions; it never publishes.
- No price table and no token-to-money conversion. The handler reports a cost
  amount; the queue adds it up. Pricing belongs to spend-meter.
- No generic background-task framework for non-paid work. It will run such
  jobs, but nothing is designed around them.
- No REST API in 0.1.0 (see section 13).
- No cross-site queue on multisite. Each site has its own queue.

README requirement: the README of this repository carries this exact line
in its first section: "Written from scratch; not derived from any
third-party plugin." It also carries the brief's sentence that the project
came out of the work on indianopinion.org and is independent of any
third-party plugin it integrates with. Its quick start and its configuration
table say, in plain words, that **with no daily cap set, nothing runs**, and
give the one command that sets it (`wp gen-queue cap <amount>`). Its FAQ has
an entry "Why is nothing running?" that starts with the missing cap.

## 3. Users and setting

- **Plugin developers** who need bulk generation: summaries, translations,
  alt text, image prompts, embeddings. They register a job type and a
  handler, and enqueue jobs.
- **Site operators** who set the daily cap, flip the kill switch, read the
  status page and run the CLI.
- It runs inside WordPress 6.4+ on PHP 8.1+, on shared hosting (WP-Cron
  only) or on a server with system cron and WP-CLI. It may be installed as a
  normal plugin or a must-use plugin.

## 4. Behaviour

Terms. A **type** is a registered kind of job with one handler. A **job** is
one unit of work with a JSON payload. An **attempt** is one run of the
handler on a job. A **runner** is one process draining the queue (a WP-Cron
tick or a CLI command). A **resource key** is a string the type or job
declares (for example a model name) that the cooldown is kept against. A
**group** is a string that ties several types to one account, so an account
error holds all of them. Money is held as integer nanos (one
billionth of the operator's currency unit) and shown as a decimal.

### 4.1 Registration

1. Types are registered on the action `gen_queue_register_types`, which fires
   on `init` at priority 20. Registering outside that action is allowed after
   it has fired and logs a notice; registering before it throws
   `RegistrationException`.
2. A type name matches `^[a-z0-9][a-z0-9_.-]{0,63}$`. The convention is
   `<plugin-slug>.<task>`. An invalid name throws `RegistrationException`.
3. Registering a name twice throws `RegistrationException`; the first
   registration stays.
4. A type with a non-callable handler throws `RegistrationException`.
5. Type defaults (each overridable per job where marked): `max_attempts` 5
   (job), `backoff_base` 30 s, `backoff_max` 3600 s, `timeout` 120 s,
   `priority` 0 (job), `order` `fifo` (or `lifo`), `ttl` 7 days (job; 0 means
   never expires), `group` the type name (job), `resource_key` the type name
   (job), `cost_estimate` 0 (job), `daily_cap` none, `meter` none (optional array
   with `purpose`, `provider` and `model`, used only by case 71 and 73).

### 4.2 Enqueue

6. `gen_queue_enqueue()` validates the type, payload and arguments, inserts
   one row with status `queued`, fires `gen_queue_enqueued`, and returns the
   job id.
7. An unregistered type throws `UnknownTypeException`, unless the argument
   `allow_unregistered` is true (for enqueuing before the owning plugin has
   loaded); such a job waits as "no handler" (case 44).
8. The payload must be an array of scalars, nulls and nested arrays. Objects,
   resources, closures, invalid UTF-8 and anything over the payload limit
   (64 KiB encoded, filterable) throw `InvalidPayloadException`. Nothing is
   inserted.
9. **Idempotency.** With an `idempotency_key` (1 to 191 bytes), the pair
   (type, key) is unique. A second enqueue with the same pair inserts nothing
   and returns the existing job id. Two concurrent enqueues with the same pair
   produce one row: the unique index decides, and the loser reads and returns
   the winner's id.
10. If the existing job with that key is in a terminal state (`failed`,
    `cancelled`, `expired`), the default `on_conflict` of `return` returns it
    unchanged. `on_conflict` `requeue` moves it back to `queued` with a fresh
    attempt allowance and the new payload, and fires `gen_queue_enqueued`
    with `requeued` true. A `succeeded` job is never requeued by enqueue; use
    `gen_queue_retry()` deliberately.
11. Without an idempotency key, every call inserts a new job.
12. `priority` is an integer from -100 to 100; out of range throws
    `InvalidArgumentException`. Higher runs first.
13. `run_after` (Unix time) delays the first attempt. A time in the past
    means now.
14. `ttl` or `expires_at` sets an expiry. `ttl` 0 means never.
15. `gen_queue_enqueue_many()` enqueues a list in batches of 500 rows per
    statement inside one transaction per batch. It returns ids in input
    order. One invalid item fails the whole call before anything is
    inserted. Idempotency rules apply per item.
16. Enqueue works while the kill switch is off and while the cap is reached.
    Jobs wait; they are not rejected.

### 4.3 Claiming and running

17. A runner first takes a worker slot: one of `max_workers` (default 2)
    named database locks. If none is free it stops with reason `busy`, which
    is distinct from `empty`.
18. Before each job, the runner re-reads, bypassing every cache: the global
    kill switch, the per-type pause list, the daily cap and today's spend. A
    switch flipped mid-run stops the runner before its next job, even in a
    CLI process that has run for hours.
19. Global kill switch off: the runner claims nothing and stops with reason
    `disabled`. Running jobs finish their current attempt.
20. A paused type is skipped. Its jobs keep their attempts and their place.
21. The runner claims the next eligible job: status `queued`, `run_after` at
    or before now, not expired, type registered and not paused, resource key
    not cooling, group not on account hold, and the cap allows it (case 30).
    Order: priority descending, then `run_after` ascending (or descending for
    `lifo` types), then id.
22. The claim is atomic: a conditional update sets status `running`, a fresh
    lease token, `lease_until` (now plus the type timeout plus 60 s) and the
    worker id, only where status is still `queued`. If no row changed, another
    runner won; the runner picks again. No job is ever run twice at once.
23. Before calling the handler, the queue increments `attempts` and writes an
    attempt row with outcome `started`. This is committed before the handler
    runs, so a fatal error mid-request still counts the attempt.
24. Immediately before calling the handler, the runner checks the kill switch
    and pause once more (fresh read). If either is now off, the job is
    released back to `queued`, the attempt row is marked `skipped`, and
    `attempts` is restored. A deliberate stop never consumes an attempt.
25. The handler receives the job and a context. It may report cost any number
    of times (`$ctx->addCost()`), check for cancellation, read its deadline
    and log lines to the attempt.
26. **Success.** The handler returns a result (or nothing). The job becomes
    `succeeded`, the result reference (JSON, up to 16 KiB) is stored, the
    attempt row is closed with outcome `success`, the resource key's failure
    count is cleared, and `gen_queue_completed` fires.
27. A runner never starts a job whose timeout exceeds the time left in its
    budget. It stops with reason `time`.
28. A runner stops after `--max` jobs with reason `max`, or when nothing is
    eligible with reason `empty` (queue empty) or `waiting` (jobs exist but
    all are in backoff, cooling, held, paused or capped).

### 4.4 Spend cap

29. The global daily cap is an amount the operator sets. **Default: unset.
    With no cap set, no job runs** and the status page says so. A cap of 0 is
    also valid and also runs nothing. The queue fails closed on money. A cap on
    another meter, or no cap there, never stands in for this one (case 74).
30. Before claiming a job the runner computes, under the claim lock:
    `spent_today + reserved + estimate <= cap`, where `spent_today` comes from
    the filter in case 34, `reserved` is the sum of estimates of jobs
    currently running, and `estimate` is the job's `cost_estimate`. If the
    check fails, the job stays `queued`, untouched, with no attempt used, and
    is reconsidered on a later tick or the next day.
31. A job with estimate 0 is allowed only while `spent_today + reserved` is
    strictly below the cap.
32. On a running job the estimate is held as a reservation. When the attempt
    ends, the reservation is released and the actual reported cost is
    recorded. Overshoot is therefore bounded by one job's actual cost minus
    its estimate.
33. The day is the site's local day (`wp_timezone()`). An attempt's cost
    counts on the local day the attempt started, even if it finishes after
    midnight.
34. `spent_today` is `apply_filters('gen_queue_spend_today', $own, $day)`,
    where `$own` is the sum of today's attempt costs in the queue's own
    records, in nanos. Any plugin may hook it to supply a wider figure.
    spend-meter does not need to: its site-wide caps reach the queue through
    the pre-spend check (case 71). If the filter returns something other than
    a non-negative integer, the queue logs a warning and uses the larger of
    `$own` and the returned value cast safely; if the cast fails it uses
    `$own`. A broken integration never lowers the figure.
35. A type may set its own `daily_cap`. Both caps apply; the stricter one
    wins. A per-type cap counts only that type's attempts.
36. The filter `gen_queue_can_run` gets the last word: it may veto a job
    (return false) but cannot override the kill switch or a reached cap.
37. When the cap is first reached on a day, `gen_queue_cap_reached` fires
    once and one log line is written. The status page shows "capped".

### 4.5 Errors, retries and backoff

38. The handler signals failure by throwing:
    - `RetryableException` (optional `retryAfter` seconds): transport
      failure, rate limit, server error, a success status with an error body,
      an empty or malformed body, a truncated output.
    - `PermanentException`: bad request, content refused, a payload the
      handler cannot use, the target post no longer exists or is no longer
      in a state the handler can write to.
    - `AccountException`: authentication failure, forbidden, out of credit.
    - Any other `Throwable` is treated as retryable with error class
      `exception`, and the message is stored.
39. Retryable, attempts left: the job returns to `queued` with
    `run_after = now + delay`. Delay is full jitter: a random value between 0
    and `min(backoff_max, backoff_base * 2^(attempt-1))`, but never less than
    `retryAfter` when given. `gen_queue_retry_scheduled` fires. The filter
    `gen_queue_backoff_delay` may change the delay (clamped to 0 to 86400).
40. Retryable, no attempts left: status `failed`, `gen_queue_failed` fires.
41. Permanent: status `failed` at once, whatever attempts remain. It does
    not count towards the resource key's cooldown.
42. Account: the job returns to `queued`, the attempt is recorded with
    outcome `account` but not counted against the job, and the whole group
    is put on hold for `account_hold` (default 3600 s), logged once.
    `gen_queue_account_hold` fires. An account error never counts towards a
    cooldown, because every resource in the group shares the account.
43. Cost reported before the exception is recorded. A failed call can still
    be billed.
44. **No handler.** A job whose type is not registered (owning plugin
    deactivated) is never claimed. It is not failed; it waits, expires on its
    TTL, and shows as "no handler" on the status page.
45. **Abandoned.** A `running` job whose `lease_until` has passed (the
    process died) is reclaimed by the next runner: its open attempt is closed
    with outcome `abandoned`, the attempt stays counted, and the job follows
    case 39 or 40. A late completion from the dead worker carries a stale
    lease token and is discarded with a log line.

### 4.6 Cooldown

46. Each resource key has a consecutive-failure counter. Retryable failures
    increment it; success clears it; permanent and account errors leave it
    alone.
47. At `cooldown_threshold` (default 2) consecutive failures the key cools
    for `cooldown_seconds` (default 600). One log line and
    `gen_queue_cooldown_started` fire once per cooldown. Jobs on a cooling key
    are not claimed and lose no attempts.
48. When the cooldown ends, the next job on that key runs normally. A failure
    then starts a new cooldown at once (the counter is still at threshold).
49. A handler may set a job's resource key per job. A handler testing against
    a sandbox endpoint must use a different key, so test failures never cool
    a live resource.
50. The queue does not fail over between resources. A handler that wants a
    fallback model asks `gen_queue_resource_cooling($key)` and chooses, and
    reports the key it used through `$ctx->useResource($key)`.

### 4.7 Cancel, retry, expire, pause

51. Cancel a `queued` job: status `cancelled`, `gen_queue_cancelled` fires.
52. Cancel a `running` job: `cancel_requested` is set. The handler may check
    `$ctx->cancelRequested()` and stop by throwing `CancelledException`.
    Whatever the handler does, the job ends `cancelled`, cost reported is
    still recorded, and a result returned after the request is stored but
    the status stays `cancelled`.
53. Cancel a terminal job: no change, returns false.
54. Bulk cancel by type, source or group cancels queued jobs and requests
    cancel on running ones; it returns the count.
55. Retry a `failed`, `cancelled` or `expired` job: back to `queued`, with
    `max_attempts` raised to current attempts plus the type default. History
    stays.
56. Expiry: each runner tick, `queued` jobs past `expires_at` become
    `expired` (a distinct status, not `failed`). Running jobs never expire
    mid-run. While the global switch is off, expiry is suspended, so a
    deliberate pause keeps its backlog.
57. Pause a type: its queued jobs are not claimed; nothing else changes.
    Resume clears it.

### 4.8 Runners

58. **WP-Cron.** On activation the plugin schedules `gen_queue_tick` on a
    60 s interval. A tick runs for `tick_seconds` (default 25), capped at the
    PHP time limit minus 5 s. The constant `GEN_QUEUE_DISABLE_CRON_RUNNER`
    stops the tick from running jobs (it still runs expiry and lease
    reclaim).
59. **WP-CLI.** `wp gen-queue run` drains with the same code path. Both can
    run at once; claims are atomic and slots bound concurrency.
60. `wp gen-queue run --until-empty` keeps going while the stop reason is
    `busy` or `time` and returns when it is `empty`, `waiting`, `capped`,
    `disabled` or `max`. It never treats `busy` as `empty`.
61. Housekeeping on each tick, bounded to 1 s: lease reclaim, expiry, and once
    a day the retention purge (case 70).

### 4.9 Edge cases and failure modes

62. Database unavailable on enqueue: throws `StorageException`. Fail closed:
    the caller knows the job was not stored.
63. Database error while closing an attempt: the job stays `running` and is
    reclaimed by lease expiry (case 45). Cost already reported is in the
    attempt row only if it was written; the handler should report cost as
    soon as it is known.
64. Clock moves backwards: leases and backoff use the database's UTC time,
    not PHP time, for comparisons.
65. A handler that runs past its timeout is not killed (PHP cannot). Its
    lease may expire and another runner may reclaim the job; the late
    completion is discarded (case 45). The status page flags types whose
    attempts often exceed their timeout.
66. A handler that calls `exit` or dies: lease expiry handles it.
67. Spend filter throws: caught, warning logged, `$own` used.
68. Object cache present: kill switch, pause list, cap and spend are read
    with the cache bypassed. A stale cache must never let a job run.
69. Site timezone changed mid-day: today's spend is recomputed for the new
    local day; costs already recorded keep their stored day.
70. Retention: terminal jobs and their attempts older than `retention_days`
    (default 30) are purged in batches of 1000. Queued and running jobs are
    never purged. 0 means keep forever.

### 4.10 spend-meter integration

spend-meter is optional. It is present when the constant `SPEND_METER_API`
is defined. gen-queue uses only its documented filter and action, never its
functions. Money crosses the boundary in nanos, which both plugins use.

71. **Pre-spend.** After the kill switch, the own cap and the per-type cap
    allow a job (cases 29 to 35), and while spend-meter is present, the
    runner applies the filter `spend_meter_pre_spend` with `null` and a dry
    run intent: `purpose` is the type's `meter` purpose, else the type name
    made to fit `^[a-z0-9][a-z0-9_.-]{0,63}$` (lower case, other characters
    to `-`, cut to 64); `caller` and `provider` are `gen-queue` unless the
    type's `meter` sets `provider`; `model` is the type's `meter` model, else
    `job`; `estimate_override` is the job's `estimate_nanos`; `dry_run` is
    true; `meta` holds `job_id`. The runner takes no reservation, because the
    handler's own provider call reserves through the same filter.
72. **Reading the decision.** A `null` result means no meter: carry on. A
    decision with `allowed` true: carry on. Anything else is a deny, and the
    job stays `queued`, untouched, no attempt used: `allowed` false, a
    missing or malformed decision, an unknown reason code, a thrown error.
    The reason `kill_switch` stops the runner with `disabled`; every other
    reason skips the job and counts it as capped. The reason code is logged
    once per event and shown on the status page. This path fails closed,
    unlike the spend filter in case 34, because it guards money.
73. **Recording.** For each cost record with `metered` false, while
    spend-meter is present, gen-queue fires `do_action('spend_meter_record',
    $call)` once, after the attempt row is written. `$call` holds `purpose`,
    `caller` and `provider` and `model` as in case 71; `provider_cost`
    (the record's `amount_nanos`); `input_tokens` and `output_tokens` (the
    record's `input_units` and `output_units`); `status` `ok` for a
    successful attempt, else `error_billed`; `attempt`; `call_id`; and `meta`
    with `job_id`. A record with amount 0 is not forwarded. When `metered` is
    true the handler already recorded the call through spend-meter, so
    gen-queue forwards nothing, and `usage_ref` holds the `call_id` or
    `reservation_id` the handler used, for tracing. `call_id` is the
    record's `usage_ref` when it is a UUID; otherwise gen-queue sends a UUID
    it generates and keeps the original in `meta` under `usage_ref`. The
    action `gen_queue_cost_recorded` fires after the forward and is the
    queue's own hook for other listeners.
74. **No cap still means nothing runs.** With spend-meter present, a job
    whose own cap is unset or 0 does not run, whatever the pre-spend check
    would say. The pre-spend check is never consulted for it.

## 5. Public interface

The contract below is stable from 0.1.0. Additions are minor versions;
removals or changed meanings are major versions. Everything lives in the
namespace `IndianOpinion\GenQueue`; the global `gen_queue_*` functions are
thin wrappers so callers can test with `function_exists()`.

### 5.1 Detection

- Constant `GEN_QUEUE_VERSION` (semver string) once the plugin has loaded.
- Action `gen_queue_loaded` after the plugin has loaded.
- Soft enqueue without a hard dependency:
  `apply_filters('gen_queue_enqueue_job', null, string $type, array $payload, array $args)`
  returns a job id (int) when gen-queue is active, or the `null` default when
  it is not, so the caller runs its own fallback. Exceptions are caught and
  returned as `WP_Error`.

### 5.2 Functions

| function | returns | notes |
|---|---|---|
| `gen_queue_register_type(string $type, callable $handler, array $args = [])` | void | args in case 5 |
| `gen_queue_enqueue(string $type, array $payload, array $args = [])` | int | args: `idempotency_key`, `on_conflict`, `priority`, `run_after`, `ttl`, `expires_at`, `max_attempts`, `cost_estimate`, `group`, `resource_key`, `source`, `allow_unregistered` |
| `gen_queue_enqueue_many(string $type, array $items)` | int[] | each item `['payload' => [...], 'args' => [...]]` |
| `gen_queue_get(int $id)` | `?Job` | |
| `gen_queue_find(string $type, string $key)` | `?Job` | by idempotency key |
| `gen_queue_cancel(int $id)` | bool | |
| `gen_queue_cancel_where(array $where)` | int | keys `type`, `source`, `group` |
| `gen_queue_retry(int $id)` | bool | |
| `gen_queue_pause(?string $type = null)` / `gen_queue_resume(?string $type = null)` | void | null type means the global switch |
| `gen_queue_is_enabled(?string $type = null)` | bool | fresh read |
| `gen_queue_resource_cooling(string $key)` | `?int` | Unix time the cooldown ends, or null |
| `gen_queue_spend_today(?string $type = null)` | string | decimal |
| `gen_queue_cap_remaining()` | `?string` | decimal, null when no cap is set |
| `gen_queue_run(array $opts = [])` | `RunSummary` | opts `time`, `max`, `type`, `worker` |
| `gen_queue_stats(int $days = 7)` | array | counts and spend per day and type |

Amounts are accepted as decimal strings or numbers and converted to
nanos with half-up rounding; negative amounts throw
`InvalidArgumentException`.

### 5.3 Objects

- `Job` (read-only): `id`, `type`, `status`, `payload`, `idempotencyKey`,
  `priority`, `attempts`, `maxAttempts`, `group`, `resourceKey`, `source`,
  `runAfter`, `expiresAt`, `costTotal` (decimal string), `result`,
  `lastError`, `lastErrorClass`, `createdAt`, `finishedAt`.
- `JobContext` (passed to the handler): `attempt()`, `deadline()` (Unix
  time), `addCost(string|float $amount, array $usage = [])` where usage may
  hold `input_units`, `output_units`, `usage_ref` (a string the caller's
  meter uses to identify the call) and `metered` (bool: true when the call
  was already recorded by another meter), `useResource(string $key)`,
  `cancelRequested()`, `capRemaining()`, `log(string $line)`.
- `JobResult`: `JobResult::ok(array $ref = [])`. A handler may also return an
  array (treated as `ok($array)`) or null.
- Exceptions: `RetryableException`, `PermanentException`, `AccountException`,
  `CancelledException`, `RegistrationException`, `UnknownTypeException`,
  `InvalidPayloadException`, `StorageException`, all extending
  `GenQueueException`.
- `RunSummary`: `claimed`, `succeeded`, `retried`, `failed`, `cancelled`,
  `skipped`, `costTotal`, `seconds`, `stopReason` (`empty`, `waiting`,
  `busy`, `time`, `max`, `capped`, `disabled`).

### 5.4 Actions

| action | arguments |
|---|---|
| `gen_queue_loaded` | none |
| `gen_queue_register_types` | none |
| `gen_queue_enqueued` | `Job $job, bool $requeued` |
| `gen_queue_claimed` | `Job $job, int $attempt, string $worker` |
| `gen_queue_completed` | `Job $job, JobResult $result` |
| `gen_queue_retry_scheduled` | `Job $job, \Throwable $error, int $run_after` |
| `gen_queue_failed` | `Job $job, \Throwable $error` |
| `gen_queue_cancelled` | `Job $job` |
| `gen_queue_expired` | `Job $job` |
| `gen_queue_cost_recorded` | `array $record` (below) |
| `gen_queue_cooldown_started` | `string $key, int $until` |
| `gen_queue_account_hold` | `string $group, int $until, string $message` |
| `gen_queue_cap_reached` | `string $spent, string $cap, string $day` |
| `gen_queue_tick_finished` | `RunSummary $summary` |

The cost record: `job_id`, `attempt`, `type`, `group`, `resource_key`,
`amount` (decimal string), `amount_nanos` (int), `currency` (operator
label), `day` (Y-m-d local), `input_units`, `output_units`, `usage_ref`,
`metered`, `recorded_at` (UTC).

### 5.5 Filters

| filter | default | purpose |
|---|---|---|
| `gen_queue_enqueue_job` | null | soft enqueue (5.1) |
| `gen_queue_spend_today` | own sum (int nanos) | another plugin supplies a wider spend figure |
| `gen_queue_daily_cap` | option value (int nanos or null) | read only after the constant |
| `gen_queue_can_run` | true | veto a job; cannot loosen switch or cap |
| `gen_queue_backoff_delay` | computed seconds | change a retry delay |
| `gen_queue_payload_max_bytes` | 65536 | payload limit |
| `gen_queue_tick_seconds` | 25 | WP-Cron time budget |
| `gen_queue_retention_days` | 30 | purge age |
| `gen_queue_capability` | `manage_options` | status page and actions |
| `gen_queue_show_payload` | false | show payloads on the status page |

### 5.6 spend-meter integration (soft)

Behaviour is in section 4.10. The names below are spend-meter's own, from its
contract version 1.

1. Pre-spend: filter `spend_meter_pre_spend`, `null` and an intent array in,
   a decision array (`allowed`, `reason`, `message`, `reservation_id`,
   `estimate`, `remaining`, `api`) or `null` out. gen-queue always sends
   `dry_run` true.
2. Record: action `spend_meter_record` with one call array (`provider_cost`
   in nanos, `status`, `call_id`, `meta` and the keys in case 73).
3. The gen-queue hooks `gen_queue_spend_today` and `gen_queue_cost_recorded`
   are gen-queue's own. spend-meter does not need to listen to either. A
   record's `metered` and `usage_ref` fields exist so that a call already
   recorded by the caller's meter is never recorded twice.
4. Per-type caps (case 35) always use gen-queue's own records.
5. Without spend-meter, nothing is applied or fired and the queue is
   complete on its own, with its own cap and switch.

### 5.7 WP-CLI

| command | effect |
|---|---|
| `wp gen-queue run [--time=<s>] [--max=<n>] [--type=<t>] [--until-empty] [--format=table\|json]` | drain; prints the summary and stop reason |
| `wp gen-queue status [--format=...]` | switch, cap, spend, counts by status and type, cooling keys, holds |
| `wp gen-queue list [--status=] [--type=] [--limit=50]` | jobs |
| `wp gen-queue show <id>` | one job with its attempts |
| `wp gen-queue enqueue <type> --payload=<json> [--key=] [--priority=]` | for testing and backfills |
| `wp gen-queue cancel <id>... \| --type=<t>` | cancel |
| `wp gen-queue retry <id>... \| --failed [--type=<t>]` | retry |
| `wp gen-queue pause [<type>]` / `resume [<type>]` | switch |
| `wp gen-queue cap <amount> \| --unset` | set the daily cap |
| `wp gen-queue purge [--older-than=<days>] [--dry-run]` | retention purge |
| `wp gen-queue stats [--days=7]` | per day and type |

Exit codes: 0 on success (including `empty` and `waiting`); 1 on error;
`run` exits 3 when it stopped for `disabled` and 4 for `capped`, so a shell
loop can tell a stop from a finish.

### 5.8 Constants

| constant | effect |
|---|---|
| `GEN_QUEUE_DISABLED` | true forces the global switch off; the option cannot turn it on |
| `GEN_QUEUE_DAILY_CAP` | decimal string; overrides the option |
| `GEN_QUEUE_DISABLE_CRON_RUNNER` | WP-Cron does housekeeping only |
| `GEN_QUEUE_MAX_WORKERS` | overrides `max_workers` |

## 6. Data

Two tables, created with `dbDelta` and verified afterwards (rule 14.9).

**`{prefix}gen_queue_jobs`**

| column | type | notes |
|---|---|---|
| `id` | bigint unsigned, auto increment | primary key |
| `type` | varchar(64) | |
| `idempotency_key` | varchar(191) null | unique with `type` |
| `status` | varchar(16) | queued, running, succeeded, failed, cancelled, expired |
| `priority` | smallint | |
| `payload` | longtext | JSON |
| `group_key` | varchar(64) | |
| `resource_key` | varchar(128) | |
| `source` | varchar(64) | caller label, default the type prefix |
| `attempts` | smallint unsigned | |
| `max_attempts` | smallint unsigned | |
| `run_after` | datetime (UTC) | |
| `expires_at` | datetime null (UTC) | |
| `lease_token` | char(32) null | |
| `lease_until` | datetime null | |
| `worker` | varchar(64) null | |
| `estimate_nanos` | bigint unsigned | |
| `cost_nanos` | bigint unsigned | sum over attempts |
| `cancel_requested` | tinyint | |
| `result` | longtext null | JSON, up to 16 KiB |
| `last_error` | varchar(500) null | truncated |
| `last_error_class` | varchar(16) null | retryable, permanent, account, exception, abandoned, cancelled |
| `created_by` | bigint unsigned | user id, 0 for system |
| `created_at`, `updated_at`, `finished_at` | datetime (UTC) | |

Indexes: unique (`type`, `idempotency_key`); (`status`, `priority`,
`run_after`); (`type`, `status`); (`resource_key`, `status`);
(`status`, `finished_at`).

**`{prefix}gen_queue_attempts`**

| column | type | notes |
|---|---|---|
| `id` | bigint unsigned | primary key |
| `job_id` | bigint unsigned | indexed |
| `attempt_no` | smallint unsigned | |
| `type`, `group_key`, `resource_key` | as above | copied for reporting |
| `outcome` | varchar(16) | started, success, retry, failed, permanent, account, abandoned, skipped, cancelled |
| `cost_nanos` | bigint unsigned | |
| `cost_day` | date | local day, indexed with `type` |
| `input_units`, `output_units` | bigint unsigned | as reported |
| `usage_ref` | varchar(191) null | |
| `metered` | tinyint | |
| `duration_ms` | int unsigned | |
| `error` | varchar(500) null | |
| `log` | text null | handler log lines, 4 KiB max |
| `started_at`, `finished_at` | datetime (UTC) | |

Options (all non-autoloaded except the settings array):
`gen_queue_enabled` ('1' or '0', default '1'), `gen_queue_daily_cap`
(nanos or empty, default empty), `gen_queue_currency` (label, default
empty), `gen_queue_paused_types` (list), `gen_queue_breakers` (per key:
count and cooling-until), `gen_queue_holds` (per group: until and message),
`gen_queue_settings` (autoloaded: `max_workers`, `tick_seconds`,
`retention_days`, `cooldown_threshold`, `cooldown_seconds`,
`account_hold`), `gen_queue_db_version`, `gen_queue_cap_notice_day`.

Retention: case 70. Uninstall (via `uninstall.php`): drops both tables,
deletes every `gen_queue_` option, and clears the scheduled hook.
Deactivation clears the scheduled hook only and keeps all data.

## 7. Configuration and defaults

| setting | default | why |
|---|---|---|
| global switch | on | the cap still stops everything until set |
| daily cap | unset (nothing runs) | money fails closed |
| currency label | empty | display only |
| max workers | 2 | enough to overlap cron and one CLI run |
| tick seconds | 25 | fits common 30 s limits |
| cooldown threshold / seconds | 2 / 600 | two failures in a row is a pattern, not a blip |
| account hold | 3600 s | an empty balance does not fix itself in minutes |
| retention | 30 days | enough for a month of status history |
| payload limit | 64 KiB | payloads hold references, not documents |
| type defaults | case 5 | |

Settings are on the status page (Tools, Generation queue) and in WP-CLI.
Constants override options.

## 8. Security and privacy

- Status page and every admin action require the `gen_queue_capability`
  (default `manage_options`) and a nonce. All output is escaped.
- Payloads are hidden on the status page by default (size and SHA-256 shown);
  the `gen_queue_show_payload` filter enables display, still escaped.
- Handlers run with no logged-in user context of their own; a handler must
  not assume `current_user_can()` is meaningful in a runner.
- No API keys are ever stored by gen-queue; handlers keep their own.
- Error messages are truncated to 500 bytes and stripped of anything that
  looks like a bearer token or key (`Bearer \S+`, `key=\S+`) before storage.
- All SQL through `$wpdb->prepare()`. Type names, keys and statuses are
  validated against fixed patterns before use.
- Personal data: `created_by` is a user id. A personal data eraser sets it to
  0 for the user; an exporter lists the jobs that user created (id, type,
  status, dates; payload omitted).

## 9. Performance budget

- Empty tick (nothing eligible): under 20 ms and at most 6 queries.
- Claim of one job: one select on the status index and one conditional
  update; under 10 ms on a table of 1,000,000 rows.
- Spend check: one indexed sum on `cost_day` per job, or the filter's value.
- `enqueue_many` of 10,000 items: under 5 s.
- Status page: under 300 ms on 1,000,000 job rows, using indexed counts and
  the last 7 days of attempts only.
- Housekeeping: bounded to 1 s per tick.
- Memory: the runner frees each job before the next; no unbounded arrays.

## 10. Compatibility

- PHP 8.1, 8.2, 8.3. WordPress 6.4+. MySQL 5.7+ or MariaDB 10.4+ (named
  locks via `GET_LOCK`, `utf8mb4`).
- Works as a normal or must-use plugin. As a must-use plugin, tables are
  created on first load when the stored version is missing.
- Persistent object caches: safe (case 68).
- Page caches: irrelevant; no front-end output.
- Hosts with WP-Cron disabled: use system cron with `wp gen-queue run`.
- Multisite: per-site tables and settings; network activation installs on
  each site; no network-wide cap in 0.1.0.

## 11. Observability

- Status page: switch state and its source (option or constant), cap,
  spend today and its source, reserved amount, counts by status and type,
  cooling keys with end time, account holds with message, paused types,
  jobs with no handler, the 50 most recent failures with error class and
  message, attempts over timeout, cost per day and type for 7 days, last
  tick time and stop reason. Actions: pause/resume, set cap, retry,
  cancel.
- Admin notice when no cap is set, when the switch is off, when a group is
  on account hold, or when WP-Cron has not ticked for 10 minutes.
- Log lines through `error_log` with the prefix `[gen-queue]`, once per
  event, never once per job in a loop: cooldown start, account hold, cap
  reached, pre-spend denial, filter misbehaviour, stale completion discarded.
- Daily failure counters per type, for observability only. Nothing halts on
  them.
- `wp gen-queue status --format=json` for monitoring.

## 12. Acceptance tests

Each test names the case it covers. Tests run against the stub layer and an
in-memory table double, plus integration tests against a real database in CI.

1. (1) Registering in the action works; before it throws; after it logs.
2. (2) Invalid type names throw.
3. (3) A duplicate registration throws; the first handler stays.
4. (4) A non-callable handler throws.
5. (5) A job inherits every type default; per-job overrides win.
6. (6) Enqueue returns an id, stores `queued`, fires the action once.
7. (7) Unknown type throws; with `allow_unregistered` it stores.
8. (8) Objects, invalid UTF-8 and oversized payloads throw; nothing stored.
9. (9) Same key twice returns the same id; two racing inserts leave one row.
10. (10) Terminal job plus `return` is unchanged; `requeue` requeues; a
    succeeded job is never requeued.
11. (11) No key, two calls, two rows.
12. (12) Priority 101 throws; higher priority is claimed first.
13. (13) A future `run_after` is not claimed until due.
14. (14) Expiry set from `ttl`; `ttl` 0 leaves it null.
15. (15) 1,200 items insert in three batches in input order; one bad item
    inserts nothing.
16. (16) Enqueue succeeds with the switch off and the cap reached.
17. (17) With all slots held, run stops with `busy`, not `empty`.
18. (18) Switching off between two jobs in one long run stops before the
    second, with a primed object cache.
19. (19) Switch off: nothing claimed, reason `disabled`.
20. (20) A paused type is skipped; its attempts are unchanged.
21. (21) Each ineligibility (backoff, cooling, hold, paused, capped, expired,
    no handler) prevents a claim.
22. (22) Two runners, one job: exactly one handler call.
23. (23) A handler that triggers a fatal leaves `attempts` at 1.
24. (24) Switch flipped inside `gen_queue_claimed`: handler not called,
    attempt restored, row `skipped`.
25. (25) Context exposes attempt, deadline, cost, cancel and log.
26. (26) Success stores the result, closes the attempt, clears the failure
    counter, fires `gen_queue_completed`.
27. (27) A job with timeout longer than remaining time is not started.
28. (28) Stop reasons `max`, `empty` and `waiting` are reported correctly.
29. (29) With no cap set, and with cap 0, no job runs.
30. (30) Cap check with estimate: a job that would exceed is left untouched.
31. (31) Estimate 0 runs below the cap and not at it.
32. (32) Reservation held while running and released after; overshoot never
    exceeds actual minus estimate.
33. (33) An attempt started 23:59 local and finished 00:01 counts on the
    first day; DST change days compute correctly.
34. (34) The filter can raise the figure; a junk return is handled safely.
35. (35) Per-type cap stops that type only; the stricter cap wins.
36. (36) `gen_queue_can_run` false vetoes; true cannot beat a reached cap.
37. (37) `gen_queue_cap_reached` fires once per day.
38. (38) Each exception class maps to its error class; a plain `Error` is
    retryable.
39. (39) Backoff is within the jitter bounds, honours `retryAfter`, and the
    filter is clamped.
40. (40) Last retryable attempt ends `failed`.
41. (41) Permanent fails at once and leaves the breaker alone.
42. (42) Account error requeues without using an attempt and holds the whole
    group; no cooldown.
43. (43) Cost added before a throw is recorded.
44. (44) A job of a deregistered type is never claimed, then expires.
45. (45) Expired lease: attempt closed `abandoned`, job retried; a late
    completion with the old token is discarded.
46. (46) Counter increments on retryable, clears on success, ignores
    permanent and account.
47. (47) Two failures cool the key for 600 s with one log line; its jobs
    keep their attempts.
48. (48) After cooldown a single failure cools again.
49. (49) A per-job resource key cools only that key.
50. (50) `gen_queue_resource_cooling()` and `useResource()` work; the
    attempt row stores the key used.
51. (51) Cancelling a queued job.
52. (52) Cancelling a running job ends `cancelled`, keeps cost and result.
53. (53) Cancelling a terminal job returns false.
54. (54) Bulk cancel returns the right count.
55. (55) Retry raises `max_attempts` and keeps history.
56. (56) Expiry runs on tick, uses `expired`, is suspended while switched off.
57. (57) Pause and resume a type.
58. (58) Activation schedules the tick; the constant limits it to
    housekeeping.
59. (59) CLI and cron in parallel never double-run a job.
60. (60) `--until-empty` retries on `busy` and exits on `empty`; exit codes
    3 and 4.
61. (61) Housekeeping stays inside 1 s with 100,000 expired rows (batched).
62. (62) Storage failure on enqueue throws `StorageException`.
63. (63) Failure while closing an attempt leaves the job for lease reclaim.
64. (64) Comparisons use database time.
65. (65) A slow handler past its lease does not overwrite the reclaimed run.
66. (66) A handler calling `exit` is reclaimed.
67. (67) A throwing spend filter falls back to own records.
68. (68) A stale object cache value for the switch or cap is ignored.
69. (69) Timezone change mid-day keeps stored days.
70. (70) Purge removes old terminal rows only; 0 keeps everything.
71. (5.6) With a listener on `gen_queue_cost_recorded`, each record arrives
    once with `metered` and `usage_ref`; with none, nothing breaks.
72. (5.1) The soft enqueue filter returns null without the plugin and an id
    with it.
73. (8) Status page actions reject a missing nonce and a user without the
    capability; output is escaped; payloads hidden by default; secrets
    redacted from stored errors.
74. (6) Uninstall drops tables and options; deactivation keeps them.
75. (14.9) After an upgrade that adds a column, the installer verifies the
    column exists and reports failure if not.
76. (71) With a stub for `spend_meter_pre_spend`, the intent has the exact
    keys, `dry_run` true and `estimate_override` equal to the estimate in
    nanos; a type name with odd characters becomes a valid purpose.
77. (72) Decisions `null`, allowed, `cap_site_day`, `kill_switch`, an unknown
    code, a malformed array and a thrown error: carry on, carry on, skip,
    stop `disabled`, skip, skip, skip. Skipped jobs keep their attempts.
78. (73) With a stub on `spend_meter_record`, an unmetered record forwards
    once with the keys in case 73; a metered record and a zero record forward
    nothing; a non-UUID `usage_ref` is replaced and kept in `meta`.
79. (74) With the stub present and allowing everything, no cap and cap 0 run
    nothing, and the stub is never called.

## 13. Open questions

For the builder to answer in `docs/ARCHITECTURE.md`:

1. Named locks (`GET_LOCK`) or a lock row in a table for worker slots and the
   claim lock? `GET_LOCK` is per connection and released on disconnect, which
   is what we want, but some managed databases limit it.
2. Should breaker and hold state live in options (current plan) or a small
   third table? Options are simple; a table avoids option-cache traps.
3. Is a plain `Throwable` better treated as retryable (current plan) or
   permanent? Retrying a programming error wastes attempts but never money,
   since nothing was called.
4. A read-only REST endpoint for monitoring in 0.2.0?
5. The spend-meter names in section 4.10 follow its merged PRD, contract
   version 1. Confirm them against the released plugin. Should the pre-spend
   dry run prove too coarse for a type, is a per-type opt-out worth adding?
6. Should `lifo` order be available per job, or only per type?
7. Network-wide cap on multisite: wanted, and how is spend aggregated?

## 14. Lessons from production

Each is a rule the build must follow.

1. **Claim before you run.** Never select work and then process it without
   an atomic claim. A manual backfill overlapping the scheduled lane will
   otherwise pay for the same work twice.
2. **Busy is not empty.** Lock contention and an empty queue must be
   different results everywhere: return values, stop reasons, exit codes.
3. **Count the attempt before the call.** Write the attempt marker and commit
   it before the handler runs, so a fatal mid-request cannot cause a retry
   loop that pays each time.
4. **A deliberate stop never consumes an attempt.** Kill switch, pause,
   cap and cooldown skips leave the job exactly as it was, so switching back
   on finds everything still eligible.
5. **Read the money and the switch fresh, every job.** Never trust a value
   read at process start or held in an object cache. Long CLI runs are where
   this bites. Re-read spend immediately before each check, so concurrent
   runners accumulate rather than clobber.
6. **Out of budget is not failed.** A capped job is untouched and is
   reconsidered tomorrow, never written off.
7. **Account errors are not job errors.** An empty balance, a bad key or a
   forbidden response is shared by every model on the account. Do not
   retry it per job, do not cool a model on it, hold the group.
8. **One bounded retry on the same resource is cheap and often enough.**
   Truncated and malformed bodies are routine; retry them with a short
   backoff before anything else. Decide retryability from structured data
   (the exception class), never by parsing an error string.
9. **Verify schema changes.** `dbDelta` has silently skipped column adds.
   After every install or upgrade, check the expected columns exist and
   report failure loudly if not.
10. **Test traffic never cools live resources.** Breakers are keyed by what
    the handler declares; a sandbox endpoint gets its own key.
11. **A pause must keep its backlog.** Expiry is suspended while the queue is
    switched off, or the first tick after resuming sweeps a deliberately
    paused queue into the bin.
12. **Freshest first when money is short.** Offer `lifo` order, so when the
    cap bites the budget goes to new work and old backfill waits.
13. **Failure counters are for eyes, not brakes.** Count chain-wide failures
    for the status page; never halt on them. Halting belongs to the cap, the
    switch, cooldowns and holds, each of which is visible and reversible.
14. **Billed failures are still spend.** Record cost reported before an
    exception; a truncated response is usually billed in full.
15. **The queue never publishes.** Handlers write drafts or suggestions for a
    human to accept. A queue that can publish on its own will one day publish
    a thousand bad posts overnight.
16. **No cap means no spend.** An unset cap is not "unlimited". The queue
    runs nothing until the operator sets a number, says so on the status page
    and in an admin notice, and ignores a meter that has no cap either. A
    default of unlimited is how a bulk job drains an account overnight.
17. **One ledger row per call, from one writer.** When a queue and a meter
    both see a cost, the handler that made the call records it and says so
    (`metered`); the queue forwards only what nobody else recorded, with a
    stable id, so a retry never counts twice.
