<?php
namespace plagiarism_simcheck;

defined('MOODLE_INTERNAL') || die();

class plugintype_simcheck extends \plagiarism_plugin {
    
    /**
     * Hook function to display links
     */
    public function get_links($params) {
        global $DB;
        
        if (!isset($params->cmid) || !isset($params->userid)) {
            return '';
        }
        
        $context = \context_module::instance($params->cmid);
        if (!has_capability('mod/assign:grade', $context)) {
            return '';
        }
        
        // Проверяем наличие результата
        $record = $DB->get_record('plagiarism_simcheck', [
            'cmid' => $params->cmid,
            'userid' => $params->userid
        ]);
        
        if ($record && $record->status === 'checked') {
            $color = $record->similarity_score >= 50 ? 'text-danger' : 'text-success';
            return "<span class='{$color}'>Схожесть: {$record->similarity_score}%</span>";
        }
        
        return '';
    }
}