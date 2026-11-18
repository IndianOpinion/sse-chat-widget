# Contributing

Thank you for helping. Small, focused pull requests are easiest to review.

## Setup

PHP 8.1 or later with mbstring and mysqli, Composer 2, and Node 20 or later
for the widget tests.

```sh
composer install
```

## Tests

Unit tests run without WordPress, on the stub layer in `tests/stubs/`:

```sh
vendor/bin/phpunit --testsuite unit
node --test tests/js/widget.test.mjs
```

The database suite (counters, log, uninstall, the parallel limit test) needs
a throwaway MySQL 5.7+ or MariaDB 10.4+ database. It drops and creates
`wptest_sse_chat_*` tables, so never point it at a real site's database.

```sh
export SSE_CHAT_TEST_DB_HOST=127.0.0.1 SSE_CHAT_TEST_DB_USER=root SSE_CHAT_TEST_DB_NAME=sse_chat_test
# or: export SSE_CHAT_TEST_DB_SOCKET=/path/to/mysqld.sock
vendor/bin/phpunit
```

Without those variables the database tests are skipped, not failed. CI runs
them against MySQL 8.0.

Tests must not touch the network. Use the fakes in `tests/Fakes/`
(`FakeTransport`, `ScriptedCaller`) for anything that would call a provider.

## Style and analysis

```sh
vendor/bin/phpcs          # PSR-12
vendor/bin/phpstan analyse --memory-limit=2G
```

Write PHP 8.1 code: no readonly classes, no DNF types, no `true` type, no
constants in traits. `declare(strict_types=1);` in every file. No `eval`, no
shell, no `@` error suppression. Escape every admin output.

Prose style in docs and comments: short sentences, plain words, British
spelling. No em dashes.

## Pull requests

- One topic per pull request, with a test for each behaviour change.
- Update `CHANGELOG.md` under Unreleased.
- Keep the PRD (`docs/PRD.md`) and `docs/ARCHITECTURE.md` true. If a change
  alters a documented case, say so in the pull request.
- A sign-off line is not required.
