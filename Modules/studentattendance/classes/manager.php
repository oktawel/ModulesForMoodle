<?php
namespace mod_studentattendance;

defined('MOODLE_INTERNAL') || die();

class manager {
    public static function generate_sessions($attendanceid, $start, $end, $num_days, $den_days, $cohortid = 0) {
        global $DB;
        
        // Находим первый понедельник
        $base_date = $start;
        while (date('N', $base_date) != 1) {
            $base_date = strtotime('+1 day', $base_date);
        }
        
        $current_date = $start;
        while ($current_date <= $end) {
            $dow = date('N', $current_date); // 1=Пн, 5=Пт
            
            // Пропускаем выходные
            if ($dow > 5) {
                $current_date = strtotime('+1 day', $current_date);
                continue;
            }
            
            // Определяем номер недели (0, 1, 2, ...)
            $diff_seconds = $current_date - $base_date;
            $week_number = floor($diff_seconds / (7 * 24 * 60 * 60));
            $is_numerator = ($week_number % 2 == 0);
            
            // Активные дни для этой недели
            $active_days = $is_numerator ? $num_days : $den_days;
            
            // Проверяем, активен ли этот день недели
            if (isset($active_days[$dow - 1]) && $active_days[$dow - 1] == '1') {
                // Проверяем, нет ли уже такой сессии
                $exists = $DB->record_exists('studentattendance_sessions', array(
                    'attendanceid' => $attendanceid,
                    'sessiondate' => $current_date,
                    'cohortid' => $cohortid
                ));
                
                if (!$exists) {
                    $record = new \stdClass();
                    $record->attendanceid = $attendanceid;
                    $record->sessiondate = $current_date;
                    $record->weektype = $is_numerator ? 'N' : 'D';
                    $record->cohortid = $cohortid;
                    $DB->insert_record('studentattendance_sessions', $record);
                }
            }
            
            $current_date = strtotime('+1 day', $current_date);
        }
    }

    public static function get_status_options() {
        return array(
            'P' => get_string('status_p', 'studentattendance'),
            'A' => get_string('status_a', 'studentattendance'),
        );
    }
}