<?php 

require_once "./rum-server-sdk.php";

$rumClient = new RUMClient("unix:///tmp/rumagent.sock", 1003, '2b88ffa3-d269-4c9a-8786-5b340262d50f');

$rumClient->sendCustomEvent("error", array('aaa' => '111', 'bbb' => '222'));

$rumClient->sendCustomEvents(
    array(
        array('ev' => 'error', 'attrs' => array('aaa' => '111', 'bbb' => '222')), 
        array('ev' => 'warn', 'attrs' => array('aaa' => '111', 'bbb' => '222'))
    )
);
