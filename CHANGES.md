# Changelog

All notable changes to `mai-cache` are documented here.

Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) · Versioning: [Semantic Versioning](https://semver.org/).

## [0.1.0] — 2026-05-15

### Added

- Initial release.
- `Mai\Cache\Cache` — transient-backed cache with a Laravel-style `remember()` pattern.
- Configurable per-instance prefix (`new Cache('acme')`) plus memoized static factory (`Cache::for('acme')`).
- Methods: `remember()`, `forget()`, `get()`, `set()`, `delete()`, `key()`, `can_cache()`, `prefix()`.
- Auto-bypass when `SCRIPT_DEBUG` is true so development never reads stale cache.
- Per-prefix filter hook `{prefix}_can_cache` to disable caching at runtime.
- `Mai_Cache_Bootstrap` — shared autoloader registry that picks the highest registered version across plugins on the same WordPress install (same pattern as [`maithemewp/mai-logger`](https://github.com/maithemewp/mai-logger)).
