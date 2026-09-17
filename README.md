# Website Check — Crawl TYPO3 sitemaps, find broken URLs and unredirected moves

Crawls TYPO3 XML sitemaps and records what every URL answers: HTTP status and
TYPO3 error pages on one environment, and — before a relaunch — whether every
URL of the live site still leads to the same page or record on the new one.

[![TYPO3](https://img.shields.io/badge/TYPO3-14.3-orange.svg)](https://typo3.org/)
[![Packagist Version](https://img.shields.io/packagist/v/oliverthiele/ot-websitecheck.svg)](https://packagist.org/packages/oliverthiele/ot-websitecheck)
[![PHP](https://img.shields.io/packagist/dependency-v/oliverthiele/ot-websitecheck/php.svg)](https://php.net/)
[![License](https://img.shields.io/packagist/l/oliverthiele/ot-websitecheck.svg)](LICENSE)
[![Changelog](https://img.shields.io/badge/Changelog-CHANGELOG.md-blue.svg)](CHANGELOG.md)

---

## Features

- **Status check** — checks the HTTP status of every URL of a sitemap snapshot and
  detects TYPO3 error pages in the response body (production "Oops, an error
  occurred!", uncaught exceptions, 404 and access-denied pages)
- **Link crawl** — follows the plugin links found on those pages, including
  the ones a sitemap never lists, and reports links whose arguments have no
  effect
- **Migration check** — compares a reference environment (usually live) with a
  target environment (a relaunch on staging): which URLs moved without a
  working redirect, which redirect to different content; the reference
  results can be reused once the reference site itself is gone. The module
  composes the command from the stored snapshots, ready to copy
- **Independent of how redirects are made** — only HTTP answers are evaluated,
  so webserver rules, `.htaccess` and EXT:redirects are all covered
- **Redirect quality** — redirect chains, temporary redirects, loops and
  redirects to a start page are reported as warnings
- **Identity per page and record** — pages and detail records are matched by
  uid, not by URL, so a moved detail page is compared with the same record;
  the same record on several pages is reported as duplicate content
- **Review workflow** — backend module with one card per tool, filters, a
  "reviewed" toggle and a note per result; both survive a re-run as long as the
  finding does not change
- **Sitemap snapshots** — stores the sitemaps of every language of a site at
  one point in time, including the raw XML, so a state stays available after
  the site has changed; imported in the backend module or on the CLI, with
  URL counts per language and sitemap group, missing groups marked, and a
  note and an environment (live, staging, development, local) per snapshot;
  every check reads its URLs from a snapshot
- **Snapshot lock** — one click on the lock icon protects a snapshot from
  deletion, in the module and by the cleanup command, so the last copy of a
  sitemap structure the live site no longer delivers cannot get lost
- **Archive** — snapshots and migration check runs can be exported into a
  file and imported again, so they survive a database that is replaced, e.g.
  by a fresh import of the live database, and move from staging to the new
  live system; records are identified by uuid, so importing twice doubles
  nothing
- **Snapshot cleanup** — a command for scheduled imports that keeps the newest
  snapshots per site and never removes locked snapshots, snapshots with a note
  or snapshots used by a run
- **HTTP Basic Auth** — for environments protected at the webserver level,
  separately for reference and target

---

## Requirements

| Requirement | Version |
|-------------|---------|
| TYPO3       | ^14.3   |
| PHP         | ^8.4    |

---

## Installation

The package is not on Packagist yet, so add its repository first:

```json
"repositories": {
    "oliverthiele/ot-websitecheck": {
        "type": "vcs",
        "url": "https://github.com/oliverthiele/ot-websitecheck.git"
    }
}
```

```bash
composer require oliverthiele/ot-websitecheck
```

Then update the database schema — also after every update of the extension:

```bash
vendor/bin/typo3 database:updateschema
# or via DDEV:
ddev typo3 database:updateschema
```

A regular dependency, not `require-dev`: the checks run where the site runs,
and that is usually a staging system deployed like production, with `--no-dev`.
A relaunch is prepared and compared there, and the snapshot of the old live site
has to be imported **before** the switch, while the old site still answers.
Leave the extension out of production only if that instance never runs a check.

---

## Configuration

### Requirements on the checked site

The migration check compares pages by what they are, not by their URL. TYPO3
renders none of that into the frontend by default, so the checked sites have
to provide three markers. Each one is read with a regular expression that can
be replaced per run (see [CLI](#cli)).

**Every environment of a comparison needs the markers** — including the
reference. A marker added to the new site only cannot be compared with
anything.

| Marker | Default pattern matches | Without it |
|--------|-------------------------|------------|
| Page uid | `<body id="page-123">` or `<body id="p123">` | Verdict `identityUnknown` for every URL |
| Language | `<html lang="de-DE">` | Language changes are not detected |
| Record | `<meta name="websitecheck:record" content="tx_myextension_domain_model_item:42">` | Detail pages get the warning `recordIdentityUnknown` |

**Page uid** — for example in the sitepackage:

```typoscript
page.bodyTagCObject = TEXT
page.bodyTagCObject {
    data = page:uid
    wrap = <body id="page-|">
}
```

**Language** — TYPO3 renders `<html lang="…">` from the site language, nothing
to do unless the page template replaces the `<html>` tag.

**Record** — needed on every detail page, i.e. every page whose plugin renders
one record out of many. Without it, all records of a detail page share one page
uid, and any redirect to any record would pass as "same page".

The simplest place is the detail template itself — the record is already there,
and the tag is only rendered where the template is:

```html
<f:page.meta property="websitecheck:record" type="name">tx_myextension_domain_model_item:{item.uid}</f:page.meta>
```

For a template that cannot be overridden, the same tag can come from
TypoScript. The arguments the route enhancer resolved are available as `GP:`
data:

```typoscript
page.meta.websitecheck:record {
    cObject = COA
    cObject {
        10 = TEXT
        10 {
            data = GP:tx_myextension_show|item
            intval = 1
            wrap = tx_myextension_domain_model_item:|
            if.isTrue.data = GP:tx_myextension_show|item
        }
    }
}
```

Add one `TEXT` per detail plugin. The value is empty on all other pages, so no
tag is rendered there.

### Requirements for sitemap snapshots

The sitemap import finds the languages of a site from the outside, the way a
search engine does:

1. The start page lists every language as
   `<link rel="alternate" hreflang="…" href="…">`. TYPO3 renders these tags for
   every language of the site that is enabled. `x-default` is skipped.
2. Each `href` is the home page of its language. The sitemap is requested below
   it, at the path the site configuration maps to the sitemap page type
   `1533906435` — e.g. `https://www.example.com/de/sitemap.xml`. Without such a
   mapping, `?type=1533906435` is used, which every TYPO3 site with EXT:seo
   answers. The EXT:seo site set `typo3/seo-sitemap` ships this mapping; a
   site without the set needs it in its own configuration:

   ```yaml
   # config/sites/<site>/config.yaml
   routeEnhancers:
     PageTypeSuffix:
       type: PageType
       default: ''
       index: index
       map:
         sitemap.xml: 1533906435
   ```

Where a site does not fit this — no hreflang tags, a different sitemap location
— use the CLI command with `--sitemap`.

Sitemap groups are read from the sub-sitemap URLs, so snapshots of TYPO3 v13
and v14 are comparable. All of these give the group `pages`:

| Sub-sitemap URL | Where it comes from |
|-----------------|---------------------|
| `/sitemap.xml?sitemap=pages` | TYPO3 v13 |
| `/sitemap.xml?tx_seo[sitemap]=pages` | TYPO3 v14 |
| `/sitemap-type/pages/sitemap.xml` | TYPO3 v14.1+ with the site set `typo3/seo-sitemap`, whose route enhancer moves the group into the path |

A group in the path is only recognised through a `Simple` route enhancer whose
`_arguments` map a placeholder to `tx_seo/sitemap` (or `sitemap` in v13) and
whose `routePath` has a static part besides the placeholder. The enhancers come
from the site the URL belongs to, site sets included. For a host outside the
configured sites, the enhancers of `typo3/seo-sitemap` are assumed. A
`StaticValueMapper` on the placeholder is applied, including its `localeMap`;
a value it does not list gives no group, as it gives no page in TYPO3. The set
only maps `pages` — every other provider keeps its group in the query string.

A sub-sitemap whose group cannot be read is listed under "(no sitemap group)"
in the module. The group is stored at import time: a snapshot imported before a
routing change keeps the groups it was imported with.

A stylesheet warning when opening a sitemap in the browser ("parsing the XSLT
stylesheet failed") usually means the webserver does not strip the cache-busting
timestamp from `Sitemap.<timestamp>.xsl`: the rewrite rule for versioned file
names has to include `xsl`. Search engines ignore the stylesheet; the snapshot is
not affected.

### Snapshot environments

Every snapshot can carry one of four environments: `live`, `staging`,
`development` or `local`. The module shows it as a badge and uses it to
suggest what a migration check compares.

For a URL of a configured site, the environment is taken from the site
configuration: `base` counts as live, a `baseVariants` entry by its condition.

| Condition of the base variant | Environment |
|-------------------------------|-------------|
| `applicationContext == "Production/Staging"` (or `…/Stage`) | `staging` |
| `applicationContext == "Production"` and other `Production/…` | `live` |
| `applicationContext == "Development/Local"` (or `…/Ddev`) | `local` |
| `applicationContext == "Development"` and other `Development/…` | `development` |
| anything else | none |

The import form and `websitecheck:importsitemaps --environment` override it.

### Environment variables

Basic Auth credentials can be passed as options or read from the environment.
The fallback reads `$_ENV`, not `getenv()` — projects loading `.env` files via
`vlucas/phpdotenv` without the putenv adapter only populate `$_ENV`/`$_SERVER`.

| Variable | Used by |
|----------|---------|
| `WEBSITECHECK_BASIC_AUTH_USER`, `WEBSITECHECK_BASIC_AUTH_PASS` | `importsitemaps`, `checksitemap`, `crawllinks` — the prefix can be changed with `--basic-auth-env` |
| `WEBSITECHECK_REFERENCE_BASIC_AUTH_USER`, `WEBSITECHECK_REFERENCE_BASIC_AUTH_PASS` | `migrationcheck`, reference environment |
| `WEBSITECHECK_TARGET_BASIC_AUTH_USER`, `WEBSITECHECK_TARGET_BASIC_AUTH_PASS` | `migrationcheck`, target environment |

Credentials are only sent to the host they belong to, never to a host a
redirect leads to.

---

## Usage

The backend module **Sites > Website Check** (admin-only) shows one card per
tool:

- **Status check** — a form that composes the `checksitemap` or `crawllinks`
  command for a snapshot, and the results, filterable by environment, only
  problems and only not yet reviewed. The form suggests the newest snapshot
  and an environment label from it — with `-links` for a link check, so its
  results do not replace those of a status check.
- **Migration check** — a form that composes the `migrationcheck` command, and
  the results, see [Migration check results](#migration-check-results). The
  form offers every complete snapshot and suggests the pair to compare: a
  locked live snapshot, otherwise the newest live one, as reference; the
  newest staging snapshot, otherwise development, then local, as target. It
  suggests labels from the environments, offers the earlier runs whose
  reference results can be reused, warns about label clashes before anything
  runs, and prints the command for `vendor/bin/typo3`, `typo3` or
  `ddev typo3`, with quoting, ready to copy.
- **Sitemaps** — import form and the stored snapshots, newest first:
  - **Import** — choose one of the base URLs of the configured sites (`base`
    and every `baseVariants` entry), "Find sitemaps" lists the sitemap of
    every language, the selected languages are imported one request per
    language, so a large site does not run into a request timeout. The
    environment of the snapshot is preselected from the site configuration —
    see [Snapshot environments](#snapshot-environments) — and can be changed.
    Basic Auth credentials can be entered for protected environments; they are
    used for the import and not stored. The form only fetches URLs on hosts of
    the configured sites — any other URL needs the CLI command.
  - **Snapshots** — the environment as a badge and a summary per snapshot
    (languages, sitemap indexes, sitemaps, URLs, failures); expanded, one row
    per language with its URL count per sitemap group — one column per group
    for up to five groups, a list beyond that. A group that other languages
    have and this one lacks, or that lists no URL, is marked as missing. Each
    language expands to its sitemap index and the sitemaps of every group,
    including every page of a paginated sitemap; a click anywhere on a row
    expands it, and one button expands or collapses all languages. "Open"
    shows a sitemap as the site delivers it now; "Raw content" shows what was
    stored. On devices with a mouse these row actions appear when the row is
    hovered or focused. The lock icon protects a snapshot from deletion. The
    language filter applies to all snapshots. A snapshot whose import was
    interrupted is marked as incomplete.

### Relaunch workflow

A relaunch usually replaces the live site with a new one prepared on staging —
often a new TYPO3 version with a reworked page tree. Once it is live, the old
URLs are only known to search engines, so their state has to be kept before:

1. **Before the switch, on staging:** import the sitemaps of the live site and
   lock the snapshot. It is the only copy of the URL structure search engines
   know, once the old site is gone. The live site has to render the
   [markers](#requirements-on-the-checked-site) by then as well.

   ```bash
   typo3 websitecheck:importsitemaps 'https://www.example.com/' \
       --label=live-before-relaunch --environment=live --lock
   ```

2. **Whenever the new site has changed, while the old one is still live:**
   import a snapshot of staging and run the migration check against it.
   Missing redirects show up per page and record, with a suggested target
   where one can be derived. The module composes this command for you.

   ```bash
   typo3 websitecheck:importsitemaps 'https://staging.example.com/' \
       --label=staging-current --environment=staging
   typo3 websitecheck:migrationcheck --run=relaunch \
       --reference-snapshot=live-before-relaunch --target-snapshot=staging-current
   ```

   A label is unique: delete the previous `staging-current` snapshot in the
   module first, or use a new label per run.

3. **Just before the switch, on staging:** export the last run; it brings the
   live snapshot along. The run holds the reference results — how every old URL
   answered while the old site was still live — and they cannot be requested
   again afterwards.

   ```bash
   typo3 websitecheck:exportsnapshots --file=var/websitecheck/relaunch.json.gz \
       --run=relaunch
   ```

   The same applies whenever the staging database is replaced, e.g. by a fresh
   import of the live database: export before, import the file with
   `websitecheck:importsnapshots` afterwards.

4. **After the switch, on the new live system:** copy the archive there,
   import it, take a snapshot of the new site and compare it with the stored
   reference results.

   ```bash
   typo3 websitecheck:importsnapshots --file=var/websitecheck/relaunch.json.gz
   typo3 websitecheck:importsitemaps 'https://www.example.com/' \
       --label=live-after-relaunch --environment=live
   typo3 websitecheck:migrationcheck --run=after-relaunch --reference-run=relaunch \
       --reference-snapshot=live-before-relaunch --target-snapshot=live-after-relaunch
   ```

   Without `--reference-run`, the check would request every reference URL
   again — under the live domain that is the new site by now, and a URL that
   fails there would count as `referenceNotOk` and be ignored.

### Migration check results

One section per sitemap group, inside it one block per page or record, inside
that the reference and target row of every language:

| Language | Environment | Code      | Final path    | Redirects                                          | Verdict             |
|----------|-------------|-----------|---------------|----------------------------------------------------|---------------------|
| en-us    | reference   | 200       | /old-path/    |                                                    | Reference           |
| en-us    | target      | 301 → 200 | /new-path/    | /old-path/ → /interim-path/ (301) → /new-path/ (301) | Moved with redirect, Redirect chain |

Where a target URL is missing, the module suggests the path of the same page or
record on the target — taken from the pages of the target snapshot.

Every verdict and every failing status code explains itself in a popover, on
hover or keyboard focus, with the usual causes — e.g. a detail page listed in
the page sitemap that only works with a record in its URL. The verdict counts
above the results filter the list; a chosen verdict is shown even when "only
problems and warnings" is set.

### Verdicts

| Verdict | Meaning |
|---------|---------|
| `ok` | Same path, same page or record |
| `movedWithRedirect` | Redirects to the same page or record |
| `missing` | 4xx, 5xx or no connection — the finding this tool exists for |
| `redirectBroken` | Redirects, but ends in an error, a loop or too many hops |
| `otherContent` | Answers 200 with a different page, record or language |
| `identityUnknown` | Answers 200, but the markers needed for a comparison are missing |
| `referenceNotOk` | Already not working on the reference — not compared, but listed with the problems: the sitemap lists a broken URL |

### Warnings

| Warning | Meaning |
|---------|---------|
| `redirectChain` | More than one redirect before the final page |
| `temporaryRedirect` | A 302, 303 or 307 in the chain — a move should be permanent |
| `redirectToRootPage` | A deep URL redirects to a start page, often treated as a soft 404 |
| `listedUrlRedirects` | A sitemap lists a URL that redirects |
| `languageChanged` | The target page is in a different language |
| `recordIdentityUnknown` | Several URLs render the same page without a record marker |
| `duplicateDetailPage` | The same record is rendered by more than one page |

---

## CLI

### `websitecheck:importsitemaps`

```bash
typo3 websitecheck:importsitemaps 'https://www.example.com/' \
    --label=live-before-relaunch --note='Sitemap for news not configured yet.' --lock

# languages given explicitly instead of read from the start page
typo3 websitecheck:importsitemaps \
    --sitemap='en-US=https://www.example.com/sitemap.xml' \
    --sitemap='de-DE=https://www.example.com/de/sitemap.xml'
```

Accepts any URL, unlike the import form in the module. Reads the languages from
the start page (see
[Requirements for sitemap snapshots](#requirements-for-sitemap-snapshots)),
fetches the sitemap of every language with all sub-sitemaps and stores them as
one snapshot: every sitemap file with its raw content and HTTP status, and every
page URL with its sitemap group and `lastmod`. Each language is fetched
completely before it is stored; an interrupted import stays marked as
incomplete. A sitemap file that fails is stored with the snapshot and reported,
not skipped. A snapshot without a single page URL is not kept.

| Option | Description |
|--------|-------------|
| `--label` | Unique name of the snapshot (default: host and time, e.g. `www.example.com 2026-01-31 14:05`). |
| `--note` | Free text stored with the snapshot; editable in the module afterwards. |
| `--environment` | `live`, `staging`, `development` or `local` (default: taken from the site configuration, see [Snapshot environments](#snapshot-environments)). |
| `--lock` | Lock the snapshot once the import is complete, so it is deleted neither in the module nor by `websitecheck:cleanupsnapshots`. An import that does not finish stays unlocked. |
| `--sitemap` | `hreflang=url` for one language. Repeatable. Replaces the detection from the start page. |
| `--sitemap-path` | Sitemap path below each language's home page, e.g. `sitemap.xml` or `?type=1533906435` (default: the path configured for the site of the start URL, `sitemap.xml` for any other URL). |
| `--timeout` | HTTP timeout per request in seconds (default: `20`). |
| `--basic-auth` | `user:password`. |
| `--basic-auth-env` | Prefix of the environment variables `<prefix>_USER` and `<prefix>_PASS` (default: `WEBSITECHECK_BASIC_AUTH`). |

The command can run as a scheduler task ("Execute console commands"). Leave
`--label` empty there: the default label contains the time, a fixed label makes
every run after the first fail. Pair it with a task for
[`websitecheck:cleanupsnapshots`](#websitecheckcleanupsnapshots).

### `websitecheck:migrationcheck`

```bash
typo3 websitecheck:migrationcheck --run=relaunch \
    --reference-snapshot=live-before-relaunch --target-snapshot=staging-current \
    --reference-label=live --target-label=staging
```

The URLs come from two stored sitemap snapshots (see
[`websitecheck:importsitemaps`](#websitecheckimportsitemaps)): the reference
snapshot is the state before the migration, the target snapshot the state after
it. Import a fresh target snapshot before a run to check the current state. The
module shows which snapshots a run compared.

Every URL of the reference snapshot is requested on the reference — unless
`--reference-run` supplies those rows — and, with the host replaced by the host
of the target snapshot, on the target. The pages of the target snapshot are
requested as well, to suggest redirect targets. Redirects are followed one hop
at a time, so each hop is recorded with its status code. Verdicts are computed
once all URLs are checked; until then the module shows the rows as "not
analysed yet".

A redirect target is only suggested when exactly one path on the target matches.
Without record markers every record of a detail page shares its page uid, so
detail pages get no suggestion rather than a wrong one.

| Option | Description |
|--------|-------------|
| `--run` | Required. Groups the results; a re-run with the same label updates the rows. |
| `--reference-snapshot` | Required. Label of the snapshot of the state before; every URL in it is checked. |
| `--target-snapshot` | Required. Label of the snapshot of the state after; its host is the target host. |
| `--reference-label`, `--target-label` | Environment labels shown in the module (default: `reference`, `target`). |
| `--group` | Only URLs from these sitemap groups, e.g. `pages`, see [Requirements for sitemap snapshots](#requirements-for-sitemap-snapshots). Repeatable. |
| `--limit` | Only the first N reference URLs. The pages of the target snapshot are still all requested. |
| `--timeout` | HTTP timeout per request in seconds (default: `10`). |
| `--max-hops` | Redirects followed per URL (default: `10`). |
| `--page-uid-pattern`, `--language-pattern`, `--record-pattern` | Replace the marker patterns, see [Requirements on the checked site](#requirements-on-the-checked-site). |
| `--reference-basic-auth`, `--target-basic-auth` | `user:password`, see [Environment variables](#environment-variables). |
| `--reference-run` | Take the reference rows from this earlier run instead of requesting the reference again. The run must have compared the same `--reference-snapshot`; it may be the `--run` itself. |
| `--analyze-only` | Request nothing; recompute verdicts, warnings and suggestions for the stored rows of `--run`. |

With `--reference-run`, only the target is requested. The reference rows are
copied from the earlier run with their environment label, which therefore
must differ from `--target-label`; their verdicts are recomputed and their
review state starts over. Reference URLs the earlier run has no row for are
skipped and counted.

### `websitecheck:checksitemap`

```bash
typo3 websitecheck:checksitemap --snapshot=staging-current --environment=staging

# request the paths of a snapshot taken on one environment on another host
typo3 websitecheck:checksitemap --snapshot=dev-current --environment=live-paths \
    --host=www.example.com
```

| Option | Description |
|--------|-------------|
| `--snapshot` | Required. Label of the sitemap snapshot whose URLs are checked. |
| `--environment` / `-e` | Label stored with every result row, e.g. `staging` or `live`. Defaults to the environment of the snapshot; required with `--host` or for a snapshot without one. |
| `--host` | Request the paths of the snapshot on this host — e.g. when a sitemap provider only exists on the source environment while the pages already exist on the target. |
| `--group` | Only URLs from these sitemap groups, e.g. `pages`. Repeatable. |
| `--timeout` | HTTP timeout per request in seconds (default: `10`). |
| `--limit` | Only check the first N URLs. |
| `--basic-auth`, `--basic-auth-env` | `user:password`, or the prefix of the environment variables, see [Environment variables](#environment-variables). |

The `source` column holds the label of the snapshot.

### `websitecheck:crawllinks`

```bash
typo3 websitecheck:crawllinks --snapshot=staging-current --environment=staging-links
```

A sitemap lists pages, not the links on them. Links that carry Extbase plugin
arguments never appear there, and that is where a plugin which has moved to a
different namespace or a different page quietly stops working: the arguments
arrive nowhere, the plugin falls back to its default action, and the visitor
gets a plausible looking wrong page — with HTTP 200.

This command therefore visits every page of the snapshot, reads the links out
of the rendered HTML, and checks each one **twice**: as it stands, and again
with all arguments removed. If both responses are the same page, the arguments
did nothing, and the result is recorded with the marker `argumentsIgnored`.

To stay affordable, links are grouped by **shape** — the path plus the argument
names, values dropped. Links differing only in a record uid exercise the same
plugin on the same page, so only a couple of samples per shape are checked.

| Option | Description |
|--------|-------------|
| `--snapshot` | Required. Label of the sitemap snapshot whose pages are the starting points. |
| `--environment` / `-e` | Use a distinct label (`…-links`) so a link run does not overwrite the rows of a sitemap run. Defaults to the environment of the snapshot followed by `-links`; required for a snapshot without one. |
| `--group` | Only start from pages of these sitemap groups. Repeatable. |
| `--samples-per-shape` | How many links per distinct shape to check (default: `2`). |
| `--max-links` | Upper bound on links checked (default: `2000`). |
| `--pages-limit` | Only read links from the first N pages of the snapshot. |
| `--all-links` | Also follow links without Extbase arguments. |
| `--timeout`, `--basic-auth`, `--basic-auth-env` | As for `checksitemap`. |

The `source` column holds the page a link was found on, which names the
template that produced the link.

### `websitecheck:exportsnapshots`

```bash
typo3 websitecheck:exportsnapshots --file=var/websitecheck/backup.json.gz --locked --run=relaunch
```

Writes sitemap snapshots and migration check runs into one gzip-compressed JSON
file with a format version: every snapshot with its label, environment, start
URL, note, lock and import time, every sitemap file with its raw content and HTTP status,
every page URL with its group and `lastmod`, and every run with all its result
rows, reviewed flags and notes included. A run brings the snapshots it
compared. Snapshots and runs without a uuid get one on export.

Where the file goes is up to the project — the path has no default, and the
directory has to exist. Keep the file out of version control.

| Option | Description |
|--------|-------------|
| `--file` | Required. Path of the archive file. |
| `--snapshot` | Label of a snapshot. Repeatable. |
| `--locked` | Every locked snapshot; unfinished imports are skipped with a note. |
| `--run` | Label of a migration check run, with its results and snapshots. Repeatable. |
| `--force` | Overwrite an existing file. |

### `websitecheck:importsnapshots`

```bash
typo3 websitecheck:importsnapshots --file=var/websitecheck/backup.json.gz --dry-run
```

Reads an archive into this database, in one transaction. A snapshot or run
whose uuid is here already is skipped — also when it was renamed here since —
so the same file can be imported after every database replacement. To replace
such a record with the archived state, delete it first. A label that a
different record uses already stops the import before anything is written,
unless `--label-suffix` is given. A run is linked to its snapshots by their
uuid; a snapshot that is neither in the archive nor here leaves the link empty.
Locks and notes are restored with the snapshots.

| Option | Description |
|--------|-------------|
| `--file` | Required. Path of the archive file. |
| `--label-suffix` | Appended to each label that is taken by a different record, e.g. `-restored`. |
| `--dry-run` | Show what would be imported or skipped, write nothing. |

Only archives of the format version this extension writes are read.

### `websitecheck:cleanupsnapshots`

```bash
typo3 websitecheck:cleanupsnapshots --keep=10 --dry-run
```

The counterpart of a scheduled import. Removes old sitemap snapshots and
imports that never finished. Always kept:

- locked snapshots — complete or not
- the newest complete snapshots per start URL, up to `--keep`
- snapshots a migration check run compared — its results refer to them
- snapshots with a note

A snapshot is locked with the lock icon in its header in the backend module,
with the "Locked" field when editing the record, or right away with
`websitecheck:importsitemaps --lock`. While locked, its delete button is
disabled and the server refuses the deletion as well; unlock it first to
delete it.

`--dry-run` lists exactly the snapshots a real run would remove.

| Option | Description |
|--------|-------------|
| `--keep` | Complete snapshots kept per start URL (default: `10`). |
| `--incomplete-hours` | Remove unfinished imports older than this many hours (default: `24`). |
| `--dry-run` | List, remove nothing. |

---

## License

GPL-2.0-or-later — see [LICENSE](LICENSE)

---

## Author

Oliver Thiele — [oliver-thiele.de](https://www.oliver-thiele.de)
