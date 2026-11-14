=== gen-queue ===
Contributors: indianopinion
Tags: queue, ai, cron, wp-cli, spend cap
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 0.1.0
License: MIT
License URI: https://opensource.org/licenses/MIT

A job queue for paid AI generation work: daily spend cap, kill switch, retries with backoff, cooldowns, cost records and a status page.

== Description ==

gen-queue runs paid generation work for other plugins. It stores jobs, runs them through WP-Cron or WP-CLI, retries them with backoff, records what each attempt cost, stops at a daily spend cap, and stops at once when the kill switch is off. It holds no prompts and calls no provider: a handler written by another plugin does the call and reports the cost.

Written from scratch; not derived from any third-party plugin.

**With no daily cap set, nothing runs.** Set one under Tools > Generation queue, or with `wp gen-queue cap <amount>`.

* Atomic claims: a job never runs twice at once, even with WP-Cron and WP-CLI draining together.
* The attempt is counted before the handler runs, so a fatal error cannot cause a paid retry loop.
* The switch, the pause list, the cap and today's spend are read fresh before every job.
* Account errors hold a whole group instead of burning every job's retries.
* Per-resource cooldowns after repeated failures.
* Optional integration with the spend-meter plugin.
* The queue never publishes. Handlers write drafts or suggestions for an editor.

== Installation ==

1. Upload the `gen-queue` folder to `/wp-content/plugins/` and activate it.
2. Set a daily cap under Tools > Generation queue, or run `wp gen-queue cap <amount>`.
3. Install a plugin that registers a job type, or write your own handler (see the README on GitHub).

To run it as a must-use plugin, copy the folder to `/wp-content/mu-plugins/gen-queue/` and add the loader from `examples/mu-plugin-loader.php`.

== Frequently Asked Questions ==

= Why is nothing running? =

First check the cap: with no daily cap set, nothing runs, and a cap of 0 runs nothing either. Then run `wp gen-queue status` or open Tools > Generation queue: it shows the switch, today's spend, paused types, cooling resources, account holds and jobs whose plugin is not active.

= Does it publish posts? =

No. Handlers write drafts, pending comments or suggestions that an editor accepts.

= Does it work without WP-Cron? =

Yes. Run `wp gen-queue run` from system cron. Define `GEN_QUEUE_DISABLE_CRON_RUNNER` to keep WP-Cron to housekeeping only.

== Changelog ==

= 0.1.0 =
* First release.
