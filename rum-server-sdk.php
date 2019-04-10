<?php 

/*  使用文档  */
/*
 
$rumClient = new RUMClient("unix:///tmp/rumagent.sock", 1003, '2b88ffa3-d269-4c9a-8786-5b340262d50f');

发送单条事件:
$rumClient->sendCustomEvent("error", array('aaa' => '111', 'bbb' => '222'));

发送多条事件:
$rumClient->sendCustomEvents(
    array(
        array('ev' => 'error', 'attrs' => array('aaa' => '111', 'bbb' => '222')),
        array('ev' => 'warn', 'attrs' => array('aaa' => '111', 'bbb' => '222'))
    )
);

*/

class RUMClient {
    private $socket = null;
    private $transport = null;
    private $endpoint = null;
    private $host;
    private $port;
    private $sid = null;
    private $rid = null;
    private $seq = 0;
    private $pid = null;
    private $key = null; 

    const CONNECTION_TIMEOUT = 3;
    const SOCKET_TIMEOUT = 3;

    protected static $supported_transports = array(
        "unix",
    );

    protected $options = array(
        "socket_timeout"     => self::SOCKET_TIMEOUT,
        "connection_timeout" => self::CONNECTION_TIMEOUT,
        "persistent"         => false,
        "max_retry" => 1,
    );

    function __construct($host, $pid, $key, $rid = null, $sid = null, $opt = array()) {
        if ($rid != null)
            $this->rid = $rid;
        else
            $this->rid = $this->_genId();
        if ($sid != null)
            $this->sid = $sid;  
        else
            $this->sid = $this->_genId();
        $this->pid = $pid;
        $this->key = $key;
        $this->__constructSocket($host, $opt); 
    }

    public function __constructSocket($socket_file, $options = array()) {
        $this->host = $socket_file;
        $this->port = 0;
        $this->mergeOptions($options);
    }

    private function _genId() {
        return intval(intval(microtime(true) * 1000) . mt_rand(10000, 99999));
    }

    public function getOption($key, $default = null) {
        $result = $default;
        if (isset($this->options[$key])) {
            $result = $this->options[$key];
        }
        return $result;
    }

    public function mergeOptions(array $options) {
        foreach ($options as $key => $value) {
            if (!array_key_exists($key, $this->options)) {
                throw new \Exception("option {$key} does not support");
            }
            $this->options[$key] = $value;
        }
    }

    protected function connect() {
        if (($pos = strpos($this->host, "://")) !== false) {
            $this->transport = substr($this->host, 0, $pos);
            $host = substr($this->host, $pos + 3);
            
            if (!in_array($this->transport, self::$supported_transports)) {
                throw new \Exception("transport `{$this->transport}` does not support");
            }
            
            if ($this->transport == "unix") {
                $this->endpoint = "unix://" . $host;
            }
        }
        
        $connect_options = \STREAM_CLIENT_CONNECT;
        if ($this->getOption("persistent", false)) {
            $connect_options |= \STREAM_CLIENT_PERSISTENT;
        }

        $socket = @stream_socket_client($this->endpoint, $errno, $errstr, $this->getOption("connection_timeout", self::CONNECTION_TIMEOUT), $connect_options);
        if (!$socket) {
            $errors = error_get_last();
            throw new \Exception($errors['message']);
        }
        stream_set_timeout($socket, $this->getOption("socket_timeout", self::SOCKET_TIMEOUT));
        $this->socket = $socket;
        return $this->socket;
    }

    protected function reconnect() {
        if (!is_resource($this->socket)) {
            $this->connect();
        }
    }

    public function __destruct() {
        if (!$this->getOption("persistent", false) && is_resource($this->socket)) {
            fclose($this->socket);
        }
    }

    public function close() {
        if (is_resource($this->socket)) {
            @fclose($this->socket);
        }
    }

    protected function write($buffer) {
        return @fwrite($this->socket, $buffer);
    }

    protected function read() {
        $ret = @fread($this->socket, 1);
        return intval($ret) == 1;
    }

    protected function postImpl($route, $body, $retry = 0) {
        while (true) {
            try {
                $this->reconnect();
                break;
            } catch (\Exception $e) {
                $this->close();
                if (++$retry <= $this->options["max_retry"]) {
                    continue;
                } else {
                    return false;
                }
            }
        }

        $routeSize = strlen($route);
        $bodySize = strlen($body);
        $packet = pack('CA*SLLA*', $routeSize, $route, 0, $this->getOption("pid", 0), $bodySize, $body);
        $length = strlen($packet);
        
        while ($length > 0) {
            $nwrite = $this->write($packet);
            if ($nwrite === false || $nwrite == 0) {
                if ($retry++ < $this->options["max_retry"]) {
                    $this->close();
                    $this->socket = null;
                    return $this->postImpl($route, $body, $retry);
                } else {
                    return false;
                }
            }
            if ($nwrite < $length) {
                $packet = substr($packet, $nwrite);
            }
            $length -= $nwrite;
        }
        return $this->read();
    }

    private function _genRumEventPayload($eventName, $attrs) {
        return array(
            'sid' => intval($this->sid),
            'ts' => time(),
            'eid' => intval($this->_genId()),
            'rid' => '' . $this->rid,
            'ev' => $eventName,
            'source' => 'php', 
            'attrs' => $attrs,
        );
    }

    private function _genRumPayload($eventsArr) {
        $events = array(); 
        foreach ($eventsArr as $v)
            $events[] = $this->_genRumEventPayload($v['ev'], $v['attrs']);
        $salt = time();
        $sign = strtoupper(md5("{$this->pid}:{$this->key}:{$salt}"));
        return json_encode(array(
            'pid' => $this->pid,
            'salt' => $salt,
            'sign' => $sign, 
            'events' => $events,
        ));
    }

    public function sendCustomEvent($eventName, $attrs) {
        return $this->postImpl("rum", $this->_genRumPayload(array(array('ev' => $eventName, 'attrs' => $attrs))));
    }

    public function sendCustomEvents($events) {
        return $this->postImpl("rum", $this->_genRumPayload($events));
    }
}

