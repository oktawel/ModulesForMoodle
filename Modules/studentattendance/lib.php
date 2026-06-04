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
    
    // Формируем строки дней недели из данных формы
    $num_weekdays = '';
    $den_weekdays = '';
    for ($i = 1; $i <= 5; $i++) {
        $num_weekdays .= isset($data->{'num_weekday' . $i}) ? '1' : '0';
        $den_weekdays .= isset($data->{'den_weekday' . $i}) ? '1' : '0';
    }
    $data->weekdays_numerator = $num_weekdays;
    $data->weekdays_denominator = $den_weekdays;

    $id = $DB->insert_record('studentattendance', $data);
    
    mod_studentattendance\manager::generate_sessions(
        $id, 
        $data->semesterstart, 
        $data->semesterend, 
        $num_weekdays, 
        $den_weekdays
    );
    
    return $id;
}

function studentattendance_update_instance($data, $mform) {
    global $DB;
    $data->timemodified = time();
    $data->id = $data->instance;

    $num_weekdays = '';
    $den_weekdays = '';
    for ($i = 1; $i <= 5; $i++) {
        $num_weekdays .= isset($data->{'num_weekday' . $i}) ? '1' : '0';
        $den_weekdays .= isset($data->{'den_weekday' . $i}) ? '1' : '0';
    }
    $data->weekdays_numerator = $num_weekdays;
    $data->weekdays_denominator = $den_weekdays;

    $DB->update_record('studentattendance', $data);
    
    // Удаляем старые сессии и записи, генерируем заново
    $sessions = $DB->get_records('studentattendance_sessions', array('attendanceid' => $data->id));
    foreach ($sessions as $session) {
        $DB->delete_records('studentattendance_records', array('sessionid' => $session->id));
    }
    $DB->delete_records('studentattendance_sessions', array('attendanceid' => $data->id));
    
    mod_studentattendance\manager::generate_sessions(
        $data->id, 
        $data->semesterstart, 
        $data->semesterend, 
        $num_weekdays, 
        $den_weekdays
    );
    
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

/**
 * Поддержка оценок
 */
function studentattendance_get_user_grades($studentattendance, $userid = 0) {
    global $DB;

    if (empty($studentattendance->grade_enabled)) {
        return false;
    }

    $grades = array();
    $max_grade = $studentattendance->max_grade;

    $sessions = $DB->get_records('studentattendance_sessions', 
        array('attendanceid' => $studentattendance->id));
    $total_sessions = count($sessions);

    if ($total_sessions == 0) {
        return false;
    }

    $cm = get_coursemodule_from_instance('studentattendance', $studentattendance->id);
    $context = context_module::instance($cm->id);
    $students = get_enrolled_users($context, '', 0, 'u.id', null, 0, 0, true);

    foreach ($students as $student) {
        if ($userid != 0 && $student->id != $userid) {
            continue;
        }

        $sql = "SELECT COUNT(*) FROM {studentattendance_records} 
                WHERE sessionid IN (SELECT id FROM {studentattendance_sessions} 
                WHERE attendanceid = :attid) 
                AND studentid = :userid AND status = 'P'";
        
        $present_count = $DB->count_records_sql($sql, 
            array('attid' => $studentattendance->id, 'userid' => $student->id));

        // Рассчитываем балл
        $percentage = ($present_count / $total_sessions) * 100;
        $raw_grade = ($percentage / 100) * $max_grade;
        
        // ОКРУГЛЯЕМ ДО БЛИЖАЙШИХ 0.25
        $grade = round($raw_grade * 4) / 4;
        // Округляем до 2 знаков после запятой для красоты
        $grade = round($grade, 2);

        $grades[$student->id] = new stdClass();
        $grades[$student->id]->userid = $student->id;
        $grades[$student->id]->rawgrade = $grade;

    }

    foreach ($grades as $studentid => $grade) {
        // Убедитесь что оценка в пределах 0-max_grade
        if ($grade->rawgrade < 0) {
            $grade->rawgrade = 0;
        }
        if ($grade->rawgrade > $max_grade) {
            $grade->rawgrade = $max_grade;
        }
    }

    return $grades;
}

/**
 * Обновление оценок в журнале
 */
function studentattendance_update_grades($studentattendance, $userid = 0, $nullifnone = true) {
    global $CFG, $DB;
    require_once($CFG->libdir . '/gradelib.php');

    if (!$studentattendance->grade_enabled || empty($studentattendance->max_grade)) {
        // Если оценивание выключено, удаляем элемент из журнала оценок
        grade_update('mod/studentattendance', $studentattendance->course, 'mod', 
            'studentattendance', $studentattendance->id, 0, null, 
            array('deleted' => 1));
        return;
    }

    // Получаем оценки
    if ($grades = studentattendance_get_user_grades($studentattendance, $userid)) {
        // Обновляем журнал оценок
        $result = grade_update('mod/studentattendance', $studentattendance->course, 'mod', 
            'studentattendance', $studentattendance->id, 0, $grades,
            array(
                'itemname' => $studentattendance->name,
                'gradetype' => GRADE_TYPE_VALUE,
                'grademax' => $studentattendance->max_grade,
                'grademin' => 0,
            )
        );
        
        if ($result !== GRADE_UPDATE_OK) {
            error_log("Grade update error: " . $result);
        }
        
        return $result;
    } else if ($nullifnone) {
        // Если оценок нет, устанавливаем как пустое значение
        grade_update('mod/studentattendance', $studentattendance->course, 'mod', 
            'studentattendance', $studentattendance->id, 0, null,
            array(
                'itemname' => $studentattendance->name,
                'gradetype' => GRADE_TYPE_VALUE,
                'grademax' => $studentattendance->max_grade,
                'grademin' => 0,
            )
        );
    }
}

/**
 * Обновление оценок при изменении посещаемости
 */
function studentattendance_update_all_grades($studentattendance) {
    studentattendance_update_grades($studentattendance);
}