<?php

namespace local_plagiarismchecker;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/doc_converter.php');
require_once($CFG->dirroot . '/mod/assign/locallib.php');

class service {

    public static function run(int $assignid, int $userid): array {

        global $DB;

        $results = [];

        // файлы текущего студента
        $targetfiles = self::get_student_files($assignid, $userid);

        if (empty($targetfiles)) {
            return [];
        }

        $submissions = $DB->get_records('assign_submission', [
            'assignment' => $assignid,
            'status' => 'submitted'
        ]);

        foreach ($submissions as $submission) {

            if ((int)$submission->userid === $userid) {
                continue;
            }

            $otherfiles = self::get_student_files($assignid, $submission->userid);

            if (empty($otherfiles)) {
                continue;
            }

            $user = $DB->get_record(
                'user',
                ['id' => $submission->userid],
                'id,firstname,lastname'
            );

            foreach ($targetfiles as $tfile) {
                foreach ($otherfiles as $ofile) {

                    if (empty($tfile['text']) || empty($ofile['text'])) {
                        continue;
                    }

                    $similarity = similarity_engine::compare(
                        $tfile['text'],
                        $ofile['text']
                    );

                    if ($similarity <= 0) {
                        continue;
                    }

                    $results[] = [
                        'userid' => $submission->userid,
                        'studentname' => fullname($user),

                        // файл текущего студента
                        'sourcefile' => $tfile['filename'],

                        // файл сравниваемого студента
                        'comparefile' => $ofile['filename'],

                        'similarity' => $similarity
                    ];
                }
            }
        }

        usort($results, function ($a, $b) {
            return $b['similarity'] <=> $a['similarity'];
        });

        return $results;
    }


    private static function get_student_files(int $assignid, int $userid): array {

        global $DB;

        $results = [];

        $assignrecord = $DB->get_record(
            'assign',
            ['id' => $assignid],
            '*',
            MUST_EXIST
        );

        $cm = get_coursemodule_from_instance(
            'assign',
            $assignid,
            0,
            false,
            MUST_EXIST
        );

        $context = \context_module::instance($cm->id);

        $assign = new \assign($context, $cm, $assignrecord);

        $submission = $assign->get_user_submission($userid, false);

        if (!$submission) {
            return [];
        }

        $fs = get_file_storage();

        $files = $fs->get_area_files(
            $context->id,
            'assignsubmission_file',
            'submission_files',
            $submission->id,
            'filename',
            false
        );

        foreach ($files as $file) {

            $filename = strtolower($file->get_filename());
            $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

            if ($extension !== 'docx' && $extension !== 'doc') {
                continue;
            }

            $tempdir = make_temp_directory('plagiarismchecker');
            $filepath = $tempdir . '/' . uniqid() . '.' . $extension;

            $file->copy_content_to($filepath);

            $text = '';

            if ($extension === 'docx') {
                $text = docx_extractor::extract($filepath);
            }

            if ($extension === 'doc') {

                $docxfile = doc_converter::convert_to_docx($filepath);

                if (!$docxfile) {
                    continue;
                }

                $text = docx_extractor::extract($docxfile);
            }

            if (!empty($text)) {
                $results[] = [
                    'filename' => $file->get_filename(),
                    'text' => $text
                ];
            }
        }

        return $results;
    }
}