<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Service;

use GuzzleHttp\Psr7\Exception\MalformedUriException;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use OliverThiele\OtWebsitecheck\Domain\ValueObject\PageMetadata;

/**
 * Reads the metadata a page renders. Whether it comes from the page
 * properties, an Extbase controller or <f:page.title> and <f:page.meta>
 * makes no difference here — only the delivered HTML counts, which is what
 * the backend cannot show for a detail view.
 */
class MetadataExtractor
{
    private const string TITLE_PATTERN = '/<title\b[^>]*>(.*?)<\/title>/is';
    private const string META_TAG_PATTERN = '/<meta\b[^>]*>/i';
    private const string JSON_LD_PATTERN = '/<script\b[^>]*\btype\s*=\s*["\']?application\/ld\+json["\']?[^>]*>(.*?)<\/script>/is';
    private const string ATTRIBUTE_PATTERN = '/\b(name|property|content)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+))/i';

    /**
     * Values longer than this are cut: they are shown and compared, not
     * stored in full.
     */
    private const int MAXIMUM_LENGTH = 500;

    /**
     * @param string $baseUrl the URL the page was delivered under; a relative og:image is resolved against it
     */
    public function extract(string $html, string $baseUrl): PageMetadata
    {
        $title = preg_match(self::TITLE_PATTERN, $html, $titleMatch) === 1 ? $this->clean($titleMatch[1]) : '';

        $meta = [];
        if (preg_match_all(self::META_TAG_PATTERN, $html, $tags) !== false) {
            foreach ($tags[0] as $tag) {
                $attributes = $this->readAttributes($tag);
                // OpenGraph uses "property", but "name" is common as well.
                $key = strtolower($attributes['property'] ?? $attributes['name'] ?? '');
                if ($key !== '' && isset($attributes['content'])) {
                    // The first occurrence wins, as with search engines.
                    $meta[$key] ??= $this->clean($attributes['content']);
                }
            }
        }

        return new PageMetadata(
            $title,
            $meta['description'] ?? '',
            $meta['robots'] ?? '',
            $meta['og:title'] ?? '',
            $meta['og:description'] ?? '',
            $this->resolve($meta['og:image'] ?? '', $baseUrl),
            $this->extractBreadcrumb($html, $baseUrl),
        );
    }

    /**
     * The first BreadcrumbList of the JSON-LD blocks — on its own, in a list,
     * or inside an @graph. An item names its URL as a string, or as an object
     * with @id or url; the last one may name none.
     *
     * @return list<array{name: string, url: string}>
     */
    private function extractBreadcrumb(string $html, string $baseUrl): array
    {
        if (preg_match_all(self::JSON_LD_PATTERN, $html, $blocks) === false) {
            return [];
        }
        foreach ($blocks[1] as $block) {
            try {
                $data = json_decode(trim($block), true, 64, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                continue;
            }
            $list = $this->findBreadcrumbList($data);
            if ($list === null) {
                continue;
            }
            $elements = is_array($list['itemListElement'] ?? null) ? $list['itemListElement'] : [];
            $items = [];
            foreach ($elements as $element) {
                if (!is_array($element)) {
                    continue;
                }
                $item = $element['item'] ?? null;
                $url = match (true) {
                    is_string($item) => $item,
                    is_array($item) && is_string($item['@id'] ?? null) => $item['@id'],
                    is_array($item) && is_string($item['url'] ?? null) => $item['url'],
                    default => '',
                };
                $name = $element['name'] ?? (is_array($item) ? ($item['name'] ?? '') : '');
                $position = $element['position'] ?? null;
                $items[] = [
                    'position' => is_numeric($position) ? (int)$position : count($items) + 1,
                    'name' => is_string($name) ? $this->clean($name) : '',
                    'url' => $this->resolve($url, $baseUrl),
                ];
            }
            usort($items, static fn(array $left, array $right): int => $left['position'] <=> $right['position']);

            return array_map(static fn(array $item): array => ['name' => $item['name'], 'url' => $item['url']], $items);
        }

        return [];
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private function findBreadcrumbList(mixed $data): ?array
    {
        if (!is_array($data)) {
            return null;
        }
        $type = $data['@type'] ?? null;
        if ($type === 'BreadcrumbList' || (is_array($type) && in_array('BreadcrumbList', $type, true))) {
            return $data;
        }
        foreach (array_is_list($data) ? $data : (is_array($data['@graph'] ?? null) ? $data['@graph'] : []) as $node) {
            $list = $this->findBreadcrumbList($node);
            if ($list !== null) {
                return $list;
            }
        }

        return null;
    }

    /**
     * @return array<string, string> lower-case attribute name => raw value
     */
    private function readAttributes(string $tag): array
    {
        if (preg_match_all(self::ATTRIBUTE_PATTERN, $tag, $matches, PREG_SET_ORDER) === false) {
            return [];
        }
        $attributes = [];
        foreach ($matches as $match) {
            $attributes[strtolower($match[1])] ??= ($match[2] ?? '') . ($match[3] ?? '') . ($match[4] ?? '');
        }

        return $attributes;
    }

    /**
     * Entities decoded, tags and runs of whitespace collapsed, as a browser
     * shows the text.
     */
    private function clean(string $value): string
    {
        $text = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);

        return mb_substr($text, 0, self::MAXIMUM_LENGTH);
    }

    private function resolve(string $url, string $baseUrl): string
    {
        if ($url === '') {
            return '';
        }
        try {
            $resolved = (string)UriResolver::resolve(new Uri($baseUrl), new Uri($url));
        } catch (MalformedUriException) {
            return '';
        }

        return in_array(strtolower((string)parse_url($resolved, PHP_URL_SCHEME)), ['http', 'https'], true) ? $resolved : '';
    }
}
