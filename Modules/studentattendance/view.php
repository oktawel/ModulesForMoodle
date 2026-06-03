<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/mod/studentattendance/classes/manager.php');

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

// Настройка страницы
$PAGE->set_url('/mod/studentattendance/view.php', array('id' => $cm->id));
if ($page_key !== null) $PAGE->url->param('page', $page_key);
if ($search !== '') $PAGE->url->param('search', $search);
if ($filter_name !== '') $PAGE->url->param('filter_name', $filter_name);
if ($filter_surname !== '') $PAGE->url->param('filter_surname', $filter_surname);
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

// Применяем фильтры по буквам ТОЛЬКО если нажата кнопка "Применить"
$filtered_students = array();
foreach ($students as $student) {
    if ($apply) {
        $firstname_char = !empty($student->firstname) ? mb_strtoupper(mb_substr($student->firstname, 0, 1)) : '';
        $lastname_char = !empty($student->lastname) ? mb_strtoupper(mb_substr($student->lastname, 0, 1)) : '';
        
        $name_match = ($filter_name === '' || $firstname_char === $filter_name);
        $surname_match = ($filter_surname === '' || $lastname_char === $filter_surname);
        
        if ($name_match && $surname_match) {
            $filtered_students[$student->id] = $student;
        }
    } else {
        // Показываем всех студентов
        $filtered_students[$student->id] = $student;
    }
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

$can_take = has_capability('mod/studentattendance:take', $context);

// ===== НАВИГАЦИЯ ПО МЕСЯЦАМ =====
if (count($sessions_by_month) > 1) {
    echo html_writer::start_div('d-flex justify-content-center gap-2 mb-3 flex-wrap sticky-top bg-white py-2', 
        array('style' => 'z-index: 100; border-bottom: 1px solid #dee2e6;'));
    foreach ($sessions_by_month as $mk => $mdata) {
        $url = new moodle_url('/mod/studentattendance/view.php', array('id' => $cm->id, 'page' => $mk));
        if ($search !== '') $url->param('search', $search);
        if ($filter_name !== '') $url->param('filter_name', $filter_name);
        if ($filter_surname !== '') $url->param('filter_surname', $filter_surname);
        $attrs = array('href' => $url, 'class' => 'btn btn-outline-secondary btn-sm');
        if ($mk === $selected_month_key) {
            $attrs['class'] = 'btn btn-primary btn-sm active';
            $attrs['aria-current'] = 'page';
        }
        echo html_writer::tag('a', $mdata['label'], $attrs);
    }
    echo html_writer::end_div();
}

// ===== ФИЛЬТРЫ ПО АЛФАВИТУ (РАБОТАЮЩИЕ) =====
$alphabet = array('А','Б','В','Г','Д','Е','Ё','Ж','З','И','К','Л','М','Н','О','П','Р','С','Т','У','Ф','Х','Ц','Ч','Ш','Щ','Э','Ю','Я');

echo html_writer::start_div('filter-container', array('style' => 'margin-bottom: 20px; padding: 15px; background: #f8f9fa; border-radius: 8px; border: 1px solid #dee2e6;'));

// Фильтр по имени
echo html_writer::tag('div', '<strong>Имя</strong>', array('style' => 'margin-bottom: 8px;'));

// Кнопка "Все" (очищает фильтр по имени)
$url_name_all = new moodle_url('/mod/studentattendance/view.php', array(
    'id' => $cm->id, 
    'page' => $page_key, 
    'filter_surname' => $filter_surname,
    'search' => $search
));
$active_class = (empty($filter_name)) ? 'btn-primary' : 'btn-outline-secondary';
echo html_writer::tag('button', 'Все', array(
    'type' => 'button',
    'id' => 'name-all-btn',
    'class' => 'btn btn-sm ' . $active_class,
    'style' => 'min-width: 40px; margin-bottom: 10px; display: inline-block; margin-right: 10px;'
));

// Буквы для имени
echo html_writer::start_div('filter-buttons', array('style' => 'display: flex; flex-wrap: wrap; gap: 4px; margin-bottom: 20px;'));
foreach ($alphabet as $letter) {
    $url = new moodle_url('/mod/studentattendance/view.php', array(
        'id' => $cm->id, 
        'page' => $page_key, 
        'filter_name' => $letter, 
        'filter_surname' => $filter_surname,
        'search' => $search
    ));
    $active_class = ($filter_name === $letter) ? 'btn-primary' : 'btn-outline-secondary';
    echo html_writer::tag('button', $letter, array(
    'type' => 'button',
    'class' => 'btn btn-sm ' . $active_class . ' name-filter-btn',
    'data-letter' => $letter,
    'style' => 'min-width: 35px; width: 35px; padding: 4px 0; text-align: center;'
));
}
echo html_writer::end_div();

// Фильтр по фамилии
echo html_writer::tag('div', '<strong>Фамилия</strong>', array('style' => 'margin-bottom: 8px;'));

// Кнопка "Все" (очищает фильтр по фамилии)
$url_surname_all = new moodle_url('/mod/studentattendance/view.php', array(
    'id' => $cm->id, 
    'page' => $page_key, 
    'filter_name' => $filter_name,
    'search' => $search
));
$active_class = (empty($filter_surname)) ? 'btn-primary' : 'btn-outline-secondary';
echo html_writer::tag('button', 'Все', array(
    'type' => 'button',
    'id' => 'surname-all-btn',
    'class' => 'btn btn-sm ' . $active_class,
    'style' => 'min-width: 40px; margin-bottom: 10px; display: inline-block; margin-right: 10px;'
));

// Буквы для фамилии
echo html_writer::start_div('filter-buttons', array('style' => 'display: flex; flex-wrap: wrap; gap: 4px;'));
foreach ($alphabet as $letter) {
    $url = new moodle_url('/mod/studentattendance/view.php', array(
        'id' => $cm->id, 
        'page' => $page_key, 
        'filter_name' => $filter_name, 
        'filter_surname' => $letter,
        'search' => $search
    ));
    $active_class = ($filter_surname === $letter) ? 'btn-primary' : 'btn-outline-secondary';
    echo html_writer::tag('button', $letter, array(
    'type' => 'button',
    'class' => 'btn btn-sm ' . $active_class . ' surname-filter-btn',
    'data-letter' => $letter,
    'style' => 'min-width: 35px; width: 35px; padding: 4px 0; text-align: center;'
));
}
echo html_writer::end_div();

// Форма с кнопкой "Применить"
echo html_writer::start_tag('form', array(
    'method' => 'get',
    'action' => new moodle_url('/mod/studentattendance/view.php'),
    'style' => 'margin-top: 15px; text-align: right;'
));
echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'id', 'value' => $cm->id));
echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'page', 'value' => $page_key));
echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'search', 'value' => $search));
echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'apply', 'value' => '1'));
echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'filter_name', 'value' => $filter_name, 'id' => 'filter-name-input'));
echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'filter_surname', 'value' => $filter_surname, 'id' => 'filter-surname-input'));
echo html_writer::tag('button', 'Применить', array('type' => 'submit', 'class' => 'btn btn-primary', 'style' => 'min-width: 100px;'));
echo html_writer::end_tag('form');
echo html_writer::end_div();


