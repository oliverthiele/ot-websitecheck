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
  working redirect, which redirect to different content
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
  note per snapshot; every check reads its URLs from a snapshot
- **Snapshot lock** — one click on the lock icon protects a snapshot from
  deletion, in the module and by the cleanup command, so the last copy of a
  sitemap structure the live site no longer delivers cannot get lost
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

Then update the database schema:

```bash
vendor/bin/typo3 database:updateschema
# or via DDEV:
ddev typo3 database:updateschema
```

A regular dependency, not `require-dev`: the checks run where the site runs.
A relaunch is compared on the new environment, and the snapshot of the old site
has to be imported there **before** the switch — a deployment with `--no-dev`
would leave the tool out exactly where it is needed. Leave it out of production
if that instance never runs a check.

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
in the module, together with the sitemap index. The group is stored at import
time: a snapshot imported before a routing change keeps the groups it was
imported with.

A stylesheet warning when opening a sitemap in the browser ("parsing the XSLT
stylesheet failed") usually means the webserver does not strip the cache-busting
timestamp from `Sitemap.<timestamp>.xsl`: the rewrite rule for versioned file
names has to include `xsl`. Search engines ignore the stylesheet; the snapshot is
not affected.

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

The backend module **System > Website Check** (admin-only) shows one card per
tool:

- **Status check** — results of `checksitemap` and `crawllinks`, filterable by
  environment, only problems and only not yet reviewed
- **Migration check** — results of `migrationcheck`, one section per sitemap
  group, inside it one block per page or record, inside that the reference and
  target row of every language:

| Language | Environment | Code      | Final path    | Redirects                                          | Verdict             |
|----------|-------------|-----------|---------------|----------------------------------------------------|---------------------|
| en-us    | reference   | 200       | /old-path/    |                                                    | Reference           |
| en-us    | target      | 301 → 200 | /new-path/    | /old-path/ → /interim-path/ (301) → /new-path/ (301) | Moved with redirect, Redirect chain |

Where a target URL is missing, the module suggests the path of the same page or
record on the target — taken from the pages of the target snapshot.

- **Sitemaps** — import form and the stored snapshots, newest first:
  - **Import** — choose one of the base URLs of the configured sites (`base`
    and every `baseVariants` entry), "Find sitemaps" lists the sitemap of every
    language, the selected languages are imported one request per language, so
    a large site does not run into a request timeout. Basic Auth credentials can
    be entered for protected environments; they are used for the import and not
    stored. The form only fetches URLs on hosts of the configured sites — any
    other URL needs the CLI command.
  - **Snapshots** — a summary per snapshot (languages, sitemap indexes,
    sitemaps, URLs, failures); expanded, one row per language with its URL
    count per sitemap group — one column per group for up to five groups, a
    list beyond that. A group that other languages have and this one lacks,
    or that lists no URL, is marked as missing. Each language expands to its
    sitemap index and the sitemaps of every group, including every page of a
    paginated sitemap; a click anywhere on a row expands it, and one button
    expands or collapses all languages. "Open"
    shows a sitemap as the site delivers it now; "Raw content" shows what was
    stored. On devices with a mouse these row actions appear when the row is
    hovered or focused. The language filter applies to all snapshots. A
    snapshot whose import was interrupted is marked as incomplete.

### Verdicts

| Verdict | Meaning |
|---------|---------|
| `ok` | Same path, same page or record |
| `movedWithRedirect` | Redirects to the same page or record |
| `missing` | 4xx, 5xx or no connection — the finding this tool exists for |
| `redirectBroken` | Redirects, but ends in an error, a loop or too many hops |
| `otherContent` | Answers 200 with a different page, record or language |
| `identityUnknown` | Answers 200, but the markers needed for a comparison are missing |
| `referenceNotOk` | Already not working on the reference — ignored |

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

Every URL of the reference snapshot is requested on the reference and, with the
host replaced by the host of the target snapshot, on the target. The pages of
the target snapshot are requested as well, to suggest redirect targets. Redirects are followed one hop at a time, so each
hop is recorded with its status code. Verdicts are computed once all URLs are
checked; until then the module shows the rows as "not analysed yet".

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
| `--analyze-only` | Request nothing; recompute verdicts, warnings and suggestions for the stored rows of `--run`. |

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
| `--environment` / `-e` | Required. Label stored with every result row, e.g. `staging` or `live`. |
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

This command therefore visits every page of the snapshot, reads the links out of the
rendered HTML, and checks each one **twice**: as it stands, and again with all
arguments removed. If both responses are the same page, the arguments did
nothing, and the result is recorded with the marker `argumentsIgnored`.

To stay affordable, links are grouped by **shape** — the path plus the argument
names, values dropped. Links differing only in a record uid exercise the same
plugin on the same page, so only a couple of samples per shape are checked.

| Option | Description |
|--------|-------------|
| `--snapshot` | Required. Label of the sitemap snapshot whose pages are the starting points. |
| `--environment` / `-e` | Required. Use a distinct label (`…-links`) so a link run does not overwrite the rows of a sitemap run. |
| `--group` | Only start from pages of these sitemap groups. Repeatable. |
| `--samples-per-shape` | How many links per distinct shape to check (default: `2`). |
| `--max-links` | Upper bound on links checked (default: `2000`). |
| `--pages-limit` | Only read links from the first N pages of the snapshot. |
| `--all-links` | Also follow links without Extbase arguments. |
| `--timeout`, `--basic-auth`, `--basic-auth-env` | As for `checksitemap`. |

The `source` column holds the page a link was found on, which names the
template that produced the link.

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
`websitecheck:importsitemaps --lock`. While locked, its delete
button is disabled and the server refuses the deletion as well; unlock it
first to delete it.

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
