<?php

use OliverThiele\OtWebsitecheck\Controller\MigrationCheckModuleController;
use OliverThiele\OtWebsitecheck\Controller\SitemapSnapshotModuleController;
use OliverThiele\OtWebsitecheck\Controller\WebsiteCheckModuleController;

return [
    // Container: shows a card per tool instead of redirecting to the first one.
    // Up to 0.3 the modules lived below "System"; the old identifiers remain as aliases.
    'site_websitecheck' => [
        'parent' => 'site',
        'position' => ['after' => 'link_management'],
        'access' => 'admin',
        'workspaces' => 'live',
        'iconIdentifier' => 'ot-websitecheck',
        'path' => '/module/site/websitecheck',
        'aliases' => ['system_websitecheck'],
        'labels' => 'LLL:EXT:ot_websitecheck/Resources/Private/Language/locallang_mod.xlf',
        'showSubmoduleOverview' => true,
    ],
    'site_websitecheck_status' => [
        'parent' => 'site_websitecheck',
        'access' => 'admin',
        'workspaces' => 'live',
        'iconIdentifier' => 'ot-websitecheck',
        'path' => '/module/site/websitecheck/status',
        'aliases' => ['system_websitecheck_status'],
        'labels' => [
            'title' => 'LLL:EXT:ot_websitecheck/Resources/Private/Language/locallang_mod.xlf:module.status.title',
            'shortDescription' => 'LLL:EXT:ot_websitecheck/Resources/Private/Language/locallang_mod.xlf:module.status.shortDescription',
            'description' => 'LLL:EXT:ot_websitecheck/Resources/Private/Language/locallang_mod.xlf:module.status.description',
        ],
        'extensionName' => 'OtWebsitecheck',
        'controllerActions' => [
            WebsiteCheckModuleController::class => [
                'index',
                'delete',
                'deleteAll',
            ],
        ],
    ],
    'site_websitecheck_migration' => [
        'parent' => 'site_websitecheck',
        'access' => 'admin',
        'workspaces' => 'live',
        'iconIdentifier' => 'ot-websitecheck',
        'path' => '/module/site/websitecheck/migration',
        'aliases' => ['system_websitecheck_migration'],
        'labels' => [
            'title' => 'LLL:EXT:ot_websitecheck/Resources/Private/Language/locallang_mod.xlf:module.migration.title',
            'shortDescription' => 'LLL:EXT:ot_websitecheck/Resources/Private/Language/locallang_mod.xlf:module.migration.shortDescription',
            'description' => 'LLL:EXT:ot_websitecheck/Resources/Private/Language/locallang_mod.xlf:module.migration.description',
        ],
        'extensionName' => 'OtWebsitecheck',
        'controllerActions' => [
            MigrationCheckModuleController::class => [
                'index',
                'saveRun',
                'deleteRun',
            ],
        ],
    ],
    'site_websitecheck_sitemaps' => [
        'parent' => 'site_websitecheck',
        'access' => 'admin',
        'workspaces' => 'live',
        'iconIdentifier' => 'ot-websitecheck',
        'path' => '/module/site/websitecheck/sitemaps',
        'aliases' => ['system_websitecheck_sitemaps'],
        'labels' => [
            'title' => 'LLL:EXT:ot_websitecheck/Resources/Private/Language/locallang_mod.xlf:module.sitemaps.title',
            'shortDescription' => 'LLL:EXT:ot_websitecheck/Resources/Private/Language/locallang_mod.xlf:module.sitemaps.shortDescription',
            'description' => 'LLL:EXT:ot_websitecheck/Resources/Private/Language/locallang_mod.xlf:module.sitemaps.description',
        ],
        'extensionName' => 'OtWebsitecheck',
        'controllerActions' => [
            SitemapSnapshotModuleController::class => [
                'index',
                'delete',
                'saveSnapshot',
                'importArchive',
                'deleteArchive',
            ],
        ],
    ],
];
