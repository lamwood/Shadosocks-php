<?php
/**
 * @author Wood Lin <frozen2way@gmail.com>
 * shadowsock 服务器端程序文件
 */
use Workerman\Worker;
use Workerman\Connection\AsyncTcpConnection;
use Workerman\Autoloader;

//自动加载
require_once ROOT_PATH.'/Workerman/Autoloader.php';
require_once APP_PATH.'/config.php';
Autoloader::setRootPath(__DIR__);

// 状态相关
define('STAGE_INIT', 0);
define('STAGE_ADDR', 1);
define('STAGE_UDP_ASSOC', 2);
define('STAGE_DNS', 3);
define('STAGE_CONNECTING', 4);
define('STAGE_STREAM', 5);
define('STAGE_DESTROYED', -1);
// 请求地址类型
define('ADDRTYPE_IPV4', 1);
define('ADDRTYPE_IPV6', 4);
define('ADDRTYPE_HOST', 3);

//将屏幕打印输出到Worker::$stdoutFile指定的文件中
Worker::$stdoutFile = ROOT_PATH.'/shadowsocks.log';
//设置所有连接的默认应用层发送缓冲区大小2M
AsyncTcpConnection::$defaultMaxSendBufferSize = 2 * 1024 * 1024;
//初始化worker，监听$PORT端口
$Worker = new Worker('tcp://'.$SERVER['host'].':'.$SERVER['port']);
//进程数量
$Worker->count = $SERVER['process'];
//名称
$Worker->name = 'Shadowsocks-server';
//当shadowsocks客户端连上来时
$Worker->onConnect = function($connection)use($SERVER){
    //if(!in_array($connection->getRemoteIp(), ['192.168.1.80'])){
        //return $connection->close();
    //}
    //设置当前连接的状态为STAGE_INIT，初始状态
    $connection->stage = STAGE_INIT;
    //初始化加密类
    $connection->encryptor = new Encryptor($SERVER['password'], $SERVER['method']);
};
//当shadowsocks客户端发来消息时
$Worker->onMessage = function($connection, $buffer){
    //判断当前连接状态
    switch($connection->stage){
        case STAGE_INIT:
        case STAGE_ADDR:
            //先解密数据
            $buffer = $connection->encryptor->decrypt($buffer);
            //解析socket5头
            $header_data = parse_socket5_header($buffer);
            //解析头部出错，则关闭连接
            if(!$header_data){
                return $connection->close();
            }
            //头部长度
            $header_len = $header_data[3];
            //解析得到实际请求地址及端口
            $host = $header_data[1];
            $port = $header_data[2];
            $address = 'tcp://'.$host.':'.$port;
            if(empty($host) || empty($port)){
                return $connection->close();
            }
            //异步建立与实际服务器的远程连接
            $remote_connection = new AsyncTcpConnection($address);
            $connection->opposite = $remote_connection;
            $remote_connection->opposite = $connection;
            //流量控制，远程连接的发送缓冲区满，则暂停读取shadowsocks客户端发来的数据
            //避免由于读取速度大于发送速度导致发送缓冲区爆掉
            $remote_connection->onBufferFull = function($remote_connection){
                $remote_connection->opposite->pauseRecv();
            };
            //流量控制，远程连接的发送缓冲区发送完毕后，则恢复读取shadowsocks客户端发来的数据
            $remote_connection->onBufferDrain = function($remote_connection){
                $remote_connection->opposite->resumeRecv();
            };
            //远程连接发来消息时，进行加密，转发给shadowsocks客户端，shadowsocks客户端会解密转发给浏览器
            $remote_connection->onMessage = function($remote_connection, $buffer){
                $remote_connection->opposite->send($remote_connection->opposite->encryptor->encrypt($buffer));
            };
            //远程连接断开时，则断开shadowsocks客户端的连接
            $remote_connection->onClose = function($remote_connection){
                //关闭对端
                $remote_connection->opposite->close();
                $remote_connection->opposite = null;
            };
            //远程连接发生错误时（一般是建立连接失败错误），关闭shadowsocks客户端的连接
            $remote_connection->onError = function($remote_connection, $code, $msg)use($address){
                echo 'remote_connection '.$address.' error code:'.$code.' msg:'.$msg."\n";
                $remote_connection->close();
                if(!empty($remote_connection->opposite)){
                    $remote_connection->opposite->close();
                }
            };
            //流量控制，shadowsocks客户端的连接发送缓冲区满时，则暂停读取远程服务端的数据
            //避免由于读取速度大于发送速度导致发送缓冲区爆掉
            $connection->onBufferFull = function($connection){
                $connection->opposite->pauseRecv();
            };
            //流量控制，当shadowsocks客户端的连接发送缓冲区发送完毕后，继续读取远程服务端的数据
            $connection->onBufferDrain = function($connection){
                $connection->opposite->resumeRecv();
            };
            //当shadowsocks客户端发来数据时，解密数据，并发给远程服务端
            $connection->onMessage = function($connection, $data){
                $connection->opposite->send($connection->encryptor->decrypt($data));
            };
            //当shadowsocks客户端关闭连接时，关闭远程服务端的连接
            $connection->onClose = function($connection){
                $connection->opposite->close();
                $connection->opposite = null;
            };
            //当shadowsocks客户端连接上有错误时，关闭远程服务端连接
            $connection->onError = function($connection, $code, $msg){
                echo 'connection err code:'.$code.' msg:'.$msg."\n";
                $connection->close();
                if(isset($connection->opposite)){
                    $connection->opposite->close();
                }
            };
            //执行远程连接
            $remote_connection->connect();
            //改变当前连接的状态为STAGE_STREAM，即开始转发数据流
            $connection->state = STAGE_STREAM;
            //shadowsocks客户端第一次发来的数据超过头部，则要把头部后面的数据发给远程服务端
            if(strlen($buffer) > $header_len){
                $remote_connection->send(substr($buffer, $header_len));
            }
            break;
        default:
            break;
    }
};

