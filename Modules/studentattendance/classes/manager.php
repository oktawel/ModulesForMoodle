<?php
namespace mod_studentattendance;

defined('MOODLE_INTERNAL') || die();

class manager {
    /**
     * Генерирует сессии с учетом расписания числителя и знаменателя
     */
    public static function generate_sessions($attendanceid, $start, $end, $num_days, $den_days) {
        global $DB;
        
        // Находим ближайший понедельник после или равный дате начала семестра
        // Это будет базовой точкой отсчета для определения четности недель
        $base_date = $start;
        while (date('N', $base_date) != 1) {
            $base_date = strtotime('+1 day', $base_date);
        }
        
        $current_date = $start;
        while ($current_date <= $end) {
            $dow = date('N', $current_date); // 1=Пн ... 7=Вс
            
            // Пропускаем выходные
            if ($dow > 5) {
                $current_date = strtotime('+1 day', $current_date);
                continue;
            }
            
            // Вычисляем номер недели относительно базового понедельника
            $diff_seconds = $current_date - $base_date;
            $week_number = floor($diff_seconds / (7 * 24 * 60 * 60));
            
            // Четная разница (0, 2, 4...) = Числитель, Нечетная = Знаменатель
            $is_numerator = ($week_number % 2 == 0);
            $active_days = $is_numerator ? $num_days : $den_days;
            
            // Проверяем индекс дня недели (0-4 для Пн-Пт)
            if (isset($active_days[$dow - 1]) && $active_days[$dow - 1] == '1') {
                $record = new \stdClass();
                $record->attendanceid = $attendanceid;
                $record->sessiondate = $current_date;
                $record->weektype = $is_numerator ? 'N' : 'D';
                $DB->insert_record('studentattendance_sessions', $record);
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