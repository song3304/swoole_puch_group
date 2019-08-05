<?php
ini_set('memory_limit','64M');
require_once __DIR__ . '/../PHPSocketIO/autoload.php';
use PHPSocketIO\SocketIO;
use App\Helper;
$worker = new \swoole_server('0.0.0.0', 5650);		
$worker->set(array(
    'worker_num' => 1,    //worker process num
    'dispatch_mode'=>4, 
));
$io = new SocketIO();
$io->swbind($worker);
$io->on('connection', function($socket)use($io){
     $socket->on('message', function ($data)use($socket){
            echo 'message:'.$data."\n";
            $socket->emit('message',$data);
     });
         $socket->on('login', function ($uid)use($socket) {
             // 通知登陆成功了
             $socket->emit('login_success', "login");
         });
             $socket->on('register', function ($uid, $product_id, $match_id, $company_id = NULL)use($socket,$io) {
             echo 'register';
             $uid = $socket->id;
             if (!isset($socket->uid))
             {
                 return;
             }
             // 将这个连接加入到uid分组，方便针对uid推送数据
             $roomId = Helper::roomId($uid, $product_id, $match_id, $company_id);
             // 进入房间名单
             $socket->join($roomId);
             // 通知进入房间了
             $io->to($roomId)->emit('member_enter', $uid, $product_id, $match_id);
         });
    // when the user disconnects.. perform this
    $socket->on('disconnect', function () use($socket) {
		
   });
   //$socket->send("hello");  
});
$worker->start();
