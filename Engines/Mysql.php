<?php
	class MysqlQEngine extends FallbackQEngine {
		public function escape($o){
			if(is_string($o)){
				return mysql_real_escape_string($o);
			} else if(is_int($o)){
				return (int) $o;
			} else if(is_float($o)){
				return (float) $o;
			} else {
				return $o;
			}
		}
		
		public function create_table($struct, &$query){
			if(empty($struct['create table']) || empty($struct['fields'])) return '';
			if(in_arrayi('temporary', $struct)) $query[] = 'TEMPORARY';
			$query[] = 'TABLE';
			if(in_arrayi('if non existent', $struct)) $query[] = 'IF NOT EXISTS';
			
			$query[] = Q::backtick(trim($struct['create table']));
			if(!empty($struct['like'])){
				$query[] = 'LIKE';
				$query[] = $struct['like'];
				return implode(' ', $query);
			}
			$query[] = '(';
			$fields = array();
			foreach($struct['fields'] as $field_name => $field_properties){
				$field = array();
				
				$field[] = Q::backtick($field_name);
				
				$field_types = array(
					// Integers
					'smallint' => 'smallint',
					'integer' => 'int',
					'bigint' => 'bigint',
					'tinyint' => 'tinyint',
					'sql_tinyint' => 'tinyint',
					'integer8' => 'tinyint',
					'sql_smallint' => 'smallint',
					'integer16 integer' => 'smallint',
					'int' => 'int',
					'sql_integer' => 'int',
					'integer32 bigint' => 'int',
					'sql_bigint' => 'bigint',
					'integer64' => 'bigint',
					'int8' => 'tinyint',
					'mediumint' => 'mediumint',
					'number' => 'int',
					
					// Floats
					'float' => 'float',
					'real' => 'real',
					'double' => 'double',
					'sql_real' => 'real',
					'float32' => 'float',
					'double precision' => 'double',
					'sql_double' => 'double',
					'float64 float' => 'real',
					'sql_float efloat' => 'real',
					'smallfloat' => 'float',
					'float' => 'float',
					'float4' => 'float',
					'binary_float' => 'float',
					'binary_double' => 'float',
					'float32' => 'float',
					'double precision' => 'double',
					
					// Decimal
					'decimal'  => 'decimal',
					'numeric' => 'decimal',
					'dec' => 'decimal',
					'sql_decimal' => 'decimal',
					'sql_numeric' => 'decimal',
					'dollar' => 'decimal',
					'money' => 'decimal',
					'smallmoney' => 'decimal',
					'number' => 'decimal',
					
					// String
					'char' => 'char',
					'varchar' => 'varchar',
					'nchar' => 'char',
					'nvarchar' => 'varchar',
					'character' => 'char',
					'echaracter' => 'char',
					'character varying' => 'varchar',
					'national character' => 'char',
					'national character varying' => 'varchar',
					'nlscharacter' => 'char',
					'text' => 'text',
					'nlstext' => 'text',
					'lvarchar' => 'varchar',
					'c' => 'char',
					'long varchar' => 'varchar',
					'long nvarchar' => 'varchar',
					'ntext' => 'text',
					'binary' => 'binary',
					'varbinary' => 'varbinary',
					'tinytext' => 'tinytext',
					'mediumtext' => 'mediumtext',
					'longtext' => 'longtext',
					'varchar2' => 'varchar',
					'nvarchar2' => 'varchar',
					'large varchar' => 'varchar',
					
					// Binary
					'glo' => 'blob',
					'binary large object' => 'blob',
					'blob' => 'blob',
					'bulk' => 'blob',
					'byte' => 'blob',
					'clob' => 'blob',
					'nclob' => 'blob',
					'character large object' => 'blob',
					'national character large object' => 'blob',
					'varbyte' => 'blob',
					'long varbyte' => 'blob',
					'binary' => 'blob',
					'varbinary' => 'blob',
					'image' => 'blob',
					'filestream' => 'blob',
					'tinyblob' => 'tinyblob',
					'mediumblob' => 'mediumblob',
					'longblob' => 'longblob',
					'raw' => 'blob',
					'longraw' => 'blob',
					'bfile' => 'blob',
					'large binary' => 'blob',
					'bytea' => 'blob',
					
					// date time
					'date' => 'date',
					'datetime' => 'datetime',
					'time' => 'timestamp',
					'timestamp' => 'timestamp',
					'edate' => 'date',
					'etime' => 'timestamp',
					'epoch_time' => 'timestamp',
					'microtimestamp' => 'timestamp',
					'interval' => 'date',
					'ansidate' => 'date',
					'ingresdate' => 'datetime',
					'datetimeoffset' => 'datetime',
					'datetime2' => 'datetime',
					'smalldatetime' => 'datetime',
					'year' => 'year',
					
					// boolean
					'boolean' => 'boolean',
					'bit' => 'boolean',
					
					// others
					'enum' => 'enum',
					'set' => 'set',
					'gis' => 'gis'
					
					// Everything else -> text, the most flexible type
				);
			
				if(!empty($field_properties['type']) && isset($field_types[$field_properties['type']])){
					$field['type'] = strtoupper($field_types[$field_properties['type']]);
					if(!empty($field_properties['length']))	$field['type'] .= '(' . $field_properties['length'] . ')';
				} else {
					$field['type'] = 'VARCHAR';
					if(!empty($field_properties['length'])) $field['type'] .= '(' . $field_properties['length'] . ')';
				}
				if(in_arrayi('not null', $field_properties)) $field[] = 'NOT NULL';
				if(!empty($field_properties['default'])){
					$field[] = 'DEFAULT';
					$field[] = $field_properties['default'];
				}
				if(in_arrayi('auto increment', $field_properties)){
					$field[] = 'AUTO_INCREMENT';
				}
				if(in_arrayi('unique', $field_properties)){
					$field[] = 'UNIQUE';
				} else if(in_arrayi('unique key', $field_properties)){
					$field[] = 'UNIQUE KEY';
				}
				
				if(in_arrayi('primary key', $field_properties)){
					$field[] = 'PRIMARY KEY';
				}
				
				$fields[] = implode(' ', $field);
			}
			$query[] = implode(', ', $fields);
			$query[] = ')';
			return implode(' ', $query);
		}
		
		public function concat(){
			$args = func_get_args();
			return 'CONCAT(' . implode(', ', $args) . ')';
		}
	}