// ===== БЛОК ПОИСКА =====
echo html_writer::start_div('student-search-container', array('style' => 'margin-bottom: 20px; display: flex; gap: 10px; align-items: center; flex-wrap: wrap;'));
echo html_writer::tag('input', '', array(
    'type' => 'text',
    'id' => 'student-search',
    'class' => 'student-search-input',
    'placeholder' => '🔍 Поиск студентов по фамилии, имени или email...',
    'autocomplete' => 'off',
    'value' => s($search),
    'style' => 'flex: 2; min-width: 250px; padding: 8px 12px; border: 1px solid #ced4da; border-radius: 4px;'
));
echo html_writer::tag('button', 'Очистить', array(
    'id' => 'student-search-clear',
    'class' => 'btn btn-secondary',
    'type' => 'button'
));
echo html_writer::tag('span', count($students) . ' студентов', array(
    'id' => 'student-count',
    'style' => 'background: #4800B4; color: white; padding: 5px 12px; border-radius: 20px; font-size: 13px;'
));
echo html_writer::end_div();

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
    
    $bg_color = ($session->weektype === 'N') ? '#e3f2fd' : '#fff8e1';
    $table->head[] = html_writer::tag('div', $date_str, 
        array('style' => "background-color: {$bg_color}; padding: 6px 8px; border-radius: 4px; min-width: 70px; text-align: center;"));
}
if (!empty($studentattendance->grade_enabled) && $studentattendance->max_grade > 0) {
    $table->head[] = html_writer::tag('div', 
        get_string('grade_calculated', 'studentattendance') . 
        html_writer::empty_tag('br') .
        html_writer::tag('small', get_string('attendancepercentage', 'studentattendance'), 
            array('class' => 'text-muted')),
        array('class' => 'text-center')
    );
} else {
    $table->head[] = get_string('attendancepercentage', 'studentattendance');
}

