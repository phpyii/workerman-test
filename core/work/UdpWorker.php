<?php

namespace core\work;

use Workerman\Worker;
use Workerman\Connection\AsyncUdpConnection;
use Workerman\Protocols\Http\Response;
use core\common\Util;

/**
 * Description of UdpWorker
 * 给udp客户端发送消息
 * @author phpyii
 */
class UdpWorker extends Worker {

    /**
     * Name of the worker processes.
     *
     * @var string
     */
    public $name = 'UdpWorker';

    /**
     * reloadable.
     *
     * @var bool
     */
    public $reloadable = false;

    /**
     * api配置
     *
     * @var string
     */
    public $apiConfig = [
        'socket_name' => 'http://0.0.0.0:1080',
        'context_option' => [],
        'ssl' => false,
    ];

    /**
     * 所有的客户端链接
     *
     * @var array
     */
    protected $_clients = [];

    /**
     * 所有的客户端链接
     *
     * @var array
     */
    public $client_port = 17000;
    
    /**
     * 构造函数
     *
     * @param string $socket_name
     * @param array $context_option
     */
    public function __construct($socket_name, $context_option = []) {
        parent::__construct($socket_name, $context_option);
        $this->onMessage = [$this, 'onClientMessage'];
        $this->onWorkerStart = [$this, 'onStart'];
    }

    /**
     * 进程启动后初始化事件分发器客户端
     * @return void
     */
    public function onStart($worker) {
        $apiWorker = new Worker($this->apiConfig['socket_name'], $this->apiConfig['context_option']);
        if ($this->apiConfig['ssl']) {
            $apiWorker->transport = 'ssl';
        }
        $apiWorker->onMessage = [$this, 'onApiClientMessage'];
        $apiWorker->listen();
    }

    /**
     * 客户端发来消息时
     * @param $connection
     * @param string $message
     * @return void
     */
    public function onClientMessage($connection, $message) {
        // debug
        Util::echoText($message . ' 来自'. $connection->getRemoteIp());
        $data = Util::jsonDecode($data);
        if (!empty($data)) {
            return;
        }
        $event = $data['event'];
        switch ($event) {
            case ':ping':
                $connection->send('{"event":"pong","data":"{}"}');
                return;
        }
    }

    /**
     * 来自http消息
     * http://127.0.0.1:1080/event/add_client
     * @param \Workerman\Connection\TcpConnection $connection
     * @param \Workerman\Protocols\Http\Request $request
     * @return type
     */
    public function onApiClientMessage($connection, $request) {
        //$connection->getRemoteIp()
        $requestData = [];
        $requestType = $request->method(); //请求类型
        if (strtoupper($requestType) == 'POST') {
            $requestData = $request->post();
            if (empty($requestData)) {
                $requestData = $request->rawBody();
            }
        }
        else{
           $requestData = $request->get(); 
        }
        $path = $request->path();
        $explode = explode('/', $path);
        $path_info = [];
        $i = 0;
        $key = '';
        foreach ($explode as $value) {
            if ($i === 0) {
                $i++;
                continue;
            }
            if ($i % 2 === 1) {
                $key = $value;
                $path_info[$key] = '';
            } else {
                $path_info[$key] = $value;
            }
            $i++;
        }
        $eventType = '';
        if (isset($path_info['event'])) {
            $eventType = 'event';
            if (empty($requestData)) {
                $response = new Response(400, [] , 'Bad Request');
                return $connection->send($response);
            }
        }
        //验证签名安全处理等省略
        switch ($eventType) {
            case 'event':
                $event = $path_info['event'];
                Util::echoText('事件：' . $event);
                switch ($event) {
                    case 'add_client':
                        $this->_clients[$requestData['client_id']] = ['ip' => $requestData['ip'], 'device' => $requestData['device'], 'client_id' => $requestData['client_id']];
                        break;
                    case 'send_client':
                        $this->sendClient($requestData['client_id'], $requestData['data']);
                        break;
                    case 'send_client_all':
                        $this->sendClientAll($requestData['data'], $requestData['device'] ?? 'all');
                        break;
                }
                return $connection->send('{"status": 200,"errcode": 0,"errmsg":"", "data": "{}"}');
            default :
                $response = new Response(400, [] , 'Bad Request');
                return $connection->send($response);
        }
    }

    public function sendClient($hard_idcode, $data) {
        if(isset($this->_clients[$hard_idcode])){
            $udp_connection = new AsyncUdpConnection('udp://'. $this->_clients[$hard_idcode]['ip'] .':'. $this->client_port);
            $udp_connection->onConnect = function($udp_connection) use ($data){
                $udp_connection->send(json_encode($data));
            };
            $udp_connection->onMessage = function($udp_connection, $data) {
                // 收到服务端返回的数据就关闭连接
                //echo "recv $data\r\n";
                Util::echoText('响应后关闭udp连接');
                // 关闭连接
                $udp_connection->close();
            };
            $udp_connection->connect();
        }
    }

    /**
     * 发送给所有客户端
     * @param array $data
     * @param string $device
     */
    public function sendClientAll($data, $device = 'all') {
        foreach ($this->_clients as $client) {
            if ($device == 'all' || $client['device'] == $device) {
                $this->sendClient($client['client_id'], $data);
            }
        }
    }

}