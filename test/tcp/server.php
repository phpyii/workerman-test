<?php

/**
 * run with command 
 * php server.php start
 */
ini_set('display_errors', 'on');

use Workerman\Worker;
use core\common\Config;

//定义常量
define('WORKERMAN_APP_DEBUG', true);
define('WORKERMAN_APP_ENV', 'dev');

//自动加载类文件
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../Autoloader.php';
spl_autoload_register(['Autoloader', 'autoload'], true, true);
//读取配置
Config::$config = require(__DIR__ . '/../../config/tcp.php');

// worker 任务进程
$work = new core\work\TcpWorker(Config::get('tcp'));
$work->apiConfig = Config::get('api');
$work->keepAliveTimeout = Config::get('keep_alive_timeout');
Worker::runAll();
