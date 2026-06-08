<?php

defined('MOODLE_INTERNAL') || die();

$functions = [

    'local_plagiarismchecker_check' => [

        'classname' =>
            'local_plagiarismchecker\external\check_external',

        'methodname' =>
            'execute',

        'classpath' => '',

        'description' =>
            'Check assignment similarity',

        'type' => 'read',

        'ajax' => true,

        'capabilities' =>
            'local/plagiarismchecker:runcheck'
    ]
];