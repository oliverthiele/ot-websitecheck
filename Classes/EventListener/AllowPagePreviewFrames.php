<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\EventListener;

use OliverThiele\OtWebsitecheck\Domain\Repository\MigrationRunRepository;
use OliverThiele\OtWebsitecheck\Domain\Repository\SitemapSnapshotRepository;
use OliverThiele\OtWebsitecheck\Domain\ValueObject\SiteBase;
use OliverThiele\OtWebsitecheck\Service\SiteBaseProvider;
use TYPO3\CMS\Backend\Module\ModuleInterface;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Directive;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Event\PolicyMutatedEvent;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\UriValue;

/**
 * The modules preview a checked page in a modal with an iframe. The backend
 * allows frames from its own origin only, so the hosts the checks request are
 * added — for the Website Check modules only, and only the hosts of the
 * configured sites, the stored snapshots and the migration check runs.
 *
 * Whether a page lets itself be framed is up to its own headers; where it
 * does not, the modal offers to open it in a new window.
 */
#[AsEventListener(identifier: 'ot-websitecheck/allow-page-preview-frames')]
final readonly class AllowPagePreviewFrames
{
    private const string MODULE_PREFIX = 'site_websitecheck';

    public function __construct(
        private SitemapSnapshotRepository $sitemapSnapshotRepository,
        private MigrationRunRepository $migrationRunRepository,
        private SiteBaseProvider $siteBaseProvider,
    ) {
    }

    public function __invoke(PolicyMutatedEvent $event): void
    {
        if (!$event->scope->type->isBackend()) {
            return;
        }
        $module = $event->request?->getAttribute('module');
        if (!$module instanceof ModuleInterface || !str_starts_with($module->getIdentifier(), self::MODULE_PREFIX)) {
            return;
        }
        $hosts = $this->collectHosts();
        if ($hosts === []) {
            return;
        }

        $event->setCurrentPolicy($event->getCurrentPolicy()->extend(
            Directive::FrameSrc,
            ...array_map(static fn(string $host): UriValue => new UriValue($host), $hosts),
        ));
    }

    /**
     * Host and port, without scheme: a source without scheme allows the
     * scheme of the backend and its upgrade to https.
     *
     * @return list<string>
     */
    private function collectHosts(): array
    {
        $urls = array_map(static fn(SiteBase $siteBase): string => $siteBase->url, $this->siteBaseProvider->getBases());
        foreach ($this->sitemapSnapshotRepository->findAll() as $snapshot) {
            $urls[] = $snapshot->startUrl;
        }
        $hosts = [];
        foreach ($urls as $url) {
            $host = parse_url($url, PHP_URL_HOST);
            $port = parse_url($url, PHP_URL_PORT);
            if (is_string($host) && $host !== '') {
                $hosts[strtolower($host) . (is_int($port) ? ':' . $port : '')] = true;
            }
        }
        foreach ($this->migrationRunRepository->findAll() as $run) {
            if ($run['targetHost'] !== '') {
                $hosts[strtolower($run['targetHost'])] = true;
            }
        }

        // Only well-formed host names reach the policy: a stored value is data.
        return array_values(array_filter(
            array_keys($hosts),
            static fn(string $host): bool => preg_match('/^[a-z0-9.-]+(?::\d+)?$/', $host) === 1,
        ));
    }
}
