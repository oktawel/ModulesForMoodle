<?php
defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {
    $settings->add(new admin_setting_heading(
        'plagiarism_simcheck_enabled',
        get_string('pluginname', 'plagiarism_simcheck'),
        ''
    ));

    $settings->add(new admin_setting_configcheckbox(
        'plagiarism_simcheck_enabled',
        'Включить плагин',
        'Включить проверку на схожесть для заданий',
        1
    ));

    $settings->add(new admin_setting_configtext(
        'plagiarism_simcheck_threshold',
        'Порог схожести (%)',
        'Выше этого процента результат будет выделен красным',
        50,
        PARAM_INT
    ));
}