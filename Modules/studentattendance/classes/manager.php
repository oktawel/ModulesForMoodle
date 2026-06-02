<?php
namespace mod_studentattendance;

defined('MOODLE_INTERNAL') || die();

class manager {
    public static function generate_sessions($attendanceid, $start, $end, $weekdays) {
        global $DB;
        
        $currentdate = $start;
        while ($currentdate <= $end) {
            $dow = date('N', $currentdate);
            if ($weekdays[$dow - 1] == '1') {
                $record = new \stdClass();
                $record->attendanceid = $attendanceid;
                $record->sessiondate = $currentdate;
                $DB->insert_record('studentattendance_sessions', $record);
            }
            $currentdate = strtotime('+1 day', $currentdate);
        }
    }

    public static function get_status_options() {
        return array(
            'P' => get_string('status_p', 'studentattendance'),
            'A' => get_string('status_a', 'studentattendance'),
            'L' => get_string('status_l', 'studentattendance'),
            'E' => get_string('status_e', 'studentattendance'),
        );
    }
}