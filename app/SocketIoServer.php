<?php
namespace App;

use PHPSocketIO\SocketIO;
use App\StatisticClient;
use App\MsgIds;
use App\ClientWorker;
use App\Helper;
use App\ShareValue;

class SocketIoServer
{   
    // 数组保存uid在线数据
    public $uidConnectionMap = array();
    //当前在线uid数
    public $online_count_now = 0;
    //当前总共连接数
    public $online_page_count_now = 0;
    //socketio
    public $sender_io = null;
    //连接中心服务器的客户端
    public $client_worker = null;
    public $gateway_addr = '';
    //ssl
    public $ssl = [];
    
    static private function classNameForLog() {
        $class = join('_', explode('\\', __CLASS__));
        return $class;
    }
    
    
    public function __construct($socket_port = 2120)
    {
        //初始化数据
        $this->conf = include __DIR__.'/config/gateway.php';
        $this->initData();
        $this->_shareValue = new ShareValue();
        //如果传参，则覆盖配置
        $this->socket_port = $socket_port;
    }
    
    //加载配置
    private function initData()
    {
        $this->socket_port = $this->conf['socket_port'];
        $this->system_status = boolval($this->conf['system_status']);
        $this->ssl_switch = $this->conf['ssl_switch'];
        if ($this->ssl_switch === 'on')
        {
            //ssl配置
            $this->ssl = $this->conf['ssl_conf'];
        }
        else
        {
            $this->ssl = [];
        }
        $this->gateway_addr = $this->conf['gateway_addr'];
    }
    
    
    protected function firstLogin($product_id, $user_id, $client_id, $company_id = NULL) {
        $data = array(
            'id' => MsgIds::MESSAGE_GATEWAY_BUSSINESS,
            'business_type'=>'firstLogin',
            'client'=>$client_id,
            'product_id'=>$product_id,
            'user_id'=>$user_id,
            'company_id'=>$company_id
        );
        $this->client_worker->sendToGateway($data);
    }
    /*
     * gateway中心发来的消息，需要转给相应的client
     * @param json 格式的消息
     * @return 返回消息处理结果
     */
    protected function gatewayDispatch($msgType,$json)
    {
        StatisticClient::tick(self::classNameForLog(), __FUNCTION__);
        if (!isset($json->data))
        {
            StatisticClient::report(self::classNameForLog(), __METHOD__, FALSE, 0, 'data节点不存在');
            return;
        }
        
        if ($msgType == MsgIds::MESSAGE_GATEWAY_TO_GROUP && !isset($json->room))
        {
            StatisticClient::report(self::classNameForLog(), __FUNCTION__, FALSE, 0, 'room 节点不存在');
            return;
        }

        if ($msgType == MsgIds::MESSAGE_GATEWAY_TO_CLIENT && !isset($this->uidConnectionMap[$json->data->to_client])) {
            StatisticClient::report(self::classNameForLog(), __FUNCTION__, FALSE, 0, '客户端连接不存在');
            return;
        }
        $event_type = isset($json->data->event_type) ? (string)$json->data->event_type : '';
        if (empty($event_type)) {
            //消息类型为空
            StatisticClient::report(self::classNameForLog(), __FUNCTION__, FALSE, 0, '消息类型为空');
            return;
        }
        if($msgType == MsgIds::MESSAGE_GATEWAY_TO_ALL){
            $this->sender_io->emit($event_type, json_encode($json->data));
        }elseif($msgType == MsgIds::MESSAGE_GATEWAY_TO_GROUP){
            //var_dump($json);return ;
            $this->sender_io->to($json->room)->emit($event_type, json_encode($json->data));
        }elseif($msgType == MsgIds::MESSAGE_GATEWAY_TO_CLIENT){
           //var_dump($json);
            unset($json->id);
            if(!empty($this->uidConnectionMap[$json->data->to_client])){
                $this->uidConnectionMap[$json->data->to_client]['connection']->emit($event_type, json_encode($json->data));
            }
        }
        StatisticClient::report(self::classNameForLog(), __FUNCTION__, true, 0, '');
    }
    
    
    /*
     * gateway中心发来的业务消息，比如说需要请求一些数据，可以去中心查询
     * @param json 格式的消息
     * @return 返回消息处理结果
     */
    protected function gatewayBussinessMsgHandle($json)
    {
        //目前业务只有tcp client join业务
        //echo $json->message."\n";
    }
    
