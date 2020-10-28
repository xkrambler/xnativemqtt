<?php

/*

	xNativeMQTT
	A simple native PHP class to connect/publish/subscribe to an MQTT broker.
	By mr.xkr at inertinc industries.
	Based on phpMQTT class by Blue Rhinos Consulting | Andrew Milsted
	         andrew@bluerhinos.co.uk | http://www.bluerhinos.co.uk

	Licence

	Permission is hereby granted, free of charge, to any person obtaining a copy
	of this software and associated documentation files (the "Software"), to deal
	in the Software without restriction, including without limitation the rights
	to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
	copies of the Software, and to permit persons to whom the Software is
	furnished to do so, subject to the following conditions:

	The above copyright notice and this permission notice shall be included in
	all copies or substantial portions of the Software.

	THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
	IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
	FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
	AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
	LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
	OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
	THE SOFTWARE.

*/

class xNativeMQTT {

	const VERSION="0.1.2";
	public $topics=array();    // used to store currently subscribed topics
	public $will=false;        // stores the will of the client
	public $connected=false;   // check connection status
	protected $o;              // options
	protected $defaults=array( // default options
		"host"=>"127.0.0.1",     // broker address
		"port"=>1883,            // broker port
		"cafile"=>null,          // Certificate Authority file
		"keepalive"=>10,         // keepalive interval
		"timeout"=>3,            // timeout for connect/disconnect/reconnect
		"qos"=>0,                // QoS
		"waiting"=>20000,        // 20ms sleeping for looping
	);
	private $socket;           // holds the socket
	private $msgid=1;          // counter for message id
	private $timesinceping;    // microtime, used to detect disconects

	// constructor
	function __construct($o=array()) {
		$this->setup($o);
	}

	// destructor
	function __destruct() {
		$this->disconnect();
		$this->close();
	}

	// get/set/isset setup configuration
	function __get($n) { return $this->o[$n]; }
	function __set($n, $v) { $this->o[$n]=$v; }
	function __isset($n) { return isset($this->o[$n]); }

	// setup options
	function setup($o=array()) {
		if (!$o["clientid"]) $o["clientid"]="c".microtime(true); // generate
		$this->o=$o+$this->defaults;
	}

	// debug rendering
	function debug($msg) {
		if ($d=$this->o["debug"]) {
			if (is_callable($d)) $d($msg);
			else error_log($msg."\n");
		}
	}

	// connects to the broker inputs: $clean: should the client send a clean session flag
	function connect($clean=true, $will=null) {

		if ($will) $this->will=$will;

		if ($this->cafile) {
			$socket_context=stream_context_create([
				"ssl"=>[
					"verify_peer_name"=>true,
					"cafile"=>$this->cafile,
				],
			]);
			$this->socket=stream_socket_client("tls://".$this->host.":".$this->port, $errno, $errstr, 60, STREAM_CLIENT_CONNECT, $socket_context);
		} else {
			$this->socket=stream_socket_client("tcp://".$this->host.":".$this->port, $errno, $errstr, 60, STREAM_CLIENT_CONNECT);
		}

	if (!$this->socket) {
			$this->debug("stream_socket_create() $errno, $errstr");
			return false;
		}

		stream_set_timeout($this->socket, 5);
		stream_set_blocking($this->socket, 0);

		$i=0;
		$buffer="";

		$buffer.=chr(0x00); $i++;
		$buffer.=chr(0x06); $i++;
		$buffer.=chr(0x4d); $i++;
		$buffer.=chr(0x51); $i++;
		$buffer.=chr(0x49); $i++;
		$buffer.=chr(0x73); $i++;
		$buffer.=chr(0x64); $i++;
		$buffer.=chr(0x70); $i++;
		$buffer.=chr(0x03); $i++;

		// no will
		$var=0;
		if ($clean) $var+=2;

		// add will info to header
		if ($this->will != NULL) {
			$var+=4;                             // set will flag
			$var+=($this->will['qos'] << 3);     // set will qos
			if ($this->will['retain']) $var+=32; // set will retain
		}

		if (isset($this->user)) $var+=128; // add username to header
		if (isset($this->pass)) $var+=64;  // add password to header

		$buffer.=chr($var); $i++;

		// keep alive
		$buffer.=chr($this->keepalive >> 8); $i++;
		$buffer.=chr($this->keepalive & 0xff); $i++;

		// client id
		$buffer.=$this->strwritestring($this->clientid, $i);

		// adding will to payload
		if ($this->will != NULL) {
			$buffer.=$this->strwritestring($this->will['topic'], $i);  
			$buffer.=$this->strwritestring($this->will['content'], $i);
		}

		// add user/pass
		if (isset($this->user)) $buffer.=$this->strwritestring($this->user, $i);
		if (isset($this->pass)) $buffer.=$this->strwritestring($this->pass, $i);

		// send header+buffer
		$head=chr(0x10).chr($i);
		$this->send($head.$buffer);

	 	// check connection
	 	$res=$this->read(4);
		if ($this->connected=(ord($res{0})>>4 == 2 && $res{3} == 0)) {
			$this->debug("Connected to Broker"); 
		} else {
			$this->debug(sprintf("Connection failed! (Error: 0x%02x 0x%02x)", ord($res{0}), ord($res{3})));
			return false;
		}

		$this->timesinceping=microtime(true);

		return true;
	}

