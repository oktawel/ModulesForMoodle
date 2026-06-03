<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/mod/studentattendance/classes/manager.php');

$id = required_param('id', PARAM_INT);
$page_key = optional_param('page', null, PARAM_ALPHANUMEXT); 

$cm = get_coursemodule_from_id('studentattendance', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', array('id' => $cm->course), '*', MUST_EXIST);
$studentattendance = $DB->get_record('studentattendance', array('id' => $cm->instance), '*', MUST_EXIST);

require_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/studentattendance:view', $context);

$selected_month_key = null;

// --- ОБРАБОТКА POST ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && has_capability('mod/studentattendance:take', $context)) {
    require_sesskey();
    $raw_status = isset($_POST['status']) ? $_POST['status'] : array();
    
    if (!empty($raw_status) && is_array($raw_status)) {
        foreach ($raw_status as $sessionid => $students) {
            if (!is_numeric($sessionid) || !is_array($students)) continue;
            foreach ($students as $studentid => $val) {
                if (!is_numeric($studentid)) continue;
                $status = ($val === '1') ? 'P' : 'A';
                
                $record = $DB->get_record('studentattendance_records', 
                    array('sessionid' => $sessionid, 'studentid' => $studentid));
                if ($record) {
                    $record->status = $status;
                    $record->timemodified = time();
                    $DB->update_record('studentattendance_records', $record);
                } else {
                    $newrecord = new stdClass();
                    $newrecord->sessionid = $sessionid;
                    $newrecord->studentid = $studentid;
                    $newrecord->status = $status;
                    $newrecord->timemodified = time();
                    $DB->insert_record('studentattendance_records', $newrecord);
                }
            }
        }
    }
    redirect(new moodle_url('/mod/studentattendance/view.php', array('id' => $cm->id)), 
             get_string('attendancesaved', 'studentattendance'));
}

// Настройка страницы
$PAGE->set_url('/mod/studentattendance/view.php', array('id' => $cm->id));
if ($page_key !== null) $PAGE->url->param('page', $page_key);
$PAGE->set_title(format_string($studentattendance->name));
$PAGE->set_heading(format_string($course->fullname));

echo $OUTPUT->header();

// Получаем ВСЕ сессии
$all_sessions = $DB->get_records('studentattendance_sessions', 
    array('attendanceid' => $studentattendance->id), 'sessiondate ASC');

if (empty($all_sessions)) {
    echo $OUTPUT->notification(get_string('nosessions', 'studentattendance'), 'warning');
    echo $OUTPUT->footer(); exit;
}

// Группировка по месяцам
$sessions_by_month = array();
foreach ($all_sessions as $session) {
    $month_key = date('Y-m', $session->sessiondate);
    $month_label = userdate($session->sessiondate, '%B %Y');
    if (!isset($sessions_by_month[$month_key])) {
        $sessions_by_month[$month_key] = array('label' => $month_label, 'sessions' => array());
    }
    $sessions_by_month[$month_key]['sessions'][$session->id] = $session;
}

// Автовыбор текущей страницы
$today = strtotime('today');
if ($page_key !== null && isset($sessions_by_month[$page_key])) {
    $selected_month_key = $page_key;
} else {
    foreach ($sessions_by_month as $mk => $mdata) {
        $first_date = reset($mdata['sessions'])->sessiondate;
        $last_date = end($mdata['sessions'])->sessiondate;
        if ($today >= $first_date && $today <= $last_date) {
            $selected_month_key = $mk; break;
        }
    }
    if ($selected_month_key === null) {
        $months_keys = array_keys($sessions_by_month);
        $first_session_date = reset(reset($sessions_by_month)['sessions'])->sessiondate;
        $selected_month_key = ($today < $first_session_date) ? reset($months_keys) : end($months_keys);
    }
}

$current_month_data = $sessions_by_month[$selected_month_key];
$current_sessions = $current_month_data['sessions'];

// Студенты (без преподавателей)
$allusers = get_enrolled_users($context, '', 0, 'u.*', 'u.lastname ASC');
$students = array();
foreach ($allusers as $user) {
    if (!has_capability('mod/studentattendance:take', $context, $user->id)) {
        $students[$user->id] = $user;
    }
}

// Процент по всем сессиям
$total_sessions_count = count($all_sessions);
$percentage_map = array();
if (!empty($students) && $total_sessions_count > 0) {
    $student_ids = array_keys($students);
    list($studsql, $studparams) = $DB->get_in_or_equal($student_ids, SQL_PARAMS_NAMED);
    $sql_count = "SELECT studentid, COUNT(*) as present_count 
                  FROM {studentattendance_records} 
                  WHERE sessionid IN (SELECT id FROM {studentattendance_sessions} WHERE attendanceid = :attid)
                    AND studentid $studsql AND status = 'P' GROUP BY studentid";
    $counts = $DB->get_records_sql($sql_count, array_merge(['attid' => $studentattendance->id], $studparams));
    foreach ($counts as $c) {
        $percentage_map[$c->studentid] = round(($c->present_count / $total_sessions_count) * 100);
    }
}

