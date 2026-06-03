<?php
defined('MOODLE_INTERNAL') || die();

function xmldb_studentattendance_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    // === ОБНОВЛЕНИЕ ДО ВЕРСИИ 2026060301 ===
    if ($oldversion < 2026060301) {
        
        // 1. Добавляем новое поле weektype в таблицу сессий
        $table = new xmldb_table('studentattendance_sessions');
        $field = new xmldb_field('weektype', XMLDB_TYPE_CHAR, '1', null, XMLDB_NOTNULL, null, 'N');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // 2. Добавляем поля для дней недели числителя и знаменателя
        $table = new xmldb_table('studentattendance');
        $field_num = new xmldb_field('weekdays_numerator', XMLDB_TYPE_CHAR, '5', null, XMLDB_NOTNULL, null, '00000');
        $field_den = new xmldb_field('weekdays_denominator', XMLDB_TYPE_CHAR, '5', null, XMLDB_NOTNULL, null, '00000');
        
        if (!$dbman->field_exists($table, $field_num)) {
            $dbman->add_field($table, $field_num);
        }
        if (!$dbman->field_exists($table, $field_den)) {
            $dbman->add_field($table, $field_den);
        }

        // 3. Миграция данных из старого поля weekdays (7 символов) в два новых (по 5 символов)
        // Берем только первые 5 символов (Пн-Пт), игнорируя Сб и Вс
        $sql = "SELECT id, weekdays FROM {studentattendance} WHERE weekdays IS NOT NULL AND weekdays != ''";
        $records = $DB->get_records_sql($sql);
        
        foreach ($records as $record) {
            $old_days = str_pad($record->weekdays, 7, '0'); // На случай если строка короче 7
            $new_days = substr($old_days, 0, 5); // Берем Пн-Пт
            
            $DB->set_field('studentattendance', 'weekdays_numerator', $new_days, array('id' => $record->id));
            $DB->set_field('studentattendance', 'weekdays_denominator', $new_days, array('id' => $record->id));
        }

        // 4. Удаляем старое поле weekdays, так как оно больше не нужно
        $field_old = new xmldb_field('weekdays');
        if ($dbman->field_exists($table, $field_old)) {
            $dbman->drop_field($table, $field_old);
        }

        // Сохраняем точку сохранения
        upgrade_mod_savepoint(true, 2026060301, 'studentattendance');
    }

    return true;
}