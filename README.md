# gen-queue

A WordPress job queue for paid AI generation work, with a daily spend cap, a kill switch, retries with backoff, cooldowns, per-attempt cost records and a status page. It holds no prompts and calls no provider: other plugins register a handler, enqueue jobs, and report what each call cost.

Written from scratch; not derived from any third-party plugin.

This project came out of the work on indianopinion.org and is written from scratch; it is independent of and not affiliated with any third-party plugin it integrates with.

Measured: before busy and empty were told apart, a long manual drain exited 4.5 hours early because another runner held the lock.

**With no daily cap set, nothing runs.** Set one with `wp gen-queue cap <amount>`.

## Why

Plugins that call a paid API in bulk each rewrite the same loop and get the same things wrong: work selected but not claimed, so an overlapping backfill pays twice; a fatal error that leaves a row looking untried, so it is paid for again and again; a cap read once at the start of a run, so concurrent runners overshoot it; an empty account balance treated as one job's failure, so every job burns its retries. gen-queue does that loop once. Your plugin writes a handler.

## Install

Requirements: PHP 8.1 or later, WordPress 6.4 or later, MySQL 5.7+ or MariaDB 10.4+.

**As a normal plugin.** Copy the folder to `wp-content/plugins/gen-queue/` and activate it. Activation creates three tables and schedules a WP-Cron tick every minute.

**As a must-use plugin.** Copy the folder to `wp-content/mu-plugins/gen-queue/` and copy [`examples/mu-plugin-loader.php`](examples/mu-plugin-loader.php) to `wp-content/mu-plugins/gen-queue-loader.php`. Must-use plugins have no activation step, so the tables are created on the first load.

**Without Composer.** Nothing to do: the plugin loads its own classes through `autoload.php`. To use the classes outside the plugin, `require 'path/to/gen-queue/autoload.php';`.

**With Composer.** `composer require indianopinion/gen-queue` (type `wordpress-plugin`, so installers place it in your plugins folder). It has no runtime dependencies.

## Quick start

1. Set a daily cap. Until you do, nothing runs:

   ```sh
   wp gen-queue cap <amount>      # for example: wp gen-queue cap 2.50
   ```

2. Register a job type in your plugin and enqueue work:

   ```php
   use IndianOpinion\GenQueue\{Job, JobContext, JobResult, RetryableException, PermanentException};

   add_action('gen_queue_register_types', function (): void {
       gen_queue_register_type('my-plugin.summary', 'my_summary_handler', [
           'resource_key'  => 'my-provider/small-model', // cooldowns are kept per key
           'group'         => 'my-provider',             // account errors hold the whole group
           'cost_estimate' => '0.01',                    // reserved against the cap while running
           'timeout'       => 60,
       ]);
   });

   function my_summary_handler(Job $job, JobContext $ctx): JobResult {
       $response = my_provider_call($job->payload['post_id']);  // your own client
       $ctx->addCost($response->cost, ['input_units' => $response->in, 'output_units' => $response->out]);
       if ($response->truncated) {
           throw new RetryableException('Truncated response');
       }
       $draftId = wp_insert_post(['post_status' => 'draft', /* ... */]);  // a draft, never a publish
       return JobResult::ok(['draft_id' => $draftId]);
   }

   gen_queue_enqueue('my-plugin.summary', ['post_id' => 42], ['idempotency_key' => 'summary-42']);
   ```

3. Let WP-Cron drain it, or run it yourself:

   ```sh
   wp gen-queue run --until-empty
   wp gen-queue status
   ```

The handler writes drafts, pending comments or suggestions for an editor to accept. gen-queue never publishes anything.

More: [`examples/`](examples/) has a full handler, the soft enqueue for plugins that do not require gen-queue, a must-use loader, and `php examples/demo.php`, which runs the queue without WordPress.

## Configuration

