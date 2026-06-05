<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/mod/studentattendance/classes/manager.php');
require_once($CFG->dirroot . '/cohort/lib.php');

$id = required_param('id', PARAM_INT);
$page_key = optional_param('page', null, PARAM_ALPHANUMEXT);
$search = optional_param('search', '', PARAM_TEXT);
$filter_name = optional_param('filter_name', '', PARAM_TEXT);
$filter_surname = optional_param('filter_surname', '', PARAM_TEXT);
$apply = optional_param('apply', 0, PARAM_INT);

$cm = get_coursemodule_from_id('studentattendance', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', array('id' => $cm->course), '*', MUST_EXIST);
$studentattendance = $DB->get_record('studentattendance', array('id' => $cm->instance), '*', MUST_EXIST);

require_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/studentattendance:view', $context);

$can_take = has_capability('mod/studentattendance:take', $context);

// Получение когорт
$current_cohort_id = optional_param('cohort', 0, PARAM_INT);
$course_context = context_course::instance($course->id);
if (has_capability('moodle/cohort:view', context_system::instance())) {
    $cohorts = cohort_get_available_cohorts($course_context, 0, 0, 0);
} else {
    $cohorts = array();
}

// === ФОРМИРОВАНИЕ ОБЪЕДИНЁННЫХ СЕССИЙ (ДО ОБРАБОТКИ POST!) ===
$all_sessions_raw = $DB->get_records('studentattendance_sessions',
    array('attendanceid' => $studentattendance->id), 'sessiondate ASC');

$sessions_by_date = array();
foreach ($all_sessions_raw as $session) {
    $date_key = date('Y-m-d', $session->sessiondate);
    if (!isset($sessions_by_date[$date_key])) {
        $sessions_by_date[$date_key] = array();
    }
    $sessions_by_date[$date_key][$session->cohortid] = $session;
}

$all_sessions = array();
$session_id_counter = 1;
foreach ($sessions_by_date as $date_key => $cohort_sessions) {
    $first_session = reset($cohort_sessions);
    $merged = new stdClass();
    $merged->id = $session_id_counter++;
    $merged->sessiondate = $first_session->sessiondate;
    $merged->cohort_sessions = $cohort_sessions;
    $merged->weektype = $first_session->weektype;
    $all_sessions[$merged->id] = $merged;
}

// Фильтрация для студента
if (!$can_take) {
    $user_cohorts = cohort_get_user_cohorts($USER->id);
    $user_cohort_ids = array_map(function($c) { return $c->id; }, $user_cohorts);
    $filtered = array();
    foreach ($all_sessions as $session) {
        foreach ($session->cohort_sessions as $cs) {
            if ($cs->cohortid == 0 || in_array($cs->cohortid, $user_cohort_ids)) {
                $filtered[$session->id] = $session;
                break;
            }
        }
    }
    $all_sessions = $filtered;
}

// === ОБРАБОТКА POST ===
// === ОБРАБОТКА POST ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_take) {
    require_sesskey();
    
    $raw_status = isset($_POST['status']) ? $_POST['status'] : array();
    
    if (!empty($raw_status) && is_array($raw_status)) {
        foreach ($raw_status as $merged_id => $students) {
            if (!is_numeric($merged_id) || !is_array($students)) continue;
            
            if (!isset($all_sessions[$merged_id])) continue;
            $session = $all_sessions[$merged_id];
            
            foreach ($students as $studentid => $val) {
                if (!is_numeric($studentid)) continue;
                
                // Пропускаем преподавателей
                if (has_capability('mod/studentattendance:take', $context, $studentid)) {
                    continue;
                }
                
                $status = ($val === '1') ? 'P' : 'A';
                
                // Находим реальную сессию для студента
                $student_cohorts = cohort_get_user_cohorts($studentid);
                $student_cohort_ids = array_map(function($c) { return $c->id; }, $student_cohorts);
                
                $target_session_id = null;
                foreach ($session->cohort_sessions as $cs) {
                    if ($cs->cohortid == 0 || in_array($cs->cohortid, $student_cohort_ids)) {
                        $target_session_id = $cs->id;
                        break;
                    }
                }
                
                if (!$target_session_id) continue;
                
                // Сохраняем или обновляем запись
                $record = $DB->get_record('studentattendance_records',
                    array('sessionid' => $target_session_id, 'studentid' => $studentid));
                
                if ($record) {
                    if ($record->status !== $status) {
                        $record->status = $status;
                        $record->timemodified = time();
                        $DB->update_record('studentattendance_records', $record);
                    }
                } else {
                    // Создаём запись только если статус "присутствует"
                    if ($status === 'P') {
                        $newrecord = new stdClass();
                        $newrecord->sessionid = $target_session_id;
                        $newrecord->studentid = $studentid;
                        $newrecord->status = $status;
                        $newrecord->timemodified = time();
                        $DB->insert_record('studentattendance_records', $newrecord);
                    }
                    // Если статус 'A' и записи нет — ничего не делаем (отметки нет в БД)
                }
            }
        }
        
        // Обновляем оценки
        if (function_exists('studentattendance_update_all_grades')) {
            try {
                studentattendance_update_all_grades($studentattendance);
            } catch (Exception $e) {
                error_log("Grade update error: " . $e->getMessage());
            }
        }
    }
    
    redirect(new moodle_url('/mod/studentattendance/view.php', array('id' => $cm->id)),
             get_string('attendancesaved', 'studentattendance'));
}

