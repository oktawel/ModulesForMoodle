<?php
defined('MOODLE_INTERNAL') || die();
$string['student'] = 'Студент';

// Основные названия плагина
$string['pluginname'] = 'Журнал посещаемости студентов';
$string['modulename'] = 'Журнал посещаемости студентов';
$string['modulenameplural'] = 'Журналы посещаемости студентов';
$string['pluginadministration'] = 'Администрирование журнала'; // <-- ДОБАВЛЕНО

// Настройки семестра
$string['semesterstart'] = 'Дата начала семестра';
$string['semesterend'] = 'Дата окончания семестра';
$string['weekdays'] = 'Дни проведения занятий';
$string['weekday1'] = 'Понедельник';
$string['weekday2'] = 'Вторник';
$string['weekday3'] = 'Среда';
$string['weekday4'] = 'Четверг';
$string['weekday5'] = 'Пятница';
$string['weekday6'] = 'Суббота';
$string['weekday7'] = 'Воскресенье';

// Статусы посещаемости
$string['status_p'] = 'Присутствовал (P)';
$string['status_a'] = 'Отсутствовал (A)';
$string['status_l'] = 'Опоздал (L)';
$string['status_e'] = 'Уважительная причина (E)';

// Действия и сообщения
$string['savechanges'] = 'Сохранить посещаемость';
$string['attendancesaved'] = 'Посещаемость успешно сохранена';
$string['attendancepercentage'] = '% посещаемости'; // <-- ИСПРАВЛЕНО (было просто "Процент...")

// Права доступа
$string['studentattendance:view'] = 'Просматривать журнал посещаемости';
$string['studentattendance:take'] = 'Отмечать посещаемость';
$string['studentattendance:addinstance'] = 'Добавлять новый журнал посещаемости';

// GDPR / Privacy
$string['privacy:metadata:studentattendance_records'] = 'Информация о посещаемости студентов';
$string['privacy:metadata:studentattendance_records:studentid'] = 'ID студента';
$string['privacy:metadata:studentattendance_records:status'] = 'Статус посещаемости';