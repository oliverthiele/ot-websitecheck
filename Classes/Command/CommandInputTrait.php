<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Command;

/**
 * Typed access to console arguments and options, which Symfony returns as mixed.
 */
trait CommandInputTrait
{
    private function stringValue(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private function intValue(mixed $value, int $default): int
    {
        return is_numeric($value) ? (int)$value : $default;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, static fn(mixed $item): bool => is_string($item) && $item !== ''));
    }
}
