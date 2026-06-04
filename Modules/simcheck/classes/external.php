<?php
namespace plagiarism_simcheck;

defined('MOODLE_INTERNAL') || die();

require_once("$CFG->libdir/externallib.php");
require_once("$CFG->libdir/filelib.php");

class external extends \external_api {

    public static function check_similarity_parameters() {
        return new \external_function_parameters([
            'cmid' => new \external_value(PARAM_INT, 'Course module ID'),
            'userid' => new \external_value(PARAM_INT, 'User ID to check'),
            'submissionid' => new \external_value(PARAM_INT, 'Submission ID'),
        ]);
    }

    public static function check_similarity_returns() {
        return new \external_single_structure([
            'status' => new \external_value(PARAM_TEXT, 'Status'),
            'score' => new \external_value(PARAM_INT, 'Similarity percentage'),
            'matched_userid' => new \external_value(PARAM_INT, 'Matched user ID'),
            'matched_username' => new \external_value(PARAM_TEXT, 'Matched user fullname'),
        ]);
    }

    public static function check_similarity($cmid, $userid, $submissionid) {
        global $DB, $USER;

        $params = self::validate_parameters(self::check_similarity_parameters(), [
            'cmid' => $cmid, 'userid' => $userid, 'submissionid' => $submissionid
        ]);

        $context = \context_module::instance($params['cmid']);
        self::validate_context($context);
        require_capability('mod/assign:grade', $context);

        // 1. Получаем все DOCX файлы этого задания
        $assign = $DB->get_record('assign', ['id' => $DB->get_field('course_modules', 'instance', ['id' => $params['cmid']])]);
        $sql = "SELECT s.id as subid, s.userid, f.id as fileid 
                FROM {assign_submission} s
                JOIN {files} f ON f.itemid = s.id
                WHERE s.assignment = :assignid 
                AND f.filename LIKE '%.docx'
                AND f.filesize > 0";
        $files = $DB->get_records_sql($sql, ['assignid' => $assign->id]);

        $current_text = '';
        foreach ($files as $f) {
            if ($f->userid == $params['userid']) {
                $current_text = self::extract_text_from_docx($f->fileid);
                break;
            }
        }

        $max_similarity = 0;
        $matched_userid = 0;

        foreach ($files as $f) {
            if ($f->userid != $params['userid']) {
                $other_text = self::extract_text_from_docx($f->fileid);
                $sim = self::calculate_similarity_ngram($current_text, $other_text, 3);
                if ($sim > $max_similarity) {
                    $max_similarity = $sim;
                    $matched_userid = $f->userid;
                }
            }
        }

        // 2. Сохраняем результат
        $record = new \stdClass();
        $record->cmid = $params['cmid'];
        $record->userid = $params['userid'];
        $record->submissionid = $params['submissionid'];
        $record->similarity_score = $max_similarity;
        $record->matched_userid = $matched_userid;
        $record->status = 'checked';
        $record->timemodified = time();

        $existing = $DB->get_record('plagiarism_simcheck', [
            'cmid' => $params['cmid'], 'userid' => $params['userid'], 'submissionid' => $params['submissionid']
        ]);

        if ($existing) {
            $record->id = $existing->id;
            $DB->update_record('plagiarism_simcheck', $record);
        } else {
            $DB->insert_record('plagiarism_simcheck', $record);
        }

        $matched_user = $DB->get_record('user', ['id' => $matched_userid], 'firstname, lastname');
        $matched_username = $matched_user ? fullname($matched_user) : 'Неизвестно';

        return [
            'status' => 'success',
            'score' => $max_similarity,
            'matched_userid' => $matched_userid,
            'matched_username' => $matched_username
        ];
    }

    /**
     * Извлечение текста из DOCX без Composer (нативный PHP)
     */
    private static function extract_text_from_docx($fileid) {
        $fs = get_file_storage();
        $file = $fs->get_file_by_id($fileid);
        if (!$file) return '';

        $tempdir = make_temp_directory('simcheck_docx');
        $tempfile = $tempdir . '/' . uniqid('doc_', true) . '.docx';
        $file->copy_content_to($tempfile);

        $text = '';
        $zip = new \ZipArchive();
        if ($zip->open($tempfile) === true) {
            $xmlContent = $zip->getFromName('word/document.xml');
            if ($xmlContent !== false) {
                libxml_use_internal_errors(true);
                $xml = simplexml_load_string($xmlContent);
                if ($xml) {
                    $xml->registerXPathNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
                    $nodes = $xml->xpath('//w:t');
                    $textParts = [];
                    foreach ($nodes as $node) {
                        $textParts[] = (string)$node;
                    }
                    $text = implode(' ', $textParts);
                }
                libxml_clear_errors();
            }
            $zip->close();
        }
        @unlink($tempfile);
        return trim(preg_replace('/\s+/', ' ', $text));
    }

    /**
     * Сравнение текстов через N-граммы (Шинглинг)
     */
    private static function calculate_similarity_ngram($text1, $text2, $n = 3) {
        $text1 = mb_strtolower($text1);
        $text2 = mb_strtolower($text2);
        $text1 = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text1);
        $text2 = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text2);

        $words1 = preg_split('/\s+/', trim($text1), -1, PREG_SPLIT_NO_EMPTY);
        $words2 = preg_split('/\s+/', trim($text2), -1, PREG_SPLIT_NO_EMPTY);

        if (count($words1) < $n || count($words2) < $n) {
            $intersection = count(array_intersect($words1, $words2));
            $union = count(array_unique(array_merge($words1, $words2)));
            return $union == 0 ? 0 : round(($intersection / $union) * 100);
        }

        $ngrams1 = self::get_ngrams($words1, $n);
        $ngrams2 = self::get_ngrams($words2, $n);

        $intersection = count(array_intersect($ngrams1, $ngrams2));
        $union = count(array_unique(array_merge($ngrams1, $ngrams2)));

        return $union == 0 ? 0 : round(($intersection / $union) * 100);
    }

    private static function get_ngrams($words, $n) {
        $ngrams = [];
        $count = count($words) - $n + 1;
        for ($i = 0; $i < $count; $i++) {
            $ngrams[] = implode(' ', array_slice($words, $i, $n));
        }
        return $ngrams;
    }
}