// === НАСТРОЙКА СТРАНИЦЫ ===
$PAGE->set_url('/mod/studentattendance/view.php', array('id' => $cm->id));
if ($page_key !== null) $PAGE->url->param('page', $page_key);
if ($current_cohort_id > 0) $PAGE->url->param('cohort', $current_cohort_id);
$PAGE->set_title(format_string($studentattendance->name));
$PAGE->set_heading(format_string($course->fullname));

echo $OUTPUT->header();

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

// Автовыбор месяца
$today = strtotime('today');
$selected_month_key = null;
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
        $selected_month_key = ($today < reset(reset($sessions_by_month)['sessions'])->sessiondate)
            ? reset($months_keys) : end($months_keys);
    }
}

$current_sessions = $sessions_by_month[$selected_month_key]['sessions'];

// Студенты
$allusers = get_enrolled_users($context, '', 0, 'u.*', 'u.lastname ASC');
$students = array();
foreach ($allusers as $user) {
    if (has_capability('mod/studentattendance:take', $context, $user->id)) continue;
    if (!$can_take && $user->id != $USER->id) continue;
    $students[$user->id] = $user;
}

// Процент посещаемости
$total_sessions_count = count($all_sessions_raw);
$percentage_map = array();
if (!empty($students) && $total_sessions_count > 0) {
    $student_ids = array_keys($students);
    list($studsql, $studparams) = $DB->get_in_or_equal($student_ids, SQL_PARAMS_NAMED);

    foreach ($students as $student) {
        $student_cohorts = cohort_get_user_cohorts($student->id);
        $student_cohort_ids = array_map(function($c) { return $c->id; }, $student_cohorts);

        // Считаем только сессии когорты студента
        $student_session_ids = array();
        foreach ($all_sessions_raw as $s) {
            if ($s->cohortid == 0 || in_array($s->cohortid, $student_cohort_ids)) {
                $student_session_ids[] = $s->id;
            }
        }

        if (empty($student_session_ids)) continue;
        $student_total = count($student_session_ids);

        list($insql, $inparams) = $DB->get_in_or_equal($student_session_ids, SQL_PARAMS_NAMED);
        $sql = "SELECT COUNT(*) FROM {studentattendance_records}
                WHERE sessionid $insql AND studentid = :sid AND status = 'P'";
        $present = $DB->count_records_sql($sql, array_merge($inparams, array('sid' => $student->id)));

        $percentage_map[$student->id] = round(($present / $student_total) * 100);
    }
}

// === ЗАГРУЗКА ЗАПИСЕЙ ПО РЕАЛЬНЫМ SESSIONID ===
$records = array();
$all_real_session_ids = array();
foreach ($all_sessions_raw as $s) {
    $all_real_session_ids[] = $s->id;
}

if (!empty($all_real_session_ids)) {
    list($insql, $inparams) = $DB->get_in_or_equal($all_real_session_ids, SQL_PARAMS_NAMED);
    $raw_records = $DB->get_records_sql(
        "SELECT id, sessionid, studentid, status FROM {studentattendance_records} WHERE sessionid $insql",
        $inparams
    );
    // Ключ: "реальный_sessionid-studentid" => status
    foreach ($raw_records as $r) {
        $records[$r->sessionid . '-' . $r->studentid] = $r->status;
    }
}

// === НАВИГАЦИЯ ПО МЕСЯЦАМ ===
if (count($sessions_by_month) > 1) {
    echo html_writer::start_div('d-flex justify-content-center gap-2 mb-3 flex-wrap bg-white py-2',
        array('style' => 'border-bottom: 1px solid #dee2e6;'));
    foreach ($sessions_by_month as $mk => $mdata) {
        $url = new moodle_url('/mod/studentattendance/view.php', array(
            'id' => $cm->id, 'page' => $mk, 'cohort' => $current_cohort_id
        ));
        $attrs = array('href' => $url, 'class' => 'btn btn-outline-secondary btn-sm');
        if ($mk === $selected_month_key) {
            $attrs['class'] = 'btn btn-primary btn-sm active';
        }
        echo html_writer::tag('a', $mdata['label'], $attrs);
    }
    echo html_writer::end_div();
}

