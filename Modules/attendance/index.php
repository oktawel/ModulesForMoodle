<?php

require('../../config.php');

$courseid = required_param('id', PARAM_INT);

require_login();

echo $OUTPUT->header();

echo 'Список журналов посещаемости';

echo $OUTPUT->footer();