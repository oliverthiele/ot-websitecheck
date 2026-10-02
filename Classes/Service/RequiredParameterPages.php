<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Service;

use Doctrine\DBAL\ParameterType;
use OliverThiele\OtWebsitecheck\Domain\ValueObject\PageIdentity;
use TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationExtensionNotConfiguredException;
use TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationPathDoesNotExistException;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Routing\PageArguments;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;

/**
 * Pages that show content only with a parameter — the detail page of a
 * plugin. Called without one, such a page shows a fallback (often the list)
 * or an error; either way it is no page of its own and belongs in no sitemap.
 *
 * TYPO3 has no such page property. A project can add a checkbox field to the
 * pages and name it in the extension configuration (requiresParameterField);
 * without that, nothing is marked.
 */
class RequiredParameterPages
{
    /**
     * @var array<int, true>|null page uid => true, null until read
     */
    private ?array $markedPageUids = null;

    public function __construct(
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly TcaSchemaFactory $tcaSchemaFactory,
        private readonly ConnectionPool $connectionPool,
    ) {
    }

    /**
     * Whether a URL calls a marked page without a parameter.
     *
     * The local routing decides where it can: it tells the page and whether
     * the URL carries arguments, also for a page that answered with an error.
     * A path the local site does not know falls back to the page the response
     * named, without a record marker.
     *
     * @param PageArguments|null $pageArguments what the local routing reads from the URL, see PageUidResolver::resolveArguments()
     * @param PageIdentity $identity what the response named; empty for an error page
     */
    public function isCalledWithoutParameter(?PageArguments $pageArguments, PageIdentity $identity): bool
    {
        $markedPageUids = $this->getMarkedPageUids();
        if ($markedPageUids === []) {
            return false;
        }
        if ($pageArguments !== null) {
            $arguments = $pageArguments->getArguments();
            unset($arguments['cHash']);

            return isset($markedPageUids[$pageArguments->getPageId()]) && $arguments === [];
        }

        return $identity->hasPage() && !$identity->hasRecord() && isset($markedPageUids[$identity->pageUid]);
    }

    /**
     * @return array<int, true> uids of the marked pages, of the default language and of their translations
     */
    public function getMarkedPageUids(): array
    {
        if ($this->markedPageUids !== null) {
            return $this->markedPageUids;
        }
        $this->markedPageUids = [];
        $field = $this->getField();
        if ($field === '') {
            return $this->markedPageUids;
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        // Hidden pages count as well: the checked environment may show what is hidden here.
        $queryBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction());
        $rows = $queryBuilder->select('uid', 'l10n_parent')
            ->from('pages')
            ->where($queryBuilder->expr()->eq($field, $queryBuilder->createNamedParameter(1, ParameterType::INTEGER)))
            ->executeQuery()
            ->fetchAllAssociative();
        foreach ($rows as $row) {
            foreach (['uid', 'l10n_parent'] as $column) {
                $pageUid = $row[$column] ?? null;
                if (is_numeric($pageUid) && (int)$pageUid > 0) {
                    $this->markedPageUids[(int)$pageUid] = true;
                }
            }
        }

        return $this->markedPageUids;
    }

    /**
     * The configured field, or '' when none is configured or the pages table
     * has no such field.
     */
    private function getField(): string
    {
        try {
            $field = $this->extensionConfiguration->get('ot_websitecheck', 'requiresParameterField');
        } catch (ExtensionConfigurationExtensionNotConfiguredException|ExtensionConfigurationPathDoesNotExistException) {
            return '';
        }
        $field = is_string($field) ? trim($field) : '';
        if ($field === '' || !$this->tcaSchemaFactory->has('pages') || !$this->tcaSchemaFactory->get('pages')->hasField($field)) {
            return '';
        }

        return $field;
    }
}