// === БЛОК ОТБОРОВ ===
if ($can_take) {
    echo html_writer::start_div('attendance-filters-wrapper', array(
        'style' => 'margin-bottom: 20px; border: 1px solid #dee2e6; border-radius: 8px; overflow: hidden; background: #f8f9fa;'
    ));

    echo html_writer::tag('div',
        '<span>Отборы</span><span class="toggle-arrow"></span>',
        array('id' => 'filters-toggle', 'class' => 'filters-toggle-btn',
            'style' => 'padding: 12px 20px; background: #f1f3f5; cursor: pointer; font-weight: 600; font-size: 15px; border-bottom: 1px solid #dee2e6; display: flex; justify-content: space-between; align-items: center; user-select: none;'));

    echo html_writer::start_div('filters-content', array('id' => 'filters-content', 'style' => 'display: none; padding: 15px;'));

    // Селектор когорты
    if (!empty($cohorts)) {
        echo html_writer::start_div('mb-3');
        echo html_writer::label('Глобальная группа', 'cohort-select', false, ['class' => 'form-label fw-bold']);
        $cohort_options = [0 => 'Все участники'];
        foreach ($cohorts as $cohort) {
            $cohort_options[$cohort->id] = format_string($cohort->name);
        }
        echo html_writer::select($cohort_options, 'cohort', $current_cohort_id, false,
            ['id' => 'cohort-select', 'class' => 'form-select', 'style' => 'max-width: 400px;']);
        echo html_writer::end_div();
    }

    // Фильтры по буквам
    $alphabet = array('А','Б','В','Г','Д','Е','Ё','Ж','З','И','К','Л','М','Н','О','П','Р','С','Т','У','Ф','Х','Ц','Ч','Ш','Щ','Э','Ю','Я');

    echo html_writer::tag('div', '<strong>Имя</strong>', array('style' => 'margin-bottom: 8px;'));
    $active_class = (empty($filter_name)) ? 'btn-primary' : 'btn-outline-secondary';
    echo html_writer::tag('button', 'Все', array('type' => 'button', 'id' => 'name-all-btn',
        'class' => 'btn btn-sm ' . $active_class, 'style' => 'min-width: 40px; margin-bottom: 10px; margin-right: 10px;'));
    echo html_writer::start_div('filter-buttons', array('style' => 'display: flex; flex-wrap: wrap; gap: 4px; margin-bottom: 20px;'));
    foreach ($alphabet as $letter) {
        $active_class = ($filter_name === $letter) ? 'btn-primary' : 'btn-outline-secondary';
        echo html_writer::tag('button', $letter, array('type' => 'button',
            'class' => 'btn btn-sm ' . $active_class . ' name-filter-btn', 'data-letter' => $letter,
            'style' => 'min-width: 35px; width: 35px; padding: 4px 0; text-align: center;'));
    }
    echo html_writer::end_div();

    echo html_writer::tag('div', '<strong>Фамилия</strong>', array('style' => 'margin-bottom: 8px;'));
    $active_class = (empty($filter_surname)) ? 'btn-primary' : 'btn-outline-secondary';
    echo html_writer::tag('button', 'Все', array('type' => 'button', 'id' => 'surname-all-btn',
        'class' => 'btn btn-sm ' . $active_class, 'style' => 'min-width: 40px; margin-bottom: 10px; margin-right: 10px;'));
    echo html_writer::start_div('filter-buttons', array('style' => 'display: flex; flex-wrap: wrap; gap: 4px;'));
    foreach ($alphabet as $letter) {
        $active_class = ($filter_surname === $letter) ? 'btn-primary' : 'btn-outline-secondary';
        echo html_writer::tag('button', $letter, array('type' => 'button',
            'class' => 'btn btn-sm ' . $active_class . ' surname-filter-btn', 'data-letter' => $letter,
            'style' => 'min-width: 35px; width: 35px; padding: 4px 0; text-align: center;'));
    }
    echo html_writer::end_div();

    echo html_writer::end_div();
    echo html_writer::end_div();
}

