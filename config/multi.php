<?php

	return [
		'init_type' => 0, //0 多任务 1 多进程和redis
		'multi_num' => 4, //只有init_type=0时有效,建议大于1,等于0|1的时候意义不大
		'page_num' => 20,
		'again_num' => 1, //重试次数
		'process_num' => 4 //只有init_type=1时有效,建议大于1,等于0|1的时候意义不大
	];