<?php
require_once __DIR__ . '/PHPSocketIO/autoload.php';

use App\SocketIoServer;

$notifyServer = new SocketIoServer('5650');
$notifyServer->startServer();