// === БЛОК ПОИСКА ===
if ($can_take) {
    echo html_writer::start_div('student-search-container', array(
        'style' => 'margin-bottom: 20px; display: flex; gap: 10px; align-items: center; flex-wrap: wrap;'));
    echo html_writer::tag('input', '', array('type' => 'text', 'id' => 'student-search',
        'class' => 'student-search-input', 'placeholder' => '🔍 Поиск...',
        'autocomplete' => 'off', 'value' => s($search),
        'style' => 'flex: 2; min-width: 250px; padding: 8px 12px; border: 1px solid #ced4da; border-radius: 4px;'));
    echo html_writer::tag('button', 'Очистить', array('id' => 'student-search-clear',
        'class' => 'btn btn-secondary', 'type' => 'button'));
    $display_count = count($students);
    echo html_writer::tag('span', "{$display_count} студентов", array('id' => 'student-count',
        'style' => 'background: #4800B4; color: white; padding: 5px 12px; border-radius: 20px; font-size: 13px;'));
    echo html_writer::end_div();
}

// === ТАБЛИЦА ===
echo html_writer::start_tag('form', array('method' => 'post',
    'action' => new moodle_url('/mod/studentattendance/view.php', array('id' => $cm->id))));
echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()));

$table = new html_table();
$table->id = 'attendance-table';
$table->attributes['class'] = 'generaltable';
$table->head = array(get_string('student', 'studentattendance'));

foreach ($current_sessions as $session) {
    $date_str = userdate($session->sessiondate, '%d.%m');

    // Метки когорт
    $cohort_names = array();
    foreach ($session->cohort_sessions as $cs) {
        if ($cs->cohortid > 0) {
            $cohort = $DB->get_record('cohort', array('id' => $cs->cohortid));
            if ($cohort) $cohort_names[] = format_string($cohort->name);
        }
    }
    if (!empty($cohort_names)) {
        $date_str .= '<br><small class="text-muted">' . implode(', ', $cohort_names) . '</small>';
    }

    if (!empty($session->weektype)) {
        $type_label = ($session->weektype === 'N') ? get_string('weektype_n', 'studentattendance') : get_string('weektype_d', 'studentattendance');
        $date_str .= '<br><small>(' . $type_label . ')</small>';
    }

    if (date('Y-m-d', $session->sessiondate) == date('Y-m-d', $today)) {
        $date_str = html_writer::tag('span', $date_str, array('class' => 'text-danger fw-bold', 'style' => 'font-size: 1.15em;'));
    }

    $week_class = ($session->weektype === 'N') ? 'numerator-cell' : 'denominator-cell';
    $table->head[] = html_writer::tag('div', $date_str, [
        'class' => $week_class,
        'style' => "width: 100%; height: 100%; min-height: 60px; display: flex; align-items: center; justify-content: center; flex-direction: column;"
    ]);
}

$is_grading = !empty($studentattendance->grade_enabled) && $studentattendance->max_grade > 0;
if ($is_grading) {
    $table->head[] = html_writer::tag('div',
        get_string('grade_calculated', 'studentattendance') . html_writer::empty_tag('br') .
        html_writer::tag('small', get_string('attendancepercentage', 'studentattendance'), ['class' => 'text-muted fw-normal']),
        ['class' => 'text-center py-2']);
} else {
    $table->head[] = html_writer::tag('div', get_string('attendancepercentage', 'studentattendance'), ['class' => 'text-center py-2']);
}

