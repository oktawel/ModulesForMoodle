<?php
defined('MOODLE_INTERNAL') || die();
require_once($CFG->dirroot . '/course/moodleform_mod.php');

class mod_studentattendance_mod_form extends moodleform_mod {
    public function definition() {
        $mform = $this->_form;

        $mform->addElement('header', 'general', get_string('general', 'form'));

        $mform->addElement('text', 'name', get_string('name'), array('size' => '64'));
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');

        $this->standard_intro_elements();

        // Обязательные даты семестра
        $mform->addElement('date_selector', 'semesterstart', get_string('semesterstart', 'studentattendance'));
        $mform->setDefault('semesterstart', time());
        $mform->addRule('semesterstart', null, 'required', null, 'client');

        $mform->addElement('date_selector', 'semesterend', get_string('semesterend', 'studentattendance'));
        $mform->setDefault('semesterend', strtotime('+4 months'));
        $mform->addRule('semesterend', null, 'required', null, 'client');

        // ОДИН ОБЩИЙ БЛОК ДЛЯ ЧИСЛИТЕЛЯ И ЗНАМЕНАТЕЛЯ
        $mform->addElement('header', 'scheduleheader', get_string('schedule_settings', 'studentattendance'));
        
        // Группа числителя
        $num_group = array();
        for ($i = 1; $i <= 5; $i++) {
            $day_name = get_string('weekday' . $i, 'studentattendance');
            $num_group[] = $mform->createElement('checkbox', 'num_weekday' . $i, '', $day_name);
        }
        $mform->addGroup($num_group, 'num_weekdays_group', 
            html_writer::tag('strong', get_string('numeratorweek', 'studentattendance')), 
            array(' '), false);
        $mform->addHelpButton('num_weekdays_group', 'numeratorweek', 'studentattendance');

        // Группа знаменателя
        $den_group = array();
        for ($i = 1; $i <= 5; $i++) {
            $day_name = get_string('weekday' . $i, 'studentattendance');
            $den_group[] = $mform->createElement('checkbox', 'den_weekday' . $i, '', $day_name);
        }
        $mform->addGroup($den_group, 'den_weekdays_group', 
            html_writer::tag('strong', get_string('denominatorweek', 'studentattendance')), 
            array(' '), false);
        $mform->addHelpButton('den_weekdays_group', 'denominatorweek', 'studentattendance');

        // ВАЛИДАЦИЯ: хотя бы один день должен быть выбран в каждой группе
        $mform->addRule('num_weekdays_group', get_string('error_at_least_one_day', 'studentattendance'), 
            'callback', 'validate_weekdays_num', 'client');
        $mform->addRule('den_weekdays_group', get_string('error_at_least_one_day', 'studentattendance'), 
            'callback', 'validate_weekdays_den', 'client');

        $this->standard_coursemodule_elements();
        $this->add_action_buttons();
    }

    // Кастомная валидация для группы чекбоксов
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
        }
        return $data;
    }
}