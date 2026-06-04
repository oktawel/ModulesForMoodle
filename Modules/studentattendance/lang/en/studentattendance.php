<?php
defined('MOODLE_INTERNAL') || die();
$string['student'] = 'Студент';

// Основные названия плагина
$string['pluginname'] = 'Журнал посещаемости студентов';
$string['modulename'] = 'Журнал посещаемости студентов';
$string['modulenameplural'] = 'Журналы посещаемости студентов';
$string['pluginadministration'] = 'Администрирование журнала';

// Настройки семестра
$string['semesterstart'] = 'Дата начала семестра';
$string['semesterend'] = 'Дата окончания семестра';
$string['weekdays'] = 'Дни проведения занятий';

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

$string['schedule_settings'] = 'Расписание занятий';
$string['numeratorweek'] = 'Неделя числителя';
$string['denominatorweek'] = 'Неделя знаменателя';
$string['numeratorweek_help'] = 'Выберите дни занятий для недель числителя. Первая полная учебная неделя от начала семестра считается числителем.';
$string['denominatorweek_help'] = 'Выберите дни занятий для недель знаменателя.';
$string['error_at_least_one_day'] = 'Необходимо выбрать хотя бы один день недели';

$string['weekday1'] = 'Понедельник';
$string['weekday2'] = 'Вторник';
$string['weekday3'] = 'Среда';
$string['weekday4'] = 'Четверг';
$string['weekday5'] = 'Пятница';

$string['status_p'] = 'Присутствовал';
$string['status_a'] = 'Отсутствовал';
$string['student'] = 'Студент';

$string['weektype_n'] = 'Ч';
$string['weektype_d'] = 'З';

$string['grade_settings'] = 'Настройки оценивания';
$string['grade_enabled'] = 'Включить оценивание посещаемости';
$string['grade_enabled_desc'] = 'Автоматически выставлять баллы в журнал оценок на основе посещаемости';
$string['grade_enabled_help'] = 'Если включено, система будет автоматически рассчитывать и выставлять баллы студентам в журнал оценок Moodle на основе процента посещаемости.';
$string['max_grade'] = 'Максимальный балл';
$string['max_grade_help'] = 'Максимальное количество баллов за 100% посещаемость. Итоговый балл рассчитывается как: (процент посещаемости / 100) * максимальный балл.';
$string['grade_calculated'] = 'Балл';

$string['nosessions'] = 'Нет запланированных занятий. Проверьте даты начала и окончания семестра, а также выбранные дни недели в настройках журнала.';
    
$string['cohort_schedule'] = 'Расписание для групп';