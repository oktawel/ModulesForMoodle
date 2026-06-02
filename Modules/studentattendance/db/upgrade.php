<?php
defined('MOODLE_INTERNAL') || die();

function xmldb_studentattendance_upgrade($oldversion) {
    global $DB;

    upgrade_mod_savepoint(true, $oldversion, 'studentattendance');

    return true;
}