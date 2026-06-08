<?php

namespace local_plagiarismchecker\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

use external_api;
use external_function_parameters;
use external_value;
use external_multiple_structure;
use external_single_structure;

class check_external extends external_api {

    public static function execute_parameters() {

        return new external_function_parameters([
            'assignid' => new external_value(
                PARAM_INT,
                'Assignment ID'
            ),
            'userid' => new external_value(
                PARAM_INT,
                'User ID'
            )
        ]);
    }

    public static function execute(
        int $assignid,
        int $userid
    ): array {

        global $PAGE;

        $params = self::validate_parameters(
            self::execute_parameters(),
            [
                'assignid' => $assignid,
                'userid' => $userid
            ]
        );

        $context = \context_system::instance();

        self::validate_context($context);

        $results =
            \local_plagiarismchecker\service::run(
                $params['assignid'],
                $params['userid']
            );

        return [
            'results' => $results
        ];
    }

    public static function execute_returns() {

        return new external_single_structure([

            'results' =>
                new external_multiple_structure(

                    new external_single_structure([

                        'userid' =>
                            new external_value(
                                PARAM_INT,
                                'User ID'
                            ),

                        'studentname' =>
                            new external_value(
                                PARAM_TEXT,
                                'Student name'
                            ),

                        'similarity' =>
                            new external_value(
                                PARAM_FLOAT,
                                'Similarity'
                            )

                    ])
                )

        ]);
    }
}