<?php
defined('MOODLE_INTERNAL') || die();

function xmldb_attendance_uninstall() {
    global $DB;
    $dbman = $DB->get_manager();

    // Удаляем таблицы в порядке, обратном созданию (из-за связей)
    $tables = ['attendance_marks', 'attendance_sessions', 'attendance'];
    foreach ($tables as $tablename) {
        $table = new xmldb_table($tablename);
        if ($dbman->table_exists($table)) {
            $dbman->drop_table($table);
        }
    }
    return true;
}