<?php
spl_autoload_register(function($name){
    $path = str_replace('\\', DIRECTORY_SEPARATOR ,$name);
    //$path = str_replace('PHPSocketIO', '', $path);
    //file_put_contents('a.txt', __DIR__ . "/../$path.php\n",FILE_APPEND);
    if(is_file($class_file = __DIR__ . "/../$path.php"))
    {
        require_once($class_file);
        if(class_exists($name, false))
        {
            return true;
        }
    }
    return false;
});

