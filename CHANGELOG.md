# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
