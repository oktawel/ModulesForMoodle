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

        $mform->addElement('date_selector', 'semesterstart', get_string('semesterstart', 'studentattendance'));
        $mform->setDefault('semesterstart', time());

        $mform->addElement('date_selector', 'semesterend', get_string('semesterend', 'studentattendance'));
        $mform->setDefault('semesterend', strtotime('+4 months'));

        $mform->addElement('header', 'weekdaysheader', get_string('weekdays', 'studentattendance'));
        
        $days = array(1 => 'weekday1', 2 => 'weekday2', 3 => 'weekday3', 4 => 'weekday4', 5 => 'weekday5', 6 => 'weekday6', 7 => 'weekday7');
        $group = array();
        foreach ($days as $key => $day) {
            $group[] = $mform->createElement('checkbox', $day, '', get_string($day, 'studentattendance'));
        }
        $mform->addGroup($group, 'weekdaysgroup', '', array(' '), false);

        $this->standard_coursemodule_elements();
        $this->add_action_buttons();
    }

    public function data_preprocessing(&$default_values) {
        if (!empty($default_values['weekdays'])) {
            $weekdays = str_split($default_values['weekdays']);
            foreach ($weekdays as $index => $val) {
                if ($val == '1') {
                    $default_values['weekday' . ($index + 1)] = 1;
                }
            }
        }
    }

    public function get_data() {
        $data = parent::get_data();
        if ($data) {
            $weekdays = '';
            for ($i = 1; $i <= 7; $i++) {
                $weekdays .= isset($data->{'weekday' . $i}) ? '1' : '0';
            }
            $data->weekdays = $weekdays;
        }
        return $data;
    }
}