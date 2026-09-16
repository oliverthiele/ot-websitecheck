<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Controller;

use OliverThiele\OtWebsitecheck\Utility\LabelUtility;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

/**
 * Base of the backend module controllers of this extension.
 */
abstract class AbstractModuleController extends ActionController
{
    private ModuleTemplateFactory $moduleTemplateFactory;

    public function injectModuleTemplateFactory(ModuleTemplateFactory $moduleTemplateFactory): void
    {
        $this->moduleTemplateFactory = $moduleTemplateFactory;
    }

    /**
     * addFlashMessage() queues into the Extbase queue of the plugin, while a
     * module template renders the default queue — without handing the Extbase
     * queue over, every flash message of an action is silently swallowed.
     */
    protected function createModuleTemplate(): ModuleTemplate
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $moduleTemplate->setFlashMessageQueue($this->getFlashMessageQueue());

        return $moduleTemplate;
    }

    /**
     * The module route is protected by a per-action security token embedded
     * in every generated URL's query string. A plain GET `<f:form>` submission
     * replaces that query string with only its own fields, dropping the
     * token — the backend then rejects the request and redirects to the
     * login/bootstrap route, which re-renders nested inside the module
     * iframe. Building the "index" URL the same way `<f:form action="index">`
     * does (via the same UriBuilder) and re-adding its token as a hidden
     * field keeps the token valid for this specific route.
     */
    protected function moduleToken(): string
    {
        $selfUri = $this->uriBuilder->reset()->uriFor('index');
        parse_str((string)parse_url($selfUri, PHP_URL_QUERY), $selfUriParams);
        $token = $selfUriParams['token'] ?? '';

        return is_string($token) ? $token : '';
    }

    protected function translate(string $key): string
    {
        return LabelUtility::translate($key);
    }
}
