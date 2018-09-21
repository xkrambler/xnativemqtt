<?php

	require_once("xnativemqtt.php");

	$mqtt=new xNativeMQTT(array(
		//"host"=>"127.0.0.1",
		//"port"=>1883,
		//"user"=>"username",
		//"pass"=>"password",
	));

	if (!$mqtt->connect()) die("error: connect");

	// test message
	$msg=str_repeat("This is a test message sent on ".date("d/m/Y H:i:s")."!", 10000);

	// subscription
	$mqtt->subscribe([
		"test"=>["qos"=>0, "function"=>function($t, $m) {
			global $msg;
			static $count;
			echo "Received #".(++$count)." (".$t."): ".strlen($m)." ".($msg==$m?"OK":"ERROR")."\n";
		}],
	], 0);

	// publish 5 messages
	for ($i=0;$i<5;$i++) $mqtt->publish("test", $msg, 0);

	// wait a little to be processed in broker side
	usleep(100000);

	// subscribe message loop until no more messages
	while ($mqtt->proc());

	// finish
	$mqtt->close();
