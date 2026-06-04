<?php
// ЭТА СТРОКА ОБЯЗАТЕЛЬНА И ДОЛЖНА БЫТЬ ПЕРВОЙ!
require_once(__DIR__ . '/../../config.php'); 

// Только ПОСЛЕ неё можно подключать остальные файлы и использовать функции Moodle
require_once($CFG->dirroot . '/mod/studentattendance/classes/manager.php');
require_once($CFG->dirroot . '/cohort/lib.php');

// Дальше идет ваш код с ini_set и параметрами...
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$id = required_param('id', PARAM_INT);
$page_key = optional_param('page', null, PARAM_ALPHANUMEXT); 
$search = optional_param('search', '', PARAM_TEXT);
$filter_name = optional_param('filter_name', '', PARAM_TEXT);
$filter_surname = optional_param('filter_surname', '', PARAM_TEXT);
$apply = optional_param('apply', 0, PARAM_INT);

$cm = get_coursemodule_from_id('studentattendance', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', array('id' => $cm->course), '*', MUST_EXIST); // <-- СНАЧАЛА КУРС
$studentattendance = $DB->get_record('studentattendance', array('id' => $cm->instance), '*', MUST_EXIST);

require_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/studentattendance:view', $context);

$can_take = has_capability('mod/studentattendance:take', $context);

// // Получаем ЛОКАЛЬНЫЕ группы курса
// $current_group_id = optional_param('group', 0, PARAM_INT);
// $groups = groups_get_all_groups($course->id);

// Получаем ГЛОБАЛЬНЫЕ группы (когорты)
$current_cohort_id = optional_param('cohort', 0, PARAM_INT);
$course_context = context_course::instance($course->id);
if (has_capability('moodle/cohort:view', context_system::instance())) {
    $cohorts = cohort_get_available_cohorts($course_context, 0, 0, 0);
} else {
    $cohorts = array();
}

// Для отладки - раскомментируйте если группы не показываются
// echo "<pre>Groups: "; print_r($groups); echo "</pre>";

// Настройка страницы
$PAGE->set_url('/mod/studentattendance/view.php', array('id' => $cm->id));
if ($page_key !== null) $PAGE->url->param('page', $page_key);
if ($search !== '') $PAGE->url->param('search', $search);
if ($filter_name !== '') $PAGE->url->param('filter_name', $filter_name);
if ($filter_surname !== '') $PAGE->url->param('filter_surname', $filter_surname);
if ($current_cohort_id > 0) $PAGE->url->param('cohort', $current_cohort_id);

$PAGE->set_title(format_string($studentattendance->name));
$PAGE->set_heading(format_string($course->fullname));

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
        studentattendance_update_all_grades($studentattendance);
    }
    redirect(new moodle_url('/mod/studentattendance/view.php', array('id' => $cm->id)), 
             get_string('attendancesaved', 'studentattendance'));
}

// ===== AJAX ОБРАБОТЧИК =====
// if (optional_param('ajax', 0, PARAM_INT) == 1) {
//     header('Content-Type: application/json; charset=utf-8');
    
//     $ajax_filter_name = optional_param('filter_name', '', PARAM_TEXT);
//     $ajax_filter_surname = optional_param('filter_surname', '', PARAM_TEXT);
//     $ajax_group = optional_param('group', 0, PARAM_INT);
//     $ajax_search = optional_param('search', '', PARAM_TEXT);
    
//     $allusers = get_enrolled_users($context, '', 0, 'u.*', 'u.lastname ASC');
//     $filtered_students = array();
    
//     foreach ($allusers as $user) {
//         if (has_capability('mod/studentattendance:take', $context, $user->id)) {
//             continue;
//         }
        
//         if ($ajax_group > 0 && !groups_is_member($ajax_group, $user->id)) {
//             continue;
//         }
        
//         $firstname_char = !empty($user->firstname) ? mb_strtoupper(mb_substr($user->firstname, 0, 1)) : '';
//         $lastname_char = !empty($user->lastname) ? mb_strtoupper(mb_substr($user->lastname, 0, 1)) : '';
        
//         $name_match = ($ajax_filter_name === '' || $firstname_char === $ajax_filter_name);
//         $surname_match = ($ajax_filter_surname === '' || $lastname_char === $ajax_filter_surname);
        
