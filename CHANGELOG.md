# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.7.0] — 2026-09-22

### Added

- Add `--retries` (default: `2`) to `websitecheck:checksitemap`,
  `websitecheck:crawllinks` and `websitecheck:migrationcheck`. A URL whose
  request timed out or got no connection is requested again after all other
  URLs, with at least five seconds since its last attempt; an HTTP answer,
  a 5xx included, is never repeated. Only the last outcome is stored
- Add the error marker `timeout` to the status check and the abort reason and
  verdict `timeout` to the migration check, so a page that answers too slowly
  is kept apart from one without a connection and is no longer reported as
  `missing`
- Print the number of timed out URLs in the summary of `checksitemap` and
  `crawllinks`

### Changed

- Require `guzzlehttp/guzzle` `^8.0`, whose exceptions tell a timeout from a
  refused connection. TYPO3 14.3 allows Guzzle 7 as well; a project still on
  Guzzle 7 updates with `composer update oliverthiele/ot-websitecheck -W`
- Classify a failed request by transport phase instead of treating every
  exception as "no connection": a connect timeout counts as no connection,
  a timeout after connecting as `timeout`

## [0.6.1] — 2026-09-18

### Fixed

- Resolve the page uid of URLs in every language, not only the default
  language. `websitecheck:checksitemap` and `websitecheck:crawllinks` handed
  the router the path below the site base, so a translated URL such as
  `/de/imprint` was routed as `de/imprint` and stored without a page uid. The
  path is now taken below the language base, as TYPO3 routes a request, and a
  base only matches at a segment boundary

## [0.6.0] — 2026-09-18

### Added

- Add the extension setting `archiveDirectory` (default: `data/websitecheck`),
  a directory inside the project and outside the public directory for archive
  files
- Save a snapshot as a file with a button in the Sitemaps module, and a
  migration check run with the snapshots it compared in the migration check
  module
- List the saved files in the Sitemaps module with their contents and what
  reading them in would do; read them in or delete them there

### Changed

- Report every label conflict of an archive at once; `plan()` of the importer
  builds on the new `inspect()`

## [0.5.0] — 2026-09-17

### Added

- Add a form to the status check module that composes the
  `websitecheck:checksitemap` or `websitecheck:crawllinks` command for a
  snapshot, with an environment label suggested from it
- Add a collapsible guide to each backend module — what the tool is for, the
  steps in their order and a link to the documentation — open while the
  module has nothing to show, and remembered per viewer
- Add info icons with examples to the fields that need one: other host,
  environment labels and links per shape of the status check, reference
  snapshot, reference results and labels of the migration check, label and
  environment of the sitemap import

### Changed

- Default `--environment` of `websitecheck:checksitemap` to the environment of
  the snapshot, unless `--host` is given, and of `websitecheck:crawllinks` to
  that environment followed by `-links`
- Translate the status check module, including the known error markers, and
  keep its filter visible when the chosen environment has no results
- Submit the status filter from a module script instead of inline handlers

## [0.4.0] — 2026-09-17

### Added

- Add an environment per sitemap snapshot — live, staging, development or
  local — shown as a badge; the import form and `websitecheck:importsitemaps`
  take it from the conditions of the site's base variants, and
  `--environment` sets it. Run `database:updateschema` after the update
- Add a form to the migration check module that composes the
  `websitecheck:migrationcheck` command from the stored snapshots: it suggests
  the snapshots to compare and the labels from their environments, offers
  earlier runs for `--reference-run`, warns about label clashes and prints the
  command, quoted, for `vendor/bin/typo3`, `typo3` or `ddev typo3`
- Add popovers to the verdicts and failing status codes of the migration check
  that explain them and name the usual causes

### Changed

- Move the backend module from System to Sites, after Link Management; the
  former module identifiers `system_websitecheck*` remain as aliases
- Carry the environment of a snapshot in archives; archives written before are
  still read
- List `referenceNotOk` rows under "only problems and warnings": the reference
  sitemap lists a URL that fails. A verdict chosen in the filter is shown
  regardless of that option, and the verdict counts of a run link to it

## [0.3.0] — 2026-09-17

### Added

- Add `--reference-run` to `websitecheck:migrationcheck`: the reference rows
  are copied from an earlier run instead of requested again, so a relaunch can
  still be compared with the old site once the new one has replaced it
- Add `websitecheck:exportsnapshots` and `websitecheck:importsnapshots`: sitemap
  snapshots and migration check runs with their results are written into a
  gzip-compressed JSON archive and read back into another or a replaced
  database; records that are already there are skipped
