<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Controller;

use OliverThiele\OtWebsitecheck\Service\FindingGuide;
use Psr\Http\Message\ResponseInterface;

/**
 * What the findings of the checks mean, who acts on them and what to do — for
 * editors, in the backend and in their language. The explanation of a finding
 * in the other modules links to its entry here; an entry that needs the
 * integrator links on to the recipes in the repository.
 */
class HelpModuleController extends AbstractModuleController
{
    private const string RECIPES_URL = 'https://github.com/oliverthiele/ot-websitecheck/blob/main/Documentation/Recipes.md';
    private const string README_URL = 'https://github.com/oliverthiele/ot-websitecheck#';

    /**
     * Entries in the order they are shown: help entry => who acts, and where
     * an integrator reads on.
     *
     * @var array<string, array{actors: list<string>, readOn: string}>
     */
    private const array ENTRIES = [
        'missing' => ['actors' => [FindingGuide::ACTOR_EDITOR], 'readOn' => ''],
        'redirectBroken' => ['actors' => [FindingGuide::ACTOR_EDITOR, FindingGuide::ACTOR_INTEGRATOR], 'readOn' => ''],
        'redirectNotFinal' => ['actors' => [FindingGuide::ACTOR_EDITOR, FindingGuide::ACTOR_INTEGRATOR], 'readOn' => self::RECIPES_URL . '#redirects-that-do-not-lead-to-the-final-url'],
        'temporaryRedirect' => ['actors' => [FindingGuide::ACTOR_EDITOR], 'readOn' => ''],
        'otherContent' => ['actors' => [FindingGuide::ACTOR_EDITOR], 'readOn' => ''],
        'languageChanged' => ['actors' => [FindingGuide::ACTOR_EDITOR], 'readOn' => ''],
        'canonical' => ['actors' => [FindingGuide::ACTOR_INTEGRATOR, FindingGuide::ACTOR_EDITOR], 'readOn' => self::RECIPES_URL . '#pages-that-show-the-content-of-another-page'],
        'detailPage' => ['actors' => [FindingGuide::ACTOR_INTEGRATOR], 'readOn' => self::RECIPES_URL . '#pages-that-require-a-parameter'],
        'sitemapContent' => ['actors' => [FindingGuide::ACTOR_INTEGRATOR], 'readOn' => ''],
        'errorPage' => ['actors' => [FindingGuide::ACTOR_EDITOR, FindingGuide::ACTOR_INTEGRATOR], 'readOn' => ''],
        'timeout' => ['actors' => [FindingGuide::ACTOR_INTEGRATOR], 'readOn' => self::README_URL . 'retries'],
        'markers' => ['actors' => [FindingGuide::ACTOR_INTEGRATOR], 'readOn' => self::README_URL . 'requirements-on-the-checked-site'],
        'duplicateDetailPage' => ['actors' => [FindingGuide::ACTOR_INTEGRATOR], 'readOn' => ''],
        'metadata' => ['actors' => [FindingGuide::ACTOR_EDITOR, FindingGuide::ACTOR_INTEGRATOR], 'readOn' => self::RECIPES_URL . '#metadata-of-detail-pages'],
        'argumentsIgnored' => ['actors' => [FindingGuide::ACTOR_INTEGRATOR], 'readOn' => self::README_URL . 'websitecheckcrawllinks'],
        'referenceNotOk' => ['actors' => [FindingGuide::ACTOR_NONE], 'readOn' => ''],
    ];

    /**
     * Terms of the checks and of TYPO3 an editor meets in the results, in the
     * order of the glossary.
     */
    private const array GLOSSARY = [
        'sitemap',
        'snapshot',
        'environment',
        'statusCode',
        'redirect',
        'redirectChain',
        'canonical',
        'contentFromPage',
        'shortcut',
        'slug',
        'pageId',
        'detailPage',
        'metaDescription',
        'openGraph',
        'noIndex',
        'languageFallback',
        'markers',
    ];

    public function indexAction(): ResponseInterface
    {
        $entries = [];
        foreach (self::ENTRIES as $name => $entry) {
            $entries[] = ['name' => $name] + $entry;
        }

        $moduleTemplate = $this->createModuleTemplate();
        $moduleTemplate->assignMultiple([
            'entries' => $entries,
            'glossary' => self::GLOSSARY,
            'actors' => FindingGuide::ACTORS,
        ]);

        return $moduleTemplate->renderResponse('HelpModule/Index');
    }
}