//         $search_match = true;
//         if ($ajax_search !== '') {
//             $search_lower = mb_strtolower($ajax_search);
//             $searchable = mb_strtolower($user->firstname . ' ' . $user->lastname . ' ' . $user->email);
//             $search_match = (strpos($searchable, $search_lower) !== false);
//         }
        
//         if ($name_match && $surname_match && $search_match) {
//             $filtered_students[] = array(
//                 'id' => $user->id,
//                 'fullname' => fullname($user)
//             );
//         }
//     }
    
//     echo json_encode(array(
//         'students' => $filtered_students,
//         'count' => count($filtered_students),
//         'total' => count($allusers)
//     ));
//     exit;
// }

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
$filtered_students = array();

foreach ($allusers as $user) {
    // Исключаем преподавателей
    if (has_capability('mod/studentattendance:take', $context, $user->id)) {
        continue;
    }
    
    // Если текущий пользователь - студент, показываем только его
    if (!$can_take && $user->id != $USER->id) {
        continue;
    }
    
    // Фильтр по когорте (глобальной группе)
    if ($current_cohort_id > 0) {
        $user_cohorts = cohort_get_user_cohorts($user->id);
        $user_cohort_ids = array_map(function($c) { return $c->id; }, $user_cohorts);
        if (!in_array($current_cohort_id, $user_cohort_ids)) {
            continue;
        }
    }
    
    // Фильтр по буквам (только если нажата кнопка "Применить")
    if ($apply) {
        $firstname_char = !empty($user->firstname) ? mb_strtoupper(mb_substr($user->firstname, 0, 1)) : '';
        $lastname_char = !empty($user->lastname) ? mb_strtoupper(mb_substr($user->lastname, 0, 1)) : '';
        
        $name_match = ($filter_name === '' || $firstname_char === $filter_name);
        $surname_match = ($filter_surname === '' || $lastname_char === $filter_surname);
        
        if (!$name_match || !$surname_match) {
            continue;
        }
    }
    
    $filtered_students[$user->id] = $user;
}
$students = $filtered_students;

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

// $can_take = has_capability('mod/studentattendance:take', $context);

// ===== НАВИГАЦИЯ ПО МЕСЯЦАМ =====
if (count($sessions_by_month) > 1) {
    echo html_writer::start_div('d-flex justify-content-center gap-2 mb-3 flex-wrap bg-white py-2', 
        array('style' => 'border-bottom: 1px solid #dee2e6;'));
    foreach ($sessions_by_month as $mk => $mdata) {
        $url = new moodle_url('/mod/studentattendance/view.php', array(
            'id' => $cm->id, 
            'page' => $mk,
            // 'group' => $current_group_id,      // Локальная группа
            'cohort' => $current_cohort_id,    // Глобальная группа
            'search' => $search,
            'filter_name' => $filter_name,
            'filter_surname' => $filter_surname
        ));
        $attrs = array('href' => $url, 'class' => 'btn btn-outline-secondary btn-sm');
        if ($mk === $selected_month_key) {
            $attrs['class'] = 'btn btn-primary btn-sm active';
            $attrs['aria-current'] = 'page';
        }
        echo html_writer::tag('a', $mdata['label'], $attrs);
    }
    echo html_writer::end_div();
}

