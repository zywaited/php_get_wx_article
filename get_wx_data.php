<?php
	use wx\MultiCurlWx;

	error_reporting(E_ALL & ~E_WARNING);
	date_default_timezone_set('PRC'); //设置时区
	ini_set('default_socket_timeout', -1); //防止超时

	require './multicurlwx.class.php';

	(new MultiCurlWx())->run();
	