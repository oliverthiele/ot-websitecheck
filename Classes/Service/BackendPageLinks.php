<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Service;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Module\ModuleProvider;
use TYPO3\CMS\Backend\Routing\Exception\RouteNotFoundException;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Routing\BackendEntryPointResolver;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Links from a result to the page or record in a backend.
 *
 * The checks name a page by its uid on the checked environment, so a page on
 * another host is linked into the backend of that host: TYPO3 accepts a
 * module URL without a token there, asks for a login if needed and opens the
 * page afterwards. A page on this host is linked into this backend, and only
 * when this database has it.
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
        private readonly BackendEntryPointResolver $backendEntryPointResolver,
        private readonly ModuleProvider $moduleProvider,
    ) {
    }

    /**
     * The page module for a page in the backend of the host a URL lives on.
     *
     * @param string $url the checked URL the page uid belongs to
     * @return array{url: string, host: string} host is empty for this backend
     */
    public function forPageOfUrl(string $url, int $pageUid, ?int $languageId, ServerRequestInterface $request): array
    {
        $none = ['url' => '', 'host' => ''];
        $origin = $this->getOrigin($url);
        $normalizedParams = $request->getAttribute('normalizedParams');
        if ($pageUid <= 0 || $origin === '' || !$normalizedParams instanceof NormalizedParams) {
            return $none;
        }
        if (strcasecmp($origin, $normalizedParams->getRequestHost()) === 0) {
            return ['url' => $this->forPage($pageUid, $languageId), 'host' => ''];
        }

        $modulePath = $this->moduleProvider->getModule('web_layout', null, false)?->getPath();
        if ($modulePath === null) {
            return $none;
        }
        $parameters = ['id' => $pageUid];
        if ($languageId !== null) {
            $parameters['languages'] = [$languageId];
        }
        // The entry point of this installation: the environments of one project share it.
        $backendPath = rtrim($this->backendEntryPointResolver->getPathFromRequest($request), '/');

        return [
            'url' => $origin . $backendPath . $modulePath . '?' . http_build_query($parameters, '', '&', PHP_QUERY_RFC3986),
            'host' => (string)parse_url($origin, PHP_URL_HOST),
        ];
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
     * tag wins over the language code alone. The site is the one of the page
     * here, or else the one whose base the URL lies below.
     */
    public function findLanguageId(int $pageUid, string $languageTag, string $url = ''): ?int
    {
        if ($languageTag === '') {
            return null;
        }
        $languages = $this->findSite($pageUid, $url)?->getAllLanguages();
        if ($languages === null) {
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

    private function findSite(int $pageUid, string $url): ?Site
    {
        try {
            return $this->siteFinder->getSiteByPageId($pageUid);
        } catch (SiteNotFoundException) {
        }
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host)) {
            return null;
        }
        foreach ($this->siteFinder->getAllSites() as $site) {
            if (strcasecmp($site->getBase()->getHost(), $host) === 0) {
                return $site;
            }
        }

        return null;
    }

    /**
     * Scheme, host and port of an http or https URL.
     */
    private function getOrigin(string $url): string
    {
        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        $host = parse_url($url, PHP_URL_HOST);
        $port = parse_url($url, PHP_URL_PORT);
        if (!in_array($scheme, ['http', 'https'], true) || !is_string($host) || $host === '') {
            return '';
        }

        return $scheme . '://' . $host . (is_int($port) ? ':' . $port : '');
    }

    private function pageExists(int $pageUid): bool
    {
        if ($pageUid <= 0) {
            return false;
        }

        return $this->existingPages[$pageUid] ??= BackendUtility::getRecord('pages', $pageUid, 'uid') !== null;
    }
}