foreach ($students as $student) {
    $row = array();
    $fullname = fullname($student);
    $row[] = html_writer::tag('span', $fullname, array(
        'class' => 'student-name',
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
            
            $bg = ($session->weektype === 'N') ? '#e3f2fd' : '#fff8e1';
            $row[] = html_writer::tag('div', $checkbox, 
                array('class' => 'text-center checkbox-cell', 'style' => "background-color: {$bg};"));
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
(function() {
    var searchInput = document.getElementById('student-search');
    var clearButton = document.getElementById('student-search-clear');
    var studentCountSpan = document.getElementById('student-count');
    var table = document.getElementById('attendance-table');
    
    if (!searchInput || !table) return;
    
    var rows = table.querySelectorAll('tbody tr');
    var totalRows = rows.length;
    
    function filterStudents() {
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
            
            if (isMatch) {
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
                studentCountSpan.innerHTML = 'Найдено: ' + visibleCount + ' из ' + totalRows;
                studentCountSpan.style.background = visibleCount > 0 ? '#28a745' : '#ca3120';
            }
        }
    }
    
    var debounceTimer;
    searchInput.addEventListener('input', function() {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(filterStudents, 150);
    });
    
    if (clearButton) {
        clearButton.addEventListener('click', function() {
            searchInput.value = '';
            filterStudents();
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
    
    function enhanceCheckboxes() {
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
                            var event = new Event('change', { bubbles: true });
                            checkbox.dispatchEvent(event);
                        }
                    }
                });
            }
        }
    }
    
    enhanceCheckboxes();
    
    // Если есть параметр search в URL, применяем фильтр сразу
    if (searchInput.value !== '') {
        filterStudents();
    }
    
})();

// Буквы имени
var nameButtons = document.querySelectorAll('.name-filter-btn');
var nameInput = document.getElementById('filter-name-input');
var nameAllBtn = document.getElementById('name-all-btn');

nameButtons.forEach(function(btn) {
    btn.addEventListener('click', function() {
        var letter = this.getAttribute('data-letter');
        if (nameInput.value === letter) {
            nameInput.value = '';
            this.classList.remove('btn-primary');
            this.classList.add('btn-outline-secondary');
        } else {
            nameInput.value = letter;
            nameButtons.forEach(function(b) {
                b.classList.remove('btn-primary');
                b.classList.add('btn-outline-secondary');
            });
            this.classList.remove('btn-outline-secondary');
            this.classList.add('btn-primary');
        }
        if (nameAllBtn) {
            nameAllBtn.classList.remove('btn-primary');
            nameAllBtn.classList.add('btn-outline-secondary');
        }
    });
});

if (nameAllBtn) {
    nameAllBtn.addEventListener('click', function() {
        nameInput.value = '';
        nameButtons.forEach(function(b) {
            b.classList.remove('btn-primary');
            b.classList.add('btn-outline-secondary');
        });
        this.classList.remove('btn-outline-secondary');
        this.classList.add('btn-primary');
    });
}

// Буквы фамилии
var surnameButtons = document.querySelectorAll('.surname-filter-btn');
var surnameInput = document.getElementById('filter-surname-input');
var surnameAllBtn = document.getElementById('surname-all-btn');

surnameButtons.forEach(function(btn) {
    btn.addEventListener('click', function() {
        var letter = this.getAttribute('data-letter');
        if (surnameInput.value === letter) {
            surnameInput.value = '';
            this.classList.remove('btn-primary');
            this.classList.add('btn-outline-secondary');
        } else {
            surnameInput.value = letter;
            surnameButtons.forEach(function(b) {
                b.classList.remove('btn-primary');
                b.classList.add('btn-outline-secondary');
            });
            this.classList.remove('btn-outline-secondary');
            this.classList.add('btn-primary');
        }
        if (surnameAllBtn) {
            surnameAllBtn.classList.remove('btn-primary');
            surnameAllBtn.classList.add('btn-outline-secondary');
        }
    });
});

if (surnameAllBtn) {
    surnameAllBtn.addEventListener('click', function() {
        surnameInput.value = '';
        surnameButtons.forEach(function(b) {
            b.classList.remove('btn-primary');
            b.classList.add('btn-outline-secondary');
        });
        this.classList.remove('btn-outline-secondary');
        this.classList.add('btn-primary');
    });
}
</script>
EOD;

echo $js;

echo $OUTPUT->footer();