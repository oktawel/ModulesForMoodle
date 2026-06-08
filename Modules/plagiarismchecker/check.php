<?php

require_once('../../config.php');

require_login();

header('Content-Type: application/json; charset=utf-8');

error_reporting(E_ALL & ~E_WARNING);
ini_set('display_errors', 0);

$cmid = required_param('assignid', PARAM_INT);
$userid = required_param('userid', PARAM_INT);

$cm = get_coursemodule_from_id(
    'assign',
    $cmid,
    0,
    false,
    MUST_EXIST
);

$assignid = $cm->instance;

require_once($CFG->dirroot . '/local/plagiarismchecker/classes/service.php');

$result = \local_plagiarismchecker\service::run(
    $assignid,
    $userid
);

echo json_encode($result);
exit;