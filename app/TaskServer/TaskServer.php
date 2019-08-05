<?php
namespace App\TaskServer;

use App\MsgIds;
use App\Model\ProductCatalog;
use App\Model\DbConnection;
use App\ShareValue;

class TaskServer
{
    private $_serv =null;
    private $_shareValue;
    public static $_product_sock = [
        '11'=>[],//示例 分类=》[产品id...]
        '23'=>[]
    ]; 
    
    public function __construct($config=[]){
        $this->conf = include __DIR__.'/../config/gateway.php';
        list($address,$port) = explode(':', $this->conf['gateway_addr']);
        $serv = new \Swoole\Server($address, $port);
        
        $this->_serv = $serv;
        !empty($config) && $this->_serv->set($config);
        $this->_shareValue = new ShareValue();
        //初始化
        $conf = $this->conf['database'];
        $db = new DbConnection($conf['host'], $conf['port'], $conf['user'], $conf['password'], $conf['dbname'], $conf['charset']);
        static::$_product_sock = (new ProductCatalog($db))->getProductList();
    }
    
    public function dispatchEvent($serv,$fd,$from_id,$data){
        $json = json_decode($data);
        if (!$json || !isset($json->id)) {
            return;
        }
        //print_r($json);
        switch ($json->id) {
            case MsgIds::MESSAGE_GATEWAY_TO_ALL :
            case MsgIds::MESSAGE_GATEWAY_TO_GROUP ://如果这个连接有效，发出
                $group_fds = explode(',', $this->_shareValue->get('JoinGroupNotify'));
                foreach ($group_fds as $gfd){
                    $this->_serv->getClientInfo($gfd) && $this->_serv->send($gfd,$data);
                }
                break;
            case MsgIds::MESSAGE_GATEWAY_TO_CLIENT ://首页登录,从哪来回哪去
                $this->_serv->getClientInfo($json->notify_fd) && $this->_serv->send($json->notify_fd,$data);
                break;
            case MsgIds::MESSAGE_GATEWAY_BUSSINESS :
                if($json->business_type == 'firstLogin'){
                    //通知相应的task
                    $catalog_id = $this->findCatalog($json->product_id);
                    if($catalog_id!=0 && !empty($product_fd=$this->_shareValue->get('catalog_'.$catalog_id))){
                        $json->notify_fd = $fd;//返回fd
                        $this->_serv->send($product_fd,json_encode($json));
                    }
                }elseif($json->business_type == 'JoinGroup'){//品种定时推广服务端
                    $catalog_id = isset($json->catalog_id)?$json->catalog_id:0;
                    if(isset(static::$_product_sock[$catalog_id])){
                        $this->_shareValue->set('catalog_'.$catalog_id,$fd);
                        $this->_serv->send($fd,json_encode(['id' => MsgIds::MESSAGE_JOIN_SUCCESS,'message'=>"join success"]));
                    }else{
                        $this->_serv->send($fd,json_encode(['id' => MsgIds::MSG_GROUP_ERROR,'message'=>"It can't find catalog_id in server"]));
                    }
                }elseif($json->business_type == 'JoinGroupNotify'){//分发服务器
                    $group_fds = explode(',', $this->_shareValue->get('JoinGroupNotify'));
                    if(!in_array($fd, $group_fds)){
                        $group_fds[] = $fd;
                        $this->_shareValue->set('JoinGroupNotify',join(',', $group_fds));
                    }
                    $this->_serv->send($fd,json_encode(['id' => MsgIds::MESSAGE_GATEWAY_BUSSINESS,'message'=>"join success"]));
                }
                break;
            default :
                //未定义的消息，不做处理
                //do nothing
                break;
        }
    }

    public function findCatalog($proudct_id){
        if(empty(static::$_product_sock)) return 0;
        foreach (static::$_product_sock as $key=>$items){
            if(in_array($proudct_id, $items)) return $key;
        }
        return 0;
    }
    
    public function workStart(){      
        $this->_serv->on('receive',function ($serv, $fd, $from_id, $data){
            $this->dispatchEvent($serv,$fd,$from_id,$data);
        });
        
        $this->_serv->on('close',function($serv,$fd){//删除保存fd
            //查找分发服务器
            $group_fds = explode(',', $this->_shareValue->get('JoinGroupNotify'));
            if(in_array($fd,$group_fds)){
                $group_fds = array_diff($group_fds, [$fd]);
                $this->_shareValue->set('JoinGroupNotify',join(',', $group_fds));
            }else{//品种定时推送
                $catalog_ids = array_keys(static::$_product_sock);
                foreach ($catalog_ids as $cid){
                    $cfd = $this->_shareValue->get('catalog_'.$cid);
                    ($cfd == $fd) && $this->_shareValue->del('catalog_'.$cid);
                }
            }
        });
        $this->_serv->start();
    }
}