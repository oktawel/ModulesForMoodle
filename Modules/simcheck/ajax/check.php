<?php
define('AJAX_SCRIPT', true);
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/plagiarism/lib.php');

require_login();
$cmid = required_param('cmid', PARAM_INT);
$userid = required_param('userid', PARAM_INT);
$submissionid = required_param('submissionid', PARAM_INT);

$context = context_module::instance($cmid);
require_capability('mod/assign:grade', $context);

global $DB;

// 1. Получаем все файлы текущего задания
// (Упрощенная логика: берем все submission файлы этого cmid)
$sql = "SELECT s.id as subid, s.userid, f.id as fileid 
        FROM {assign_submission} s
        JOIN {files} f ON f.itemid = s.id
        WHERE s.assignment = (SELECT id FROM {assign} WHERE id = (SELECT instance FROM {course_modules} WHERE id = :cmid))
        AND f.filename LIKE '%.docx'";
$files = $DB->get_records_sql($sql, ['cmid' => $cmid]);

// 2. Функция для извлечения текста из DOCX (требует PhpOffice/PhpWord через Composer)
function extract_text_from_docx($fileid) {
    // В реальности здесь нужно получить файл через $fs = get_file_storage(); $file = $fs->get_file_by_id($fileid);
    // И использовать PhpOffice\PhpWord\IOFactory::load($file->get_content_file_handle())
    // Для примера вернем заглушку:
    return "текст из документа студента"; 
}

// 3. Простой алгоритм сравнения (Коэффициент Жаккара)
function calculate_similarity($text1, $text2) {
    $words1 = array_unique(str_word_count(mb_strtolower($text1), 1));
    $words2 = array_unique(str_word_count(mb_strtolower($text2), 1));
    
    $intersection = count(array_intersect($words1, $words2));
    $union = count(array_unique(array_merge($words1, $words2)));
    
    return $union == 0 ? 0 : round(($intersection / $union) * 100);
}

// 4. Логика проверки конкретного студента
$current_text = '';
$current_fileid = null;
foreach ($files as $f) {
    if ($f->userid == $userid) {
        $current_text = extract_text_from_docx($f->fileid);
        $current_fileid = $f->fileid;
        break;
    }
}

$max_similarity = 0;
$matched_userid = 0;

foreach ($files as $f) {
    if ($f->userid != $userid) { // Не сравниваем с самим собой
        $other_text = extract_text_from_docx($f->fileid);
        $sim = calculate_similarity($current_text, $other_text);
        if ($sim > $max_similarity) {
            $max_similarity = $sim;
            $matched_userid = $f->userid;
        }
    }
}

// 5. Сохраняем результат в БД
$record = new stdClass();
$record->cmid = $cmid;
$record->userid = $userid;
$record->submissionid = $submissionid;
$record->similarity_score = $max_similarity;
$record->matched_userid = $matched_userid;
$record->status = 'checked';
$record->timemodified = time();

// Обновляем или вставляем
$existing = $DB->get_record('plagiarism_simcheck', ['cmid' => $cmid, 'userid' => $userid, 'submissionid' => $submissionid]);
if ($existing) {
    $record->id = $existing->id;
    $DB->update_record('plagiarism_simcheck', $record);
} else {
    $DB->insert_record('plagiarism_simcheck', $record);
}

echo json_encode([
    'status' => 'success',
    'score' => $max_similarity,
    'matched_userid' => $matched_userid
]);