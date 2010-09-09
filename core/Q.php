<?php
	require_once dirname(__FILE__).'/QCache/QCache.php';
	
	if(!function_exists('in_arrayi')){
		function in_arrayi($needle, $haystack){ // case-insensitive in_array
			return in_array(strtolower($needle), array_map('strtolower', $haystack));
		}
	}
	
	class FallbackQEngine { // default fallback query engine if none is found
		/**
		 * Converts an array like so:
		 * 
		 * array(
		 * 	'a' => 'b',
		 * 	'c'
		 * );
		 * 
		 * to:
		 * 
		 * array('a AS b', 'c');
		 * 
		 * @param array $array
		 * @return array $fields
		 */
		protected function array_to_aliased_fields($array){
			foreach($array as $k => $v){
				$fields[] = is_numeric($k) ? trim($v) : trim($k) . ' AS ' . trim($v);
			}
			return $fields;
		}
		
		/**
		 * Parses where condition
		 * 
		 * @param array $array
		 * @return array $where
		 */
		protected function parse_where_condition($array){
			$where = array();
			foreach($array as $k => $v){
#				if(is_string($v)){ // raw queries, for e.g. nested selects
#					$where[] = $v; // we can't really do much with these
#				} else if(is_array($v)) { // comparisons
				if(is_array($v)){
#					$v[2] = $this->escape($v[2]);
					$where[] = count($v) == 2 ? implode(' ', array($v[0], '=', $v[1])) : implode(' ', $v);
				} else if(is_float($v)){ // logical operators, AND, OR
					switch($v){
						case Q::_AND:
							$where[] = 'AND';
						break;
						case Q::_OR:
							$where[] = 'OR';
						break;
					}
				} else {
					$where[] = $v;
				}
			}
			return $where;
		}
		
		/**
		 * Parse join conditions
		 * 
		 * @param array $join_condition
		 * @param array &$unpack_into
		 * @return void
		 */
		protected function parse_join_condition($join_condition, &$unpack_into){
			if(!empty($join_condition['on'])){
				$unpack_into[] = 'ON';
				$unpack_into[] = implode(' ', $this->parse_where_condition($join_condition['on']));
			} else if(!empty($join_condition['using'])){
				$unpack_into[] = 'USING';
				$unpack_into[] = '(' . implode(', ', $join_condition['using']) . ')';
			}
		}
		
		/**
		 * Parse ORDER BY and GROUP BY statements
		 * 
		 * @param array $array
		 * @return array $ordering
		 */
		protected function parse_ordering($array){
			$ordering = array();
			if(is_numeric(end($array))){
				$order = array_pop($array);
			}
			$ordering[] = implode(', ', $array);
			if($order){
				$ordering[] = $order == Q::ASC ? 'ASC' : 'DESC';
			}
			return $ordering;
		}
		
		/**
		 * Parses JOIN direction, whether LEFT or RIGHT by looking at the elements in the array
		 * 
		 * @param array $array
		 * @return string
		 */
		protected function parse_join_direction($array){
			return in_arrayi('left', $array) ? 'LEFT' : (in_arrayi('right', $array) ? 'RIGHT' : NULL);
		}
		
		/**
		 * Default, fallback escape
		 * 
		 * @param string|int|float $o
		 * @return string|int|float
		 */
		public function escape($o){
			if(is_string($o)){
				return str_replace(array("\\", "\0", "\n", "\r", "\x1a", "'", '"'), array("\\\\", "\\0", "\\n", "\\r", "\Z", "\'", '\"'), $o);
			} else if(is_int($o)){
				return (int) $o;
			} else if(is_float($o)){
				return (float) $o;
			}
		}
		
		/**
		 * SELECT helper function, this is where the statement is composed.
		 * 
		 * @param array $struct
		 * @return string
		 */
		public function select($struct){
			if(empty($struct['from'])) return '';
			if(!empty($struct['select'])){
				$query = array('SELECT');
				if(in_arrayi('distinct', $struct)){
					$query[] = 'DISTINCT';
				}
				$fields = $this->array_to_aliased_fields($struct['select']);
				$from = $this->array_to_aliased_fields($struct['from']);
				$where = array();
				if(!empty($struct['where'])){
					$where = $this->parse_where_condition($struct['where']);
					array_unshift($where, 'WHERE');
				}
			}
			
			$joins = array();
			
			if(!empty($struct['join'])){
				$struct['join_ci'] = array_change_key_case($struct['join'], CASE_LOWER);
				$struct['join_k'] = array_keys($struct['join']);
				$index = 0;
				foreach($struct['join_ci'] as $table_name => $join_info){
					$table_name = trim($struct['join_k'][$index]);
					if(in_arrayi('straight', $join_info)){ // Straight join
						$joins[] = 'STRAIGHT_JOIN';
						$joins[] = $table_name;
						$this->parse_join_condition($join_info, $joins);
					} else if(in_arrayi('natural', $join_info)){ // Natural join
						$joins[] = 'NATURAL';
						if(in_arrayi('outer', $join_info)){
							$direction = $this->parse_join_direction($join_info);
							if(!empty($direction)){
								$joins[] = $direction;
							}
							$joins[] = 'OUTER';
						}
						$joins[] = 'JOIN';
						$joins[] = $table_name;
						$this->parse_join_condition($join_info, $joins);
					} else if(in_arrayi('cross', $join_info)){ // cross join
						$joins[] = 'CROSS JOIN';
						$joins[] = $table_name;
						$this->parse_join_condition($join_info, $joins);
					} else if(in_arrayi('inner', $join_info)){ // inner join
						$joins[] = 'INNER JOIN';
						$joins[] = $table_name;
						$this->parse_join_condition($join_info, $joins);
					} else if(in_arrayi('outer', $join_info)){ // outer join
						$joins[] = $this->parse_join_direction($join_info);
						$joins[] = 'OUTER JOIN';
						$joins[] = $table_name;
						$this->parse_join_condition($join_info, $joins);
					} else {
						$joins[] = $this->parse_join_direction($join_info);
						$joins[] = 'JOIN';
						$joins[] = $table_name;
						$this->parse_join_condition($join_info, $joins);
					}
					$index++;
				}
			}
			
			if(empty($struct['select'])){
				$from = $this->array_to_aliased_fields($struct['from']);
				return implode(', ', $from) . ' ' . implode(' ', $joins);
			}
			
			$query[] = implode(', ', $fields);
			$query[] = 'FROM';
			$query[] = implode(', ', $from);
			$query[] = implode(' ', $joins);
			$query[] = implode(' ', $where);
			
			if(!empty($struct['group by'])){
				$query[] = 'GROUP BY';
				$query[] = implode(' ', $this->parse_ordering($struct['group by']));
			}
			
			if(!empty($struct['having'])){
				$having = $this->parse_where_condition($struct['having']);
				array_unshift($having, 'HAVING');
				$query[] = implode(' ', $having);
			}
			
			if(!empty($struct['order by'])){
				$query[] = 'ORDER BY';
				$query[] = implode(' ', $this->parse_ordering($struct['order by']));
			}
			
			if(!empty($struct['limit'])){
				$query[] = 'LIMIT';
				$query[] = implode(', ', $struct['limit']);
			}
			
			return implode(' ', $query);
		}
		
		/**
		 * UPDATE helper function
		 * 
		 * @param array $struct
		 * @return string
		 */
		public function update($struct){
			if(empty($struct['update'])) return '';
			if(empty($struct['values'])) return '';
			$query = array('UPDATE', implode(', ', $struct['update']), 'SET');
			$sets = array();
			foreach($struct['values'] as $k => $v){
				if(is_numeric($k)){
					$sets[] = $v;
				} else {
					$sets[] = $k . ' = ' . $v;
				}
			}
			$query[] = implode(', ', $sets);
			if(!empty($struct['where'])){
				$query[] = 'WHERE';
				$query[] = implode(' ', $this->parse_where_condition($struct['where']));
			}
			
			if(!empty($struct['order by'])){
				$query[] = 'ORDER BY';
				$query[] = implode(' ', $this->parse_ordering($struct['order by']));
			}
			
			if(!empty($struct['limit'])){
				$query[] = 'LIMIT';
				$query[] = implode(', ', $struct['limit']);
			}
			
			return implode(' ', $query);
		}
		
		/**
		 * DELETE helper function, since the DELETE statement is almost similar to SELECT, 
		 * we simply remove the column references and use the SELECT helper function to compose the statement,
		 * finally we replace the first "SELECT" with "DELETE"
		 * 
		 * @param array $struct
		 * @return string
		 */
		public function delete($struct){
			if(empty($struct)) return '';
			$struct['select'] = array('');
			$stmt = empty($struct['delete']) ? 'DELETE' : 'DELETE ' . implode(' ', $this->array_to_aliased_fields($struct['delete']));
			return preg_replace('/^SELECT/i', $stmt, $this->select($struct), 1); // only replace the very first DELETE
		}
		
		/**
		 * INSERT helper function
		 * 
		 * @param array $struct
		 * @return string
		 */
		public function insert($struct){
			if(empty($struct)) return '';
			if(!empty($struct['insert'])){
				if(!empty($struct['values'])){
					$keys = array_keys($struct['values']);
					$string_keys = array_filter($keys, 'is_string');
					if(count($string_keys) == count($keys)){ // all associative
						return implode(' ', array('INSERT INTO', $struct['insert'], '(' . implode(', ', $keys) . ')', 'VALUES', '(' . implode(', ', $struct['values']) . ')'));
					} else if(empty($string_keys)){ // all numeric
						return implode(' ', array('INSERT INTO', $struct['insert'], 'VALUES', '(' . implode(', ', $struct['values']) . ')'));
					} else { // some numeric, while others associative
						return '';
					}
				}
			}
			return '';
		}
		
		/**
		 * CREATE helper function
		 * 
		 * @param string $obj Contains what to 'create', 'table' or 'view' or anything else
		 * @param array $struct
		 * @return string
		 */
		public function create($obj, $struct){
			$query = array('CREATE');
			$acts = array( // why use ifs and switches when you can do it easier?
				'table' => 'create_table'
			);
			return isset($acts[$obj]) ? call_user_func(array(&$this, $acts[$obj]), $struct, &$query) : '';
		}
		
		/**
		 * CREATE TABLE helper function
		 * 
		 * @param array $struct
		 * @param array &$query This is passed by FallbackQEngine->create
		 * @return string
		 */
		public function create_table($struct, &$query){
			if(empty($struct['create table']) || empty($struct['fields'])) return '';
			if(in_arrayi('temporary', $struct)) $query[] = 'TEMPORARY';
			$query[] = 'TABLE';
			if(in_arrayi('if non existent', $struct)) $query[] = 'IF NOT EXISTS';
			$query[] = trim($struct['create table']);
			if(!empty($struct['like'])){
				$query[] = 'LIKE';
				$query[] = $struct['like'];
				return implode(' ', $query);
			}
			$query[] = '(';
			$fields = array();
			foreach($struct['fields'] as $field_name => $field_properties){
				$field = array();
				$field[] = $field_name;
				if(!empty($field_properties['type'])){
					$field['type'] = strtoupper($field_properties['type']);
					if(!empty($field_properties['length']))	$field['type'] .= '(' . $field_properties['length'] . ')';
				}
				if(in_arrayi('not null', $field_properties)) $field[] = 'NOT NULL';
				if(!empty($field_properties['default'])){
					$field[] = 'DEFAULT';
					$field[] = $field_properties['default'];
				}
				if(in_arrayi('auto increment', $field_properties)) $field[] = 'AUTO_INCREMENT';
				if(in_arrayi('unique', $field_properties)){
					$field[] = 'UNIQUE';
				} else if(in_arrayi('unique key', $field_properties)){
					$field[] = 'UNIQUE KEY';
				}
				
				if(in_arrayi('primary key', $field_properties)){
					$field[] = 'PRIMARY KEY';
				}
				
				if(!empty($field_properties['comment'])){
					$field[] = 'COMMENT';
					$field[] = "'".$field_properties['comment']."'";
				}
				
				$fields[] = implode(' ', $field);
			}
			$query[] = implode(', ', $fields);
			$query[] = ')';
			return implode(' ', $query);
		}
		
		/**
		 * Concatenation helper for cross-compatibility
		 * 
		 * @param string|int|float
		 * @return string
		 */
		public function concat(){
			$args = func_get_args();
			return implode(' + ', $args);
		}
		
		public function _case($struct){
			$query = array('CASE', $struct['case']);
			foreach($struct['when'] as $when => $then){
				if(is_numeric($when)){ // when a numeric key is found, assume it's an ELSE
					$query[] = 'ELSE';
					$query[] = '('.$then.')';
					break; // we're done, break, we don't want to check for any more values
				}
				$query[] = sprintf('WHEN (%s) THEN (%s)', $when, $then);
			}
			$query[] = 'END CASE';
			return implode(' ', $query);
		}
	}
	
	class Q {
		private static $_engines = NULL; // Our initiated engines will be stored here
		private static $_engine_dir = './Engines/{engine}.php';
		private static $_possible_acts = array('create table', 'delete', 'drop table', 'insert', 'select', 'update', '_case');
		private static $_default_engine = array('fallback', 'FallbackQEngine');
		
		private static $_cache = NULL; // Our Cache engine
		private static $_enable_cache = TRUE;

		private static $_errors = array();
		
		private $_query = NULL;
		private $_latest = array();
		
		/**
		 * Constructor, don't call it manually!
		 * 
		 * @param string $engine The engine name
		 */
		protected function __construct($engine){
			Q::init($engine);
		}
		
		/**
		 * Helper function to instantiate a new Q object, call it via: Q::uery()
		 * 
		 * @param string $engine The engine name
		 * @return object Q
		 */
		public static function uery($engine = NULL){
			if(empty($engine)) $engine = strtolower(self::$_default_engine[0]); // if engine is empty, set it to last loaded
			return new Q($engine);
		}
		
		public function sql(){
			return $this->__toString();
		}
		
		/**
		 * SELECT helper for chainable queries
		 * 
		 * @param string|array One argument if array, several if string
		 * @return object $this Allows chaining
		 */
		public function select(){
			$args = func_get_args();
			if(isset($args[0]) && is_array($args[0])){
				$args = $args[0];
			}
			$this->_query['select'] = $args;
			$this->_latest['token'] = 'select';
			return $this;
		}
		
		public function distinct(){
			if(func_num_args() > 0){
				$args = func_get_args();
				call_user_func_array(array(&$this, 'select'), $args);
			}
			$this->_query[] = 'distinct';
			return $this;
		}
		
		/**
		 * DELETE helper
		 * 
		 * @param void
		 * @return object $this
		 */
		public function delete(){
			$args = func_get_args();
			if(isset($args[0]) && is_array($args[0])){
				$args = $args[0];
			}
			$this->_query['delete'] = $args;
			$this->_latest['token'] = 'delete';
			return $this;
		}
		
		/**
		 * UPDATE helper
		 * 
		 * @param string,... Table references
		 * @return object $this
		 */
		public function update(){
			$this->_query['update'] = func_get_args();
			$this->_latest['token'] = 'update';
			return $this;
		}
		
		/**
		 * SET helper for INSERT and UPDATE
		 * 
		 * @param array $values
		 * @return object $this
		 */
		public function values(){
			if($this->_latest['token'] == 'update' || $this->_latest['token'] == 'insert'){
				$args = is_array(func_get_arg(0)) ? func_get_arg(0) : func_get_args();
				$this->_query['values'] = array_map(create_function('$x', 'return $x === NULL ? \'NULL\' : $x;'), $args);
			}
			return $this;
		}
		
		/**
		 * INSERT helper
		 * 
		 * @return object $this
		 */
		public function insert(){
			$this->_query['insert'] = NULL;
			$this->_latest['token'] = 'insert';
			return $this;
		}
		
		/**
		 * CREATE TABLE helper
		 * 
		 * @return object $this
		 */
		public function create_table(){
			$args = func_get_args();
			$table_name = array_shift($args);
			if(in_arrayi('if non existent', $args)) $this->_query[] = 'if non existent';
			if(in_arrayi('temporary', $args)) $this->_query[] = 'temporary';
			$this->_query['create table'] = $table_name;
			$this->_latest['token'] = 'create table';
			return $this;
		}
		
		/**
		 * Helper to provide flexibility
		 * 
		 * @param mixed $obj
		 */
		public function append($obj){
			$this->_query[] = $obj;
		}
		
		/**
		 * Helper for CREATE TABLE
		 * 
		 * @param array $fields Field definitions
		 * @return object $this
		 */
		public function fields($fields){
			if($this->_latest['token'] == 'create table'){
				$this->_query['fields'] = $fields;
			}
			return $this;
		}
		
		/**
		 * LIKE helper
		 * 
		 * @param string $match
		 */
		public function like($match){
			if($this->_latest['token'] == 'where'){
				$this->_query['where'][] = 'LIKE'; // TODO: fix this please! Brain too tired to think of anything better.
				$this->_query['where'][] = Q::str($match);
				return $this;
			}
			$this->_query['like'] = $match;
			return $this;
		}
		
		public function unlike($match){
			if($this->_latest['token'] == 'where'){
				$this->_query['where'][] = 'NOT LIKE'; // TODO: fix this please! Brain too tired to think of anything better.
				$this->_query['where'][] = Q::str($match);
				return $this;
			}
			return $this;
		}
		
		public function order_by(){
			$args = func_get_args();
			$this->_query['order by'] = $args;
			return $this;
		}
		
		public function group_by(){
			$args = func_get_args();
			$this->_query['group by'] = $args;
			return $this;
		}
		
		public function having(){
			$args = func_get_args();
			$this->_query['having'] = $args;
			return $this;
		}
		
		public function _and(){
			$this->_query['where'][] = Q::_AND;
			$args = func_get_args();
			$this->_latest['token'] = 'conditional';
			return call_user_func_array(array(&$this, 'where'), $args);
		}
		
		public function _or(){
			$this->_query['where'][] = Q::_OR;
			$args = func_get_args();
			$this->_latest['token'] = 'conditional';
			return call_user_func_array(array(&$this, 'where'), $args);
		}
		
		public function _case($case_value = NULL){
			if($this->_latest['token'] === NULL){ // case doesn't "belong" to anything, it's a statement of it's own
				$this->_query['_case'] = $case_value;
				$this->_latest['token'] = 'case';
			}
			return $this;
		}
		
		public function when($conditions){
			if($this->_latest['token'] == 'case'){
				$this->_query['when'] = $conditions;
			}
			return $this;
		}
		
		/**
		 * FROM helper for SELECT and DELETE
		 * 
		 * @param array|string,...
		 * @return object $this
		 */
		public function from(){
			$args = func_get_args();
			if(isset($args[0]) && is_array($args[0])){
				$args = $args[0];
			}
			if($this->_latest['token'] == 'select' || $this->_latest['token'] == 'delete'){
				$this->_query['from'] = $args;
			}
			$this->_latest['token'] = 'from';
			return $this;
		}
		
		/**
		 * WHERE helper
		 * 
		 * @param mixed,...
		 */
		public function where(){
/*			$args = func_get_args();
			if(func_num_args() == 3){
				$args = array(func_get_args());
			}
			$this->_query['where'] = $args;*/
			if($this->_latest['token'] == 'conditional' || $this->_latest['token'] == 'from' || $this->_latest['token'] == 'update' || $this->_latest['token'] == 'join'){
				$args = func_get_args();
				$this->_query['where'] = (isset($this->_query['where']) && is_array($this->_query['where'])) ? array_merge($this->_query['where'], $args) : $args;
				$this->_latest['token'] = 'where';
			}
			return $this;
		}
		
		/**
		 * Unified JOIN helper
		 * 
		 */

		private function _join($table_name, $type){
			if(isset($this->_query['join'][$table_name])){
				$table_name = str_repeat(' ', count($this->_query['join'])) . $table_name;
			}
			$this->_query['join'][$table_name][] = $type;
			$this->_latest['token'] = 'join';
			$this->_latest['join'] = $type;
			$this->_latest['join_on'] = $table_name;
			return $table_name;
		}
		
		public function straight_join($table_name){
			call_user_func(array(&$this, '_join'), $table_name, 'straight');
			return $this;
		}
		
		public function natural_join($table_name){
			$direction = $this->_latest['direction'];
			$this->_latest['direction'] = NULL; // it's job is done here ...
			$is_outer = ($this->_latest['join'] == 'outer' && $this->_latest['token'] == 'join');
			$table_name = call_user_func(array(&$this, '_join'), $table_name, 'natural');
			if($is_outer){
				$this->_query['join'][$table_name][] = $direction;
				$this->_query['join'][$table_name][] = 'outer';
			}
			return $this;
		}
		
		public function inner_join($table_name){
			call_user_func(array(&$this, '_join'), $table_name, 'inner');
			return $this;
		}
		
		public function cross_join($table_name){
			call_user_func(array(&$this, '_join'), $table_name, 'cross');
			return $this;
		}
		
		public function outer(){
			$this->_latest['join'] = 'outer';
			$this->_latest['token'] = 'join';
			return $this;
		}
		
		public function join($table_name){ // just a helper for the 'outer' function
			if(	$this->_latest['join'] != 'natural' &&
				$this->_latest['join'] != 'straight' &&
				$this->_latest['join'] != 'cross' &&
				$this->_latest['join'] != 'inner'
			){
				$direction = $this->_latest['direction'];
				$outer = ($this->_latest['join'] == 'outer' && $this->_latest['token'] == 'join') ? 'outer' : 'plain';
				$table_name = call_user_func(array(&$this, '_join'), $table_name, $outer);
				$this->_query['join'][$table_name][] = $direction;
			}
			return $this;
		}
		
		public function left(){
			$this->_latest['direction'] = 'left';
			return $this;
		}
		
		public function right(){
			$this->_latest['direction'] = 'right';
			return $this;
		}
		
		public function on(){
			if($this->_latest['token'] == 'join'){
				$this->_query['join'][$this->_latest['join_on']]['on'] = func_get_args();
			}
			return $this;
		}
		
		public function using(){
			if($this->_latest['token'] == 'join'){
				$this->_query['join'][$this->_latest['join_on']]['using'] = func_get_args();
			}
			return $this;
		}
		
		public function into($tbl_name){
			if($this->_latest['token'] == 'insert'){
				$this->_query['insert'] = $tbl_name;
			}
			return $this;
		}
		
		public function __toString(){
			return Q::build($this->_query);
		}
		
		const _AND = 3.5;
		const _OR = 2.5;
		const ASC = 2;
		const DESC = 3;

		const ON = TRUE; // syntactic sugar
		const OFF = FALSE; // syntactic sugar
		
		/**
		 * Turn Caching on or off
		 * 
		 * @param Q::ON|Q::OFF
		 * @return void
		 */
		public function set_cache($state = Q::OFF){
			self::$_enable_cache = $state;
			return $this;
		}
		
		/**
		 * Sets new engine directory
		 * 
		 * @param string $path
		 * @return void
		 */
		public static function set_engine_dir($path = ''){
			if(empty($path)) return;
			self::$_engine_dir = $path;
		}
		
		/**
		 * Interface to quote strings
		 * 
		 * @param string $str
		 * @return string
		 */
		public static function str($str = NULL){ // syntactic sugar
			return "'" . $str . "'";
		}
		
		/**
		 * Interface to backtick strings
		 * 
		 * @param string $str
		 * @return string
		 */
		public static function backtick($str){ // syntactic sugar
			return '`' . $str . '`';
		}
		
		/**
		 * Set query engine
		 * 
		 * @param string $engine The engine name
		 * @return void
		 */
		public static function set_query_engine($engine = NULL){
			$engine = ucwords(strtolower($engine));
			self::$_default_engine = array($engine, $engine . 'QEngine');
		}
		
		/**
		 * Sets the cache engine
		 * 
		 * @param QCache $engine
		 * @return void
		 */
		public static function set_cache_engine($engine = NULL){
			self::$_cache = $engine;
		}
		
		/**
		 * Concatenation helper, calls the "concat" function from the last-loaded engine
		 * 
		 * @param string,...
		 * @return string
		 */
		public static function concat(){
			$args = func_get_args();
			if(end($args) instanceof FallbackQEngine){
				$engine = array_pop($args);
			} else {
				$engine = self::$_engines[self::$_default_engine[1]];
			}
			return call_user_func_array(array($engine, 'concat'), $args);
		}
		
		/**
		 * Escape helper, calls the "escape" function from the last-loaded engine
		 * 
		 * @param string
		 * @return string
		 */
		public static function escape(){
			$args = func_get_args();
			return call_user_func_array(array(&self::$_engines[self::$_default_engine[1]], 'escape'), $args);
		}
		
		/**
		 * Returns a loaded engine
		 * 
		 * @param string $engine Engine name
		 * @return string
		 */
		public static function Engine($engine){
			return self::$_engines[ucwords(strtolower($engine)) . 'QEngine'];
		}
		
		/**
		 * Helper to format a SQL-comaptible function
		 * 
		 * @param string $function_name
		 * @param string,...
		 * @return string
		 */
		public static function func(){
			$args = func_get_args();
			$func_name = array_shift($args);
			return strtoupper($func_name) . '(' . implode(', ', $args) . ')';
		}
		
		/**
		 * Initializes engines
		 * 
		 * @param string $engine Engine name
		 * @param bool $transparent If true, the engine will be loaded but not registered as the last-loaded engine
		 * @return void
		 */
		public static function init($engine = NULL, $transparent = FALSE){
			if(empty($engine)) $engine = self::$_default_engine[0];
			
			if(!isset(self::$_engines['FallbackQEngine'])){
				self::$_engines['FallbackQEngine'] = new FallbackQEngine; // we always want this initialized! After all, this is our fallback, hence the name
			}

			if(func_num_args() > 1){
				$args = func_get_args();
				foreach($args as $engine){
					self::init($engine);
				}
				return;
			}
			
			$engine = ucwords(strtolower($engine));
			if(!$transparent){
				self::$_default_engine = array($engine, $engine . 'QEngine');
				$engine_data = self::$_default_engine;
			} else {
				$engine_data = array($engine, $engine . 'QEngine');
			}

			if(!isset(self::$_engines[$engine_data[1]])){
				if(!class_exists($engine_data[1])){
					$engine_file = preg_replace('/^\./', dirname(__FILE__), str_replace('{engine}', $engine, self::$_engine_dir));
					if(file_exists($engine_file)){
						include_once $engine_file;
					} else {
						return FALSE;
					}
				}

				self::$_engines[$engine_data[1]] = new $engine_data[1]();
			
				if(self::$_enable_cache && !empty(self::$_cache)){
					self::$_cache->load($engine_data);
				}
				
				return TRUE;
			}
		}
		
		/**
		 * Build the query defined by $struct using $engine_name
		 * 
		 * @param array $struct
		 * @param string $engine_name This engine will be loaded and used for this specific query ONLY ONCE!
		 * @param bool $force_cache_reload If a cache engine is defined, this will force reload the cache
		 * @return string Returns the parsed query
		 */
		public static function build($struct = NULL, $engine_name = NULL, $force_cache_reload = FALSE){
			if(empty($struct)) return '';
			if(empty($engine_name)){
				$engine_name = self::$_default_engine[0];
				$engine_raw = strtolower($engine_name);
				$engine = self::$_default_engine[1];
			} else {
				$engine_raw = $engine_name;
				$engine_name = ucwords(strtolower($engine_raw));
				Q::init($engine_name, TRUE); // load transparently, of course!
				$engine = $engine_name . 'QEngine';
			}
		
			if(self::$_enable_cache && !empty(self::$_cache) && $query = self::$_cache->fetch($engine, sha1(json_encode($struct)))){
				return $query;
			}

			$struct = array_change_key_case($struct, CASE_LOWER);
			
			$struct_keys = array_keys($struct);
			
			$act_index = array_search($struct_keys[0], self::$_possible_acts);
			
			if($act_index !== FALSE){
				$acts = explode(' ', strtolower(self::$_possible_acts[$act_index]));
				$act = $acts[0];
			} else {
				self::$_errors[] = 'Undefined action, you provided: "' . $struct_keys[0] . '"';
				return '';
			}
			
			$query = count($acts) > 1 ? call_user_func(array(self::$_engines[$engine], $act), $acts[1], $struct) : call_user_func(array(self::$_engines[$engine], $act), $struct);
			$query = preg_replace('/\s{2,}/', ' ', $query); // convert two or more whitespaces to a single whitespace, let us join the effort to reduce whitespace pollution. :)
			if(self::$_enable_cache && !empty(self::$_cache)){
				self::$_cache->record(array(
					'engine_raw' => $engine_raw,
					'engine' => $engine,
					'q' => $query,
					'struct' => $struct
				));
			}
			
			return $query;
		}
		
		public function A(){ // for lazy people
			return func_get_args();
		}
	}
	
	$default_cache = new QCache;
	Q::set_cache_engine($default_cache);
