# Contributing to gen-queue

Thank you for helping. Small, focused pull requests are easiest to review.

## Setup

You need PHP 8.1 or later and Composer.

```sh
git clone https://github.com/IndianOpinion/gen-queue.git
cd gen-queue
composer install
```

## Tests

```sh
composer test        # unit tests, no WordPress or database needed
composer analyse     # PHPStan, level 8
composer lint        # PHP_CodeSniffer, PSR-12
composer check       # all three
```

The integration tests run the database store against a real MySQL or
MariaDB. Point them at an empty test database (they drop and create their
own tables):

```sh
GEN_QUEUE_TEST_DB_HOST=127.0.0.1 GEN_QUEUE_TEST_DB_USER=root \
GEN_QUEUE_TEST_DB_PASSWORD=secret GEN_QUEUE_TEST_DB_NAME=gen_queue_test \
composer test:integration
```

Every behaviour in [docs/PRD.md](docs/PRD.md) section 4 has at least one
test, and each test names its case. A change in behaviour needs a test that
fails before it and passes after.

## Style

- PSR-12, checked by `composer lint`. `declare(strict_types=1);` in every file.
- Code must run on PHP 8.1: no readonly classes, no DNF types, no `true`
  type, no constants in traits.
- Errors are typed exceptions. No silent failure, no `@`, no `eval`, no shell.
- Every SQL statement goes through `$wpdb->prepare()`; every output on an
  admin screen is escaped; every admin action checks the capability and a
  nonce.
- Money is integer nanos. Never use floats for sums.
- Prose: short sentences, plain words, British spelling.

## Pull requests

- One topic per pull request, with a clear description of what changes and why.
- Keep `composer check` green.
- Update `CHANGELOG.md` under Unreleased.
- Update `README.md` and `docs/ARCHITECTURE.md` when behaviour or a decision changes.
- A DCO-style sign-off is not required.

By contributing you agree that your work is released under the MIT licence.
