<?php
	
	namespace wx;

	class Worker
	{
		//进程数
		public $process_num = 0;
		//当前进程的id
		private $process_id = 0;
		//当前进程的pid
		private $process_pid = 0;
		//进程中需要执行的函数
		public $worker_start_fun = null;
		//主进程pid
		protected static $master_pid = 0;
		//主进程中记录子进程
		protected static $master_sids = array();

		/**
		 * 构造函数
		 * @access public
		 */
		public function __construct()
		{
			self::$master_pid = posix_getpid();
			// declare(ticks = 1); //ticks机制性能比较差
			$this->installSignal();
		}

		/**
	     * 安装信号处理函数
	     * @access protected
	     * @return void
	     */
		protected function installSignal()
		{
			pcntl_signal(SIGINT, array($this,'handlerSignal'));
			pcntl_signal(SIGUSR1, array($this,'handlerSignal'));
			pcntl_signal(SIGUSR2, array($this,'handlerSignal'));
		}

		/**
	     * 信号处理函数
	     * @access public
	     * @param Int $signo 信号
	     * @return void
	     */
		public function handlerSignal($signo)
		{
			switch ($signo) {
				case SIGINT:
					$this->stopAll();
				 	break;
				case SIGUSR1:
					echo 'Caught SIGUSR1...' . PHP_EOL;
				 	break;
				case SIGUSR2:
				 	echo 'Caught SIGUSR2...' . PHP_EOL;
				 	break;
			}
		}

		/**
	     * 卸载信号处理
	     * @access protected
	     * @return void
	     */
		protected function uninstallSignal()
		{
			pcntl_signal(SIGINT, SIG_IGN);
			pcntl_signal(SIGUSR1, SIG_IGN);
			pcntl_signal(SIGUSR2, SIG_IGN);
		}

		/**
	     * 执行关闭流程
	     * @access protected
	     * @return void
	     */
		protected function stopAll()
		{
			//主进程
			if(self::$master_pid == posix_getpid())
			{
				//关闭所有的子进程
				foreach(self::$master_sids as $master_sid)
				{
					posix_kill($master_sid, SIGINT);
				}
			}
			//子进程
			else
			{
				exit(0);
			}
		}

		/**
	     * 创建一个子进程
	     * @access protected
	     * @param Int $process_id
	     */
		protected function forkOneWorker($process_id)
		{
			//创建进程
			$pid = pcntl_fork();
			//主进程
			if($pid > 0)
				self::$master_sids[$pid] = $pid;
			//子进程
			elseif(0 == $pid)
			{
				$this->process_id = $process_id;
				$this->process_pid = posix_getpid();
				self::$master_sids = array();
				if($this->worker_start_fun)
					call_user_func($this->worker_start_fun);
				//子进程退出，防止继续
				exit(0);
			}
			else{
				exit('fork one worker failed');
			}
		}

		/**
	     * 监控所有子进程
	     * @access protected
	     * @return bool
	     */
		protected function monitorWorkers()
		{
			while(true)
			{
				//状态码
				$status = 0;
				//调用信号控制器
				pcntl_signal_dispatch();
				$pid = pcntl_wait($status,WUNTRACED);
				//调用信号控制器
				pcntl_signal_dispatch();
				//子进程退出
				if($pid > 0)
				{
					//异常退出
					if(0 !== $status)
						echo 'worker ' . $pid . ' exit with status ' . $status . PHP_EOL;
					//去除子进程
					unset(self::$master_sids[$pid]);
					if(!self::$master_sids)
						return true;
				}
				else
				{
					return false;
				}
			}
		}

		/**
	     * 运行worker实例
	     */
	    public function run()
	    {
	        for ($i = 0; $i < $this->process_num; ++$i) 
	        {
	            $this->forkOneWorker($i);
	        }
	        $this->monitorWorkers();
	    }

	}