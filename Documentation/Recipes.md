# Website Check — Recipes for integrators

Most findings of the Website Check are fixed by an editor: a missing redirect,
a page that was not translated. Some are not — they come from how the site is
built and keep coming back until the integration changes. This page collects
the fixes for those.

The backend module links here from the explanation of a finding.

---

## Pages that require a parameter

**Finding:** the migration check reports the detail page of a plugin as
`missing` or `otherContent`; the status check reports it with HTTP 404 or 500,
or with an exception such as `RequiredArgumentMissingException`.

**Why:** a detail page shows content only with a record in its URL —
`/products/detail/my-product`. Called without one, as `/products/detail/`, it
shows a fallback (often the list of all records) or an error. The page sitemap
of EXT:seo lists every page, including this one. On the old site the fallback
answered with 200, on the new one the same URL fails — the check reports a
difference although nobody ever needed that URL.

**Fix:** mark such pages, take them out of the page sitemap, and tell the
Website Check about the mark. The detail views stay indexable through the
sitemap of their records.

### 1. A page field

In the sitepackage, `Configuration/TCA/Overrides/pages.php`:

```php
<?php

declare(strict_types=1);

defined('TYPO3') or die();

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

ExtensionManagementUtility::addTCAcolumns('pages', [
    'tx_mysitepackage_requires_parameter' => [
        'label' => 'LLL:EXT:my_sitepackage/Resources/Private/Language/locallang_db.xlf:pages.tx_mysitepackage_requires_parameter',
        'description' => 'LLL:EXT:my_sitepackage/Resources/Private/Language/locallang_db.xlf:pages.tx_mysitepackage_requires_parameter.description',
        'config' => [
            'type' => 'check',
            'renderType' => 'checkboxToggle',
        ],
    ],
]);
// Next to "no_index" and "no_follow" on the SEO tab.
ExtensionManagementUtility::addFieldsToPalette('pages', 'robots', 'tx_mysitepackage_requires_parameter', 'after:no_follow');
```

A description that tells the editor what the switch does, e.g.: "The page shows
content only with a parameter, e.g. the detail page of a plugin. It is excluded
from the XML sitemap of pages; the detail views themselves stay indexable."

The database column is created from the TCA (`typo3 extension:setup` or the
database analyser); no `ext_tables.sql` is needed.

### 2. Out of the page sitemap

The page sitemap of EXT:seo takes an `additionalWhere`. Its default leaves out
pages with `no_index` and pages with their own canonical link. Add the new
field — and, while at it, pages that show the content of another page (see
below):

```yaml
# config/sites/<site>/settings.yaml
seo:
  sitemap:
    pages:
      additionalWhere: "{#no_index} = 0 AND {#canonical_link} = '' AND {#content_from_pid} = 0 AND {#tx_mysitepackage_requires_parameter} = 0"
```

Check the result: the detail page is gone from `/sitemap.xml`, its records
are still in the sitemap of their record type.

### 3. Tell the Website Check

**Settings > Extension Configuration > ot_websitecheck > requiresParameterField**:
`tx_mysitepackage_requires_parameter`.

A URL that calls a marked page without arguments is then reported as
`detailPageWithoutRecord` — not a problem, only a hint that the sitemap still
lists it. The pages are read from the local database and a URL is matched to
its page through the local routing, so this applies where the checked
environments share the local page tree.

### 4. Then

Mark every detail page in the backend, import a fresh sitemap snapshot and run
the checks again.

---

## Pages that show the content of another page

**Finding:** the status check marks a URL with `canonicalElsewhere`, the
migration check warns `listedUrlNotCanonical` or `canonicalDiffers`.

**Why:** a page with "Show Content from this page" (`content_from_pid`, tab Appearance) answers
under its own path, but EXT:seo names the other page as its canonical URL. For
search engines that is correct: the content is indexed once, under the
canonical URL. The page sitemap of EXT:seo still lists the page, and search
engines then report "alternate page with proper canonical tag" or a duplicate.

**Fix:** leave the page as it is, but take it out of the page sitemap — the
`additionalWhere` line above does this with `{#content_from_pid} = 0`.

A redirect to such a page is not final: it should point at the canonical URL
directly. The migration check reports that as `redirectNotFinal` with the
canonical URL as the suggested target.

---

## Redirects that do not lead to the final URL

**Finding:** the migration check reports `redirectNotFinal`, with the warnings
`redirectChain`, `shortcutInChain` or `canonicalDiffers`; the status check marks
`redirectChain`.