foreach ($students as $student) {
    $row = array();
    $fullname = fullname($student);

    // Получаем когорту студента
    $student_cohorts = cohort_get_user_cohorts($student->id);
    $student_cohort_ids = array_map(function($c) { return $c->id; }, $student_cohorts);
    $student_cohort_id = !empty($student_cohorts) ? reset($student_cohorts)->id : 0;

    $row[] = html_writer::tag('span', $fullname, array(
        'class' => 'student-name',
        'data-student-id' => $student->id,
        'data-cohortid' => $student_cohort_id,
        'data-firstname' => mb_strtolower($student->firstname),
        'data-lastname' => mb_strtolower($student->lastname),
        'data-email' => mb_strtolower($student->email),
        'data-fullname' => mb_strtolower($fullname)
    ));

    foreach ($current_sessions as $session) {
        // Находим реальную сессию для этого студента
        $target_real_session_id = null;
        $can_mark_this = false;

        foreach ($session->cohort_sessions as $cs) {
            if ($cs->cohortid == 0 || in_array($cs->cohortid, $student_cohort_ids)) {
                $target_real_session_id = $cs->id;
                $can_mark_this = true;
                break;
            }
        }

        // Получаем статус из records по РЕАЛЬНОМУ sessionid
        $current_status = 'A';
        if ($target_real_session_id) {
            $key = $target_real_session_id . '-' . $student->id;
            $current_status = isset($records[$key]) ? $records[$key] : 'A';
        }

        $week_class = ($session->weektype === 'N') ? 'numerator-cell' : 'denominator-cell';

        if ($can_take && $can_mark_this) {
            $checkbox = html_writer::checkbox(
                "status[{$session->id}][{$student->id}]", '1',
                ($current_status == 'P'), '',
                ['class' => 'form-check-input attendance-checkbox', 'id' => "chk_{$session->id}_{$student->id}"]
            );
            $row[] = html_writer::tag('div', $checkbox, [
                'class' => "checkbox-cell {$week_class}"
            ]);
        } else if (!$can_take) {
            if ($current_status == 'P') {
                $icon_html = $OUTPUT->pix_icon('present', 'Присутствует', 'studentattendance', 
                    array('class' => 'attendance-icon'));
            } else {
                $icon_html = $OUTPUT->pix_icon('absent', 'Отсутствует', 'studentattendance', 
                    array('class' => 'attendance-icon'));
            }
            
            $row[] = html_writer::tag('div', $icon_html, [
                'class' => "checkbox-cell {$week_class}"
            ]);
        } else {
            $row[] = html_writer::tag('div', '', [
                'class' => "checkbox-cell {$week_class}"
            ]);
        }
    }
    $pct = isset($percentage_map[$student->id]) ? $percentage_map[$student->id] : 0;

    if ($is_grading) {
        $raw_grade = ($pct / 100) * $studentattendance->max_grade;
        $grade = round($raw_grade * 4) / 4;
        $grade = round($grade, 2);
        $row[] = html_writer::tag('div',
            html_writer::tag('strong', $grade, array('class' => 'text-primary')) .
            html_writer::empty_tag('br') .
            html_writer::tag('small', $pct . '%', array('class' => 'text-muted')),
            array('class' => 'text-center'));
    } else {
        $row[] = html_writer::tag('strong', $pct . '%', array('class' => 'text-primary'));
    }

    $table->data[] = $row;
}

echo html_writer::table($table);

if ($can_take) {
    echo html_writer::tag('button', get_string('savechanges', 'studentattendance'),
        array('type' => 'submit', 'class' => 'btn btn-primary btn-lg mt-3'));
}
echo html_writer::end_tag('form');

// === JAVASCRIPT ===
$js = <<<EOD
<script>
// === localStorage для сохранения отметок между страницами ===
var STORAGE_KEY = 'attendance_{$cm->id}';

// Загружаем сохранённые отметки из localStorage
function loadSavedAttendance() {
    var saved = localStorage.getItem(STORAGE_KEY);
    return saved ? JSON.parse(saved) : {};
}

// Сохраняем отметки в localStorage
function saveAttendanceToStorage(data) {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(data));
}

// Получаем начальное состояние чекбоксов (из БД)
function getInitialState() {
    var initialState = {};
    var checkboxes = document.querySelectorAll('.attendance-checkbox');
    
    checkboxes.forEach(function(cb) {
        var name = cb.getAttribute('name');
        var match = name.match(/status\\[(\\d+)\\]\\[(\\d+)\\]/);
        if (match) {
            var key = match[1] + '-' + match[2];
            initialState[key] = cb.checked ? '1' : '0';
        }
    });
    
    return initialState;
}

// Применяем сохранённые отметки из localStorage к чекбоксам
function applySavedAttendance() {
    var saved = loadSavedAttendance();
    var checkboxes = document.querySelectorAll('.attendance-checkbox');
    
    checkboxes.forEach(function(cb) {
        var name = cb.getAttribute('name');
        var match = name.match(/status\\[(\\d+)\\]\\[(\\d+)\\]/);
        if (match) {
            var key = match[1] + '-' + match[2];
            if (saved.hasOwnProperty(key)) {
                cb.checked = (saved[key] === '1');
            }
        }
    });
}

// Проверяем, есть ли несохранённые изменения
function hasUnsavedChanges() {
    var saved = loadSavedAttendance();
    var initialState = getInitialState();
    var checkboxes = document.querySelectorAll('.attendance-checkbox');
    var hasChanges = false;
    
    checkboxes.forEach(function(cb) {
        var name = cb.getAttribute('name');
        var match = name.match(/status\\[(\\d+)\\]\\[(\\d+)\\]/);
        if (match) {
            var key = match[1] + '-' + match[2];
            var currentState = cb.checked ? '1' : '0';
            var originalState = initialState[key] || '0';
            var savedState = saved[key];
            
            // Если есть сохранённое в localStorage состояние
            if (savedState !== undefined) {
                if (savedState !== currentState) {
                    hasChanges = true;
                }
            } else if (currentState !== originalState) {
                // Если изменили относительно начального состояния
                hasChanges = true;
            }
        }
    });
    
    return hasChanges;
}

