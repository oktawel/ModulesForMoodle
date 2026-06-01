<?php

require('../../config.php');

$id = required_param('id', PARAM_INT);
$userid = required_param('userid', PARAM_INT);
$date = required_param('date', PARAM_RAW);

$date = strtotime(date('Y-m-d', strtotime($date)));

$cm = get_coursemodule_from_id('attendance', $id, 0, false, MUST_EXIST);

require_login($cm->course, false, $cm);

$attendance = $DB->get_record('attendance', ['id' => $cm->instance], '*', MUST_EXIST);

$record = $DB->get_record('attendance_marks', [
    'attendanceid' => $attendance->id,
    'userid' => $userid,
    'sessiondate' => $date
]);

if ($record) {
    $record->status = $record->status ? 0 : 1;
    $DB->update_record('attendance_marks', $record);
} else {
    $DB->insert_record('attendance_marks', (object)[
        'attendanceid' => $attendance->id,
        'userid' => $userid,
        'sessiondate' => $date,
        'status' => 1
    ]);
}

redirect(new moodle_url('/mod/attendance/view.php', [
    'id' => $id,
    'start' => $date
]));