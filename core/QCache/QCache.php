<?php
	define('__CWD__', dirname(__FILE__));
	class QCache {
		private $_cache_dir = 'cache/{engine}.cache';
		
		private $_cache = NULL;
		
		public function __construct($cache_dir = NULL){
			if(empty($cache_dir)){
				$this->_cache_dir = __CWD__ . '/' . $this->_cache_dir;
			} else {
				$this->_cache_dir = $cache_dir;
			}
		}
		
		/*
		 * Allows setting the cache directory
		 * 
		 * @param string $dir
		 * @return void
		 */
		public function set_cache_dir($dir){
			$this->_cache_dir = $dir;
		}
		
		/*
		 * Loads parsed query for a specific engine into memory
		 * 
		 * @param array $engine_data
		 * @return void
		 */
		public function load($engine_data){
			$cache_file = str_replace('{engine}', strtolower($engine_data[0]), $this->_cache_dir);
			if(file_exists($cache_file)){
				$this->_cache[$engine_data[1]] = parse_ini_file($cache_file);
			} else {
				$this->_cache[$engine_data[1]] = array();
			}
		}
		
		/*
		 * Saves the parsed query and it's signature into database/file
		 * 
		 * @param array $data
		 * @return void
		 */
		public function record($data){
			$cache_file = str_replace('{engine}', $data['engine_raw'], $this->_cache_dir);
			if(is_writeable(dirname($cache_file)) && !empty($data['q'])){
				$fh = fopen($cache_file, 'a+');
				$qid = sha1(json_encode($data['struct']));
				if(fwrite($fh, $qid . ' = ' . '"' . $data['q'] . '"' . "\n")){
					$this->_cache[$data['engine']][$qid] = $data['q'];
				}
				fclose($fh);
			}
		}
		
		/*
		 * Loads a specific record into memory identified by a signature
		 * 
		 * @param $engine Engine name
		 * @return $id The query signature
		 */
		public function fetch($engine, $id){
			return isset($this->_cache[$engine][$id]) ? $this->_cache[$engine][$id] : FALSE;
		}
	}
