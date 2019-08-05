<?php
namespace App;

/**
 * Description of ClientWorker
 *
 * @author Xp
 */
class ClientWorker {

    /**
     * 保存与gateway的连接connection对象
     * @var array
     */
    public $gatewayConnection = null;

    /**
     * 需要发送的数据
     * @var array
     */
    protected $_dataToSend = array();

    /*
     *  gateway地址
     *  @var string
     */
    public $gatewayAddr = '';

    /*
     *  消息处理
     *  @var method
     */
    public $onMessage = null;

    /**
     * 构造函数
     * @param string $socket_name
     * @param array $context_option
     */
    public function __construct($gateway_addr = '127.0.0.1:8282', $group_info = NULL) {
        $this->gatewayAddr = $gateway_addr;
        $this->groupInfo = $group_info;
        $this->checkGatewayConnections();
        //断开时触发重连
        swoole_timer_tick(1000, function(){
            $this->checkGatewayConnections();
        });
    }

    public function joinGroup() {
        if (empty($this->groupInfo)) {
            return;
        } else {
            $this->sendToGateway($this->groupInfo);
        }
    }

    public function sendToGateway($msg) {
        if (!is_string($msg)) {
            $msg = json_encode($msg);
        }
        if ($msg === FALSE) {
            //错误了
            return;
        }
        if (!is_null($this->gatewayConnection)) {
            $this->gatewayConnection->send($msg);
        } else {
            // 没有连接上
            //Worker::log("connect to $this->gatewayAddr failed!");
        }
    }

    /**
     * 当gateway发来数据时
     * @param TcpConnection $connection
     * @param string $data
     */
    public function onGatewayMessage($connection, $data) {
        // TODO:远端服务器发来消息
        if ($this->onMessage) {
            call_user_func($this->onMessage, $connection, $data);
        }
    }

    /**
     * 当与Gateway的连接断开时触发
     * @param TcpConnection $connection
     * @return  void
     */
    public function onClose($connection) {
        unset($this->gatewayConnection);
        $this->gatewayConnection = null;
        //Worker::log("$connection->remoteAddress" . " has closed...");
    }

    /**
     * 检查gateway的通信端口是否已经连接
     * 如果没有连接，尝试连接
     * @return void
     */
    public function checkGatewayConnections() {
        $addr = $this->gatewayAddr;
        if (is_null($this->gatewayConnection)) {
            list($host,$port) = explode(":", $addr);
            $gateway_connection = new \swoole_client(SWOOLE_SOCK_TCP, SWOOLE_SOCK_ASYNC);
            //$gateway_connection->remoteAddress = $addr;
            $gateway_connection->on('connect',function($connection){$this->onConnectGateway($connection);});
            $gateway_connection->on('receive',function($connection,$data){$this->onGatewayMessage($connection,$data);});
            $gateway_connection->on('close',function($connection){$this->onClose($connection);});
            $gateway_connection->on('error',function($connection){$this->onError($connection);});
            $gateway_connection->set(['socket_buffer_size'     => 1024*1024*10]);
            $gateway_connection->connect($host,$port);
        }
    }

    /**
     * 当连接上gateway的通讯端口时触发
     * 将连接connection对象保存起来
     * @param TcpConnection $connection
     * @return void
     */
    public function onConnectGateway($connection) {
        $this->gatewayConnection = $connection;
        $this->joinGroup();
    }

    /**
     * 当与gateway的连接出现错误时触发
     * @param TcpConnection $connection
     * @param int $error_no
     * @param string $error_msg
     */
    public function onError($connection) {
        if ($connection->errCode === 102) {//ENETRESET
            $this->tryToDeleteGatewayAddress($this->gatewayAddr, $error_msg);
            return;
        } else if ($connection->errCode === 70) {//ECOMM
            // 发送失败 
            // TODO:应该保存下来，重新发送
            //Worker::log("send fail\n");
        }
    }

    /**
     * 删除连不上的gateway通讯端口
     * @param string $addr
     * @param string $errstr
     */
    public function tryToDeleteGatewayAddress($addr, $errstr) {
        // 删除所有设置，以方便下次重新连接
        unset($this->gatewayConnection);
       // Worker::log("tcp://$addr " . $errstr . " $addr will reconnect");
    }

}
