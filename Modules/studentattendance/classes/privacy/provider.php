<?php
namespace mod_studentattendance\privacy;

defined('MOODLE_INTERNAL') || die();

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\writer;

class provider implements 
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\plugin\provider 
{
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('studentattendance_records', [
            'studentid' => 'privacy:metadata:studentattendance_records:studentid',
            'status' => 'privacy:metadata:studentattendance_records:status',
        ], 'privacy:metadata:studentattendance_records');
        return $collection;
    }

    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        $sql = "SELECT c.id
                  FROM {context} c
                  JOIN {course_modules} cm ON cm.id = c.instanceid AND c.contextlevel = :contextlevel
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                  JOIN {studentattendance} a ON a.id = cm.instance
                  JOIN {studentattendance_sessions} s ON s.attendanceid = a.id
                  JOIN {studentattendance_records} r ON r.sessionid = s.id
                 WHERE r.studentid = :userid";
        $contextlist->add_from_sql($sql, [
            'contextlevel' => CONTEXT_MODULE,
            'modname' => 'studentattendance',
            'userid' => $userid
        ]);
        return $contextlist;
    }

    public static function export_user_data(approved_contextlist $contextlist) {
    }

    public static function delete_data_for_all_users_in_context(\context $context) {
        global $DB;
        if ($context->contextlevel != CONTEXT_MODULE) return;
        $cm = get_coursemodule_from_id('studentattendance', $context->instanceid);
        if (!$cm) return;
        $sessions = $DB->get_records('studentattendance_sessions', ['attendanceid' => $cm->instance]);
        foreach ($sessions as $session) {
            $DB->delete_records('studentattendance_records', ['sessionid' => $session->id]);
        }
    }

    public static function delete_data_for_user(approved_contextlist $contextlist) {
    }
}