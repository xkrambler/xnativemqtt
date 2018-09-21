<?php

/*

 	xNativeMQTT
	A simple native PHP class to connect/publish/subscribe to an MQTT broker.
	By mr.xkr at inertinc industries
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

	protected $o;           // options
	private $socket;        // holds the socket
	private $msgid=1;       // counter for message id
	public $keepalive=10;   // default keepalive timmer
	public $timeout=3;      // timeout (keepalive+timeout)
	public $timesinceping;  // microtime, used to detect disconects
	public $topics=array(); // used to store currently subscribed topics
	public $debug=false;    // should output debug messages
	public $host;           // broker address
	public $port;           // broker port
	public $clientid;       // client id sent to brocker
	public $will;           // stores the will of the client
	public $cafile;         // Certificate Authority file

	function __construct($o=array()) {
		$this->setup($o);
	}

	// setup options
	function setup($o=array()) {
		$this->o=$o;
		$this->host=($o["host"]?$o["host"]:"127.0.0.1");
		$this->port=($o["port"]?$o["port"]:1883);
		$this->clientid=($o["clientid"]?$o["clientid"]:"c".microtime(true));
		$this->cafile=($o["cafile"]?$o["cafile"]:NULL);
	}

	// automatic reconnection
	function reconnect($clean=false, $will=NULL) {
		while (!$this->connect($clean, $will))
			sleep($this->timeout);
		return true;
	}

	// connects to the broker inputs: $clean: should the client send a clean session flag
	function connect($clean=true, $will=NULL) {

		if ($will) $this->will=$will;

		if ($this->cafile) {
			$socketContext=stream_context_create([
				"ssl"=>[
					"verify_peer_name"=>true,
					"cafile"=>$this->cafile,
				],
			]);
			$this->socket=stream_socket_client("tls://".$this->host.":".$this->port, $errno, $errstr, 60, STREAM_CLIENT_CONNECT, $socketContext);
		} else {
			$this->socket=stream_socket_client("tcp://".$this->host.":".$this->port, $errno, $errstr, 60, STREAM_CLIENT_CONNECT);
		}

		if (!$this->socket) {
			if ($this->debug) error_log("stream_socket_create() $errno, $errstr \n");
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
			$var+=4;                            // set will flag
			$var+=($this->will['qos'] << 3);    // set will qos
			if ($this->will['retain']) $var+=32; // set will retain
		}

		if (isset($this->o["user"])) $var+=128; // add username to header
		if (isset($this->o["pass"])) $var+=64;	 // add password to header

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
		if (isset($this->o["user"])) $buffer.=$this->strwritestring($this->o["user"], $i);
		if (isset($this->o["pass"])) $buffer.=$this->strwritestring($this->o["pass"], $i);

		$head=chr(0x10).chr($i);
		fwrite($this->socket, $head);
		fwrite($this->socket, $buffer);

	 	$res=$this->read(4);

		if (ord($res{0})>>4 == 2 && $res{3} == 0) {
			if ($this->debug) echo "Connected to Broker\n"; 
		} else {
			error_log(sprintf("Connection failed! (Error: 0x%02x 0x%02x)\n", ord($res{0}), ord($res{3})));
			return false;
		}

		$this->timesinceping=microtime(true);

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

	// ping: sends a keep alive ping
	function ping() {
		$head=chr(0xc0).chr(0x00);
		fwrite($this->socket, $head);
		if ($this->debug) echo "ping sent\n";
	}

	// disconnect: sends a proper disconect cmd
	function disconnect() {
		$head=chr(0xe0).chr(0x00);
		fwrite($this->socket, $head);
	}

	// close: sends a proper disconect, then closes the socket
	function close() {
	 	$this->disconnect();
		stream_socket_shutdown($this->socket, STREAM_SHUT_WR);
	}

	// publish: publishes $content on a $topic
	function publish($topic, $content, $qos=0, $retain=0) {

		$i=0;
		$buffer="";

		$buffer.=$this->strwritestring($topic,$i);

		if ($qos) {
			$id=$this->msgid++;
			$buffer.=chr($id >> 8);  $i++;
		 	$buffer.=chr($id % 256);  $i++;
		}

		$buffer.=$content;
		$i+=strlen($content);

		$cmd=0x30;
		if ($qos) $cmd+=$qos << 1;
		if ($retain) $cmd+=1;

		$head=chr($cmd).$this->setmsglength($i);

		fwrite($this->socket, $head, strlen($head));
		fwrite($this->socket, $buffer, $i);

	}

	// subscribe: subscribes to topics
	function subscribe($topics, $qos=0) {

		$i=0;
		$buffer="";
		$id=$this->msgid;
		$buffer.=chr($id >> 8); $i++;
		$buffer.=chr($id % 256); $i++;

		foreach ($topics as $key => $topic) {
			$buffer.=$this->strwritestring($key,$i);
			$buffer.=chr($topic["qos"]); $i++;
			$this->topics[$key]=$topic; 
		}

		$cmd=0x80;
		$cmd+=($qos << 1); // $qos

		$head=chr($cmd).chr($i);

		fwrite($this->socket, $head);
		fwrite($this->socket, $buffer, $i);
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
		if ($this->debug && !$found) echo "msg received but no match in subscriptions\n";
	}

	// proc: the processing loop for an "always on" client
	// set true when you are doing other stuff in the loop good for watching something else at the same time
	function proc($loop=true) {

		do {

			$cmd=0;

			if (feof($this->socket)) {
				if ($this->debug) echo "eof receive going to reconnect for good measure\n";
				fclose($this->socket);
				$this->reconnect();
				if (count($this->topics)) $this->subscribe($this->topics);	
			}

			$byte=$this->read(1, true);
			if (strlen($byte)) {
			
				$cmd=(int)(ord($byte) / 16);
				if ($this->debug) echo "Received: $cmd\n";

				$multiplier=1;
				$value=0;
				do {
					$digit=ord($this->read(1));
					$value+=($digit & 0x7F) * $multiplier; 
					$multiplier*=0x80;
				} while (($digit & 0x80) != 0);

				$string=false;
				if ($value) {
					if ($this->debug) echo "Fetching: $value\n";
					$string=$this->read($value);
				}

				if ($cmd && $string!==false) {
					switch ($cmd) {
					case 3: $this->message($string); break;
					}
					$this->timesinceping=microtime(true);
				}

			} else {

				if ($loop) usleep(20000);

			}

			if ($this->timesinceping < (microtime(true) - $this->keepalive)) {
				if ($this->debug) echo "not found something so ping\n";
				$this->ping();	
			}

			if ($this->timesinceping < (microtime(true) - ($this->keepalive + $this->timeout))) {
				if ($this->debug) echo "not seen a package in a while, disconnecting\n";
				fclose($this->socket);
				$this->reconnect();
				if (count($this->topics)) $this->subscribe($this->topics);
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