/**
 * 解析shadowsocks客户端发来的socket5头部数据
 * @param string $buffer
 * 包头格式
    +--------------+---------------------+------------------+----------+
    | Address Type | Destination Address | Destination Port |   Data   |
    +--------------+---------------------+------------------+----------+
    |      1       |       Variable      |         2        | Variable |
    +--------------+---------------------+------------------+----------+
 */
function parse_socket5_header($buffer){
    if(strlen($buffer) < 1){
        return false;
    }
    $addr_type = ord($buffer[0]);
    switch($addr_type){
        case ADDRTYPE_IPV4:
            $header_length = 7;
            if(strlen($buffer) < $header_length){
                return false;
            }
            $dest_addr = ord($buffer[1]).'.'.ord($buffer[2]).'.'.ord($buffer[3]).'.'.ord($buffer[4]);
            $port_data = unpack('n', substr($buffer, 5, 2));
            $dest_port = $port_data[1];
            break;
        case ADDRTYPE_HOST:
            if(strlen($buffer) < 2){
                return false;
            }
            $addrlen = ord($buffer[1]);
            $header_length = $addrlen + 4;
            if(strlen($buffer) < $header_length){
                return false;
            }
            $dest_addr = substr($buffer, 2, $addrlen);
            $port_data = unpack('n', substr($buffer, 2 + $addrlen, 2));
            $dest_port = $port_data[1];
            break;
       case ADDRTYPE_IPV6:
            $header_length = 19;
           if(strlen($buffer) < $header_length){
               return false;
           }
           $dest_addr = inet_ntop(substr($buffer, 1, 16));
           $port_data = unpack('n', substr($buffer, 17, 2));
           $dest_port = $port_data[1];
            return false;
       default:
            echo 'unsupported addrtype '.$addr_type."\n";
            return false;
    }
    return [$addr_type, $dest_addr, $dest_port, $header_length];
}