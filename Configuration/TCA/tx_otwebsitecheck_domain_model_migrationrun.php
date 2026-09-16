<?php

return [
    'ctrl' => [
        'title' => 'LLL:EXT:ot_websitecheck/Resources/Private/Language/locallang_db.xlf:tx_otwebsitecheck_domain_model_migrationrun',
        'label' => 'run_label',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'default_sortby' => 'started_at DESC',
        'rootLevel' => 1,
        'hideTable' => true,
        'security' => [
            'ignorePageTypeRestriction' => true,
        ],
    ],
    'types' => [
        '1' => ['showitem' => 'run_label, reference_snapshot, target_snapshot, target_host, started_at'],
    ],
    'columns' => [
        'run_label' => [
            'label' => 'LLL:EXT:ot_websitecheck/Resources/Private/Language/locallang_db.xlf:tx_otwebsitecheck_domain_model_migrationrun.run_label',
            'config' => [
                'type' => 'input',
                'size' => 20,
                'max' => 100,
                'readOnly' => true,
            ],
        ],
        'reference_snapshot' => [
            'label' => 'LLL:EXT:ot_websitecheck/Resources/Private/Language/locallang_db.xlf:tx_otwebsitecheck_domain_model_migrationrun.reference_snapshot',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'foreign_table' => 'tx_otwebsitecheck_domain_model_sitemapsnapshot',
                'readOnly' => true,
            ],
        ],
        'target_snapshot' => [
            'label' => 'LLL:EXT:ot_websitecheck/Resources/Private/Language/locallang_db.xlf:tx_otwebsitecheck_domain_model_migrationrun.target_snapshot',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'foreign_table' => 'tx_otwebsitecheck_domain_model_sitemapsnapshot',
                'readOnly' => true,
            ],
        ],
        'target_host' => [
            'label' => 'LLL:EXT:ot_websitecheck/Resources/Private/Language/locallang_db.xlf:tx_otwebsitecheck_domain_model_migrationrun.target_host',
            'config' => [
                'type' => 'input',
                'size' => 30,
                'max' => 255,
                'readOnly' => true,
            ],
        ],
        'started_at' => [
            'label' => 'LLL:EXT:ot_websitecheck/Resources/Private/Language/locallang_db.xlf:tx_otwebsitecheck_domain_model_migrationrun.started_at',
            'config' => [
                'type' => 'datetime',
                'readOnly' => true,
                'searchable' => false,
            ],
        ],
    ],
];
