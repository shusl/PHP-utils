#!/usr/bin/php -q
<?php
//error_reporting(E_ALL ^ E_NOTICE);
error_reporting(0);
set_time_limit(120);
ini_set('memory_limit', '256M');
define(FCGI_VERSION_1, 1);

define(FCGI_BEGIN_REQUEST, 1);
define(FCGI_ABORT_REQUEST, 2);
define(FCGI_END_REQUEST, 3);
define(FCGI_PARAMS, 4);
define(FCGI_STDIN, 5);
define(FCGI_STDOUT, 6);
define(FCGI_STDERR, 7);
define(FCGI_DATA, 8);
define(FCGI_GET_VALUES, 9);
define(FCGI_GET_VALUES_RESULT, 10);

if(isset($_REQUEST['-h']) || isset($_REQUEST['--help']) || count($_REQUEST) == 1){
	print_useage();
}

$port = '9000';
if(isset($_REQUEST['--port'])){
	$port = $_REQUEST['--port'];
}
$config = null;
if(isset($_REQUEST['--config'])){
	$config = $_REQUEST['--config'];
	
	$hosts = parse_nginx_config_file($config,$port);
	//print_r($hosts);
	if(!empty($hosts)){		
		foreach ($hosts as $host){
			$h = explode(':', $host);
			test_for_app_server($h[0],$h[1]);
		}
	}else{
		echo "no port $port fastcgi server in '$config'\n";
	}
}else{
	$host = 'localhost';
	if(isset($_REQUEST['--host'])){
		$host = $_REQUEST['--host'];
	}
	$file = $_REQUEST['--file'];
	$GLOBALS ["docroot"] = '/data/htdocs';
	if(isset($_REQUEST['--docroot']) && !empty($_REQUEST['--docroot'])){
		$GLOBALS ["docroot"] = $_REQUEST['--docroot'];
	}
	
	if(empty($file)){
		$file = 'index.php';
		test_for_app_server($host,$port,$file);
	}else{
		get_file_output($file,$host,$port);
	}
	
}
function test_for_app_server($host,$port = 9000,$file = 'index.php'){
	if(empty($port)){
		$port = 9000;
	}
	$cgi = new mod_fcgi();
	$req_err = '';
	$cgi_headers = array();
	$cgi->parser_open("$host:$port", $file, $rq_err, $cgi_headers);
	if($req_err){
		print_test_result($host,$port,false);
		//echo $req_err;
	}else{
		$contents = trim($cgi->parser_get_output());
		if(empty($contents)){
			print_test_result($host,$port,false);
		}elseif($contents == "No input file specified." || $contents == "Access denied."){
			print_test_result($host,$port,true);
		}else{
			print_test_result($host,$port,true);
			echo "output for $host:$port :",$contents,"\n";
		}		
	}
}
function print_useage(){
	$file = basename(__FILE__);
	$useage = <<<USEAGE
Usage: $file [OPTION]
    test the contact for the nginx server to  fastcgi server.
Example: $file --host=localhost --port=9000
         $file --config=/etc/nginx/nginx.conf
OPTIONS:
    --host      the fastcgi server host, default localhost
    --port      the fastcgi server bind port, default 9000
    --config    the nginx config file path, all the fastcgi config
                with port will be tested.
	--file      the php script file to execute and get output, must related path
	--docroot   set the document root for the script file
    -h,--help   show this message

USEAGE;
	echo $useage;
	exit();
}
function print_test_result($host,$port,$result){
	if($result){
		echo "$host:$port OK\n";
	}else{
		echo "$host:$port FAIL\n";
	}
}

function get_file_output($file,$host= 'localhost',$port = 9000){
	$GLOBALS ["docroot_prefix"] = '';
	$GLOBALS ["http_uri"] = $file;
	$GLOBALS ["http_action"] = 'GET';
	$GLOBALS ["real_uri"] = $file;
	$GLOBALS ["path_info"] = '';
	$cgi = new mod_fcgi();
	$req_err = '';
	$cgi_headers = array();
	$cgi->parser_open("$host:$port", $file, $rq_err, $cgi_headers);
	if($req_err){
		print_test_result($host,$port,false);
		//echo $req_err;
	}else{
		$contents = trim($cgi->parser_get_output());
		echo $contents,"\n";	
	}
}