// Считаем количество несохранённых изменений
function countUnsavedChanges() {
    var saved = loadSavedAttendance();
    var initialState = getInitialState();
    var checkboxes = document.querySelectorAll('.attendance-checkbox');
    var count = 0;
    
    checkboxes.forEach(function(cb) {
        var name = cb.getAttribute('name');
        var match = name.match(/status\\[(\\d+)\\]\\[(\\d+)\\]/);
        if (match) {
            var key = match[1] + '-' + match[2];
            var currentState = cb.checked ? '1' : '0';
            var originalState = initialState[key] || '0';
            var savedState = saved[key];
            
            // Если есть изменение относительно начального состояния
            if (currentState !== originalState) {
                count++;
            }
        }
    });
    
    return count;
}

// Сохраняем изменение чекбокса в localStorage
function saveCheckboxToStorage(checkbox) {
    var saved = loadSavedAttendance();
    var name = checkbox.getAttribute('name');
    var match = name.match(/status\\[(\\d+)\\]\\[(\\d+)\\]/);
    if (match) {
        var key = match[1] + '-' + match[2];
        // Сохраняем ВСЕ изменения, включая снятие отметки
        saved[key] = checkbox.checked ? '1' : '0';
        saveAttendanceToStorage(saved);
        updateSaveIndicator();
    }
}

// Собираем все изменения для отправки на сервер
function collectAllChanges() {
    var saved = loadSavedAttendance();
    var changes = {};
    
    // Берём ВСЕ изменения из localStorage (со всех страниц)
    for (var key in saved) {
        changes[key] = saved[key];
    }
    
    // Добавляем текущие значения чекбоксов (они могут быть изменены)
    var checkboxes = document.querySelectorAll('.attendance-checkbox');
    checkboxes.forEach(function(cb) {
        var name = cb.getAttribute('name');
        var match = name.match(/status\[(\d+)\]\[(\d+)\]/);
        if (match) {
            var key = match[1] + '-' + match[2];
            changes[key] = cb.checked ? '1' : '0';
        }
    });
    
    return changes;
}

// === ЛОКАЛЬНЫЙ ПОИСК ПО ТЕКСТУ ===
(function() {
    var searchInput = document.getElementById('student-search');
    var clearButton = document.getElementById('student-search-clear');
    var studentCountSpan = document.getElementById('student-count');
    var table = document.getElementById('attendance-table');

    if (!searchInput || !table) return;

    var rows = table.querySelectorAll('tbody tr');
    var totalRows = rows.length;

    function filterStudentsLocal() {
        var searchTerm = searchInput.value.trim().toLowerCase();
        var visibleCount = 0;

        for (var i = 0; i < rows.length; i++) {
            var row = rows[i];
            var nameSpan = row.querySelector('td:first-child .student-name');
            if (!nameSpan) continue;

            var firstName = nameSpan.getAttribute('data-firstname') || '';
            var lastName = nameSpan.getAttribute('data-lastname') || '';
            var email = nameSpan.getAttribute('data-email') || '';
            var fullName = nameSpan.getAttribute('data-fullname') || '';

            var searchableText = (firstName + ' ' + lastName + ' ' + email + ' ' + fullName).toLowerCase();
            var isMatch = (searchTerm === '') || (searchableText.indexOf(searchTerm) !== -1);

            var isHiddenByLetter = row.style.display === 'none' && row.classList.contains('hidden-by-letter');

            if (isMatch && !isHiddenByLetter) {
                row.style.display = '';
                visibleCount++;
            } else {
                row.style.display = 'none';
            }
        }

        if (studentCountSpan) {
            if (searchTerm === '') {
                studentCountSpan.innerHTML = totalRows + ' студентов';
                studentCountSpan.style.background = '#4800B4';
            } else {
                studentCountSpan.innerHTML = 'Найдено: ' + visibleCount;
                studentCountSpan.style.background = visibleCount > 0 ? '#28a745' : '#ca3120';
            }
        }
    }

    var debounceTimer;
    searchInput.addEventListener('input', function() {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(filterStudentsLocal, 150);
    });

    if (clearButton) {
        clearButton.addEventListener('click', function() {
            searchInput.value = '';
            filterStudentsLocal();
            searchInput.focus();
        });
    }

    if (searchInput.value !== '') filterStudentsLocal();
})();

