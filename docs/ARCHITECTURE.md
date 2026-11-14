# gen-queue architecture

This document describes how gen-queue is built and records every decision
taken while building it from [PRD.md](PRD.md). Section and case numbers
refer to the PRD.

## Modules

| File | Role |
|---|---|
| `gen-queue.php` | Plugin header. Defines `GEN_QUEUE_VERSION`, loads `autoload.php`, calls `Plugin::load()`. |
| `autoload.php` | PSR-4 loader for `IndianOpinion\GenQueue\` plus `src/functions.php`. No Composer needed. |
| `src/functions.php` | The global `gen_queue_*` wrappers (section 5.2). |
| `src/Plugin.php` | Builds the services, hooks them into WordPress, activation, deactivation, the WP-Cron tick, the soft enqueue filter. |
| `src/Registry.php`, `src/JobType.php` | Type registration, name rules, defaults (cases 1 to 5). |
| `src/Queue.php` | Enqueue, idempotency, `enqueue_many`, cancel, retry, pause (cases 6 to 16, 51 to 57). |
| `src/Runner.php` | The run loop: fresh reads, candidate selection, cap checks, atomic claim, the handler call, outcomes (cases 17 to 45). |
| `src/Controls.php` | Switch, pause list, cap, spend, settings, all read fresh (cases 18, 29 to 36, 68). |
| `src/Breakers.php` | Cooldowns per resource key and holds per group (cases 42, 46 to 50). |
| `src/SpendMeter.php` | The optional spend-meter bridge (section 4.10, cases 71 to 74). |
| `src/Housekeeping.php` | Lease reclaim, expiry, retention purge, bounded to 1 s (cases 45, 56, 61, 70). |
| `src/Backoff.php` | Full-jitter exponential backoff and its filter (case 39). |
| `src/Installer.php`, `src/Uninstaller.php` | Schema install with column verification (rule 14.9), uninstall. |
| `src/Status.php` | One snapshot for the status page, the notices and `wp gen-queue status` (section 11). |
| `src/Admin/StatusPage.php`, `src/Admin/Notices.php` | Tools > Generation queue and the admin notices (sections 8 and 11). |
| `src/Cli/Command.php` | `wp gen-queue ...` (section 5.7), with an `Io` interface so it is testable. |
| `src/Privacy.php` | Personal data exporter and eraser for `created_by` (section 8). |
| `src/Storage/Store.php` | The storage interface. Every database read and write goes through it. |
| `src/Storage/WpdbStore.php` | The `$wpdb` implementation: prepared SQL, UTC datetimes, direct option reads and writes. |
| `src/Money.php`, `src/Payload.php`, `src/Days.php`, `src/Redactor.php`, `src/Validate.php`, `src/Log.php` | Small helpers. |
| `tests/Support/MemoryStore.php` | The in-memory table double used by the unit tests and `examples/demo.php`. |

## Data flow

1. **Register.** On `init` at priority 20 the plugin opens the registry and
   fires `gen_queue_register_types`. Handlers are stored as closures.
2. **Enqueue.** `Queue::enqueue()` validates the arguments and the payload,
   encodes the payload as JSON, and inserts one `queued` row. With an
   idempotency key, a refused insert means the unique (type, key) index
   decided, so the existing row is read and returned (or requeued).
3. **Run.** `Plugin::run()` (WP-Cron tick or `wp gen-queue run`) calls
   `Runner::run()`:
   1. housekeeping (reclaim, expiry, daily purge), at most 1 s;
   2. kill switch; then a worker slot row, or stop `busy`;
   3. per job: fresh switch, cap, pause list, cooling keys and holds; select
      up to 25 candidates in order (priority desc, `run_after` asc, id asc);
   4. per candidate, under the claim lock: global cap, type cap,
      `gen_queue_can_run`, spend-meter pre-spend, then the conditional
      `UPDATE ... SET status = 'running', attempts = attempts + 1 ... WHERE
      id = ? AND status = 'queued'`;
   5. insert the attempt row (`started`), fire `gen_queue_claimed`, re-check
      the switch and the pause list, call the handler;
   6. close: one conditional update keyed by the lease token, then the
      attempt row, breakers and holds, actions, and the cost forward.
4. **Observe.** `Status::snapshot()` feeds the status page, the notices and
   `wp gen-queue status --format=json`.

## Rulings on the PRD's open questions

1. **Locks: row claims in the plugin's own table through one atomic UPDATE;
   no MySQL named locks.** Reason: `GET_LOCK` is per connection, some
   managed databases limit or disable it, and it cannot be seen from the
   status page. A lock row with an owner and an expiry time works on every
   MySQL and MariaDB, survives a dead process (the expiry frees it) and is
   claimed by one `UPDATE ... WHERE name = ? AND (owner = '' OR owner = ? OR
   locked_until < now)`. This needs a third table, `{prefix}gen_queue_locks`
   (name, owner, locked_until). Worker slots are rows `worker-1` to
   `worker-N`; the claim lock is the row `claim`; housekeeping has its own
   row. Job claims themselves are the conditional update on the jobs table.
2. **Cooldown state: one option per provider, keyed by model.** Reason: a
   single option for every key grows without bound and turns every failure
   anywhere into a write of one big value. The provider is the part of the
   resource key before the first `/` (`acme/model-x` belongs to `acme`); a
   key without a slash belongs to `default`. The option is
   `gen_queue_breakers_<provider>`, the provider made safe for an option
   name (lower case, `[a-z0-9_-]`, with a short hash suffix when it had to
   change). All breaker options are read in one `LIKE` query, fresh. Holds
   stay in one option, `gen_queue_holds`, keyed by group.
3. **Unexpected Throwable: not retryable.** The job is marked `failed` with
   error class `exception`, and only the exception's class name is stored
   (on the job and on the attempt), never its message. Reason: an
   unexpected error is a bug in the handler; retrying it wastes attempts
   and may repeat a paid call that already happened, and its message may
   carry data that should not be stored. This changes PRD case 38 and test
   38, which assumed retryable. It does not count towards a cooldown,
   because it says nothing about the resource. A handler that returns
   something other than a `JobResult`, an array or null is treated the same
   way (`UnexpectedValueException`).
4. **REST: none in 0.1.0; roadmap item.** Reason: WP-CLI and the status page
   cover operators, and `wp gen-queue status --format=json` covers
   monitoring. A read-only endpoint is listed under Unreleased ideas in the
   CHANGELOG.
5. **Hook names: as the PRD states (spend-meter contract 1).**
   `spend_meter_pre_spend` and `spend_meter_record`, with `SPEND_METER_API`
   as the presence check. Reason: the names are spend-meter's own,
   contract version 1, and both plugins use nanos. No per-type opt-out of
   the pre-spend check was added: the check is one dry-run filter call, and
   an opt-out can come later as a minor version if a type proves to need it.
6. **Ordering: FIFO within priority; priority is a job field. No LIFO.**
   Registering a type with `order` other than `fifo` throws
   `RegistrationException` with a message pointing at priority. Reason: one
   order keeps the claim query on one index and keeps behaviour
   predictable; "freshest first when money is short" (rule 14.12) is done by
   enqueuing new work at a higher priority.
7. **Network cap: single site only in 0.1.0; on multisite each site has its
   own cap.** Each site has its own tables, options, schedule and cap.
   Network activation installs on every site; uninstall removes from every
   site.

## Builder decisions

Where the PRD left a choice open, this is what was built and why.

1. **Claim lock.** Cap checks and the claim run under the `claim` lock row,
   held for at most 30 s and normally milliseconds. A runner that cannot
   get it within about 1 s stops with `busy`. Reason: case 30 requires the
   cap check under the claim lock so concurrent runners accumulate.
2. **Worker slot lease** is the run's time budget plus 300 s. A runner that
   dies frees its slot when the lease passes.
3. **Timeout longer than the whole budget.** Case 27 says a runner never
   starts a job whose timeout exceeds its time left. With the defaults
   (timeout 120 s, tick 25 s) WP-Cron would then never run anything. So a
   job whose timeout is at least the whole budget may run as a runner's
   first job; after that, case 27 applies strictly.
4. **TTL counts from `run_after`,** not from the enqueue, so a delayed job
   cannot expire before it was allowed to run. `expires_at` overrides it.
5. **Costs are written as they are reported.** Each `addCost()` adds to the
   attempt row and the job's total at once, so a fatal error later does not
   lose a billed cost. If that write fails, the cost is written when the
   attempt closes. The `spend_meter_record` forward and
   `gen_queue_cost_recorded` happen when the attempt closes, because the
   status (`ok` or `error_billed`) is only known then.
6. **Late completions** (stale lease token) change nothing on the job except
   adding the cost they reported, which is also forwarded as
   `error_billed`. Billed is billed (rule 14.14).
7. **Reservations are conservative.** While a job runs, both its full
   estimate (reserved) and any cost it has already reported (spent) count
   against the cap. This can only make the queue stop earlier, never later.
8. **`gen_queue_can_run`** vetoes on anything other than `true`, so a broken
   filter fails closed. A vetoed job is skipped for the rest of that run and
   keeps its attempts.
9. **`gen_queue_cap_reached`** fires the first time on a local day that the
   global cap stops a job (the estimate no longer fits), with one log line.
   The day is kept in `gen_queue_cap_notice_day`.
10. **No cap set** stops the runner with reason `capped`, so `wp gen-queue
    run` exits 4 and a shell loop treats it as a stop, not a finish. The
    pre-spend check is never consulted (case 74).
11. **`enqueue_many` inserts one row per statement inside one transaction
    per batch of 500.** Reason: with MySQL's interleaved auto-increment mode
    (the default since 8.0) the ids of a multi-row insert are not
    guaranteed to be consecutive, so ids could not be returned in input
    order reliably. Every item is validated before the first insert.
12. **Options are read and written with direct SQL.** Reads go straight to
    the options table (case 68). Writes use `INSERT ... ON DUPLICATE KEY
    UPDATE` and then clear the option caches, because `update_option()`
    compares with a possibly stale cached value and can skip the write.
13. **Oversized or unencodable results** (over 16 KiB) are replaced by a
    small marker and logged; the job still succeeds, because the work was
    done and paid for.
14. **Spend-meter purpose.** The type's `meter.purpose`, else the type name,
    is made to fit `^[a-z0-9][a-z0-9_.-]{0,63}$`: lower case, other
    characters to `-`, leading separators dropped, cut to 64; an empty
    result becomes `gen-queue`. Reason codes that are not short snake case
    are treated as `unknown`. A denial is logged once per reason per run
    and the latest is stored in `gen_queue_last_denial` for the status page.
15. **Spend filter values.** A float is rounded up; a digit string is used;
    in both cases the larger of that and the queue's own sum wins. Anything
    else falls back to the queue's own sum with a warning.
16. **Error text** is redacted (`Bearer ...`, `key=`, `token=`, `secret=`,
    `password=`, `sk-...`) and cut to 500 bytes on a UTF-8 boundary before
    it is stored or logged.
17. **A failed schema check blocks running.** The error is stored in
    `gen_queue_install_error`, shown as an admin notice and on the status
    page, and every run stops with `disabled` until a reinstall verifies.
18. **Account errors** count as `retried` in `RunSummary`, since the job goes
    back to the queue. Releasing a hold early is an action on the status
    page.
19. **Jobs of unregistered types** enqueued with `allow_unregistered` take
    fallback defaults (5 attempts, 7 day TTL, group and resource key equal
    to the type name).
20. **Retrying an expired job** gives it a fresh expiry from its type's TTL,
    or none, so it does not expire again at once.
21. **`gen_queue_expired`** is fired per job only when something listens, so
    sweeping 100,000 rows stays inside the 1 s housekeeping budget.
22. **Abandoned attempts** (lease expired) do not count towards a cooldown:
    a dead PHP process says nothing about the provider.
23. **Extra operator surface.** `wp gen-queue settings` shows and changes the
    numeric settings (section 7 says settings are in WP-CLI); the status
    page has a settings form and a "release hold" action.
24. **Extra options**, all prefixed `gen_queue_` and removed on uninstall:
    `gen_queue_last_tick`, `gen_queue_last_denial`, `gen_queue_purge_day`,
    `gen_queue_install_error`, and the per-provider `gen_queue_breakers_*`.
25. **The runner's own pause re-check (case 24)** also covers a type paused
    inside `gen_queue_claimed`: that job goes back to `queued` with its
    attempt returned, and the run carries on with other types.
26. **Idempotent enqueue looks before it inserts.** With a key, the existing
    row is read first; only a true race reaches the unique index, and that
    expected duplicate-key error is kept out of the debug output.
27. **Install check on page loads** uses the cached `get_option()` for the
    schema version and confirms with a fresh read only when it differs, so
    ordinary requests pay no extra query. Money and switch reads stay fresh.

## Testing

- `tests/Unit`: 144 tests against the WordPress stub layer in
  `tests/stubs/` and the in-memory store. Each test names the PRD case it
  covers. spend-meter tests run in separate processes because they define
  `SPEND_METER_API`.
- `tests/Integration`: the `$wpdb` store against a real MySQL or MariaDB,
  through a small `wpdb` stand-in on mysqli: 9 tests, including four runner
  processes draining one queue in parallel (every job runs exactly once).
  Skipped unless `GEN_QUEUE_TEST_DB_HOST` or `GEN_QUEUE_TEST_DB_SOCKET` is
  set. CI runs them against MySQL 8.0.
