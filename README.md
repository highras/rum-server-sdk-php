# rum-server-sdk-php

## 使用文档
 
```
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
```