**Why:** every redirect costs a request, and a chain dilutes what search
engines carry over to the new URL. A chain usually grows when a redirect points
at a URL that was moved later — or at a TYPO3 shortcut page, which redirects
again with 307.

**Fix:** change the first redirect so it points at the suggested final URL.
Where the redirect is a record of EXT:redirects, an editor can do that in
Link Management › Redirects; a rule in the webserver configuration needs the
integrator.
The check cannot tell the two apart — only HTTP answers are evaluated.

---

## Metadata of detail pages

**Finding:** the status check marks detail views with "Shared with other URLs"
or "No description"; the migration check warns "Description lost".

**Why:** a normal page takes its title, description and OpenGraph data from
its page properties. The detail view of a plugin renders a record on one page,
so unless the plugin or its template sets the metadata of the record, every
detail view carries the title and description of the detail page. The backend
cannot show this — only the delivered page does, which is what the checks
read.

**Fix:** set the metadata in the template of the detail view. Since TYPO3 14,
Fluid has ViewHelpers for it:

```html
<f:page.title>{item.title}</f:page.title>
<f:page.meta property="description" replace="{true}">{item.teaser}</f:page.meta>
<f:page.meta property="og:title" replace="{true}">{item.title}</f:page.meta>
<f:page.meta property="og:description" replace="{true}">{item.teaser}</f:page.meta>
<f:if condition="{item.image}">
    <f:page.meta property="og:image" replace="{true}">{f:uri.image(image: item.image, width: 1200, absolute: true)}</f:page.meta>
</f:if>
```

`replace` matters: without it, the description of the page properties stays.
An extension that cannot be changed can do the same in its controller with
the `MetaTagManagerRegistry` and a page title provider (`RecordTitleProvider`
since TYPO3 14).

Run the status check again afterwards: "Shared with other URLs" disappears
once every detail view has its own title and description.

---

## Structured data for the breadcrumb

**Finding:** the status check marks a page with "Breadcrumb links a broken
page" or "Breadcrumb links a redirect".

**Why:** a `BreadcrumbList` in JSON-LD lets search engines show the path of a
page in their results. On the detail view of a plugin, the trail is usually
built from the page tree, so its last item is the detail page itself — a URL
without the record, which answers with a list, an empty page or an error.

**Fix:** let the trail of a detail view end with the record, or leave the
detail page out. The current page does not have to be part of the trail at
all: Google takes the URL of the page itself for a last item without `item`.

```json
{
  "@context": "https://schema.org",
  "@type": "BreadcrumbList",
  "itemListElement": [
    {"@type": "ListItem", "position": 1, "name": "Home", "item": "https://www.example.com/"},
    {"@type": "ListItem", "position": 2, "name": "Jobs", "item": "https://www.example.com/jobs/"},
    {"@type": "ListItem", "position": 3, "name": "Title of the record"}
  ]
}
```

Item URLs should be the final URLs: no redirect, and the canonical URL of the
page they link. The check compares them with the results of the same run and,
for a URL the sitemap does not list, with the pages marked as requiring a
parameter — see [Pages that require a parameter](#pages-that-require-a-parameter).

---

## Previewing pages of another environment

**Finding:** the page preview in the modules stays empty, or the browser shows
that the page does not allow being embedded.

**Why:** the preview shows a checked page in a frame of the backend. The
backend allows frames from the hosts of the configured sites, snapshots and
runs — but the page itself decides whether it may be framed. A webserver that
sends `X-Frame-Options: SAMEORIGIN` allows frames on its own host only, so the
backend of the live site cannot show a page of staging or development. Pages
behind Basic Auth ask for credentials inside the frame as well.

**Fix:** use "Open in a new window" — that always works. To preview across
environments, let the environments that are checked allow the backends that
check them. `X-Frame-Options` cannot name another host; a `frame-ancestors`
directive can, and browsers ignore `X-Frame-Options` when it is present:

```nginx
# Staging and development: may be framed by their own host and the live backend
add_header Content-Security-Policy "frame-ancestors 'self' https://www.example.com" always;
```

```apache
Header always set Content-Security-Policy "frame-ancestors 'self' https://www.example.com"
```

Merge it into a `Content-Security-Policy` header the site already sends rather
than adding a second one. Keep the live site as it is: it does not need to be
framed by any other environment.

---

## Markers the checked site has to render

The migration check compares pages by uid and records by table and uid, not by
URL. TYPO3 renders neither into the frontend by default; see
[Requirements on the checked site](../README.md#requirements-on-the-checked-site)
in the README.
