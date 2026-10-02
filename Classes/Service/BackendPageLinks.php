<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Service;

use TYPO3\CMS\Backend\Routing\Exception\RouteNotFoundException;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Links from a result to the page or record in this backend.
 *
 * The checks name a page by its uid on the checked environment. That is the
 * page here only where the environments share this page tree — the same
 * premise as the page titles in the modules. A uid this database does not
 * have gets no link.
 */
class BackendPageLinks
{
    /**
     * @var array<int, bool> page uid => exists here
     */
    private array $existingPages = [];

    public function __construct(
        private readonly UriBuilder $uriBuilder,
        private readonly SiteFinder $siteFinder,
        private readonly TcaSchemaFactory $tcaSchemaFactory,
    ) {
    }

    /**
     * The page module for the page, with the language selected.
     *
     * @param int|null $languageId null when unknown; the page module then keeps its own selection
     */
    public function forPage(int $pageUid, ?int $languageId = null): string
    {
        if (!$this->pageExists($pageUid)) {
            return '';
        }
        $parameters = ['id' => $pageUid];
        if ($languageId !== null) {
            $parameters['languages'] = [$languageId];
        }
        try {
            return (string)$this->uriBuilder->buildUriFromRoute('web_layout', $parameters);
        } catch (RouteNotFoundException) {
            return '';
        }
    }

    /**
     * The form to edit a record, when the table is known here and has it.
     */
    public function forRecord(string $table, int $uid, string $returnUrl): string
    {
        if ($uid <= 0 || !$this->tcaSchemaFactory->has($table) || BackendUtility::getRecord($table, $uid, 'uid') === null) {
            return '';
        }
        try {
            return (string)$this->uriBuilder->buildUriFromRoute('record_edit', [
                'edit' => [$table => [$uid => 'edit']],
                'returnUrl' => $returnUrl,
            ]);
        } catch (RouteNotFoundException) {
            return '';
        }
    }

    /**
     * The site language a page renders as the given language tag — the value
     * of <html lang>, lower case, as the migration check stores it. The full
     * tag wins over the language code alone.
     */
    public function findLanguageId(int $pageUid, string $languageTag): ?int
    {
        if ($languageTag === '') {
            return null;
        }
        try {
            $languages = $this->siteFinder->getSiteByPageId($pageUid)->getAllLanguages();
        } catch (SiteNotFoundException) {
            return null;
        }
        $languageTag = strtolower(str_replace('_', '-', $languageTag));
        $byLanguageCode = [];
        foreach ($languages as $language) {
            $locale = $language->getLocale();
            $tags = [
                strtolower(str_replace('_', '-', $locale->getName())),
                strtolower($language->getHreflang()),
            ];
            if (in_array($languageTag, $tags, true)) {
                return $language->getLanguageId();
            }
            $byLanguageCode[strtolower($locale->getLanguageCode())][] = $language->getLanguageId();
        }
        $candidates = $byLanguageCode[explode('-', $languageTag)[0]] ?? [];

        return count($candidates) === 1 ? $candidates[0] : null;
    }

    /**
     * The title of a site language, as the page module shows it.
     */
    public function findLanguageTitle(int $pageUid, int $languageId): string
    {
        try {
            return $this->siteFinder->getSiteByPageId($pageUid)->getLanguageById($languageId)->getTitle();
        } catch (SiteNotFoundException|\InvalidArgumentException) {
            return (string)$languageId;
        }
    }

    private function pageExists(int $pageUid): bool
    {
        if ($pageUid <= 0) {
            return false;
        }

        return $this->existingPages[$pageUid] ??= BackendUtility::getRecord('pages', $pageUid, 'uid') !== null;
    }
}