function parse_nginx_config_file($config,$port = 9000){
	$cmd = "grep $port $config |awk '{if (\$1 == \"server\" || \$1 == \"fastcgi_pass\") print \$2}'";
	$output = array();
	$rs = exec($cmd,$output);
	if(empty($output)){
		return $output;
	}
	$hosts = array();
	foreach($output as $host){
		$host = trim($host,' ;');
		$hosts[$host] = $host;
	}
	return array_values($hosts);
}

class mod_fcgi {

	public function __construct() {

		$this->modtype="parser_FCGI";
		$this->modname="FastCGI support";
	
	}

	function build_fcgi_packet($type, $content) {

		$clen=strlen($content);
				
		$packet=chr(FCGI_VERSION_1);
		$packet.=chr($type);
		$packet.=chr(0).chr(1); // Request id = 1
		$packet.=chr((int)($clen/256)).chr($clen%256); // Content length
		$packet.=chr(0).chr(0); // No padding and reserved
		$packet.=$content;

		return($packet);
	
	}

   function build_fcgi_nvpair($name, $value) {

		$nlen = strlen($name);
		$vlen = strlen($value);
			 
		if ($nlen < 128) {
		   
			$nvpair = chr($nlen);

		} else {
		   
			$nvpair = chr(($nlen >> 24) | 0x80) . chr(($nlen >> 16) & 0xFF) . chr(($nlen >> 8) & 0xFF) . chr($nlen & 0xFF);

		}

		if ($vlen < 128) {
		   
			$nvpair .= chr($vlen);

		} else {
		   
			$nvpair .= chr(($vlen >> 24) | 0x80) . chr(($vlen >> 16) & 0xFF) . chr(($vlen >> 8) & 0xFF) . chr($vlen & 0xFF);

		}

		return $nvpair . $name . $value;
     
	} 
	
	function decode_fcgi_packet($data) {
		$ret = array();
		$ret["version"]=ord($data{0});
		$ret["type"]=ord($data{1});
		$ret["length"]=(ord($data{4}) << 8)+ord($data{5});
		$ret["content"]=substr($data, 8, $ret["length"]);

		return($ret);
	
	}

	function parser_open($args, $filename, &$rq_err, &$cgi_headers) {

		global $conf, $add_errmsg;
		
		// Connect to FastCGI server

		$fcgi_server=explode(":", $args);

		if (!$this->sck=fsockopen($fcgi_server[0], $fcgi_server[1], $errno, $errstr, 5)) {

			$rq_err=500;
			$tmperr = sprintf("unable to contact application server %s:%s ($errno : $errstr).",$fcgi_server[0], $fcgi_server[1]);
			$add_errmsg.=($tmperr."<br><br>");
			echo "ERROR: ",$tmperr,"\n";
			return (false);

		}

		// Begin session
		
		$begin_rq_packet=chr(0).chr(1).chr(0).chr(0).chr(0).chr(0).chr(0).chr(0);
		fwrite($this->sck, $this->build_fcgi_packet(FCGI_BEGIN_REQUEST, $begin_rq_packet));

		// Build params
		
		$fcgi_params_packet.=$this->build_fcgi_nvpair("GATEWAY_INTERFACE", "FastCGI/1.0");
		$nsv=nw_server_vars();
		if ($conf["global"]["fcgifilterpathinfo"][0]) unset($nsv["PATH_INFO"]);
		foreach($nsv as $key=>$var) $fcgi_params_packet.=$this->build_fcgi_nvpair($key, $var);

		if ($rq_hdrs=$GLOBALS["htreq_headers"]) {
			foreach ($rq_hdrs as $key=>$val) {
				$fcgi_params_packet.=$this->build_fcgi_nvpair("HTTP_".str_replace("-", "_", $key),$val);	
			}
		}
		
		if ($GLOBALS["http_action"]=="POST" && $GLOBALS["htreq_content"]) {
		
			$fcgi_params_packet.=$this->build_fcgi_nvpair("CONTENT_TYPE", $rq_hdrs["CONTENT-TYPE"]);
			$fcgi_params_packet.=$this->build_fcgi_nvpair("CONTENT_LENGTH", $rq_hdrs["CONTENT-LENGTH"]);

			$stdin_content=$GLOBALS["htreq_content"];
			
		} else {
			$stdin_content="";
		}

		// Send params
		
		fwrite($this->sck, $this->build_fcgi_packet(FCGI_PARAMS, $fcgi_params_packet));
		fwrite($this->sck, $this->build_fcgi_packet(FCGI_PARAMS, ""));
		
		// Build and send stdin flow

		if ($stdin_content) fwrite($this->sck, $this->build_fcgi_packet(FCGI_STDIN, $stdin_content));
		fwrite($this->sck, $this->build_fcgi_packet(FCGI_STDIN, ""));

		// Read answers from fastcgi server

		$content="";

		while (($p1=strpos($content, "\r\n\r\n"))===false) {

			$tmpp=$this->decode_fcgi_packet($packet=fread($this->sck, 8));
			$tl=$tmpp["length"]%8;
			$tadd=($tl?(8-$tl):0);
			if($tmpp["length"]+$tadd < 1){
				echo "ERROR: can't read response from app appserver";
				break;
			}
			$resp=$this->decode_fcgi_packet($packet.fread($this->sck, $tmpp["length"]+$tadd));

			if ($valid_pck=($resp["type"]==FCGI_STDOUT || $resp["type"]==FCGI_STDERR)) $content.=$resp["content"];

			if ($resp["type"]==FCGI_STDERR) echo "ERROR: app server returned error : '".$resp["content"]."'", "\n";

		}

		if (feof($this->sck)) $this->peof=true;
		
		if ($p1) {
		
			$headers=explode("\n", trim(substr($content, 0, $p1)));
			$content=substr($content, $p1+4);

		}

		$GLOBALS["http_resp"]="";
		
		$cnh=access_query("fcginoheader");
		
		foreach ($headers as $s) if ($s=trim($s)) {

			if (substr($s, 0, 5)=="HTTP/") {

				$hd_key="STATUS";
				strtok($s, " ");
			
			} else {
			
				$hd_key=strtok($s, ":");

			}

			$hd_val=trim(strtok(""));
			$hku=strtoupper($hd_key);
			
			if ($cnh) foreach ($cnh as $nohdr) if ($hku==strtoupper($nohdr)) $hd_key="";
			
			if ($hd_key) {
			
				if ($hku=="SET-COOKIE") {
					
					$cgi_headers["cookies"][]=$hd_val;

				} else {

					$cgi_headers[$hd_key]=$hd_val;

				}

			}
		
		}

		$this->parsed_output=$content;

	}

