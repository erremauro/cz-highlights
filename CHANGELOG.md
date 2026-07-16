# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.3.2] 2026-07-16
### Changed
- Highlights now appear ordered by their position in the article text instead of by creation date, in every view (article drawer, volume drawer, and the "Le Mie Note" page). Ordering is computed server-side by locating each highlight's fingerprint in the post's plain text; highlights that can no longer be located keep their original relative order at the end of their group.

## [1.3.1] 2026-06-12
### Fixed
- Validate `status` field in `update_highlight` REST endpoint — only `active`, `displaced`, `orphaned` are accepted.
- `maybe_upgrade_db` now runs `CZH_DB::create_table()` in addition to `CZH_PostNotes_DB::create_table()` on schema upgrade.
### Security
- Added maximum of 200 highlights per user per post in `create_highlight` (HTTP 429 when exceeded).
### Added
- `CZH_DB_VERSION` constant to track schema version independently from plugin version.
- `CZH_DB::count_by_user_post()` helper used by the highlights limit guard.
- `KEY idx_user (user_id)` index on `czh_highlights` table for faster user-wide queries.
- `uninstall.php` — drops `czh_highlights` and `czh_post_notes` tables and removes plugin options on uninstall.
### Changed
- Updated author metadata to Roberto Mauro.

## [1.3.0] 2026-05-30
### Added
- Add Personal Notes to Articles.

## [1.2.1] 2026-05-30
### Fixed
- Fix an issue with the drawer length on browsers with dynamic address bars

## [1.2.0] 2026-05-29
### Changed
- A floating contextual now appear when a quote is clicked in Page View
- Note's Edit buttons in Page View are now shown only in edit mode.
- Clicking a quoted highlight in Page View now jumps to the note
### Fixed
- Fix an issue with opening a note in a articles that had an in-progress reading.

## [1.1.1] 2026-05-27
### Changed
- Button styles have been uniformed with the rest of the site.

## [1.1.0] 2026-05-27
### Changed
- The note UI has been improved for a better text editing experience.
- "Aggiungi Nota" button in "Le Mie Note" drawer has been renamed to "Modifica"

## [1.0.1] 2026-05-26
### Changed
- Changed highlight button position on mobile
### Fixed
- Fix an issue with drawer shadow's bleeing on mobile

## [1.0.0] 2026-05-26

### Added

- First Release!


[Unreleased]: https://github.com/erremauro/cz-highlights/compare/v1.3.1...HEAD
[1.3.1]: https://github.com/erremauro/cz-highlights/releases/tag/v1.3.1
[1.3.0]: https://github.com/erremauro/cz-highlights/releases/tag/v1.3.0
[1.2.1]: https://github.com/erremauro/cz-highlights/releases/tag/v1.2.1
[1.2.0]: https://github.com/erremauro/cz-highlights/releases/tag/v1.2.0
[1.1.1]: https://github.com/erremauro/cz-highlights/releases/tag/v1.1.1
[1.1.0]: https://github.com/erremauro/cz-highlights/releases/tag/v1.1.0
[1.0.1]: https://github.com/erremauro/cz-highlights/releases/tag/v1.0.1
[1.0.0]: https://github.com/erremauro/cz-highlights/releases/tag/v1.0.0
