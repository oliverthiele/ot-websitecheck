<?php

use OliverThiele\OtWebsitecheck\Controller\MigrationCheckModuleController;
use OliverThiele\OtWebsitecheck\Controller\SitemapSnapshotModuleController;
use OliverThiele\OtWebsitecheck\Controller\WebsiteCheckModuleController;

return [
    // Container: shows a card per tool instead of redirecting to the first one.
    'system_websitecheck' => [
        'parent' => 'system',
        'access' => 'admin',
        'workspaces' => 'live',
        'iconIdentifier' => 'ot-websitecheck',
        'path' => '/module/system/websitecheck',
        'labels' => 'LLL:EXT:ot_websitecheck/Resources/Private/Language/locallang_mod.xlf',
        'showSubmoduleOverview' => true,
    ],
    'system_websitecheck_status' => [
        'parent' => 'system_websitecheck',
        'access' => 'admin',
        'workspaces' => 'live',
        'iconIdentifier' => 'ot-websitecheck',
        'path' => '/module/system/websitecheck/status',
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
    'system_websitecheck_migration' => [
        'parent' => 'system_websitecheck',
        'access' => 'admin',
        'workspaces' => 'live',
        'iconIdentifier' => 'ot-websitecheck',
        'path' => '/module/system/websitecheck/migration',
        'labels' => [
            'title' => 'LLL:EXT:ot_websitecheck/Resources/Private/Language/locallang_mod.xlf:module.migration.title',
            'shortDescription' => 'LLL:EXT:ot_websitecheck/Resources/Private/Language/locallang_mod.xlf:module.migration.shortDescription',
            'description' => 'LLL:EXT:ot_websitecheck/Resources/Private/Language/locallang_mod.xlf:module.migration.description',
        ],
        'extensionName' => 'OtWebsitecheck',
        'controllerActions' => [
            MigrationCheckModuleController::class => [
                'index',
                'deleteRun',
            ],
        ],
    ],
    'system_websitecheck_sitemaps' => [
        'parent' => 'system_websitecheck',
        'access' => 'admin',
        'workspaces' => 'live',
        'iconIdentifier' => 'ot-websitecheck',
        'path' => '/module/system/websitecheck/sitemaps',
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
            ],
        ],
    ],
];
