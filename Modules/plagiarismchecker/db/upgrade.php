<?php

defined('MOODLE_INTERNAL') || die();

function xmldb_local_plagiarismchecker_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026060608) {

        $table = new xmldb_table('assign');

        $field = new xmldb_field(
            'showplagiarism',
            XMLDB_TYPE_INTEGER,
            '1',
            null,
            XMLDB_NOTNULL,
            null,
            '0'
        );

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(
            true,
            2026060608,
            'local',
            'plagiarismchecker'
        );
    }

    return true;
}