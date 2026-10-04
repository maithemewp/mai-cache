# State
Updated: 2026-10-03 by Claude (Opus 5.5)

## Now

0.6.0 is released: tagged `v0.6.0` on `main`, and `main` and `develop` pushed. It includes everything listed for the never-tagged 0.5.0, and loads through mai-package-loader from `mai-package.php`; `init.php` and `Mai_Cache_Bootstrap` are gone. It is 0.6.0 rather than 0.5.0 because mai-engine 2.40 ships a copy that registers itself as 0.5.0.

## Next

mai-engine moves to `^0.6` inside its grid cache beta.5 release. The steps are in `~/LocalPackages/mai-package-loader/STATE.md`, "Gotchas".

## Blocked / waiting on

mai-engine's local verification, run by another session, before mai-engine changes.

## Verify

```sh
composer install && vendor/bin/phpunit
```

Expect 99 tests passing.

## Gotchas

- Bump `version` in `mai-package.php` with every release.
- Never delete or rename a released class; the loader drops a newer copy that lacks a file an older copy has.
- `main` used to lag: v0.4.0 was tagged on `develop`. Since 0.6.0, `main` is fast-forwarded to `develop` on release.
