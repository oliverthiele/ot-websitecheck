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
        );
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
