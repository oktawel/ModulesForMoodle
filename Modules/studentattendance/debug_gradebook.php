<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/mod/studentattendance/lib.php');
require_once($CFG->libdir . '/gradelib.php');
require_login();

$attendanceid = required_param('attendanceid', PARAM_INT);
$attendance = $DB->get_record('studentattendance', array('id' => $attendanceid), '*', MUST_EXIST);

require_login($attendance->course);
$PAGE->set_context(context_course::instance($attendance->course));

echo "<h2>Диагностика оценок</h2>";
echo "<pre>";
echo "attendance->id: " . $attendance->id . "\n";
echo "attendance->course: " . $attendance->course . "\n";
echo "attendance->name: " . $attendance->name . "\n";
echo "attendance->grade_enabled: " . var_export($attendance->grade_enabled, true) . "\n";
echo "attendance->max_grade: " . var_export($attendance->max_grade, true) . "\n";
echo "Тип max_grade: " . gettype($attendance->max_grade) . "\n";

$grades = studentattendance_get_user_grades($attendance);
echo "\nПолученные оценки:\n";
var_dump($grades);

echo "\nПопытка обновления оценок...\n";
$result = studentattendance_update_grades($attendance);
echo "Результат: " . var_export($result, true) . "\n";
echo "</pre>";