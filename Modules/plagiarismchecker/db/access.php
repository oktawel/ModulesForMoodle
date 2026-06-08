<?php

defined('MOODLE_INTERNAL') || die();

$capabilities = [

    'local/plagiarismchecker:view' => [

        'riskbitmask' => RISK_PERSONAL,

        'captype' => 'read',

        'contextlevel' => CONTEXT_COURSE,

        'archetypes' => [
            'teacher'        => CAP_ALLOW,
            'editingteacher' => CAP_ALLOW,
            'manager'        => CAP_ALLOW
        ]
    ],

    'local/plagiarismchecker:runcheck' => [

        'riskbitmask' => RISK_PERSONAL,

        'captype' => 'write',

        'contextlevel' => CONTEXT_COURSE,

        'archetypes' => [
            'editingteacher' => CAP_ALLOW,
            'manager'        => CAP_ALLOW
        ]
    ]
];