	// disconnect: sends a proper disconect cmd, if needed
	function disconnect() {
		if ($this->connected) {
			$head=chr(0xe0).chr(0x00);
			$this->send($head);
			$this->connected=false;
			return true;
		}
		return false;
	}

	// automatic reconnection
	function reconnect($clean=false, $will=null) {
		if ($this->connected) $this->close();
		while (!$this->connect($clean, $will))
			sleep($this->timeout);
		if ($this->topics) $this->subscribe($this->topics);
		return true;
	}

	// read: reads in so many bytes
	function read($int=8192, $nb=false) {

		//print_r(socket_get_status($this->socket));

		$string="";
		$togo=$int;

		if ($nb) return fread($this->socket, $togo);

		while (!feof($this->socket) && $togo > 0) {
			$fread=fread($this->socket, $togo);
			$string.=$fread;
			$togo=$int - strlen($string);
		}

		if (feof($this->socket)) return false;

		return $string;

	}

	// send binary data
	function send($data, $len=null) {
		return fwrite($this->socket, $data, ($len === null?strlen($data):$len));
	}

	// ping: sends a keep alive ping
	function ping() {
		if ($this->connected) {
			$this->send(chr(0xc0).chr(0x00));
			$this->debug("ping sent");
		}
		return $this->connected;
	}

	// close: sends a proper disconect, then closes the socket
	function close() {
	 	$this->connected=false;
		@stream_socket_shutdown($this->socket, STREAM_SHUT_WR);
		return @fclose($this->socket);
	}

	// publish: publishes $content on a $topic
	function publish($topic, $content, $qos=null, $retain=0) {

		if ($qos !== null) $this->qos=intval($qos);
		if (!is_numeric($this->qos)) return false;

		$i=0;
		$buffer="";

		$buffer.=$this->strwritestring($topic, $i);

		if ($this->qos) {
			$id=$this->msgid++;
			$buffer.=chr($id >> 8);  $i++;
		 	$buffer.=chr($id % 256);  $i++;
		}

		$buffer.=$content;
		$i+=strlen($content);

		$cmd=0x30;
		if ($this->qos) $cmd+=$this->qos << 1;
		if ($retain) $cmd+=1;

		$head=chr($cmd).$this->setmsglength($i);

		$this->send($head);
		$this->send($buffer, $i);

	}

