<?php
defined('MOODLE_INTERNAL') || die();

$functions = [
    'plagiarism_simcheck_check' => [
        'classname'   => 'plagiarism_simcheck_external',
        'methodname'  => 'check_similarity',
        'description' => 'Проверка схожести файла студента с другими',
        'type'        => 'write',
        'ajax'        => true,
        'loginrequired' => true,
    ]
];