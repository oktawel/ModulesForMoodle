<?php
namespace plagiarism_simcheck\privacy;

defined('MOODLE_INTERNAL') || die();

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\writer;

class provider implements \core_privacy\local\metadata\provider, \core_privacy\local\request\plugin\provider {
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table(
            'plagiarism_simcheck',
            [
                'userid' => 'privacy:metadata:userid',
                'similarity_score' => 'privacy:metadata:score',
            ],
            'privacy:metadata:tableexplanation'
        );
        return $collection;
    }

    public static function export_user_data(approved_contextlist $contextlist) {
        // Минимальная реализация для соответствия API
    }
}