<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Domain\ValueObject;

/**
 * What a rendered page tells search engines and social networks about itself:
 * the title, the meta description and robots directives, the OpenGraph tags
 * shared links are previewed with, and the breadcrumb trail of its structured
 * data. Empty where the page renders none.
 */
final readonly class PageMetadata
{
    /**
     * @param list<array{name: string, url: string}> $breadcrumb the items of a JSON-LD BreadcrumbList, in their order; url is empty where an item names none
     */
    public function __construct(
        public string $title = '',
        public string $description = '',
        public string $robots = '',
        public string $openGraphTitle = '',
        public string $openGraphDescription = '',
        public string $openGraphImage = '',
        public array $breadcrumb = [],
    ) {
    }

    public function isNoindex(): bool
    {
        return preg_match('/\b(noindex|none)\b/i', $this->robots) === 1;
    }

    /**
     * @return array<string, string> only the texts the page renders, without the breadcrumb
     */
    public function toArray(): array
    {
        return array_filter([
            'title' => $this->title,
            'description' => $this->description,
            'robots' => $this->robots,
            'ogTitle' => $this->openGraphTitle,
            'ogDescription' => $this->openGraphDescription,
            'ogImage' => $this->openGraphImage,
        ], static fn(string $value): bool => $value !== '');
    }

    public function isEmpty(): bool
    {
        return $this->toArray() === [] && $this->breadcrumb === [];
    }

    public function toJson(): string
    {
        if ($this->isEmpty()) {
            return '';
        }
        $data = $this->toArray();
        if ($this->breadcrumb !== []) {
            $data['breadcrumb'] = $this->breadcrumb;
        }

        return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * Reads what toJson() wrote; anything else gives no metadata.
     */
    public static function fromJson(string $json): self
    {
        if ($json === '') {
            return new self();
        }
        try {
            $data = json_decode($json, true, 4, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return new self();
        }
        if (!is_array($data)) {
            return new self();
        }
        $value = static fn(string $key): string => is_string($data[$key] ?? null) ? $data[$key] : '';
        $breadcrumb = [];
        foreach (is_array($data['breadcrumb'] ?? null) ? $data['breadcrumb'] : [] as $item) {
            if (is_array($item)) {
                $breadcrumb[] = [
                    'name' => is_string($item['name'] ?? null) ? $item['name'] : '',
                    'url' => is_string($item['url'] ?? null) ? $item['url'] : '',
                ];
            }
        }

        return new self(
            $value('title'),
            $value('description'),
            $value('robots'),
            $value('ogTitle'),
            $value('ogDescription'),
            $value('ogImage'),
            $breadcrumb,
        );
    }
}