// ===== БЛОК ОТБОРОВ (СВОРАЧИВАЕМЫЙ) =====
if ($can_take) {
    echo html_writer::start_div('attendance-filters-wrapper', array(
        'style' => 'margin-bottom: 20px; border: 1px solid #dee2e6; border-radius: 8px; overflow: hidden; background: #f8f9fa;'
    ));

    // Заголовок-кнопка
    echo html_writer::tag('div', 
        '<span>Отборы</span><span class="toggle-arrow"></span>', 
        array(
            'id' => 'filters-toggle',
            'class' => 'filters-toggle-btn',
            'style' => 'padding: 12px 20px; background: #f1f3f5; cursor: pointer; font-weight: 600; font-size: 15px; border-bottom: 1px solid #dee2e6; display: flex; justify-content: space-between; align-items: center; user-select: none; transition: background-color 0.2s;'
        )
    );

    // Раскрывающийся контент
    echo html_writer::start_div('filters-content', array(
        'id' => 'filters-content',
        'style' => 'display: none; padding: 15px;'
    ));

    // --- Селектор глобальной группы ---
    if (!empty($cohorts)) {
        echo html_writer::start_div('mb-3');
        echo html_writer::label('Группа ', 'cohort-select', false, ['class' => 'form-label fw-bold']);
        
        $cohort_options = [0 => 'Все участники'];
        foreach ($cohorts as $cohort) {
            $cohort_options[$cohort->id] = format_string($cohort->name);
        }
        
        echo html_writer::select(
            $cohort_options,
            'cohort',
            $current_cohort_id,
            false,
            [
                'id' => 'cohort-select',
                'class' => 'form-select',
                'style' => 'max-width: 400px;'
            ]
        );
        echo html_writer::end_div();
    }

    // --- Фильтр по имени ---
    $alphabet = array('А','Б','В','Г','Д','Е','Ё','Ж','З','И','К','Л','М','Н','О','П','Р','С','Т','У','Ф','Х','Ц','Ч','Ш','Щ','Э','Ю','Я');

    echo html_writer::tag('div', '<strong>Имя</strong>', array('style' => 'margin-bottom: 8px;'));

    $active_class = (empty($filter_name)) ? 'btn-primary' : 'btn-outline-secondary';
    echo html_writer::tag('button', 'Все', array(
        'type' => 'button',
        'id' => 'name-all-btn',
        'class' => 'btn btn-sm ' . $active_class,
        'style' => 'min-width: 40px; margin-bottom: 10px; display: inline-block; margin-right: 10px;'
    ));

    echo html_writer::start_div('filter-buttons', array('style' => 'display: flex; flex-wrap: wrap; gap: 4px; margin-bottom: 20px;'));
    foreach ($alphabet as $letter) {
        $active_class = ($filter_name === $letter) ? 'btn-primary' : 'btn-outline-secondary';
        echo html_writer::tag('button', $letter, array(
            'type' => 'button',
            'class' => 'btn btn-sm ' . $active_class . ' name-filter-btn',
            'data-letter' => $letter,
            'style' => 'min-width: 35px; width: 35px; padding: 4px 0; text-align: center;'
        ));
    }
    echo html_writer::end_div();

    // --- Фильтр по фамилии ---
    echo html_writer::tag('div', '<strong>Фамилия</strong>', array('style' => 'margin-bottom: 8px;'));

    $active_class = (empty($filter_surname)) ? 'btn-primary' : 'btn-outline-secondary';
    echo html_writer::tag('button', 'Все', array(
        'type' => 'button',
        'id' => 'surname-all-btn',
        'class' => 'btn btn-sm ' . $active_class,
        'style' => 'min-width: 40px; margin-bottom: 10px; display: inline-block; margin-right: 10px;'
    ));

    echo html_writer::start_div('filter-buttons', array('style' => 'display: flex; flex-wrap: wrap; gap: 4px;'));
    foreach ($alphabet as $letter) {
        $active_class = ($filter_surname === $letter) ? 'btn-primary' : 'btn-outline-secondary';
        echo html_writer::tag('button', $letter, array(
            'type' => 'button',
            'class' => 'btn btn-sm ' . $active_class . ' surname-filter-btn',
            'data-letter' => $letter,
            'style' => 'min-width: 35px; width: 35px; padding: 4px 0; text-align: center;'
        ));
    }
    echo html_writer::end_div();

    echo html_writer::end_div(); // конец filters-content
    echo html_writer::end_div(); // конец attendance-filters-wrapper
}

// ===== БЛОК ПОИСКА И ГРУПП =====
if ($can_take) {
    echo html_writer::start_div('student-search-container', array(
        'style' => 'margin-bottom: 20px; display: flex; gap: 10px; align-items: center; flex-wrap: wrap;'
    ));

    // Поле текстового поиска
    echo html_writer::tag('input', '', array(
        'type' => 'text',
        'id' => 'student-search',
        'class' => 'student-search-input',
        'placeholder' => '🔍 Поиск по фамилии, имени или email...',
        'autocomplete' => 'off',
        'value' => s($search),
        'style' => 'flex: 2; min-width: 250px; padding: 8px 12px; border: 1px solid #ced4da; border-radius: 4px;'
    ));

    echo html_writer::tag('button', 'Очистить', array(
        'id' => 'student-search-clear',
        'class' => 'btn btn-secondary',
        'type' => 'button'
    ));

    // Счетчик студентов
    if ($can_take) {
        $display_count = count($students);
        $count_text = ($current_cohort_id > 0) ? "{$display_count} в группе" : "{$display_count} студентов";
        echo html_writer::tag('span', $count_text, array(
            'id' => 'student-count',
            'style' => 'background: #4800B4; color: white; padding: 5px 12px; border-radius: 20px; font-size: 13px;'
        ));
    }
    echo html_writer::end_div();
}