- Add a unique uuid to sitemap snapshots and migration check runs; existing
  records get one on their first export. Run `database:updateschema` after the
  update
- Add a relaunch workflow to the README: lock and export the live state on
  staging, and compare the new live site with the stored reference results

## [0.2.0] — 2026-09-17

### Added

- Add a lock per sitemap snapshot: the lock icon in the backend module, or the
  "Locked" field of the record, protects a snapshot from deletion both in the
  module and by `websitecheck:cleanupsnapshots`
- Add `--lock` to `websitecheck:importsitemaps`: locks the snapshot as soon as
  the import is complete

### Changed

- Show one row per language in a sitemap snapshot, with the URL count per
  sitemap group as a column (up to five groups) or a list; a group another
  language has and this one lacks is marked as missing
- Move the sitemap index into the details of its language instead of listing
  it as a group with 0 URLs; count sitemap indexes and sitemaps separately
- Add chevrons to the expandable rows, toggle a row by a click anywhere on it,
  and add a button that expands or collapses all languages of a snapshot
- Format URL and sitemap counts with a thousands separator
- Show the start URL of a snapshot in its expanded area, and the import time
  next to the label only when the label does not contain it
- Show a status only for failed sitemaps, and the row actions "Open" and "Raw
  content" only on hover or focus on devices with a mouse

### Removed

- Remove the sitemap group filter from the sitemap module; the groups are now
  columns

## [0.1.1] — 2026-09-16

### Fixed

- Fix the sitemap group of sub-sitemaps whose route puts the group into the
  path, e.g. `/sitemap-type/pages/sitemap.xml` from the EXT:seo site set
  `typo3/seo-sitemap` (TYPO3 v14.1+); their URLs were stored without a group.
  The routes are read from the site configuration, site sets included, and
  from `typo3/seo-sitemap` for hosts outside the configured sites

## [0.1.0] — 2026-09-15

First alpha release.

### Added

- Add sitemap snapshots: the sitemaps of every language of a site stored at one
  point in time, with the raw XML and HTTP status of every sitemap file and every
  page URL with its sitemap group and `lastmod`, a note and an import status per
  snapshot
- Add `websitecheck:importsitemaps` CLI command; the languages are read from the
  `hreflang` links of the start page, the sitemap path from the site's PageType
  route enhancer, and `--sitemap` gives the sitemaps explicitly
- Add sitemap group detection for TYPO3 v13 (`?sitemap=`) and v14
  (`?tx_seo[sitemap]=`) sitemaps, so snapshots of both versions are comparable
- Add `websitecheck:cleanupsnapshots` CLI command for scheduled imports, with
  `--keep`, `--incomplete-hours` and `--dry-run`; snapshots with a note or used
  by a migration check run are never removed
- Add `websitecheck:checksitemap` CLI command: requests every URL of a snapshot
  and records the HTTP status and TYPO3 error markers (production and
  development exceptions, 404 and access-denied pages); `--host` requests the
  paths on a different host
- Add `websitecheck:crawllinks` CLI command: follows the plugin links on the
  pages of a snapshot — the links a sitemap never lists — and reports links
  whose arguments have no effect (`argumentsIgnored`); links are sampled per
  shape to keep a run affordable
- Add `websitecheck:migrationcheck` CLI command: requests every URL of a
  reference snapshot on the reference and on the host of a target snapshot and
  records the full redirect chain of both, hop by hop
- Add verdicts per target URL — `ok`, `movedWithRedirect`, `missing`,
  `redirectBroken`, `otherContent`, `identityUnknown`, `referenceNotOk` —
  derived from the page uid, language and record read out of the HTML, with
  configurable marker patterns
- Add warnings for redirect chains, temporary redirects, redirects to a start
  page, sitemap URLs that redirect, language changes, detail pages without a
  record marker and records rendered by more than one page
- Add redirect target suggestions for URLs missing on the target, taken from
  the pages of the target snapshot, and `--analyze-only` to recompute a run
- Add backend module System > Website Check with one card per tool:
  - "Status check" with filters, a "reviewed" toggle and a note per result
  - "Migration check" with filters for run, sitemap group, language and
    verdict, the snapshots a run compared, and a "reviewed" toggle and note
  - "Sitemaps" with an import form for the base URLs of the configured sites
    (language-by-language, Basic Auth that is not stored) and the stored
    snapshots per language and sitemap group, with links to the sitemaps
- Add HTTP Basic Auth for protected environments, separately for reference and
  target of a migration check
- Add unit tests for verdicts, redirect chains, the sitemap crawler, language
  detection, site bases, sitemap groups, identity markers and snapshot retention
