<?php

// require class
require_once("xnativemqtt.php");

// instance class
$mqtt=new xNativeMQTT(array(
	//"host"=>"127.0.0.1",
	//"port"=>1883,
	//"user"=>"username",
	//"pass"=>"password",
));

// connect
if (!$mqtt->connect()) die("error: connect");

// test message
$msg=str_repeat("This is a test message sent on ".date("d/m/Y H:i:s")."!", 10000);

// subscription
$mqtt->subscribe(array(
	"test"=>["qos"=>0, "function"=>function($t, $m) {
		static $count;
		echo "Received #".(++$count)." (".$t."): ".strlen($m)." ".($GLOBALS["msg"] == $m?"OK":"ERROR")."\n";
	}],
));

// publish some messages
for ($i=0; $i<5; $i++) $mqtt->publish("test", $msg, 0);

// wait a little (250ms) to be processed at broker side, increment it if needed
usleep(250000);

// subscribe message loop until no more messages
while ($mqtt->proc());

// finish (not needed, called indirectly on object destroy)
//$mqtt->close();
