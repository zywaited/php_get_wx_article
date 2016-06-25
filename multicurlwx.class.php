<?php

	namespace wx;
	use wx\Mysqlidb;
	use wx\Redisdb;
	use wx\Worker;

	class MultiCurlWx
	{
		//抓取页面内容的头信息
		public $contentHeader = null;
		//抓取图片的数据流,也可以是头信息，不过这里只是单纯的换一种方式
		protected $imgStream = null;
		// protected $imgHeader = null;
		//DOM解析器
		protected $dom = null;
		//mysql实例
		protected $db = null;
		//Worker实例
		protected $worker = null;
		//配置数据
		protected $wx_config = null;
		//最小时间
		protected $last_time = null;

		//redisdb实例,init_type=1时有效
		protected $redisdb = null;
		
		//init_type=0时有效
		//数组数据
		protected $wx_list = null;
		//失败数据
		protected $fail_list = array();
		//失败长度
		protected $fail_len = 0;
		//记录文章相关信息
		protected $fetch_info = array();
		//记录curl相关信息
		protected $requestCurl = array();

		/**
		 * 构造函数
		 * @access public
		 */
		public function __construct()
		{
			$this->contentHeader = array(
				'Host' => 'www.gsdata.cn',
				'User-Agent' => 'Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/39.0.2171.95 Safari/537.36 MicroMessenger/6.5.2.501 NetType/WIFI WindowsWechat',
				'Referer' => 'http://www.gsdata.cn'
			);
			$this->imgStream = stream_context_create(array(
				'header' => array(
					'Host' => 'img1.gsdata.cn',
					'Referer' => 'http://www.gsdata.cn',
					'User-Agent' => 'Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/39.0.2171.95 Safari/537.36 MicroMessenger/6.5.2.501 NetType/WIFI WindowsWechat'
				)
			));
			$this->dom = new \DOMDocument();

			$this->wx_config = require './config/multi.php';
		}

		/**
		 * 初始化
		 * @access public
		 * @param Array $wx_config 配置数据
		 * @return void
		 */
		public function init($wx_config)
		{
			$this->wx_config = $wx_config;
		}

		/**
		 * 返回进程数量
		 * @access public
		 * @return Int
		 */
		public function getProcessNum()
		{
			return $this->wx_config['process_num'];
		}

		/**
		 * 获取页面内容
		 * @access public
		 * @param String $wx 微信号
		 * @param Int $page 页码数
		 * @return String 返回内容
		 */
		public function getWxContent($wx,$page = 1)
		{
			//这里url的参数可以添加时间查询,对于后面解析更方便，这里没有这么做
			if($this->last_time)
				$url = 'http://www.gsdata.cn/query/article?q=' . $wx . '&sort=-1&search_field=4&page=' . $page;
			else
				$url = 'http://www.gsdata.cn/query/article?q=' . $wx . '&post_time=0&sort=-1&search_field=4&page=' . $page;
			$curl = $this->createCurl($url);
			$content = curl_exec($curl);
			curl_close($curl);
			return $content;
		}

		/**
		 * 返回curl对象
		 * @access protected
		 * @param String $url 初始化url
		 * @return Curl
		 */
		protected function createCurl($url)
		{
			$curl = curl_init();
			curl_setopt($curl, CURLOPT_URL, $url);
			curl_setopt($curl, CURLOPT_HEADER, 0);
			curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
			curl_setopt($curl, CURLOPT_HTTPHEADER, $this->contentHeader);
			return $curl;
		}

		/**
		 * 获取图片信息
		 * @access public
		 * @param String $img_hash 图片加密字符串
		 * @return String 返回内容
		 */
		public function getWxImg($img_hash)
		{
			$re = file_get_contents('http://img1.gsdata.cn/index.php/rank/getImageUrl?callback=jQuery&hash='.$img_hash.'&_='.time(),false,$this->imgStream);
			$index = strpos($re,'{');
			$data = json_decode(substr($re,$index,strpos($re,'}')-$index+1),true);
			return $data['url'];
		}

		/**
		 * 解析页面内容
		 * @access public
		 * @param String $content 页面内容
		 * @param Array $wx_info 当前微信相关信息
		 * @return Array 页面文章数据
		 */
		public function parseContent($content,$wx_info)
		{
			$this->fetch_info[$wx_info['wx']]['time_flag'] = false;
			$wx_arrs = [];
			if(!$this->dom->loadHTML($content))
				throw new \Exception('the dom loadHTML is error');
			$dx = new \DOMXPath($this->dom);
			$query = '//ul[@class="article-ul"]//li';
			$nodes = $dx->query($query);
			for ($j = 0, $len = $nodes->length; $j < $len; ++$j)
			{
				$li = $nodes->item($j)->childNodes;  //li标签

				$flag_index = 0;
				$wx_news = $li->item(3)->childNodes;  //div['wx-news'] 标签
				$wx_ft = $wx_news->item(5)->childNodes; // div['wx-ft'] 标签
				if( ($wx_ft->length < 6) || (! $wx_ft->item(3)->nodeValue) )
				{
					$flag_index = 2; //防止原创图标出现干扰
					$wx_ft = $wx_news->item(5 + $flag_index)->childNodes;
				}
				$span = explode('：', $wx_ft->item(3)->nodeValue);  //span 文本

				if(!isset($span[1]))
					throw new \Exception('the ' . $wx_info['wx'] . '\'s time has not found');
					
				$tmp_time = strtotime($span[1]);  //解析时间
				if($tmp_time > $this->last_time)
				{
					$wx_arrs[$j]['time'] = $tmp_time;
					$hash = $li->item(1)->childNodes->item(1)->childNodes->item(0)->getAttribute('data-hash'); //img标签中的hash
					if(!$hash)
						throw new \Exception('the ' . $wx_info['wx'] . '\'s img_hash has not found');
					$wx_arrs[$j]['imglink'] = $this->getWxImg($hash);
					
					$font = $wx_ft->item(5)->childNodes;  //font 标签
					$wx_arrs[$j]['readnum'] = intval(trim($font->item(2)->nodeValue));  //阅读量
					$wx_arrs[$j]['likenum'] = intval(trim($font->item(4)->nodeValue));  //赞

					$h4 = $wx_news->item(1 + $flag_index)->childNodes;  //h4 标签
					$a = $h4->item(1); //h4 a 标签
					$wx_arrs[$j]['url'] = $a->getAttribute('href');  //a href属性
					$wx_arrs[$j]['title'] = $a->nodeValue;  //a 文本
					$wx_arrs[$j]['content'] = $wx_news->item(3 + $flag_index)->nodeValue; // a desc文本
					if(!$wx_arrs[$j]['url'] || !$wx_arrs[$j]['title'] || !$wx_arrs[$j]['content'])
						throw new \Exception('the ' . $wx_info['wx'] . '\'s url|title|content has not found');
					$wx_arrs[$j]['id'] = $wx_info['id'];
				}
				else
				{
					//没有新数据
					$this->fetch_info[$wx_info['wx']]['time_flag'] = true;
					break;
				}
			}
			if(! $wx_arrs)
				$this->fetch_info[$wx_info['wx']]['time_flag'] = true;
			return $wx_arrs;
		}

		/**
		 * 执行抓取
		 * @access public
		 * @return void
		 */
		public function run()
		{
			require './mysqli.class.php';
			$this->db = Mysqlidb::getInit();
			$this->wx_list = $this->db->getAll(); //初始化数据
			$this->last_time = $this->db->getLastTime();
			$this->db->truncate();
			// $this->db->begin_transaction();
			if($this->wx_config['init_type'])
			{
				require './worker.class.php';
				require './redis.class.php';
				$this->worker = new Worker();
				$this->redisdb = Redisdb::getInit();
				$this->redisdb->initData($this->wx_list);
				//去除数据
				unset($this->wx_list);
				$this->worker->worker_start_fun = array($this,'multiProcess');
				$this->worker->process_num = $this->wx_config['process_num'];
				$this->worker->run();
				$this->redisdb->printAndPut();
				while($this->wx_config['again_num'] && $this->redisdb->isFailed())
				{
					// $this->db->begin_transaction();
					--$this->wx_config['again_num'];
					echo 'start try again and has ' . $this->wx_config['again_num'] . ' chance' . PHP_EOL;
					$this->worker->run();
				}
				$this->redisdb->printAndPut(true);
			}
			else
			{
				$this->db->begin_transaction();
				$this->multiCurl();
				$this->printAndPut();
				while($this->wx_config['again_num'] && $this->isFailed())
				{
					$this->wx_list = $this->fail_list;
					$this->fail_list = $this->fetch_info = array();
					--$this->wx_config['again_num'];
					$this->multiCurl();
				}
				$this->printAndPut(true);
				$this->db->commit();
			}
			// $this->db->commit();
		}

		/**
		 * 多curl抓取
		 * @access protected
		 * @return void
		 */
		protected function multiCurl()
		{
			if($this->wx_list && $this->wx_config['multi_num'])
			{
				//是否已经结束的标志,防止意外退出
				$over_flag = false;
				$wx_len = count($this->wx_list);
				$mh = curl_multi_init();
				//记录当前curl，方便查找
				for($i = 0; $i < $this->wx_config['multi_num'] && $i < $wx_len; ++$i)
				{
					$curl = $this->createByWx($i);
					$this->requestCurl[$i] = $curl;
					curl_multi_add_handle($mh, $curl);
				}

				$status = 0;
				do
				{
					while(($cme = curl_multi_exec($mh, $status)) == CURLM_CALL_MULTI_PERFORM)
						;
					if(CURLM_OK != $cme)
						break;

					while ($result = curl_multi_info_read($mh))
					{
						$info = curl_getinfo($result['handle']);

						$wx_index = (substr($info['url'],strrpos($info['url'], 'wx_index=') + 9)) + 0;

						// $error = curl_error($result['handle']);

						// $wx_index = $this->getWxIndex($result['handle']);
						$wx_nows = $this->wx_list[$wx_index];

						try
						{
							if(!isset($this->fetch_info[$wx_nows['wx']]['fetched']))
							{
								$content = curl_multi_getcontent($result['handle']);
								$affected_num = $this->saveData($this->parseContent($content,$wx_nows));
								if($affected_num)
									echo 'the WeChat public number (' . $wx_nows['id'] . ') : ' . $wx_nows['wx'] . ' has inserted ' . $affected_num . ' and the page is ' . $this->fetch_info[$wx_nows['wx']]['page'] . PHP_EOL;
								else
									echo 'the WeChat public number (' . $wx_nows['id'] . ') : ' . $wx_nows['wx'] . ' has no data and the page is ' . $this->fetch_info[$wx_nows['wx']]['page'] . PHP_EOL;
								
								$this->fetch_info[$wx_nows['wx']]['fetched'] = true;
							}
							else
								$this->fetch_info[$wx_nows['wx']]['time_flag'] = true;	// 如果出现重复的情况，这里重置一下，跳出本微信号
						} catch(\Exception $e)
						{
							echo 'the WeChat public number (' . $wx_nows['id'] . ') : ' . $wx_nows['wx'] . ' has failed and the error message is ' . $e->getMessage() . PHP_EOL;
							$this->fail_list[] = $wx_nows;
							$this->fetch_info[$wx_nows['wx']]['time_flag'] = true; //出错就不在继续抓取该微信
						}

						//移除已经完成的
		                curl_multi_remove_handle($mh, $result['handle']);
		                // curl_close($result['handle']);
		                unset($this->requestCurl[$wx_index]);

						//如果需要，加入新的句柄
						if ($i < $wx_len && isset($this->wx_list[$i]))
		                { 
		                	if($this->fetch_info[$wx_nows['wx']]['time_flag'])
		                	{
		                		$curl = $this->createByWx($i);
								$this->requestCurl[$i] = $curl;
								++$i;
							}
							//抓取该微信下一页
							elseif($this->fetch_info[$wx_nows['wx']]['page'] < $this->wx_config['page_num'])
							{
								$curl = $this->createByWx($wx_index);
								$this->requestCurl[$wx_index] = $curl;
							}
							
							curl_multi_add_handle($mh, $curl);
		                }
		                else
		                	$over_flag = true;
		            }

		            //睡眠，不要抓取太频繁
				    // sleep(0.6);
					if ($status)
		                curl_multi_select($mh, 10);
				} while($status || !$over_flag);
			}
			curl_multi_close($mh);
		}

		/**
		 * 多进程抓取，这个方法在多进程类需要调用，所以设为public
		 * @access public
		 * @return void
		 */
		public function multiProcess()
		{
			// Redisdb::clearInstance();
			// $this->redisdb = Redisdb::getInit();
			//多进程完全复制一份数据，此处需要重置mysql连接，不然会出现2006错误
			Mysqlidb::clearInstance();
			$this->db = Mysqlidb::getInit();
			while(true)
			{
				$wx_nows = $this->redisdb->get();
				if(! $wx_nows)
					break; //没有微信号数据时跳出while
				echo 'start' . PHP_EOL;
				$this->db->begin_transaction();
				for($i = 0; $i < $this->wx_config['page_num']; ++$i)
				{
					try
					{
						$affected_num = $this->saveData($this->parseContent($this->getWxContent($wx_nows['wx'], $i + 1),$wx_nows));
						if($affected_num)
							echo 'the WeChat public number : ' . $wx_nows['wx'] . ' has inserted ' . $affected_num . 'and the id is ' . $wx_nows['id'] . ' and the page is ' . ($i + 1) . PHP_EOL;
						else
							echo 'the WeChat public number : ' . $wx_nows['wx'] . ' has no data and the id is ' . $wx_nows['id'] . ' and the page is ' . ($i + 1) . PHP_EOL;

						//睡眠，不要抓取太频繁
			            sleep(1);
						if($this->fetch_info[$wx_nows['wx']]['time_flag'])
							break; //已经没有新数据时跳出for
					} catch(\Exception $e)
					{
						echo 'the WeChat public number : ' . $wx_nows['wx'] . ' has failed and the error message is ' . $e->getMessage() . PHP_EOL;
						$this->redisdb->failPut($wx_nows);

						//睡眠，不要抓取太频繁
			            sleep(1);
						break; //出现问题时跳出for和当前微信号
					}
				}
				$this->db->commit();
				echo 'end' . PHP_EOL;
			}
		}

		/**
		 * 存储数据
		 * @access protected
		 * @param Array $wx_arrs 文章数据
		 * @throws Exception
		 * @return Int
		 */
		protected function saveData($wx_arrs)
		{
			if(!$this->db)
				throw new \Exception('the mysql db is null');
			return $this->db->insertAll($wx_arrs);
		}

		/**
		 * 获取当前curl正在抓取的公众号的位置
		 * @access protected
		 * @param Curl $curl curl资源
		 * @return Int
		 */
		protected function getWxIndex($curl)
		{
			foreach($this->requestCurl as $k => $v)
			{
				if($v == $curl)
					return $k;
			}
		}

		/**
		 * 根据微信位置创建句柄
		 * @access protected
		 * @param Int $i 微信位置
		 * @return Curl
		 */
		protected function createByWx($i)
		{
			$wx = $this->wx_list[$i]['wx'];
			$this->fetch_info[$wx]['page'] = !empty($this->fetch_info[$wx]['page']) ? ($this->fetch_info[$wx]['page'] + 1) : 1;
			if($this->last_time)	
				$url = 'http://www.gsdata.cn/query/article?q=' . $wx . '&sort=-1&search_field=4&page=' . $this->fetch_info[$wx]['page'] . '&wx_index=' . $i;
			else
				$url = 'http://www.gsdata.cn/query/article?q=' . $wx . '&post_time=0&sort=-1&search_field=4&page=' . $this->fetch_info[$wx]['page'] . '&wx_index=' . $i;
        	
        	return $this->createCurl($url);
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

		/**
		 * 打印、移除失败元素并重新加入队列
		 * @access public
		 * @param Bool $is_over 是否全部完成
		 * @return void
		 */
		public function printAndPut($is_over=false)
		{
			$this->fail_len = count($this->fail_list);
			if($this->fail_len)
			{
				echo 'now ' . $this->fail_len .' WeChat public numbers have failed' . PHP_EOL;
				foreach($this->fail_list as $fail)
				{
					echo 'the fail WeChat public number is ' . $fail['wx'] . PHP_EOL;
				}
			}
			elseif($is_over)
			{
				echo 'now ' . $this->fail_len .' WeChat public numbers have failed' . PHP_EOL;
				echo 'all WeChat public numbers have fetched and success' . PHP_EOL;
			}
		}
	}