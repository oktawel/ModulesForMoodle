<?php
defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/plagiarism/lib.php');

class plagiarism_plugin_simcheck extends plagiarism_plugin {

    /**
     * Проверяет, включен ли плагин
     */
    public function is_enabled($cmid = null) {
        global $CFG;
        
        if (empty($CFG->plagiarism_simcheck_enabled)) {
            return false;
        }
        
        if ($cmid) {
            $context = context_module::instance($cmid);
            if (!has_capability('mod/assign:grade', $context)) {
                return false;
            }
        }
        
        return true;
    }

    /**
     * Возвращает название плагина
     */
    public function get_name() {
        return get_string('pluginname', 'plagiarism_simcheck');
    }

    /**
     * Основной метод - добавляет кнопки в таблицу оценивания
     */
    public function get_links($params) {
        global $USER, $DB, $PAGE, $OUTPUT;

        // Проверяем, что это задание
        if (!isset($params->modname) || $params->modname !== 'assign') {
            return '';
        }

        // Проверяем права преподавателя
        if (!isset($params->cmid)) {
            return '';
        }
        
        $context = context_module::instance($params->cmid);
        if (!has_capability('mod/assign:grade', $context)) {
            return '';
        }

        // Проверяем, есть ли уже результат проверки
        $record = $DB->get_record('plagiarism_simcheck', [
            'cmid' => $params->cmid,
            'userid' => $params->userid,
            'submissionid' => $params->itemid
        ]);

        if ($record && $record->status === 'checked') {
            $color = $record->similarity_score >= 50 ? 'text-danger' : 'text-success';
            $matched_user = $DB->get_record('user', ['id' => $record->matched_userid], 'firstname, lastname');
            $name = $matched_user ? fullname($matched_user) : 'Нет совпадений';
            
            $result_text = "Схожесть: {$record->similarity_score}% (с {$name})";
            return html_writer::tag('span', $result_text, [
                'class' => "simcheck-result {$color} font-weight-bold ml-2"
            ]);
        }

        // Подключаем JavaScript
        $PAGE->requires->js_call_amd('plagiarism_simcheck/simcheck', 'init', [$params->cmid]);

        // Возвращаем кнопку проверки
        $attributes = [
            'class' => 'btn btn-sm btn-outline-primary simcheck-btn',
            'data-cmid' => $params->cmid,
            'data-userid' => $params->userid,
            'data-submissionid' => $params->itemid,
            'type' => 'button',
            'title' => 'Проверить на плагиат'
        ];
        
        return html_writer::tag('button', '🔍 Проверить', $attributes);
    }

    /**
     * Hook для обновления оценок
     */
    public function update_grade($course, $cm, $userid, $grade) {
        return true;
    }

    /**
     * Hook для получения настроек
     */
    public function get_settings($cmid) {
        return true;
    }
}