	function parser_get_output() {

		if (!$this->peof && !$this->parsed_output) {
		
			$tmpp=$this->decode_fcgi_packet($packet=fread($this->sck, 8));
			$tl=$tmpp["length"]%8;
			$tadd=($tl?(8-$tl):0);
			$resp=$this->decode_fcgi_packet($packet.fread($this->sck, $tmpp["length"]+$tadd));

			if ($valid_pck=($resp["type"]==FCGI_STDOUT || $resp["type"]==FCGI_STDERR)) {
				
				$content.=$resp["content"];

			} else {

				$this->peof=true;				
			
			}

			if ($resp["type"]==FCGI_STDERR) techo("WARN: mod_fcgi: app server returned error : '".$resp["content"]."'", NW_EL_WARNING);

		}

		if ($this->parsed_output) {

			$content=$this->parsed_output;
			$this->parsed_output="";
		
		}

		return($content);
	
	}
	
	function parser_eof() {

		return($this->peof);
	
	}
	
	function parser_close() {

		$this->peof=false;
		fclose($this->sck);

	}

}
function techo($s, $level=NW_EL_NOTICE, $flush=false) {

	global $conf;

	static $srv_buf;
	
	$tl=date("Ymd:His")." $s\n";
	echo $tl;
	return;
	if (!$conf["_complete"] && !$flush) {

		$srv_buf[]=array($tl, $level);
	
	} else {
	
		if (($conf["global"]["servermode"][0]!="inetd") && !$GLOBALS["quiet"]) {

			if ($srv_buf) foreach ($srv_buf as $sb_arr) echo $sb_arr[0];
			echo $tl;
			flush();

		}

		if ($srv_buf) {
			
			foreach ($srv_buf as $sb_arr) log_srv($sb_arr[0], $sb_arr[1]);
	
			$srv_buf=array();

		}

		log_srv($tl, $level);

	}

}