| Setting | Default | Where | Notes |
|---|---|---|---|
| Daily cap | **unset: nothing runs** | `wp gen-queue cap <amount>`, Tools > Generation queue, or `GEN_QUEUE_DAILY_CAP` | A cap of 0 also runs nothing. The constant overrides the option. |
| Kill switch | on | `wp gen-queue pause` / `resume`, status page, or `GEN_QUEUE_DISABLED` | The constant forces it off; the option cannot turn it back on. |
| Pause one type | none | `wp gen-queue pause <type>` | Its jobs wait and keep their attempts. |
| Currency label | empty | `wp gen-queue cap <amount> --currency=EUR`, status page | Display only. |
| `max_workers` | 2 | `wp gen-queue settings --max_workers=3`, status page, `GEN_QUEUE_MAX_WORKERS` | Runners allowed at once. |
| `tick_seconds` | 25 | settings, filter `gen_queue_tick_seconds` | WP-Cron time budget, capped at the PHP time limit minus 5 s. |
| `retention_days` | 30 | settings, filter `gen_queue_retention_days` | Finished jobs older than this are purged daily. 0 keeps everything. |
| `cooldown_threshold` | 2 | settings | Consecutive retryable failures before a resource key cools. |
| `cooldown_seconds` | 600 | settings | How long a key cools. |
| `account_hold` | 3600 | settings | How long a group is held after an account error. |
| Cron runner | on | `GEN_QUEUE_DISABLE_CRON_RUNNER` | When true, WP-Cron does housekeeping only; run jobs with `wp gen-queue run` from system cron. |

Type options for `gen_queue_register_type()` (those marked "job" can also be set per job in `gen_queue_enqueue()`):

| Option | Default | Notes |
|---|---|---|
| `max_attempts` (job) | 5 | |
| `backoff_base` / `backoff_max` | 30 / 3600 s | Full jitter: a random delay up to `min(max, base * 2^(attempt - 1))`. |
| `timeout` | 120 s | A runner does not start a job whose timeout exceeds its time left (except as its first job). |
| `priority` (job) | 0 | -100 to 100. Higher runs first; first in, first out within a priority. |
| `ttl` (job) | 7 days | Counted from when the job may first run. 0 means never expires. |
| `group` (job) | type name | Ties types to one account for account holds. |
| `resource_key` (job) | type name | What cooldowns are kept against, for example a model. `provider/model` keeps one option per provider. |
| `cost_estimate` (job) | 0 | Reserved against the cap while the job runs. |
| `daily_cap` | none | A per-type cap on top of the global one; the stricter wins. |
| `meter` | none | `purpose`, `provider` and `model` sent to spend-meter. |

Enqueue options also include `idempotency_key`, `on_conflict` (`return` or `requeue`), `run_after`, `expires_at`, `source` and `allow_unregistered`.

## How it works

- **Storage.** Three tables: jobs, attempts (one row per run of a handler, with its cost and local day), and a small lock table. Settings and state are options. The schema is checked column by column after every install or upgrade.
- **Runners.** A WP-Cron tick and `wp gen-queue run` use the same code. A runner takes one of `max_workers` slot rows; if none is free it stops with `busy`, which is never confused with `empty`.
- **Fresh reads.** Before every job the runner reads the switch, the pause list, the cap and today's spend straight from the database, past any object cache.
- **Claims.** Under a short claim lock the runner checks the global cap, the type cap, `gen_queue_can_run` and spend-meter, then claims the job with one conditional `UPDATE ... WHERE status = 'queued'`. No job runs twice at once.
- **Attempt first.** The attempt is counted and written before the handler runs, so a fatal error cannot cause a paid retry loop. A deliberate stop (switch, pause) after the claim gives the attempt back.
- **Outcomes.** `RetryableException` retries with backoff and counts towards the resource's cooldown. `PermanentException` fails at once. `AccountException` requeues without using an attempt and holds the whole group. `CancelledException` ends cancelled. Any other `Throwable` is a bug in the handler: the job fails and only the exception class is stored.
- **Leases.** A claimed job has a lease. If the worker dies, the next runner reclaims the job, closes the attempt as `abandoned` and retries it. A late completion with an old lease is discarded, but its cost is kept: billed is billed.
- **Money.** Amounts are held as integer nanos (one billionth of your currency unit). A job may run when `spent today + reserved + estimate <= cap`; a job with estimate 0 needs the total strictly below the cap. Costs count on the site's local day the attempt started.
- **Housekeeping.** Each tick spends at most a second on lease reclaim, expiry (suspended while the switch is off, so a pause keeps its backlog) and the daily purge.

See [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) for modules, data flow and every decision.

### spend-meter