	// subscribe: subscribes to one or more topics defined in array
	// 	each topic sintax: array("topic"=>"string", "function"=>function($topic, $msg){})
	function subscribe($topics, $qos=null) {

		if ($qos !== null) $this->qos=intval($qos);
		if (!is_numeric($this->qos)) return false;

		$i=0;
		$buffer="";
		$id=$this->msgid;
		$buffer.=chr($id >> 8); $i++;
		$buffer.=chr($id % 256); $i++;

		foreach ($topics as $key => $topic) {
			$buffer.=$this->strwritestring($key, $i);
			$buffer.=chr($topic["qos"]); $i++;
			$this->topics[$key]=$topic; 
		}

		$cmd=0x80;
		$cmd+=($this->qos << 1); // $qos

		$head=chr($cmd).chr($i);

		$this->send($head);
		$this->send($buffer, $i);
		$string=$this->read(2);
		
		$bytes=ord(substr($string, 1, 1));
		$string=$this->read($bytes);

	}

	// message: processes a received topic
	function message($msg) {
		$tlen=(ord($msg{0})<<8) + ord($msg{1});
		$topic=substr($msg, 2, $tlen);
		$msg=substr($msg, ($tlen+2));
		$found=0;
		foreach ($this->topics as $key=>$top) {
			if (preg_match("/^".str_replace("#",".*",
					str_replace("+","[^\/]*",
						str_replace("/","\/",
							str_replace("$",'\$',
								$key))))."$/", $topic)) {
				if (is_callable($top['function'])) {
					call_user_func($top['function'], $topic, $msg);
					$found=1;
				}
			}
		}
		if (!$found) $this->debug("msg received but no match in subscriptions");
	}

	// proc: the processing loop for an "always on" client
	// set true when you are doing other stuff in the loop good for watching something else at the same time
	function proc($loop=true) {

		do {

			$cmd=0;

			if (feof($this->socket)) {
				$this->debug("eof receive going to reconnect for good measure");
				$this->reconnect();
			}

			$byte=$this->read(1, true);
			if (strlen($byte)) {

				$cmd=(int)(ord($byte) / 16);
				$this->debug("Received: $cmd");

				$multiplier=1;
				$value=0;
				do {
					$digit=ord($this->read(1));
					$value+=($digit & 0x7F) * $multiplier; 
					$multiplier*=0x80;
				} while (($digit & 0x80) != 0);

				$string=false;
				if ($value) {
					$this->debug("Fetching: $value");
					$string=$this->read($value);
				}

				if ($cmd && $string!==false) {
					switch ($cmd) {
					case 3: $this->message($string); break;
					}
					$this->timesinceping=microtime(true);
				}

			} else {

				if ($loop && $this->waiting > 0) usleep($this->waiting);

			}

			if ($this->timesinceping < (microtime(true) - $this->keepalive)) {
				$this->debug("not found something so ping");
				$this->ping();	
			}

			if ($this->timesinceping < (microtime(true) - ($this->keepalive + $this->timeout))) {
				$this->debug("not seen a package in a while, disconnecting");
				$this->reconnect();
			}

		} while (strlen($byte));

		return ($multiplier?true:false);

	}

	// getmsglength: get message length
	function getmsglength(&$msg, &$i) {
		$multiplier=1;
		$value=0;
		do {
			$digit=ord($msg{$i});
			$value+=($digit & 0x7F) * $multiplier; 
			$multiplier*=0x80;
			$i++;
		} while (($digit & 0x80) != 0);
		return $value;
	}

	// setmsglength: set message length
	function setmsglength($len) {
		$string="";
		do {
			$digit=$len % 0x80;
			$len>>=7;
			if ($len > 0) $digit|=0x80; // if there are more digits to encode, set the top bit of this digit
			$string.=chr($digit);
		} while ($len > 0);
		return $string;
	}

	// strwritestring: writes a string to a buffer
	function strwritestring($str, &$i) {
		$len=strlen($str);
		$msb=$len >> 8;
		$lsb=$len % 256;
		$ret=chr($msb).chr($lsb).$str;
		$i+=($len+2);
		return $ret;
	}

}
