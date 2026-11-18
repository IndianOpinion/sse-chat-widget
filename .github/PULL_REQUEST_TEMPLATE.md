## What and why

## How it was tested

- [ ] `vendor/bin/phpunit` passes (with a test database, see CONTRIBUTING.md)
- [ ] `vendor/bin/phpstan analyse` passes
- [ ] `vendor/bin/phpcs` passes
- [ ] `node --test tests/js/widget.test.mjs` passes, if the widget changed
- [ ] CHANGELOG.md updated under Unreleased
- [ ] Nothing reaches the browser that should stay on the server (prompt, model, key, excerpts)
