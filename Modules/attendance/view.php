<?php

require('../../config.php');

$id = required_param('id', PARAM_INT);

$start = optional_param('start', strtotime('monday this week'), PARAM_INT);
$end   = optional_param('end', strtotime('+2 weeks', $start), PARAM_INT);

$cm = get_coursemodule_from_id('attendance', $id, 0, false, MUST_EXIST);

$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$attendance = $DB->get_record('attendance', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, true, $cm);

$context = context_module::instance($cm->id);
$students = get_enrolled_users(context_course::instance($course->id));

$PAGE->set_url('/mod/attendance/view.php', ['id' => $cm->id]);
$PAGE->set_title($attendance->name);
$PAGE->set_heading($course->fullname);

echo $OUTPUT->header();

echo html_writer::tag('h2', format_string($attendance->name));

/* ===== PERIOD FORM ===== */

echo html_writer::start_tag('form', ['method' => 'get']);

echo html_writer::empty_tag('input', [
    'type' => 'hidden',
    'name' => 'id',
    'value' => $cm->id
]);

echo "С ";
echo html_writer::empty_tag('input', [
    'type' => 'date',
    'name' => 'start',
    'value' => date('Y-m-d', $start)
]);

echo " по ";
echo html_writer::empty_tag('input', [
    'type' => 'date',
    'name' => 'end',
    'value' => date('Y-m-d', $end)
]);

echo html_writer::empty_tag('input', [
    'type' => 'submit',
    'value' => 'Применить'
]);

echo html_writer::end_tag('form');

/* ===== DATES ===== */

$dates = [];

for ($d = $start; $d <= $end; $d = strtotime('+1 day', $d)) {
    $dates[] = strtotime(date('Y-m-d', $d));
}

/* ===== PRELOAD MARKS ===== */

$marks_raw = $DB->get_records('attendance_marks', [
    'attendanceid' => $attendance->id
]);

$marks = [];

foreach ($marks_raw as $m) {
    $marks[$m->userid][$m->sessiondate] = $m;
}

/* ===== TABLE ===== */

$table = new html_table();
$table->attributes['class'] = 'generaltable';

$head = ['Студент'];

foreach ($dates as $date) {
    $head[] = date('d.m', $date);
}

$table->head = $head;

/* ===== ROWS ===== */

foreach ($students as $student) {

    $row = [];
    $row[] = fullname($student);

    foreach ($dates as $date) {

        $mark = $marks[$student->id][$date] ?? null;

        $url = new moodle_url('/mod/attendance/toggle.php', [
            'id' => $cm->id,
            'userid' => $student->id,
            'date' => date('Y-m-d', $date)
        ]);

        $value = ($mark && $mark->status) ? '✓' : '—';

        $row[] = html_writer::link($url, $value, [
            'style' => 'text-decoration:none;font-weight:bold;font-size:16px;'
        ]);
    }

    $table->data[] = $row;
}

echo html_writer::table($table);

echo $OUTPUT->footer();