// === ФИЛЬТРАЦИЯ ПО БУКВАМ ===
var activeName = '';
var activeSurname = '';

function filterByLetters() {
    var table = document.getElementById('attendance-table');
    var studentCountSpan = document.getElementById('student-count');
    var cohortSelect = document.getElementById('cohort-select');
    if (!table) return;

    var selectedCohort = cohortSelect ? parseInt(cohortSelect.value) : 0;
    var rows = table.querySelectorAll('tbody tr');
    var visibleCount = 0;

    rows.forEach(function(row) {
        var nameSpan = row.querySelector('td:first-child .student-name');
        if (!nameSpan) return;

        var cohortId = nameSpan.getAttribute('data-cohortid');
        var firstName = nameSpan.getAttribute('data-firstname') || '';
        var lastName = nameSpan.getAttribute('data-lastname') || '';

        var firstCharName = firstName.charAt(0).toUpperCase();
        var firstCharSurname = lastName.charAt(0).toUpperCase();

        var cohortMatch = (selectedCohort === 0 || cohortId == selectedCohort);
        var nameMatch = (activeName === '' || firstCharName === activeName);
        var surnameMatch = (activeSurname === '' || firstCharSurname === activeSurname);

        if (cohortMatch && nameMatch && surnameMatch) {
            row.style.display = '';
            row.classList.remove('hidden-by-letter');
            visibleCount++;
        } else {
            row.style.display = 'none';
            row.classList.add('hidden-by-letter');
        }
    });

    if (studentCountSpan) {
        var cohortText = selectedCohort > 0 ? ' в группе' : '';
        studentCountSpan.innerHTML = visibleCount + ' студентов' + cohortText;
        studentCountSpan.style.background = visibleCount > 0 ? '#4800B4' : '#ca3120';
    }
}

document.querySelectorAll('.name-filter-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var letter = this.getAttribute('data-letter');
        if (activeName === letter) {
            activeName = '';
            this.classList.remove('btn-primary');
            this.classList.add('btn-outline-secondary');
        } else {
            document.querySelectorAll('.name-filter-btn').forEach(function(b) {
                b.classList.remove('btn-primary');
                b.classList.add('btn-outline-secondary');
            });
            activeName = letter;
            this.classList.remove('btn-outline-secondary');
            this.classList.add('btn-primary');
        }
        var nameAllBtn = document.getElementById('name-all-btn');
        if (nameAllBtn) {
            if (activeName === '') {
                nameAllBtn.classList.remove('btn-outline-secondary');
                nameAllBtn.classList.add('btn-primary');
            } else {
                nameAllBtn.classList.remove('btn-primary');
                nameAllBtn.classList.add('btn-outline-secondary');
            }
        }
        filterByLetters();
    });
});

document.querySelectorAll('.surname-filter-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var letter = this.getAttribute('data-letter');
        if (activeSurname === letter) {
            activeSurname = '';
            this.classList.remove('btn-primary');
            this.classList.add('btn-outline-secondary');
        } else {
            document.querySelectorAll('.surname-filter-btn').forEach(function(b) {
                b.classList.remove('btn-primary');
                b.classList.add('btn-outline-secondary');
            });
            activeSurname = letter;
            this.classList.remove('btn-outline-secondary');
            this.classList.add('btn-primary');
        }
        var surnameAllBtn = document.getElementById('surname-all-btn');
        if (surnameAllBtn) {
            if (activeSurname === '') {
                surnameAllBtn.classList.remove('btn-outline-secondary');
                surnameAllBtn.classList.add('btn-primary');
            } else {
                surnameAllBtn.classList.remove('btn-primary');
                surnameAllBtn.classList.add('btn-outline-secondary');
            }
        }
        filterByLetters();
    });
});

var nameAllBtn = document.getElementById('name-all-btn');
if (nameAllBtn) {
    nameAllBtn.addEventListener('click', function() {
        activeName = '';
        document.querySelectorAll('.name-filter-btn').forEach(function(b) {
            b.classList.remove('btn-primary');
            b.classList.add('btn-outline-secondary');
        });
        this.classList.remove('btn-outline-secondary');
        this.classList.add('btn-primary');
        filterByLetters();
    });
}

var surnameAllBtn = document.getElementById('surname-all-btn');
if (surnameAllBtn) {
    surnameAllBtn.addEventListener('click', function() {
        activeSurname = '';
        document.querySelectorAll('.surname-filter-btn').forEach(function(b) {
            b.classList.remove('btn-primary');
            b.classList.add('btn-outline-secondary');
        });
        this.classList.remove('btn-outline-secondary');
        this.classList.add('btn-primary');
        filterByLetters();
    });
}

