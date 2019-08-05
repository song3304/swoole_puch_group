<?php
require_once __DIR__ . '/PHPSocketIO/autoload.php';

use App\TaskServer\TaskServer;

$taskServer = new TaskServer();
$taskServer->workStart();