function log_srv($str, $loglevel=NW_EL_NOTICE) {

	if ($srvlog_arr=$GLOBALS["conf"]["global"]["_serverlog"]) foreach ($srvlog_arr as $s=>$bmode) if ($loglevel & $bmode) {
		
		if (($GLOBALS["pmode"]=="master") && (!file_exists($s))) $chown=true;
		
		if ($sl=@fopen($s, NW_BSAFE_APP_OPEN)) {

			fputs($sl, $str);
			fclose($sl);
			
		}

		if ($chown && $GLOBALS["posix_av"] && ($lids=log_ids())) {

			chgrp($s, $lids["gid"]);
			chown($s, $lids["uid"]);

		}

	}

}
function nw_server_string() {

	switch (strtolower(access_query("serversignature", 0))) {

		case "fake": return(access_query("serverfakesignature", 0));
		case "off": return("");
		case "prod": return(SERVER_STRING);
		case "min": return(SERVER_STRING_V);
		case "os": return(SERVER_STRING_V." (".PHP_OS.")");
		case "php": return(SERVER_STRING_V." (".PHP_OS."; PHP/".phpversion().")");

		case "full": 
		default:
		return(SERVER_STRING_V." (".PHP_OS."; PHP/".phpversion().($GLOBALS["mod_tokens"]?"; ":"").implode("; ", $GLOBALS["mod_tokens"]).")");

	}
	
}
function nw_server_vars($include_cgi_vars=false) {

	global $conf;
	
	$filename=$GLOBALS["docroot"] . '/' .$GLOBALS["http_uri"];
	
	$nsv ["SERVER_SOFTWARE"] = nw_server_string ();
	$nsv ["SERVER_NAME"] = $conf [$GLOBALS ["vhost"]] ["servername"] [0];
	$nsv ["SERVER_PROTOCOL"] = HTTP_VERSION;
	$nsv ["SERVER_PORT"] = $GLOBALS ["lport"];
	$nsv ["SERVER_ADDR"] = $conf ["global"] ["listeninterface"] [0];
	$nsv ["SERVER_API"] = VERSION;
	$nsv ["SERVER_ADMIN"] = $conf [$GLOBALS ["vhost"]] ["serveradmin"] [0];
	$nsv ["REQUEST_METHOD"] = $GLOBALS ["http_action"];
	$nsv ["PATH_TRANSLATED"] = $nsv ["SCRIPT_FILENAME"] = realpath ( $filename );
	$nsv ["SCRIPT_NAME"] = "/" . $GLOBALS ["docroot_prefix"] . $GLOBALS ["http_uri"];
	$nsv ["QUERY_STRING"] = $GLOBALS ["query_string"];
	$nsv ["REMOTE_HOST"] = $GLOBALS ["remote_host"];
	$nsv ["REMOTE_ADDR"] = $GLOBALS ["remote_ip"];
	$nsv ["REMOTE_PORT"] = $GLOBALS ["remote_port"];
	$nsv ["AUTH_TYPE"] = $GLOBALS ["auth_type"];
	$nsv ["DOCUMENT_ROOT"] = $GLOBALS ["docroot"];
	$nsv ["REQUEST_URI"] = "/" . $GLOBALS ["real_uri"] . ($nsv ["QUERY_STRING"] ? ("?" . $nsv ["QUERY_STRING"]) : "");
	$nsv ["PATH_INFO"] = $GLOBALS ["path_info"];

	if (($GLOBALS["logged_user"]) && ($GLOBALS["logged_user"] != " ")) {

		$nsv["REMOTE_USER"] = $GLOBALS["logged_user"];

	}

	if ($asv=access_query("addservervar")) foreach ($asv as $str) {

		$k=strtok($str, " ");
		$v=strtok("");
		if ($k) $nsv[$k]=$v;
	
	}

	if ($GLOBALS["add_nsv"]) foreach ($GLOBALS["add_nsv"] as $key=>$val) $nsv[$key]=$val;

	if ($include_cgi_vars && ($rq_hdrs=$GLOBALS["htreq_headers"])) foreach($rq_hdrs as $key=>$val) $nsv["HTTP_".str_replace("-", "_", $key)]=$val;

	return $nsv;

}
function access_query($key, $idx=false) {

	global $access, $conf;

	$ap=$GLOBALS["access_policy"][$key] or
	$ap=$conf["global"]["accesspolicy"][0];

	switch ($ap) {

		case "override":
		$tmp=$access["global"][$key] or
		$tmp=$conf[$GLOBALS["vhost"]][$key] or
		$tmp=$conf["global"][$key];
		break;

		case "merge":
		$tmp=array_merge($conf["global"][$key] ? $conf["global"][$key] : array(), $conf[$GLOBALS["vhost"]][$key] ? $conf[$GLOBALS["vhost"]][$key] : array(), $access["global"][$key] ? $access["global"][$key] : array());
		break;

	}

	if ($idx===false) {

		return($tmp);

	} else {

		return($tmp[$idx]);
	
	}

}
