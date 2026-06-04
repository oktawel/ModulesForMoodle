<?php
defined('MOODLE_INTERNAL') || die();
require_once($CFG->dirroot . '/course/moodleform_mod.php');
require_once($CFG->dirroot . '/cohort/lib.php');

class mod_studentattendance_mod_form extends moodleform_mod {
    public function definition() {
        $mform = $this->_form;

        // === АВТОМАТИЧЕСКИЙ РАСЧЁТ ДАТ СЕМЕСТРА ===
        $today = time();
        $currentYear = date('Y', $today);
        $currentMonth = (int)date('n', $today);

        $startTimestamp = 0;
        $endTimestamp = 0;

        if ($currentMonth >= 7) { 
            $startTimestamp = strtotime("01 September {$currentYear}");
            $endTimestamp = strtotime("31 December {$currentYear}");
        } else { 
            $endTimestamp = strtotime("08 June {$currentYear}");
            
            $janFirst = strtotime("01 January {$currentYear}");
            $dayOfWeek = (int)date('N', $janFirst);
            
            if ($dayOfWeek === 1) {
                $nearestMonday = $janFirst;
            } else {
                $daysToAdd = 8 - $dayOfWeek;
                $nearestMonday = strtotime("+{$daysToAdd} days", $janFirst);
            }
            
            $startTimestamp = strtotime("+4 weeks", $nearestMonday);
        }

        // === ОБЩИЕ НАСТРОЙКИ ===
        $mform->addElement('header', 'general', get_string('general', 'form'));

        $mform->addElement('text', 'name', get_string('name'), array('size' => '64'));
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');

        $this->standard_intro_elements();

        // === ДАТЫ СЕМЕСТРА ===
        $mform->addElement('date_selector', 'semesterstart', get_string('semesterstart', 'studentattendance'));
        $mform->setDefault('semesterstart', $startTimestamp);
        $mform->addRule('semesterstart', null, 'required', null, 'client');

        $mform->addElement('date_selector', 'semesterend', get_string('semesterend', 'studentattendance'));
        $mform->setDefault('semesterend', $endTimestamp);
        $mform->addRule('semesterend', null, 'required', null, 'client');

        // === НАСТРОЙКИ ОЦЕНИВАНИЯ ===
        $mform->addElement('header', 'gradeheader', get_string('grade_settings', 'studentattendance'));

        $mform->addElement('advcheckbox', 'grade_enabled', get_string('grade_enabled', 'studentattendance'), 
            get_string('grade_enabled_desc', 'studentattendance'));
        $mform->setDefault('grade_enabled', 0);

        $mform->addElement('text', 'max_grade', get_string('max_grade', 'studentattendance'), array('size' => '10'));
        $mform->setType('max_grade', PARAM_FLOAT);
        $mform->setDefault('max_grade', 100);
        $mform->disabledIf('max_grade', 'grade_enabled', 'notchecked');

        // === РАСПИСАНИЕ ДЛЯ ГРУПП ===
        $mform->addElement('header', 'scheduleheader', get_string('schedule_settings', 'studentattendance'));
        
        $courseid = $this->current->course ?? 0;
        $cohorts = array();
        
        if ($courseid) {
            $course_context = context_course::instance($courseid);
            if (has_capability('moodle/cohort:view', context_system::instance())) {
                $cohorts = cohort_get_available_cohorts($course_context, 0, 0, 0);
            }
        }

        if (empty($cohorts)) {
            $mform->addElement('static', 'no_cohorts_info', '', 
                '<div class="alert alert-info">Нет доступных глобальных групп (когорт). Расписание будет общим для всех студентов.</div>');
            
            $num_group = array();
            for ($i = 1; $i <= 5; $i++) {
                $day_name = get_string('weekday' . $i, 'studentattendance');
                $num_group[] = $mform->createElement('checkbox', 'num_weekday' . $i, '', $day_name);
            }
            $mform->addGroup($num_group, 'num_weekdays_group', 
                html_writer::tag('strong', get_string('numeratorweek', 'studentattendance')), 
                array(' '), false);

            $den_group = array();
            for ($i = 1; $i <= 5; $i++) {
                $day_name = get_string('weekday' . $i, 'studentattendance');
                $den_group[] = $mform->createElement('checkbox', 'den_weekday' . $i, '', $day_name);
            }
            $mform->addGroup($den_group, 'den_weekdays_group', 
                html_writer::tag('strong', get_string('denominatorweek', 'studentattendance')), 
                array(' '), false);
                
            // $mform->addRule('num_weekdays_group', get_string('error_at_least_one_day', 'studentattendance'), 
            //     'callback', 'validate_weekdays_num', 'client');
            // $mform->addRule('den_weekdays_group', get_string('error_at_least_one_day', 'studentattendance'), 
            //     'callback', 'validate_weekdays_den', 'client');
        } else {
            $mform->addElement('static', 'cohorts_info', '', 
                '<div class="alert alert-info">Найдено глобальных групп: ' . count($cohorts) . '. Настройте расписание для каждой группы отдельно.</div>');
            
            $cohort_options = array();
            foreach ($cohorts as $cohort) {
                $cohort_options[$cohort->id] = format_string($cohort->name);
            }
            
            $mform->addElement('select', 'selected_cohort', 'Выберите группу', $cohort_options);
            $mform->setDefault('selected_cohort', reset($cohorts)->id);
            
            foreach ($cohorts as $cohort) {
                $cohort_div_start = '<div id="cohort_schedule_' . $cohort->id . '" class="cohort-schedule-block" style="display: none; padding: 15px; background: #f8f9fa; border-radius: 8px; margin-top: 10px;">';
                $cohort_div_end = '</div>';
                
                $mform->addElement('html', $cohort_div_start);
                
                $mform->addElement('static', 'cohort_name_' . $cohort->id, '', 
                    '<h5>' . format_string($cohort->name) . '</h5>');
                
                $num_group = array();
                for ($i = 1; $i <= 5; $i++) {
                    $day_name = get_string('weekday' . $i, 'studentattendance');
                    $num_group[] = $mform->createElement('checkbox', 
                        'cohort_' . $cohort->id . '_num_weekday' . $i, '', $day_name);
                }
                $mform->addGroup($num_group, 
                    'cohort_' . $cohort->id . '_num_weekdays_group', 
                    html_writer::tag('strong', get_string('numeratorweek', 'studentattendance')), 
                    array(' '), false);
                
                $den_group = array();
                for ($i = 1; $i <= 5; $i++) {
                    $day_name = get_string('weekday' . $i, 'studentattendance');
                    $den_group[] = $mform->createElement('checkbox', 
                        'cohort_' . $cohort->id . '_den_weekday' . $i, '', $day_name);
                }
                $mform->addGroup($den_group, 
                    'cohort_' . $cohort->id . '_den_weekdays_group', 
                    html_writer::tag('strong', get_string('denominatorweek', 'studentattendance')), 
                    array(' '), false);
                
                $mform->addElement('html', $cohort_div_end);
            }
            
            $js = '<script>
document.addEventListener("DOMContentLoaded", function() {
    var cohortSelect = document.getElementById("id_selected_cohort");
    if (cohortSelect) {
        function showCohortSchedule() {
            var selectedId = cohortSelect.value;
            var blocks = document.querySelectorAll(".cohort-schedule-block");
            blocks.forEach(function(block) {
                block.style.display = "none";
            });
            var activeBlock = document.getElementById("cohort_schedule_" + selectedId);
            if (activeBlock) {
                activeBlock.style.display = "block";
            }
        }
        
        cohortSelect.addEventListener("change", showCohortSchedule);
        showCohortSchedule();
    }
});
</script>';
            
            $mform->addElement('html', $js);
        }

        $this->standard_coursemodule_elements();
        $this->add_action_buttons();
    }

