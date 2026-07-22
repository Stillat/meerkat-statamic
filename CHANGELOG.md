# Changelog

All notable changes to Meerkat are documented here.

## [4.0.0] - 2026-07-21

Meerkat 4 is a ground-up release for Statamic 6.24.2 and newer.

### Added

- Eloquent-backed comments, threads, author metadata, moderation audits, revisions, and thread metrics.
- A Statamic 6 control-panel moderation experience with bulk actions, inline replies, revisions, and scoped permissions.
- Public template tags, a read-only JSON API, and Statamic Pro GraphQL integration.
- Configurable spam guards, request rate limiting, signed submission contexts, and Akismet support.
- Filesystem mirroring plus an idempotent `meerkat:sync` importer for v3 comment trees, with a `--dry-run` preview.
- Identity export, erasure, retention, health, title-sync, and metric-sync commands.
- Dedicated database connection and table-prefix support for installs whose default connection already uses common table names.

### Changed

- Comment storage moves from v3 flat files to the site's configured database connection.
- The primary template tags are now `meerkat:form`, `meerkat:comments`, and `meerkat:comment_count`.
- Configuration is consolidated into `config/meerkat.php`, with editorial settings available from Statamic's addon settings screen.
- Sensitive comment fields (author email, IP, moderation data) stay available to trusted templates but are withheld while Antlers evaluates user-submitted content; toggle with `meerkat.security.guard_content_variables`.

### Upgrade notes

- Back up the v3 `content/comments` tree before upgrading.
- Run `php artisan meerkat:install` to publish the v4 blueprint and migrations and create the required tables.
- Run `php artisan meerkat:sync` to import the v3 filesystem tree.
- Review the complete [v3 upgrade guide](https://stillat.com/meerkat/v4/upgrading-from-v3) before deploying.

[4.0.0]: https://github.com/Stillat/meerkat-statamic/releases/tag/v4.0.0