// Записи текущего месяца
$records = array();
if (!empty($current_sessions)) {
    $current_session_ids = array_keys($current_sessions);
    list($insql, $inparams) = $DB->get_in_or_equal($current_session_ids, SQL_PARAMS_NAMED);
    $records = $DB->get_records_sql_menu(
        "SELECT CONCAT(sessionid, '-', studentid) as recid, status 
         FROM {studentattendance_records} WHERE sessionid $insql", $inparams);
}

$can_take = has_capability('mod/studentattendance:take', $context);

// ===== НАВИГАЦИЯ ПО МЕСЯЦАМ ВВЕРХУ =====
if (count($sessions_by_month) > 1) {
    echo html_writer::start_div('d-flex justify-content-center gap-2 mb-3 flex-wrap sticky-top bg-white py-2', 
        array('style' => 'z-index: 100; border-bottom: 1px solid #dee2e6;'));
    foreach ($sessions_by_month as $mk => $mdata) {
        $url = new moodle_url('/mod/studentattendance/view.php', array('id' => $cm->id, 'page' => $mk));
        $attrs = array('href' => $url, 'class' => 'btn btn-outline-secondary btn-sm');
        if ($mk === $selected_month_key) {
            $attrs['class'] = 'btn btn-primary btn-sm active';
            $attrs['aria-current'] = 'page';
        }
        echo html_writer::tag('a', $mdata['label'], $attrs);
    }
    echo html_writer::end_div();
}

// ФОРМА
echo html_writer::start_tag('form', array('method' => 'post', 
    'action' => new moodle_url('/mod/studentattendance/view.php', array('id' => $cm->id))));
echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()));

$table = new html_table();
$table->head = array(get_string('student', 'studentattendance'));

foreach ($current_sessions as $session) {
    $date_str = userdate($session->sessiondate, '%d.%m');
    
    // Метка типа недели
    if (!empty($session->weektype)) {
        $type_label = ($session->weektype === 'N') 
            ? get_string('weektype_n', 'studentattendance') 
            : get_string('weektype_d', 'studentattendance');
        $date_str .= '<br><small class="fw-normal">(' . $type_label . ')</small>';
    }
    
    // Выделение текущей даты красным
    if (date('Y-m-d', $session->sessiondate) == date('Y-m-d', $today)) {
        $date_str = html_writer::tag('span', $date_str, array(
            'class' => 'text-danger fw-bold',
            'title' => get_string('today', 'moodle'),
            'style' => 'font-size: 1.15em;'
        ));
    }
    
    // ЦВЕТОВОЕ КОДИРОВАНИЕ СТОЛБЦОВ
    $bg_color = ($session->weektype === 'N') ? '#e3f2fd' : '#fff8e1';
    $table->head[] = html_writer::tag('div', $date_str, 
        array('style' => "background-color: {$bg_color}; padding: 6px 8px; border-radius: 4px; min-width: 70px;"));
}
$table->head[] = get_string('attendancepercentage', 'studentattendance');

foreach ($students as $student) {
    $row = array();
    $row[] = fullname($student);
    
    foreach ($current_sessions as $session) {
        $key = $session->id . '-' . $student->id;
        $current_status = isset($records[$key]) ? $records[$key] : 'A';
        
        if ($can_take) {
            $checkbox = html_writer::checkbox(
                "status[{$session->id}][{$student->id}]", '1', 
                ($current_status == 'P'), '', array('class' => 'form-check-input'));
            
            // Ячейки тоже подсвечиваем фоном типа недели
            $bg = ($session->weektype === 'N') ? '#e3f2fd' : '#fff8e1';
            $row[] = html_writer::tag('div', $checkbox, 
                array('class' => 'text-center', 'style' => "background-color: {$bg};"));
        } else {
            $row[] = ($current_status == 'P') ? '✔' : '✘';
        }
    }
    
    $pct = isset($percentage_map[$student->id]) ? $percentage_map[$student->id] : 0;
    $row[] = html_writer::tag('strong', $pct . '%', array('class' => 'text-primary'));
    $table->data[] = $row;
}

echo html_writer::table($table);

if ($can_take) {
    echo html_writer::tag('button', get_string('savechanges', 'studentattendance'), 
        array('type' => 'submit', 'class' => 'btn btn-primary btn-lg mt-3'));
}

echo html_writer::end_tag('form');
echo $OUTPUT->footer();