When the spend-meter plugin is present (`SPEND_METER_API` is defined), gen-queue asks `spend_meter_pre_spend` with a dry-run intent before each job and forwards unmetered costs to `spend_meter_record`. A `null` answer or `allowed: true` lets the job run; anything else keeps it waiting, and `kill_switch` stops the runner. gen-queue's own cap still applies: with no cap set nothing runs, whatever the meter says. Without spend-meter, the queue is complete on its own.

### Public interface

Functions: `gen_queue_register_type`, `gen_queue_enqueue`, `gen_queue_enqueue_many`, `gen_queue_get`, `gen_queue_find`, `gen_queue_cancel`, `gen_queue_cancel_where`, `gen_queue_retry`, `gen_queue_pause`, `gen_queue_resume`, `gen_queue_is_enabled`, `gen_queue_resource_cooling`, `gen_queue_spend_today`, `gen_queue_cap_remaining`, `gen_queue_run`, `gen_queue_stats`.

Actions: `gen_queue_loaded`, `gen_queue_register_types`, `gen_queue_enqueued`, `gen_queue_claimed`, `gen_queue_completed`, `gen_queue_retry_scheduled`, `gen_queue_failed`, `gen_queue_cancelled`, `gen_queue_expired`, `gen_queue_cost_recorded`, `gen_queue_cooldown_started`, `gen_queue_account_hold`, `gen_queue_cap_reached`, `gen_queue_tick_finished`.

Filters: `gen_queue_enqueue_job` (soft enqueue), `gen_queue_spend_today`, `gen_queue_daily_cap`, `gen_queue_can_run`, `gen_queue_backoff_delay`, `gen_queue_payload_max_bytes`, `gen_queue_tick_seconds`, `gen_queue_retention_days`, `gen_queue_capability`, `gen_queue_show_payload`.

WP-CLI: `wp gen-queue run|status|list|show|enqueue|cancel|retry|pause|resume|cap|purge|stats|settings`. `run` exits 0 on `empty`, `waiting` and `max`, 3 on `disabled`, 4 on `capped`, 1 on error.

The full contract is in [docs/PRD.md](docs/PRD.md), section 5.

## Limitations

- No REST API in 0.1.0. Use `wp gen-queue status --format=json` for monitoring.
- One queue and one cap per site. On multisite each site has its own tables and its own cap; there is no network-wide cap.
- Order is first in, first out within a priority. There is no newest-first order; give new work a higher priority instead.
- PHP cannot stop a handler that runs past its timeout. Its lease expires and another runner may retry the job; the first run's late result is discarded.
- The queue never fails over between models on its own. A handler that wants a fallback asks `gen_queue_resource_cooling()` and reports the key it used with `$ctx->useResource()`.
- Cooldown counters are read and written as options. Two runners failing on one key at the same moment may count one failure, not two; the next failure trips it.
- No price table. Your handler reports what each call cost.

## FAQ

**Why is nothing running?**
First, check the cap: with no daily cap set, nothing runs, and a cap of 0 runs nothing either. Set one with `wp gen-queue cap <amount>`. Then run `wp gen-queue status`: it shows whether the switch is off (option or `GEN_QUEUE_DISABLED`), whether today's spend has reached the cap, which types are paused, which resource keys are cooling, which groups are on account hold, and which queued types have no handler because their plugin is not active. If none of those apply, check that WP-Cron ticks (the status page warns after 10 minutes without a tick) or run `wp gen-queue run` from system cron.

**Why did a job not use up its attempts when I switched the queue off?**
A deliberate stop never consumes an attempt. Switch, pause, cap and cooldown skips leave the job as it was.

**My provider said "out of credit". Why are other job types waiting too?**
An account error is shared by everything on the account, so the queue holds the whole group for `account_hold` seconds instead of burning every job's retries. Release it early on the status page.

**How do I keep test traffic from cooling a live model?**
Give the test job its own `resource_key`, for example `sandbox/model-x`.

**Can a filter make jobs run past the cap?**
No. `gen_queue_can_run` can only veto. `gen_queue_spend_today` can raise the spend figure, never lower it below the queue's own records.

**Does gen-queue publish anything?**
No. Handlers write drafts, pending comments or suggestions that an editor accepts.

## Contributing and security

See [CONTRIBUTING.md](CONTRIBUTING.md) and [SECURITY.md](SECURITY.md). Report security issues to hello@indianopinion.org.

## Licence

MIT. Copyright (c) 2026 Indian Opinion. See [LICENSE](LICENSE).