    public static function validate_weekdays_num($value) {
        foreach ($value as $val) {
            if (!empty($val)) return true;
        }
        return false;
    }

    public static function validate_weekdays_den($value) {
        foreach ($value as $val) {
            if (!empty($val)) return true;
        }
        return false;
    }

    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        
        // $courseid = $this->current->course ?? 0;
        // if ($courseid) {
        //     $course_context = context_course::instance($courseid);
        //     if (has_capability('moodle/cohort:view', context_system::instance())) {
        //         $cohorts = cohort_get_available_cohorts($course_context, 0, 0, 0);
        //     } else {
        //         $cohorts = array();
        //     }
        // } else {
        //     $cohorts = array();
        // }
        
        // if (empty($cohorts)) {
        //     $has_num = false;
        //     $has_den = false;
        //     for ($i = 1; $i <= 5; $i++) {
        //         if (!empty($data['num_weekday' . $i])) $has_num = true;
        //         if (!empty($data['den_weekday' . $i])) $has_den = true;
        //     }
        //     if (!$has_num) {
        //         $errors['num_weekdays_group'] = get_string('error_at_least_one_day', 'studentattendance');
        //     }
        //     if (!$has_den) {
        //         $errors['den_weekdays_group'] = get_string('error_at_least_one_day', 'studentattendance');
        //     }
        // } else {
        //     foreach ($cohorts as $cohort) {
        //         $has_num = false;
        //         $has_den = false;
        //         for ($i = 1; $i <= 5; $i++) {
        //             if (!empty($data['cohort_' . $cohort->id . '_num_weekday' . $i])) $has_num = true;
        //             if (!empty($data['cohort_' . $cohort->id . '_den_weekday' . $i])) $has_den = true;
        //         }
        //         if (!$has_num || !$has_den) {
        //             $errors['selected_cohort'] = 'Для группы "' . format_string($cohort->name) . '" необходимо выбрать хотя бы один день для числителя и знаменателя';
        //         }
        //     }
        // }
        
