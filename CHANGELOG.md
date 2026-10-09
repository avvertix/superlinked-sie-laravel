# Release Notes

## [Unreleased](https://github.com/avvertix/superlinked-sie-laravel/compare/v0.2.0...HEAD)

## [v0.2.0](https://github.com/avvertix/superlinked-sie-laravel/compare/v0.1.0...v0.2.0) - 2026-10-09

### What's Changed

#### Breaking Changes

**The model catalog is no longer cached** (in https://github.com/avvertix/superlinked-sie-laravel/pull/7)

`SIE::models()` used to cache the cluster's model catalog for an hour, with a `catalog` block in the config to tune it and a `fresh` flag to bypass it. All of it has been removed: the catalog is now read live on every call.

The `catalog` block in `config/superlinked-sie-laravel.php` can be safely removed as well as `SIE_CATALOG_CACHE_STORE` and `SIE_CATALOG_CACHE_TTL` environment variables.

**SIE::fake() behavior changes**

- `SIE::fake()` verify if a global mock already exists before attempting to set one and ensure later calls return the existing mock
- A model you pass to `SIE::fake()` now raises when asked for a capability it has no answer for, rather than falling back to a default. Models you do not configure at all still answer everything, so a bare `SIE::fake()` is unchanged.
- `FakeModel::outputs()` reports what the cluster reports. Update any assertion against the faked catalog:

Refer to [UPGRADE.md](https://github.com/avvertix/superlinked-sie-laravel/blob/main/UPGRADE.md) for all the breaking changes and how to update your code.

#### Enhancements

* Provide Laravel Boost skill for library setup and upgrade
* Provide classification gateway in Laravel AI by @avvertix in https://github.com/avvertix/superlinked-sie-laravel/pull/14

#### Documentation

* Expand docs and simplify the readme by @avvertix in https://github.com/avvertix/superlinked-sie-laravel/pull/16

#### Maintenance

* Guzzle 8 compatibility by @avvertix in https://github.com/avvertix/superlinked-sie-laravel/pull/8
* Update rybakit/msgpack requirement from ^0.10.0 to ^0.11.2 by @dependabot[bot] in https://github.com/avvertix/superlinked-sie-laravel/pull/12
* Update laravel/ai requirement from ^0.10.3 to ^1.2.0 by @dependabot[bot] in https://github.com/avvertix/superlinked-sie-laravel/pull/6 and by @avvertix in https://github.com/avvertix/superlinked-sie-laravel/pull/14
* Re-organize integration tests by @avvertix in https://github.com/avvertix/superlinked-sie-laravel/pull/13
* Integration tests by @avvertix in https://github.com/avvertix/superlinked-sie-laravel/pull/15

### New Contributors

* @dependabot[bot] made their first contribution in https://github.com/avvertix/superlinked-sie-laravel/pull/6

**Full Changelog**: https://github.com/avvertix/superlinked-sie-laravel/compare/v0.1.0...v0.2.0

## [v0.1.0](https://github.com/avvertix/superlinked-sie-laravel/compare/...v0.1.0) - 2026-08-17

Initial release.

* SIE Client by @avvertix in https://github.com/avvertix/superlinked-sie-laravel/pull/1
* Add sie:models command by @avvertix in https://github.com/avvertix/superlinked-sie-laravel/pull/3
