<?php
namespace App;

use \swoole_table;

class ShareValue
{
    private $_share_values;
    
    public function __construct($cnt=100,$length=255){
        $table = new swoole_table($cnt);
        $table->column('value', swoole_table::TYPE_STRING, 255);
        $table->create();
        $this->_share_values = $table;
    }
    
    public function get($key){
        return $this->_share_values->get($key,'value');
    }
    
    public function set($key,$value){
        $this->_share_values->set($key,['value'=>$value]);
    }
    
    public function del($key){
        return $this->_share_values->del($key);
    }
}