        return $errors;
    }

    public function data_preprocessing(&$default_values) {
        if (!empty($default_values['weekdays_numerator'])) {
            $days = str_split($default_values['weekdays_numerator']);
            foreach ($days as $index => $val) {
                if ($val == '1') $default_values['num_weekday' . ($index + 1)] = 1;
            }
        }
        if (!empty($default_values['weekdays_denominator'])) {
            $days = str_split($default_values['weekdays_denominator']);
            foreach ($days as $index => $val) {
                if ($val == '1') $default_values['den_weekday' . ($index + 1)] = 1;
            }
        }
        
        if (!empty($default_values['cohort_schedules'])) {
            $schedules = json_decode($default_values['cohort_schedules'], true);
            if (is_array($schedules)) {
                foreach ($schedules as $cohortid => $schedule) {
                    if (isset($schedule['numerator'])) {
                        $days = str_split($schedule['numerator']);
                        foreach ($days as $index => $val) {
                            if ($val == '1') {
                                $default_values['cohort_' . $cohortid . '_num_weekday' . ($index + 1)] = 1;
                            }
                        }
                    }
                    if (isset($schedule['denominator'])) {
                        $days = str_split($schedule['denominator']);
                        foreach ($days as $index => $val) {
                            if ($val == '1') {
                                $default_values['cohort_' . $cohortid . '_den_weekday' . ($index + 1)] = 1;
                            }
                        }
                    }
                }
            }
        }
    }

    public function get_data() {
        $data = parent::get_data();
        if ($data) {
            $num = ''; $den = '';
            for ($i = 1; $i <= 5; $i++) {
                $num .= isset($data->{'num_weekday' . $i}) ? '1' : '0';
                $den .= isset($data->{'den_weekday' . $i}) ? '1' : '0';
            }
            $data->weekdays_numerator = $num;
            $data->weekdays_denominator = $den;
            
            $cohort_schedules = array();
            
            $courseid = $this->current->course ?? 0;
            if ($courseid) {
                $course_context = context_course::instance($courseid);
                if (has_capability('moodle/cohort:view', context_system::instance())) {
                    $cohorts = cohort_get_available_cohorts($course_context, 0, 0, 0);
                } else {
                    $cohorts = array();
                }
            } else {
                $cohorts = array();
            }
            
            foreach ($cohorts as $cohort) {
                $cohort_num = '';
                $cohort_den = '';
                for ($i = 1; $i <= 5; $i++) {
                    $cohort_num .= isset($data->{'cohort_' . $cohort->id . '_num_weekday' . $i}) ? '1' : '0';
                    $cohort_den .= isset($data->{'cohort_' . $cohort->id . '_den_weekday' . $i}) ? '1' : '0';
                }
                $cohort_schedules[$cohort->id] = array(
                    'numerator' => $cohort_num,
                    'denominator' => $cohort_den
                );
            }
            
            $data->cohort_schedules = json_encode($cohort_schedules);
        }
        return $data;
    }
}