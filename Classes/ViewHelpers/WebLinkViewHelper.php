<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\ViewHelpers;

use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractTagBasedViewHelper;

/**
 * Links to a URL the extension stored from a remote source — a sitemap entry,
 * a Location header, an imported archive — only when it is an http or https
 * URL. Anything else, a javascript: URL above all, is rendered as plain text:
 * Fluid escapes HTML, not URL schemes.
 *
 *     <ws:webLink url="{result.url}" target="_blank" rel="noreferrer">{result.path}</ws:webLink>
 */
final class WebLinkViewHelper extends AbstractTagBasedViewHelper
{
    protected $tagName = 'a';

    public function initializeArguments(): void
    {
        parent::initializeArguments();
        $this->registerArgument('url', 'string', 'The URL to link to; only http and https URLs become a link.', true);
    }

    public function render(): string
    {
        $url = is_string($this->arguments['url']) ? trim($this->arguments['url']) : '';
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $children = $this->renderChildren();
        $content = is_scalar($children) || $children instanceof \Stringable ? (string)$children : '';
        if (!is_string($scheme) || !in_array(strtolower($scheme), ['http', 'https'], true)) {
            return $content;
        }

        $this->tag->addAttribute('href', $url);
        $this->tag->setContent($content);

        return $this->tag->render();
    }
}
