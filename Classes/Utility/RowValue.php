<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Utility;

/**
 * Typed access to the values of a database row, which DBAL returns as mixed.
 */
final class RowValue
{
    /**
     * @param array<array-key, mixed> $row
     */
    public static function string(array $row, string $field): string
    {
        $value = $row[$field] ?? null;

        return is_scalar($value) ? (string)$value : '';
    }

    /**
     * @param array<array-key, mixed> $row
     */
    public static function int(array $row, string $field): int
    {
        $value = $row[$field] ?? null;

        return is_numeric($value) ? (int)$value : 0;
    }
}
