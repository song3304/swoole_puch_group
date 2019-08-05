<?php
namespace PHPSocketIO;
use PHPSocketIO\Engine\Engine;
class SocketIO
{
    public $nsps = array();
    protected $_nsp = null;
    protected $_socket = null;
    protected $_adapter = null;
    public $eio = null;
    public $engine = null;
    protected $_origins = '*:*';
    protected $_path = null;
	public $worker=null;
	//
	public $fd2sockers=[];

    public function __construct($port = null, $opts = array())
    {
        $nsp = isset($opts['nsp']) ? $opts['nsp'] : '\PHPSocketIO\Nsp';
        $this->nsp($nsp);

        $socket = isset($opts['socket']) ? $opts['socket'] : '\PHPSocketIO\Socket';
        $this->socket($socket);

        $adapter = isset($opts['adapter']) ? $opts['adapter'] : '\PHPSocketIO\DefaultAdapter';
        $this->adapter($adapter);
        if(isset($opts['origins']))
        {
            $this->origins($opts['origins']);
        }

        unset($opts['nsp'], $opts['socket'], $opts['adapter'], $opts['origins']);

        $this->sockets = $this->of('/');

        if(!class_exists('Protocols\SocketIO'))
        {
            class_alias('PHPSocketIO\Engine\Protocols\SocketIO', 'Protocols\SocketIO');
        }

    }

    public function nsp($v = null)
    {
         if (empty($v)) return $this->_nsp;
         $this->_nsp = $v;
         return $this;
    }

    public function socket($v = null)
    {
         if (empty($v)) return $this->_socket;
         $this->_socket = $v;
         return $this;
    }

    public function adapter($v = null)
    {
         if (empty($v)) return $this->_adapter;
         $this->_adapter = $v;
         foreach($this->nsps as $nsp)
         {
             $nsp->initAdapter();
         }
         return $this;
    }

    public function origins($v = null)
    {
        if ($v === null) return $this->_origins;
        $this->_origins = $v;
        if(isset($this->engine)) {
            $this->engine->origins = $this->_origins;
        }
        return $this;
    }
    
    /*Dosgo*/
    function swbind($serv){
        $this->attach($serv);
        $serv->Connections=array();//
        $serv->io=$this;
        $serv->on('connect', function ($serv, $fd){
            call_user_func_array(array('PHPSocketIO\Swoole\WorkermanApi','WorkerConnect'),[$serv,$fd]);
        });
        $serv->on('receive', function ($serv, $fd, $from_id, $data){
            call_user_func_array(array('PHPSocketIO\Swoole\WorkermanApi','WorkerMessage'),[$serv,$fd,$data]);
        });
        $serv->on('close', function ($serv, $fd) {
            call_user_func_array(array('PHPSocketIO\Swoole\WorkermanApi','Free'),[$serv,$fd]);
        });
    }
    
    

    public function attach($serv)
    {
         $engine = new Engine();
         $this->eio = $engine->attach($serv);

         // Export http server
         $this->worker = $serv;

         // bind to engine events
         $this->bind($engine);

         return $this;
    }

    public function bind($engine)
    {
        $this->engine = $engine;
        $this->engine->on('connection', array($this, 'onConnection'));
        $this->engine->origins = $this->_origins;
        return $this;
    }

    public function of($name, $fn = null)
    {
        if($name[0] !== '/')
        {
            $name = "/$name";
        }
        if(empty($this->nsps[$name]))
        {
            $nsp_name = $this->nsp();
            $this->nsps[$name] = new $nsp_name($this, $name);
        }
        if ($fn)
        {
            $this->nsps[$name]->on('connect', $fn);
        }
        return $this->nsps[$name];
    }

    public function onConnection($engine_socket)
    {
        $client = new Client($this, $engine_socket);
        $client->connect('/');
        return $this;
    }

    public function on()
    {
        $args = array_pad(func_get_args(), 2, null);

        if ($args[0] === 'workerStart') {
           $this->worker->onWorkerStart = $args[1];
        } else if ($args[0] === 'workerStop') {
           $this->worker->onWorkerStop = $args[1];
        } else if ($args[0] !== null) {
            return call_user_func_array(array($this->sockets, 'on'), $args);
        }
    }

    public function in()
    {
        return call_user_func_array(array($this->sockets, 'in'), func_get_args());
    }

    public function to()
    {
        return call_user_func_array(array($this->sockets, 'to'), func_get_args());
    }

    public function emit()
    {
        return call_user_func_array(array($this->sockets, 'emit'), func_get_args());
    }

    public function send()
    {
        return call_user_func_array(array($this->sockets, 'send'), func_get_args());
    }

    public function write()
    {
        return call_user_func_array(array($this->sockets, 'write'), func_get_args());
    }
}
