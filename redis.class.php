<?php

	namespace wx;

	class Redisdb
	{
		//redisdb实例
		private static $instance = null;
		//redis实例
		private $redis = null;

		//信息队列
		private $wx_list = 'wx_list';
		//失败信息队列
		private $fail_list = 'fail_wx_list';
		//是否已经初始化
		private $flag = 'wx_init_flag';
		//错误信息长度
		private $fail_len = 0;

		/**
		 * 构造函数
		 * @access protected
		 */
		protected final function __construct()
		{
			$init_data = require './config/redis.php';
			try
			{
				$this->redis = new \Redis();
				$this->redis->connect($init_data['host'], $init_data['port']);
				if($init_data['passwd'])
				{
					$this->redis->auth($init_data['passwd']);
				}
				$this->redis->select($init_data['db']);
			} catch(\Exception $e)
			{
				echo 'the init redis error and the message is ' . $e->getMessage() . PHP_EOL;
				exit(0);
			}
		}

		/**
		 * 获取reids实例
		 * @access public
		 * @return resource
		 */
		public function getRedis()
		{
			return $this->redis;
		}

		/**
		 * 初始化类
		 * @access public
		 * @return resource
		 */
		public static function getInit()
		{
			if(self::$instance == null)
			{
				self::$instance = new self();
			}
			return self::$instance;
		}

		/**
		 * 初始化信息
		 * @access public
		 * @param Array $arr 初始化信息数组
		 */
		public function initData($arr)
		{
			if(!$this->isHaveInit())
			{
				foreach($arr as $data)
				{
					$this->listPut($data);
				} 
				$this->haveInit();
			}
		}

		/**
		 * 队列添加元素
		 * @access private
		 * @param String $name 队列名称
		 * @param String $value 值
		 * @return mixed 成功返回长度，失败返回false
		 */
		private function put($name,$value)
		{
			return $this->redis->rPush($name,$value);
		}

		/**
		 * 队列添加元素
		 * @access private
		 * @param Array $arr 值
		 * @return Int
		 */
		private function listPut($arr)
		{
			return $this->put($this->wx_list,serialize($arr));
		}

		/**
		 * 失败队列添加元素
		 * @access public
		 * @param Array $arr 值
		 * @return Int
		 */
		public function failPut($arr)
		{
			return $this->put($this->fail_list,serialize($arr));
		}

		/**
		 * 是否已经初始化
		 * @access public
		 * @return mixed 成功返回Int,失败返回false
		 */
		public function isHaveInit()
		{
			return $this->redis->get($this->flag);
		}

		/**
		 * 设置已经初始化
		 * @access private
		 * @return mixed 成功返回Int,失败返回false
		 */
		private function haveInit()
		{
			return $this->redis->set($this->flag,1);
		}

		/**
		 * 队列大小
		 * @access public
		 * @return bool
		 */
		public function listLen()
		{
			return $this->size($this->wx_list);
		}

		/**
		 * 获取队列元素
		 * @access public
		 * @return Array
		 */
		public function get()
		{
			if($this->listLen()){
				$wx_nows = $this->redis->lPop($this->wx_list);
				if(! $wx_nows)
					return array();
				return unserialize($wx_nows);
			}
			return array();
		}

		/**
		 * 返回队列大小
		 * @access private
		 * @param String $name 队列名称
		 * @return Int
		 */
		private function size($name)
		{
			return $this->redis->lLen($name);
		}

		/**
		 * 返回失败队列大小
		 * @access public
		 * @return Int
		 */
		public function failLen()
		{
			return $this->size($this->fail_list);
		}

		/**
		 * 打印、移除失败元素并重新加入队列
		 * @access public
		 * @param Bool $is_over 是否全部完成
		 */
		public function printAndPut($is_over=false)
		{
			$this->fail_len = $this->failLen();
			echo 'now ' . $this->fail_len .' WeChat public numbers have failed' . PHP_EOL;
			if($this->fail_len)
			{
				for($i = 0; $i < $this->fail_len; ++$i)
				{
					echo 'the fail WeChat public number is ' . $this->redis->rPopLPush($this->fail_list,$this->wx_list) . PHP_EOL;
				}
			}
			elseif($is_over)
			{
				echo 'all WeChat public numbers have fetched and success' . PHP_EOL;
			}
			if($is_over)
				$this->redis->delete($this->wx_list,$this->fail_list,$this->flag);
		}

		/**
		 * 是否有失败
		 * @access public
		 * @param Int
		 */
		public function isFailed()
		{
			return $this->fail_len > 0;
		} 

	}