// ФОРМА
echo html_writer::start_tag('form', array('method' => 'post', 
    'action' => new moodle_url('/mod/studentattendance/view.php', array('id' => $cm->id))));
echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()));

$table = new html_table();
$table->id = 'attendance-table';
$table->attributes['class'] = 'generaltable';
$table->head = array(get_string('student', 'studentattendance'));

foreach ($current_sessions as $session) {
    $date_str = userdate($session->sessiondate, '%d.%m');
    
    if (!empty($session->weektype)) {
        $type_label = ($session->weektype === 'N') 
            ? get_string('weektype_n', 'studentattendance') 
            : get_string('weektype_d', 'studentattendance');
        $date_str .= '<br><small>(' . $type_label . ')</small>';
    }
    
    if (date('Y-m-d', $session->sessiondate) == date('Y-m-d', $today)) {
        $date_str = html_writer::tag('span', $date_str, array(
            'class' => 'text-danger fw-bold',
            'title' => get_string('today', 'moodle'),
            'style' => 'font-size: 1.15em;'
        ));
    }
    
    // Применяем цвет и центрирование напрямую к ячейке заголовка
    $bg_color = ($session->weektype === 'N') ? '#e3f2fd' : '#fff8e1';
    $table->head[] = html_writer::tag('div', $date_str, [
        'style' => "background-color: {$bg_color}; width: 100%; height: 100%; min-height: 40px; display: flex; align-items: center; justify-content: center; flex-direction: column;"
    ]);
}
$is_grading = !empty($studentattendance->grade_enabled) && $studentattendance->max_grade > 0;

if ($is_grading) {
    // Если оценки включены: показываем "Балл" крупно, "%" мелко
    $table->head[] = html_writer::tag('div', 
        get_string('grade_calculated', 'studentattendance') . 
        html_writer::empty_tag('br') .
        html_writer::tag('small', get_string('attendancepercentage', 'studentattendance'), 
            ['class' => 'text-muted fw-normal']),
        ['class' => 'text-center py-2']
    );
} else {
    // Если оценки выключены: показываем просто "%" или "Посещаемость"
    $table->head[] = html_writer::tag('div', 
        get_string('attendancepercentage', 'studentattendance'), 
        ['class' => 'text-center py-2']
    );
}

