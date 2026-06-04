<?php
defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/studentattendance/classes/manager.php');
require_once($CFG->dirroot . '/cohort/lib.php');

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
    
    $id = $DB->insert_record('studentattendance', $data);
    
    // Получаем когорты курса
    $course_context = context_course::instance($data->course);
    if (has_capability('moodle/cohort:view', context_system::instance())) {
        $cohorts = cohort_get_available_cohorts($course_context, 0, 0, 0);
    } else {
        $cohorts = array();
    }
    
    if (empty($cohorts)) {
        // Общее расписание
        mod_studentattendance\manager::generate_sessions(
            $id, 
            $data->semesterstart, 
            $data->semesterend, 
            $data->weekdays_numerator, 
            $data->weekdays_denominator,
            0
        );
    } else {
        // Расписание для каждой когорты из JSON
        $cohort_schedules = json_decode($data->cohort_schedules, true);
        
        foreach ($cohorts as $cohort) {
            $num_days = '00000';
            $den_days = '00000';
            
            if (isset($cohort_schedules[$cohort->id])) {
                $num_days = $cohort_schedules[$cohort->id]['numerator'] ?? '00000';
                $den_days = $cohort_schedules[$cohort->id]['denominator'] ?? '00000';
            }
            
            mod_studentattendance\manager::generate_sessions(
                $id, 
                $data->semesterstart, 
                $data->semesterend, 
                $num_days, 
                $den_days,
                $cohort->id
            );
        }
    }
    
    return $id;
}

function studentattendance_update_instance($data, $mform) {
    global $DB;
    $data->timemodified = time();
    $data->id = $data->instance;

    $DB->update_record('studentattendance', $data);
    
    // Удаляем старые сессии и записи
    $sessions = $DB->get_records('studentattendance_sessions', array('attendanceid' => $data->id));
    foreach ($sessions as $session) {
        $DB->delete_records('studentattendance_records', array('sessionid' => $session->id));
    }
    $DB->delete_records('studentattendance_sessions', array('attendanceid' => $data->id));
    
    // Получаем когорты курса
    $course_context = context_course::instance($data->course);
    if (has_capability('moodle/cohort:view', context_system::instance())) {
        $cohorts = cohort_get_available_cohorts($course_context, 0, 0, 0);
    } else {
        $cohorts = array();
    }
    
    if (empty($cohorts)) {
        mod_studentattendance\manager::generate_sessions(
            $data->id, 
            $data->semesterstart, 
            $data->semesterend, 
            $data->weekdays_numerator, 
            $data->weekdays_denominator,
            0
        );
    } else {
        $cohort_schedules = json_decode($data->cohort_schedules, true);
        
        foreach ($cohorts as $cohort) {
            $num_days = '00000';
            $den_days = '00000';
            
            if (isset($cohort_schedules[$cohort->id])) {
                $num_days = $cohort_schedules[$cohort->id]['numerator'] ?? '00000';
                $den_days = $cohort_schedules[$cohort->id]['denominator'] ?? '00000';
            }
            
            mod_studentattendance\manager::generate_sessions(
                $data->id, 
                $data->semesterstart, 
                $data->semesterend, 
                $num_days, 
                $den_days,
                $cohort->id
            );
        }
    }
    
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
    global $DB;

    if (empty($studentattendance->grade_enabled) || empty($studentattendance->max_grade)) {
        return false;
    }

    $grades = array();
    $max_grade = $studentattendance->max_grade;

    $cm = get_coursemodule_from_instance('studentattendance', $studentattendance->id);
    if (!$cm) {
        return false;
    }
    
    $context = context_module::instance($cm->id);
    
    // Получаем всех enrolled пользователей
    $allusers = get_enrolled_users($context, '', 0, 'u.id', null, 0, 0, true);
    
    // Фильтруем: оставляем только студентов
    $students = array();
    foreach ($allusers as $user) {
        if (has_capability('mod/studentattendance:take', $context, $user->id)) {
            continue;
        }
        $students[$user->id] = $user;
    }
    
    if ($userid != 0) {
        if (!isset($students[$userid])) {
            return false;
        }
        $students = array($userid => $students[$userid]);
    }

    if (empty($students)) {
        return false;
    }

    $all_sessions = $DB->get_records('studentattendance_sessions', 
        array('attendanceid' => $studentattendance->id));

    if (empty($all_sessions)) {
        return false;
    }

    foreach ($students as $student) {
        if (!$student) continue;
        
        $student_cohorts = cohort_get_user_cohorts($student->id);
        $student_cohort_ids = array_map(function($c) { return $c->id; }, $student_cohorts);
        
        $student_session_ids = array();
        foreach ($all_sessions as $session) {
            if ($session->cohortid == 0 || in_array($session->cohortid, $student_cohort_ids)) {
                $student_session_ids[] = $session->id;
            }
        }
        
        if (empty($student_session_ids)) {
            $grades[$student->id] = new stdClass();
            $grades[$student->id]->userid = $student->id;
            $grades[$student->id]->rawgrade = 0;
            continue;
        }
        
        $total_sessions = count($student_session_ids);
        
        list($insql, $inparams) = $DB->get_in_or_equal($student_session_ids, SQL_PARAMS_NAMED);
        $sql = "SELECT COUNT(*) FROM {studentattendance_records} 
                WHERE sessionid $insql 
                AND studentid = :userid AND status = 'P'";
        
        $present_count = $DB->count_records_sql($sql, array_merge($inparams, array('userid' => $student->id)));

        $percentage = ($present_count / $total_sessions) * 100;
        $raw_grade = ($percentage / 100) * $max_grade;
        
        $grade = round($raw_grade * 4) / 4;
        $grade = round($grade, 2);

        $grades[$student->id] = new stdClass();
        $grades[$student->id]->userid = $student->id;
        $grades[$student->id]->rawgrade = $grade;
    }

    return $grades;
}

function studentattendance_update_grades($studentattendance, $userid = 0, $nullifnone = true) {
    global $CFG;
    require_once($CFG->libdir . '/gradelib.php');

    // Если оценивание выключено - удаляем элемент из журнала оценок
    if (!$studentattendance->grade_enabled || empty($studentattendance->max_grade)) {
        grade_update('mod/studentattendance', $studentattendance->course, 'mod', 
            'studentattendance', $studentattendance->id, 0, null, 
            array('deleted' => 1));
        return GRADE_UPDATE_OK;
    }

    $grades = studentattendance_get_user_grades($studentattendance, $userid);
    
    if ($grades && !empty($grades)) {
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
        
        return $result;
    } else if ($nullifnone) {
        // Если оценок нет, создаем пустой элемент
        $updateitem = new stdClass();
        $updateitem->itemname = $studentattendance->name;
        $updateitem->gradetype = GRADE_TYPE_VALUE;
        $updateitem->grademax = $studentattendance->max_grade;
        $updateitem->grademin = 0;
        
        return grade_update('mod/studentattendance', $studentattendance->course, 'mod', 
            'studentattendance', $studentattendance->id, 0, null, $updateitem);
    }
    
    return GRADE_UPDATE_OK;
}

function studentattendance_update_all_grades($studentattendance) {
    return studentattendance_update_grades($studentattendance, 0, false);
}
