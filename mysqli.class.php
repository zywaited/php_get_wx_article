<?php

	namespace wx;

	class Mysqlidb
	{
		/*
		 * 
		 */
		private static $instance = null;
		private $conn = null;
		protected $table = null;
		protected $time_table = null;
		protected $info_table = null;
		private $time_flag = null;

		private $file_opener = null;

		/**
		  * 构造函数
		  * @param String $host 主机名
		  * @param String $user 数据库用户名
		  * @param String $passwd 数据库密码
		  * @param String $dbname 数据库名
		  * @param String $charset 字符编码,utf8
		  */
		protected final function __construct()
		{
			$init_data = require './config/db.php';
			$this->table = $init_data['table'];
			$this->time_table = $init_data['time_table'];
			$this->info_table = $init_data['info_table'];
			$this->conn = new \Mysqli($init_data['host'],$init_data['user'],$init_data['passwd'],$init_data['db']);
			if(! $this->conn)
			{
				echo 'the init mysqli or file is error ' . PHP_EOL;
				exit(0);
			}
			$this->conn->set_charset($init_data['charset']);
				
			if($init_data['file_flag'] && $init_data['file_path'])
				$this->file_opener = fopen($init_data['file_path'], 'a');
			$this->time_flag = $init_data['time_flag'];
		}

		private function __clone(){}

		/**
		  * 初始化
		  * @return object
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
		  * 
		  * @param String $sql
		  * @return int
		  */
		public function query($sql)
		{
			$this->log($sql . PHP_EOL . PHP_EOL);
			if($this->conn->query($sql))
				return $this->conn->affected_rows;
			return 0;
		}

		/**
		  * 返回所有的数据
		  * @return array
		  */
		public function getAll()
		{
			$sql = 'select id,wx from ' . $this->info_table . ' where is_exist=1';
			$re = $this->conn->query($sql);
			if(! $re)
				return array();
			$arr = [];
			while($row = $re->fetch_array(MYSQLI_ASSOC))
				$arr[] = $row;
			return $arr;
		}

		/**
		  * 返回时间
		  * @return string
		  */
		public function getLastTime()
		{
			if(!$this->time_flag)
				return 0;
			$sql = 'select time from ' . $this->time_table . ' order by aid desc limit 1';
			$re = $this->conn->query($sql);
			if(! $re)
				return 0;
			return $re->fetch_array(MYSQLI_ASSOC)['time'];
		}

		/**
		  * 插入数据
		  * @param Array $arr 二维数组 
		  * @return array
		  */
		public function insertAll($arr)
		{
			if(!count($arr)) {
            	return false;
	        }

	        $sql = 'insert into ' . $this->table;
	        foreach($arr as $k => $v)
	        {
	        	if($k == 0)
		        	$sql .= ' (' . implode(',',array_keys($v)) . ')'.' values ';
		        $sql .= '(\''.implode("','",array_values($v));
		        $sql .= '\'),';
	        }
	        $sql = rtrim($sql,',');
	        // echo $sql . PHP_EOL;
	        //$this->log($sql . PHP_EOL . PHP_EOL);
	        if(! $this->query($sql))
	        	throw new \Exception('ErrorCode : ' . $this->conn->errno);
	        return $this->conn->affected_rows;
		}

		/**
		  * 写入文件
		  */
		public function log($str,$flag = false)
		{
			if($this->file_opener)
			{
				flock($this->file_opener, LOCK_SH);
				fwrite($this->file_opener, $str);
				flock($this->file_opener, LOCK_UN);
			}
		}

		public function begin_transaction()
		{
			$this->conn->begin_transaction();
			echo 'begin_transaction' . PHP_EOL;
		}
		public function commit()
		{
			$this->conn->commit();
			echo 'commit_transaction' . PHP_EOL;
			$this->close();
		}

		public function truncate()
		{
			$sql = 'truncate ' . $this->table;
			if ($this->conn->query($sql))
				echo 'truncate table' . PHP_EOL;
		}

		private function close()
		{
			if($this->file_opener)
				fclose($this->file_opener);
			// $this->conn->close();
		}
		
		/**
         * 清空实例以便多进程使用而不会中断
         * @access public
         * @return void
         */
        public static function clearInstance()
        {               
            self::$instance = null;
        }

	}