foreach ($students as $student) {
    $row = array();
    $fullname = fullname($student);
    
    // Получаем когорту студента (глобальную группу)
    $student_cohorts = cohort_get_user_cohorts($student->id);
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
        $key = $session->id . '-' . $student->id;
        $current_status = isset($records[$key]) ? $records[$key] : 'A';
        
        if ($can_take) {
            $checkbox = html_writer::checkbox(
                "status[{$session->id}][{$student->id}]", '1', 
                ($current_status == 'P'), '', 
                ['class' => 'form-check-input attendance-checkbox', 'id' => "chk_{$session->id}_{$student->id}"]
            );
            
            // Убираем обертку div, применяем фон и выравнивание напрямую к td
            $bg = ($session->weektype === 'N') ? '#e3f2fd' : '#fff8e1';
            $row[] = html_writer::tag('div', $checkbox, [
                'class' => 'checkbox-cell', 
                'style' => "background-color: {$bg}; width: 100%; height: 100%; min-height: 40px; display: flex; align-items: center; justify-content: center;"
            ]);
        } else {
            $row[] = ($current_status == 'P') ? '✔' : '✘';
        }
    }
    
    // Рассчитываем процент и балл
    $pct = isset($percentage_map[$student->id]) ? $percentage_map[$student->id] : 0;
    
    // Если оценивание включено, показываем балл
    if (!empty($studentattendance->grade_enabled) && $studentattendance->max_grade > 0) {
        $raw_grade = ($pct / 100) * $studentattendance->max_grade;
        $grade = round($raw_grade * 4) / 4;
        $grade = round($grade, 2);
        
        $grade_display = html_writer::tag('div', 
            html_writer::tag('strong', $grade, array('class' => 'text-primary')) . 
            html_writer::empty_tag('br') .
            html_writer::tag('small', $pct . '%', array('class' => 'text-muted')),
            array('class' => 'text-center')
        );
        $row[] = $grade_display;
    } else {
        // Если оценивание выключено, показываем только процент
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

// ===== JAVASCRIPT ДЛЯ ПОИСКА =====
$js = <<<EOD
<script>
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
            
            // Проверяем, не скрыта ли строка фильтрами по буквам
            var isHiddenByLetter = row.style.display === 'none' && row.classList.contains('hidden-by-letter');
            
            if (isMatch && !isHiddenByLetter) {
                row.style.display = '';
                row.classList.remove('hidden-by-search');
                visibleCount++;
            } else {
                row.style.display = 'none';
                row.classList.add('hidden-by-search');
            }
        }
        
        if (studentCountSpan) {
            if (searchTerm === '') {
                var totalVisible = document.querySelectorAll('#attendance-table tbody tr:not(.hidden-by-letter)').length;
                studentCountSpan.innerHTML = totalVisible + ' студентов';
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
    
    document.addEventListener('keydown', function(e) {
        if ((e.ctrlKey || e.metaKey) && e.key === 'f') {
            e.preventDefault();
            searchInput.focus();
            searchInput.select();
        }
    });
    
    if (searchInput.value !== '') {
        filterStudentsLocal();
    }
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
        
        var cohortMatch = (selectedCohort === 0 || parseInt(cohortId) === selectedCohort);
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

// Обработчики кнопок имени
document.querySelectorAll('.name-filter-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var letter = this.getAttribute('data-letter');
        
        // Toggle: если нажали уже активную кнопку - сбрасываем
        if (activeName === letter) {
            activeName = '';
            this.classList.remove('btn-primary');
            this.classList.add('btn-outline-secondary');
        } else {
            // Сбрасываем все кнопки
            document.querySelectorAll('.name-filter-btn').forEach(function(b) {
                b.classList.remove('btn-primary');
                b.classList.add('btn-outline-secondary');
            });
            // Активируем нажатую
            activeName = letter;
            this.classList.remove('btn-outline-secondary');
            this.classList.add('btn-primary');
        }
        
        // Обновляем кнопку "Все"
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

// Обработчики кнопок фамилии
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

// Кнопка "Все" для имени
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

// Кнопка "Все" для фамилии
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

// // Обработчик выбора локальной группы
// var groupSelect = document.getElementById('group-select');
// if (groupSelect) {
//     groupSelect.addEventListener('change', function() {
//         filterByLetters();
//     });
// }

// Обработчик выбора глобальной группы
var cohortSelect = document.getElementById('cohort-select');
if (cohortSelect) {
    cohortSelect.addEventListener('change', function() {
        filterByLetters();
    });
}

// === УВЕЛИЧЕНИЕ ЧЕКБОКСОВ ===
var checkboxes = document.querySelectorAll('.path-mod-studentattendance .generaltable input[type="checkbox"]');
for (var i = 0; i < checkboxes.length; i++) {
    var cb = checkboxes[i];
    cb.style.transform = 'scale(1.3)';
    cb.style.margin = '0 auto';
    cb.style.display = 'inline-block';
    cb.style.verticalAlign = 'middle';
    cb.style.cursor = 'pointer';
    cb.style.width = '18px';
    cb.style.height = '18px';
    
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


// === СВОРАЧИВАЕМЫЙ БЛОК ОТБОРОВ ===
var filtersToggle = document.getElementById('filters-toggle');
var filtersContent = document.getElementById('filters-content');

if (filtersToggle && filtersContent) {
    // Проверяем, есть ли активные фильтры
    var hasActiveFilters = (activeName !== '' || activeSurname !== '' || 
        (document.getElementById('cohort-select') && document.getElementById('cohort-select').value !== '0'));
    
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
</script>
EOD;

echo $js;

echo $OUTPUT->footer();