# Security policy

## Supported versions

Only the latest release of gen-queue receives security fixes.

## Reporting a vulnerability

Please email **hello@indianopinion.org** with a description of the issue,
the steps to reproduce it and the version you tested. Do not open a public
issue for a security problem.

We acknowledge reports within 3 working days and aim to ship a fix, or a
clear plan, within 30 days. We credit reporters in the release notes unless
they ask us not to.

## Scope

gen-queue moves money-bearing work. Reports that matter most:

- a way to run jobs past the daily cap, or with the kill switch off;
- a way for a user without the configured capability to change the queue;
- SQL injection, missing escaping, or missing nonce checks;
- secrets or payloads exposed on the status page or in logs.
