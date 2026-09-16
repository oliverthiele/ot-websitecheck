<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Utility;

use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

/**
 * Labels of the backend modules and their AJAX endpoints.
 */
final class LabelUtility
{
    private const string LANGUAGE_FILE = 'LLL:EXT:ot_websitecheck/Resources/Private/Language/locallang.xlf';

    /**
     * @return string The label, or the key itself when it is missing — visible, but never an empty string.
     */
    public static function translate(string $key): string
    {
        return LocalizationUtility::translate(self::LANGUAGE_FILE . ':' . $key) ?? $key;
    }
}
