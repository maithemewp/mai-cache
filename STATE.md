# State
Updated: 2026-11-03 by Claude (Opus 5.5)

## Now

0.6.1 is committed on `develop`, not pushed or tagged. It fixes multisite: version tokens were remembered per request by scope only, so a bump or flush after `switch_to_blog()` wrote under the first site's tokens and the target site never saw it. Tokens are now remembered per site ID. Single sites are unchanged: `get_current_blog_id()` is always 1 there. `mai-package.php` says 0.6.1; CHANGES.md says `[0.6.1] - unreleased`.

## Next

1. With Mike's yes: date the changelog in a `Release 0.6.1` commit, fast-forward `main` to `develop`, push both, tag `v0.6.1`.
2. In mai-engine: `composer update maithemewp/mai-cache`, then `composer dump-autoload --no-dev`, then commit, so mai-engine 2.41.1 ships it.

## Blocked / waiting on

Mike's yes to push and tag.

## Verify

```sh
composer install && vendor/bin/phpunit
```

Expect 102 tests passing. mai-engine's unit (280) and integration (715) suites also passed with this `src/Cache.php` copied into its vendor folder.

## Gotchas

- Bump `version` in `mai-package.php` with every release.
- Never delete or rename a released class; the loader drops a newer copy that lacks a file an older copy has.
- `main` used to lag: v0.4.0 was tagged on `develop`. Since 0.6.0, `main` is fast-forwarded to `develop` on release.
- Every test gets `get_current_blog_id()` mocked to 1 in `tests/TestCase.php`. Brain Monkey leaves a function defined once any test mocks it, so without the default, later tests error.