    public function onGatewayMessage($connection, $data)
    {
        // 查看是否需要发给订阅了消息的客户端
        $json = json_decode($data);
        if (!$json || !isset($json->id)) return;
        // 根据消息类型处理
        switch ($json->id)
        {
            case MsgIds::MESSAGE_GATEWAY_TO_ALL :
                $this->gatewayDispatch($json->id,$json);
                break;
            case MsgIds::MESSAGE_GATEWAY_TO_GROUP :
                $this->gatewayDispatch($json->id,$json);
                break;
            case MsgIds::MESSAGE_GATEWAY_TO_CLIENT :
                $this->gatewayDispatch($json->id,$json);
                break;
            case MsgIds::MESSAGE_GATEWAY_BUSSINESS :
                $this->gatewayBussinessMsgHandle($json);
                break;
                
            default :
                // 未知消息
                break;
        }
    }
    
    public function clientWorkerInit()
    {
        $group_info = ['id' => MsgIds::MESSAGE_GATEWAY_BUSSINESS,'business_type' => 'JoinGroupNotify'];
        // 初始化与gateway连接服务
        $client_worker = new ClientWorker($this->gateway_addr, $group_info);
        $this->client_worker = $client_worker;
        // 消息回调
        $this->onMessage = array($this, 'onGatewayMessage');
        $this->client_worker->onMessage = $this->onMessage;
    }
    public function startServer()
    {
        $worker = new \swoole_server('0.0.0.0', $this->socket_port);
        $worker->set(array(
            'worker_num' => 6,    //worker process num
            'dispatch_mode'=>4, //这个重点，模式必须是4，无效
        ));
        $sw_socket = $worker->getSocket();
        @socket_set_option($sw_socket, SOL_SOCKET, SO_KEEPALIVE, 1);
        @socket_set_option($sw_socket, SOL_TCP, TCP_NODELAY, 1);
        // PHPSocketIO服务
        $this->sender_io = new SocketIO();
        $this->sender_io->swbind($worker);
        // 客户端发起连接事件时，设置连接socket的各种事件回调
        $this->sender_io->on('connection', function($socket) {
            // 当客户端登录验证
            $socket->on('login', function ($uid)use($socket) {
                // 更新对应uid的在线数据
                $uid = $socket->id;     //重写uid，使用socket的唯一标识
                $uid = (string) $uid;
                //合法之后存入uid，这是登陆成功的标记
                $socket->uid = $uid;
                if (!isset($this->uidConnectionMap[$uid]))
                {
                    $this->uidConnectionMap[$uid]['count'] = 0;
                    $this->uidConnectionMap[$uid]['connection'] = $socket;
                }
                // 这个uid有++$uidConnectionMap[$uid]个socket连接
                ++$this->uidConnectionMap[$uid]['count'];

                // 通知登陆成功了
                $socket->emit('login_success', "login");
            });
                
            // 用户注册自己订阅的服务
            $socket->on('register', function ($uid, $product_id, $match_id, $company_id = NULL)use($socket) {
                    $uid = $socket->uid;
                    if (!isset($socket->uid))
                    {
                        return;
                    }
                    // 将这个连接加入到uid分组，方便针对uid推送数据
                    $roomId = Helper::roomId($uid, $product_id, $match_id, $company_id);
                    // 进入房间名单
                    $socket->join($roomId);
//                     // 通知进入房间了
                    $this->sender_io->to($roomId)->emit('member_enter', $uid, $product_id, $match_id);
                    $this->firstLogin($product_id, $match_id, $socket->uid, $company_id);
            });
   
            // 当客户端断开连接时触发（一般是关闭网页或者跳转刷新导致）
            $socket->on('disconnect', function () use($socket) {
                $socket->leaveAll();
                if (!isset($socket->uid)) return;
                // 将uid的在线socket数减一
                if (isset($this->uidConnectionMap[$socket->uid]['count']) &&
                    --$this->uidConnectionMap[$socket->uid]['count'] <= 0)
                        {
                            unset($this->uidConnectionMap[$socket->uid]);
                        }
            });
        });
        
        
       $worker->on('WorkerStart', function() {
          $this->clientWorkerInit();
       });
       
       $worker->start();
    }
}