var cohortSelect = document.getElementById('cohort-select');
if (cohortSelect) {
    cohortSelect.addEventListener('change', function() {
        filterByLetters();
    });
}

// === СВОРАЧИВАЕМЫЙ БЛОК ОТБОРОВ ===
var filtersToggle = document.getElementById('filters-toggle');
var filtersContent = document.getElementById('filters-content');

if (filtersToggle && filtersContent) {
    var hasActiveFilters = (activeName !== '' || activeSurname !== '' ||
        (cohortSelect && cohortSelect.value !== '0'));

    if (hasActiveFilters) {
        filtersContent.style.display = 'block';
        filtersToggle.classList.add('filters-open');
    }

    filtersToggle.addEventListener('click', function() {
        if (filtersContent.style.display === 'none' || filtersContent.style.display === '') {
            filtersContent.style.display = 'block';
            this.classList.add('filters-open');
        } else {
            filtersContent.style.display = 'none';
            this.classList.remove('filters-open');
        }
    });
}

// === УВЕЛИЧЕНИЕ ЧЕКБОКСОВ + СОХРАНЕНИЕ В localStorage ===
var checkboxes = document.querySelectorAll('.path-mod-studentattendance .generaltable input[type="checkbox"]');
for (var i = 0; i < checkboxes.length; i++) {
    var cb = checkboxes[i];
    cb.style.transform = 'scale(1.3)';
    cb.style.margin = '0 auto';
    cb.style.cursor = 'pointer';

    cb.addEventListener('change', function() {
        saveCheckboxToStorage(this);
    });

    var cell = cb.closest('td');
    if (cell && !cell.hasAttribute('data-click-bound')) {
        cell.setAttribute('data-click-bound', 'true');
        cell.style.cursor = 'pointer';
        cell.addEventListener('click', function(e) {
            if (e.target.tagName !== 'INPUT') {
                var checkbox = this.querySelector('input[type="checkbox"]');
                if (checkbox) {
                    checkbox.checked = !checkbox.checked;
                    checkbox.dispatchEvent(new Event('change', { bubbles: true }));
                }
            }
        });
    }
}

// === ПРИМЕНЯЕМ СОХРАНЁННЫЕ ОТМЕТКИ ПРИ ЗАГРУЗКЕ ===
applySavedAttendance();

// === ИНДИКАТОР НЕСОХРАНЁННЫХ ИЗМЕНЕНИЙ (БАДЖ ПОСЛЕ КНОПКИ) ===
function updateSaveIndicator() {
    var count = countUnsavedChanges();
    var badge = document.getElementById('unsaved-badge');
    var saveBtn = document.querySelector('button[type="submit"]');
    
    if (!saveBtn) return;
    
    if (count > 0) {
        if (!badge) {
            badge = document.createElement('span');
            badge.id = 'unsaved-badge';
            badge.style.cssText = 'display: inline-flex; align-items: center; justify-content: center; min-width: 22px; height: 22px; margin-left: 10px; padding: 0 6px; background: #ffc107; color: #000; border-radius: 50%; font-size: 12px; font-weight: bold; vertical-align: middle;';
            saveBtn.parentNode.insertBefore(badge, saveBtn.nextSibling);
        }
        badge.textContent = count;
        badge.style.display = 'inline-flex';
    } else {
        if (badge) {
            badge.style.display = 'none';
        }
    }
}

// Показываем индикатор при загрузке
updateSaveIndicator();

// === ПЕРЕХВАТЫВАЕМ ОТПРАВКУ ФОРМЫ ===
var form = document.querySelector('form[method="post"]');
if (form) {
    form.addEventListener('submit', function(e) {
        // Собираем ВСЕ изменения из localStorage (со всех страниц)
        var saved = loadSavedAttendance();
        
        // Удаляем старые чекбоксы из формы
        var oldCheckboxes = form.querySelectorAll('input[name^="status["]');
        oldCheckboxes.forEach(function(cb) {
            cb.remove();
        });
        
        // Добавляем скрытые поля со ВСЕМИ изменениями из localStorage
        for (var key in saved) {
            var match = key.match(/^(\d+)-(\d+)$/);
            if (match) {
                var sessionId = match[1];
                var studentId = match[2];
                var value = saved[key];
                
                var hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.name = 'status[' + sessionId + '][' + studentId + ']';
                hidden.value = value;
                form.appendChild(hidden);
            }
        }
        
        // Очищаем localStorage после отправки
        localStorage.removeItem(STORAGE_KEY);
    });
}
</script>
EOD;

echo $js;
echo $OUTPUT->footer();