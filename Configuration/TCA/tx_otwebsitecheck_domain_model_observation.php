<?php

return [
    'ctrl' => [
        'title' => 'LLL:EXT:ot_websitecheck/Resources/Private/Language/locallang_db.xlf:tx_otwebsitecheck_domain_model_observation',
        'label' => 'requested_path',
        'label_alt' => 'environment,verdict',
        'label_alt_force' => true,
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'default_sortby' => 'checked_at DESC',
        'rootLevel' => 1,
        'security' => [
            'ignorePageTypeRestriction' => true,
        ],
    ],
    'types' => [
        '1' => ['showitem' => 'reviewed, note, --div--;LLL:EXT:ot_websitecheck/Resources/Private/Language/locallang_db.xlf:tx_otwebsitecheck_domain_model_observation.tab.observation, run_label, environment, role, sitemap_group, requested_url, requested_path, first_status, final_url, final_path, final_status, hop_count, redirect_chain, abort_reason, page_uid, language, record_table, record_uid, verdict, warnings, suggested_target, checked_at'],
    ],
    'columns' => [
        'run_label' => [
            'label' => 'LLL:EXT:ot_websitecheck/Resources/Private/Language/locallang_db.xlf:tx_otwebsitecheck_domain_model_observation.run_label',
            'config' => [
                'type' => 'input',
                'size' => 20,
                'max' => 100,
                'readOnly' => true,
            ],
        ],
        'environment' => [
            'label' => 'LLL:EXT:ot_websitecheck/Resources/Private/Language/locallang_db.xlf:tx_otwebsitecheck_domain_model_observation.environment',
            'config' => [
                'type' => 'input',
                'size' => 20,
                'max' => 100,
                'readOnly' => true,
            ],
        ],
        'role' => [
            'label' => 'LLL:EXT:ot_websitecheck/Resources/Private/Language/locallang_db.xlf:tx_otwebsitecheck_domain_model_observation.role',
            'config' => [
                'type' => 'input',
                'size' => 20,
                'max' => 20,
                'readOnly' => true,
            ],
        ],
        'sitemap_group' => [
            'label' => 'LLL:EXT:ot_websitecheck/Resources/Private/Language/locallang_db.xlf:tx_otwebsitecheck_domain_model_observation.sitemap_group',
            'config' => [
                'type' => 'input',
                'size' => 20,
                'max' => 100,
                'readOnly' => true,
            ],
        ],
        'requested_url' => [
            'label' => 'LLL:EXT:ot_websitecheck/Resources/Private/Language/locallang_db.xlf:tx_otwebsitecheck_domain_model_observation.requested_url',
            'config' => [
                'type' => 'input',
                'size' => 50,
                'max' => 2048,
                'readOnly' => true,
            ],
        ],
        'requested_path' => [
            'label' => 'LLL:EXT:ot_websitecheck/Resources/Private/Language/locallang_db.xlf:tx_otwebsitecheck_domain_model_observation.requested_path',
            'config' => [
                'type' => 'input',
                'size' => 50,
                'max' => 2048,
                'readOnly' => true,
                'searchable' => false,
            ],
        ],
        'first_status' => [
            'label' => 'LLL:EXT:ot_websitecheck/Resources/Private/Language/locallang_db.xlf:tx_otwebsitecheck_domain_model_observation.first_status',
            'config' => [
                'type' => 'number',
                'readOnly' => true,
            ],
        ],
        'final_url' => [
            'label' => 'LLL:EXT:ot_websitecheck/Resources/Private/Language/locallang_db.xlf:tx_otwebsitecheck_domain_model_observation.final_url',
            'config' => [
                'type' => 'input',
                'size' => 50,
                'max' => 2048,
                'readOnly' => true,
            ],
        ],
        'final_path' => [
            'label' => 'LLL:EXT:ot_websitecheck/Resources/Private/Language/locallang_db.xlf:tx_otwebsitecheck_domain_model_observation.final_path',
            'config' => [
                'type' => 'input',
                'size' => 50,
                'max' => 2048,
                'readOnly' => true,
                'searchable' => false,
            ],
        ],
        'final_status' => [
            'label' => 'LLL:EXT:ot_websitecheck/Resources/Private/Language/locallang_db.xlf:tx_otwebsitecheck_domain_model_observation.final_status',
            'config' => [
                'type' => 'number',
                'readOnly' => true,
            ],
        ],
        'hop_count' => [
            'label' => 'LLL:EXT:ot_websitecheck/Resources/Private/Language/locallang_db.xlf:tx_otwebsitecheck_domain_model_observation.hop_count',
            'config' => [
                'type' => 'number',
                'readOnly' => true,
            ],
        ],
        'redirect_chain' => [
            'label' => 'LLL:EXT:ot_websitecheck/Resources/Private/Language/locallang_db.xlf:tx_otwebsitecheck_domain_model_observation.redirect_chain',
            'config' => [
                'type' => 'text',
                'cols' => 60,
                'rows' => 5,
                'readOnly' => true,
                'searchable' => false,
            ],
        ],
        'abort_reason' => [
            'label' => 'LLL:EXT:ot_websitecheck/Resources/Private/Language/locallang_db.xlf:tx_otwebsitecheck_domain_model_observation.abort_reason',
            'config' => [
                'type' => 'input',
                'size' => 20,
                'max' => 50,
                'readOnly' => true,
            ],
        ],
        'page_uid' => [
            'label' => 'LLL:EXT:ot_websitecheck/Resources/Private/Language/locallang_db.xlf:tx_otwebsitecheck_domain_model_observation.page_uid',
            'config' => [
                'type' => 'number',
                'readOnly' => true,
            ],
        ],
        'language' => [
            'label' => 'LLL:EXT:ot_websitecheck/Resources/Private/Language/locallang_db.xlf:tx_otwebsitecheck_domain_model_observation.language',
            'config' => [
                'type' => 'input',
                'size' => 10,
                'max' => 20,
                'readOnly' => true,
            ],
        ],
        'record_table' => [
            'label' => 'LLL:EXT:ot_websitecheck/Resources/Private/Language/locallang_db.xlf:tx_otwebsitecheck_domain_model_observation.record_table',
            'config' => [
                'type' => 'input',
                'size' => 30,
                'max' => 255,
                'readOnly' => true,
            ],
        ],
        'record_uid' => [
            'label' => 'LLL:EXT:ot_websitecheck/Resources/Private/Language/locallang_db.xlf:tx_otwebsitecheck_domain_model_observation.record_uid',
            'config' => [
                'type' => 'number',
                'readOnly' => true,
            ],
        ],
        'verdict' => [
            'label' => 'LLL:EXT:ot_websitecheck/Resources/Private/Language/locallang_db.xlf:tx_otwebsitecheck_domain_model_observation.verdict',
            'config' => [
                'type' => 'input',
                'size' => 20,
                'max' => 50,
                'readOnly' => true,
            ],
        ],
        'warnings' => [
            'label' => 'LLL:EXT:ot_websitecheck/Resources/Private/Language/locallang_db.xlf:tx_otwebsitecheck_domain_model_observation.warnings',
            'config' => [
                'type' => 'input',
                'size' => 50,
                'max' => 255,
                'readOnly' => true,
            ],
        ],
        'suggested_target' => [
            'label' => 'LLL:EXT:ot_websitecheck/Resources/Private/Language/locallang_db.xlf:tx_otwebsitecheck_domain_model_observation.suggested_target',
            'config' => [
                'type' => 'input',
                'size' => 50,
                'max' => 2048,
                'readOnly' => true,
                'searchable' => false,
            ],
        ],
        'checked_at' => [
            'label' => 'LLL:EXT:ot_websitecheck/Resources/Private/Language/locallang_db.xlf:tx_otwebsitecheck_domain_model_observation.checked_at',
            'config' => [
                'type' => 'datetime',
                'readOnly' => true,
                'searchable' => false,
            ],
        ],
        'reviewed' => [
            'label' => 'LLL:EXT:ot_websitecheck/Resources/Private/Language/locallang_db.xlf:tx_otwebsitecheck_domain_model_observation.reviewed',
            'config' => [
                'type' => 'check',
                'default' => 0,
            ],
        ],
        'note' => [
            'label' => 'LLL:EXT:ot_websitecheck/Resources/Private/Language/locallang_db.xlf:tx_otwebsitecheck_domain_model_observation.note',
            'config' => [
                'type' => 'text',
                'cols' => 40,
                'rows' => 5,
                'eval' => 'trim',
            ],
        ],
    ],
];
