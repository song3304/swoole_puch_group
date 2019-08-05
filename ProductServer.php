<?php
require_once __DIR__ . '/PHPSocketIO/autoload.php';

foreach(glob(__DIR__.'/App/TaskServer/Product/*.php') as $start_file)
{
    require_once $start_file;
    $className = basename($start_file,".php");
    $fullClassName = "App\\TaskServer\\Product\\".$className;
    $productServer = new $fullClassName();
    $productServer->workerStart();
}