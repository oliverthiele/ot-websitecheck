<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Domain\ValueObject;

/**
 * What a rendered page tells search engines and social networks about itself:
 * the title, the meta description and robots directives, and the OpenGraph
 * tags shared links are previewed with. Empty where the page renders none.
 */
final readonly class PageMetadata
{
    public function __construct(
        public string $title = '',
        public string $description = '',
        public string $robots = '',
        public string $openGraphTitle = '',
        public string $openGraphDescription = '',
        public string $openGraphImage = '',
    ) {
    }

    public function isNoindex(): bool
    {
        return preg_match('/\b(noindex|none)\b/i', $this->robots) === 1;
    }

    public function isEmpty(): bool
    {
        return $this->toArray() === [];
    }

    /**
     * @return array<string, string> only the values the page renders
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

    public function toJson(): string
    {
        return $this->isEmpty() ? '' : json_encode($this->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
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
            $data = json_decode($json, true, 2, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return new self();
        }
        if (!is_array($data)) {
            return new self();
        }
        $value = static fn(string $key): string => is_string($data[$key] ?? null) ? $data[$key] : '';

        return new self(
            $value('title'),
            $value('description'),
            $value('robots'),
            $value('ogTitle'),
            $value('ogDescription'),
            $value('ogImage'),
        );
    }
}
