<?php
defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/studentattendance/classes/manager.php');

function studentattendance_supports($feature) {
    switch($feature) {
        case FEATURE_GROUPS: return true;
        case FEATURE_GROUPINGS: return true;
        case FEATURE_MOD_INTRO: return true;
        case FEATURE_GRADE_HAS_GRADE: return true;
        case FEATURE_BACKUP_MOODLE2: return true;
        default: return null;
    }
}

function studentattendance_add_instance($data, $mform) {
    global $DB;
    $data->timecreated = time();
    $data->timemodified = time();
    
    $weekdays = '';
    for ($i = 1; $i <= 7; $i++) {
        $weekdays .= isset($data->{'weekday' . $i}) ? '1' : '0';
    }
    $data->weekdays = $weekdays;

    $id = $DB->insert_record('studentattendance', $data);
    
    mod_studentattendance\manager::generate_sessions($id, $data->semesterstart, $data->semesterend, $weekdays);
    
    return $id;
}

function studentattendance_update_instance($data, $mform) {
    global $DB;
    $data->timemodified = time();
    $data->id = $data->instance;

    $weekdays = '';
    for ($i = 1; $i <= 7; $i++) {
        $weekdays .= isset($data->{'weekday' . $i}) ? '1' : '0';
    }
    $data->weekdays = $weekdays;

    $DB->update_record('studentattendance', $data);
    
    $sessions = $DB->get_records('studentattendance_sessions', array('attendanceid' => $data->id));
    foreach ($sessions as $session) {
        $DB->delete_records('studentattendance_records', array('sessionid' => $session->id));
    }
    $DB->delete_records('studentattendance_sessions', array('attendanceid' => $data->id));
    
    mod_studentattendance\manager::generate_sessions($data->id, $data->semesterstart, $data->semesterend, $weekdays);
    
    return true;
}

function studentattendance_delete_instance($id) {
    global $DB;
    $sessions = $DB->get_records('studentattendance_sessions', array('attendanceid' => $id));
    foreach ($sessions as $session) {
        $DB->delete_records('studentattendance_records', array('sessionid' => $session->id));
    }
    $DB->delete_records('studentattendance_sessions', array('attendanceid' => $id));
    $DB->delete_records('studentattendance', array('id' => $id));
    return true;
}

function studentattendance_get_user_grades($studentattendance, $userid = 0) {
    return true;
}