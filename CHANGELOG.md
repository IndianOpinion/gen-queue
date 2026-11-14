# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project
uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

Ideas under consideration, not promises:

- A read-only REST endpoint for monitoring.
- A network-wide cap on multisite.

## [0.1.0] - 2026-11-14

### Added

- Job types registered on `gen_queue_register_types`, with defaults for
  attempts, backoff, timeout, priority, TTL, group, resource key, cost
  estimate, a per-type daily cap and spend-meter identity.
- Enqueue with idempotency keys (`return` or `requeue` on conflict),
  priority, delayed start, expiry, and `gen_queue_enqueue_many()` in
  batches of 500.
- Runners on WP-Cron and WP-CLI sharing one code path, worker slots,
  atomic row claims, attempts counted before the handler runs, and
  leases with reclaim of dead workers.
- A daily cap that defaults to unset (nothing runs), reservations for
  running jobs, a per-type cap, a fresh read of the switch, pause list,
  cap and spend before every job, and a global kill switch.
- Retryable, permanent, account and cancel outcomes; full-jitter backoff;
  per-resource cooldowns; account holds per group.
- Per-attempt cost records in nanos, `gen_queue_cost_recorded`, and the
  optional spend-meter integration (`spend_meter_pre_spend`,
  `spend_meter_record`).
- Tools > Generation queue status page, admin notices, `wp gen-queue`
  commands with distinct exit codes for disabled and capped runs.
- Schema verification after install, retention purge, uninstall cleanup,
  and a personal data exporter and eraser.

[Unreleased]: https://github.com/IndianOpinion/gen-queue/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/IndianOpinion/gen-queue/releases/tag/v0.1.0
