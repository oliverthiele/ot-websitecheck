<?php

use OliverThiele\OtWebsitecheck\Controller\SitemapImportAjaxController;
use OliverThiele\OtWebsitecheck\Controller\ToggleLockedAjaxController;
use OliverThiele\OtWebsitecheck\Controller\ToggleReviewedAjaxController;

return [
    'websitecheck_toggle_reviewed' => [
        'path' => '/websitecheck/toggle-reviewed',
        'methods' => ['POST'],
        'target' => ToggleReviewedAjaxController::class . '::toggleAction',
    ],
    'websitecheck_sitemap_discover' => [
        'path' => '/websitecheck/sitemap/discover',
        'methods' => ['POST'],
        'target' => SitemapImportAjaxController::class . '::discoverAction',
    ],
    'websitecheck_sitemap_import_start' => [
        'path' => '/websitecheck/sitemap/import/start',
        'methods' => ['POST'],
        'target' => SitemapImportAjaxController::class . '::startAction',
    ],
    'websitecheck_sitemap_import_language' => [
        'path' => '/websitecheck/sitemap/import/language',
        'methods' => ['POST'],
        'target' => SitemapImportAjaxController::class . '::importLanguageAction',
    ],
    'websitecheck_sitemap_import_finish' => [
        'path' => '/websitecheck/sitemap/import/finish',
        'methods' => ['POST'],
        'target' => SitemapImportAjaxController::class . '::finishAction',
    ],
    'websitecheck_sitemap_toggle_locked' => [
        'path' => '/websitecheck/sitemap/toggle-locked',
        'methods' => ['POST'],
        'target' => ToggleLockedAjaxController::class . '::toggleAction',
    ],
];
