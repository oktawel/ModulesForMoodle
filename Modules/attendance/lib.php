<?php

defined('MOODLE_INTERNAL') || die();

function attendance_add_instance($data) {
    global $DB;
    return $DB->insert_record('attendance', $data);
}

function attendance_update_instance($data) {
    global $DB;
    return $DB->update_record('attendance', $data);
}

function attendance_delete_instance($id) {
    global $DB;

    $DB->delete_records('attendance_marks', ['attendanceid' => $id]);
    return $DB->delete_records('attendance', ['id' => $id]);
}