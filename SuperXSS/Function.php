<?php
function randChar($length){
	$chars = 'qwertyuiopasdfghjklzxcvbnm1234567890';
	$return = '';
	for($i=0;$i<$length;$i++){
		$return .= substr($chars,mt_rand(1,strlen($chars)-1),1);
	}
	return $return;
}