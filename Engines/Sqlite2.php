<?php
	class Sqlite2QEngine extends FallbackQEngine {
		public function escape($o){
			if(is_string($o)){
				return sqlite_escape_string($o);
			} else if(is_int($o)){
				return (int) $o;
			} else if(is_float($o)){
				return (float) $o;
			}
		}
		
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
				
				$field_types = array(
					// Integers
					'smallint' => 'integer',
					'integer' => 'integer',
					'bigint' => 'integer',
					'tinyint' => 'integer',
					'sql_tinyint' => 'integer',
					'integer8' => 'integer',
					'sql_smallint' => 'integer',
					'integer16 integer' => 'integer',
					'int' => 'integer',
					'sql_integer' => 'integer',
					'integer32 bigint' => 'integer',
					'sql_bigint' => 'integer',
					'integer64' => 'integer',
					'int8' => 'integer',
					'mediumint' => 'integer',
					'number' => 'integer',
					
					// Floats
					'float' => 'real',
					'real' => 'real',
					'double' => 'real',
					'sql_real' => 'real',
					'float32' => 'real',
					'double precision' => 'real',
					'sql_double' => 'real',
					'float64 float' => 'real',
					'sql_float efloat' => 'real',
					'smallfloat' => 'real',
					'float' => 'real',
					'float4' => 'real',
					'binary_float' => 'real',
					'binary_double' => 'real',
					'float32' => 'real',
					'double precision' => 'real',
					
					// Decimal
					'decimal'  => 'integer',
					'numeric' => 'integer',
					'dec' => 'integer',
					'sql_decimal' => 'integer',
					'sql_numeric' => 'integer',
					'dollar' => 'integer',
					'money' => 'integer',
					'smallmoney' => 'integer',
					'number' => 'integer',
					
					// String
					'char' => 'text',
					'varchar' => 'text',
					'nchar' => 'text',
					'nvarchar' => 'text',
					'character' => 'text',
					'echaracter' => 'text',
					'character varying' => 'text',
					'national character' => 'text',
					'national character varying' => 'text',
					'nlscharacter' => 'text',
					'character large object' => 'text',
					'text' => 'text',
					'national character large object' => 'text',
					'and nlstext' => 'text',
					'lvarchar' => 'text',
					'c' => 'text',
					'long varchar' => 'text',
					'long nvarchar' => 'text',
					'ntext' => 'text',
					'binary' => 'text',
					'varbinary' => 'text',
					'text tinytext' => 'text',
					'mediumtext' => 'text',
					'longtext' => 'text',
					'varchar2' => 'text',
					'clob' => 'text',
					'nclob' => 'text',
					'nvarchar2' => 'text',
					'large varchar' => 'text',
					
					// Binary
					'glo' => 'blob',
					'binary large object' => 'blob',
					'blob' => 'blob',
					'bulk' => 'blob',
					'byte' => 'blob',
					'clob' => 'blob',
					'varbyte' => 'blob',
					'long varbyte' => 'blob',
					'binary' => 'blob',
					'varbinary' => 'blob',
					'image' => 'blob',
					'filestream' => 'blob',
					'tinyblob' => 'blob',
					'mediumblob' => 'blob',
					'longblob' => 'blob',
					'raw' => 'blob',
					'longraw' => 'blob',
					'bfile' => 'blob',
					'large binary' => 'blob',
					'bytea' => 'blob',
					
					// Everything else -> text, the most flexible type
				);
				
				if(!empty($field_properties['type']) && isset($field_types[$field_properties['type']])){
					$field['type'] = strtoupper($field_types[$field_properties['type']]);
					if(!empty($field_properties['length']))	$field['type'] .= '(' . $field_properties['length'] . ')';
				} else {
					$field['type'] = 'TEXT';
					if(!empty($field_properties['length'])) $field['type'] .= '(' . $field_properties['length'] . ')';
				}
				if(in_arrayi('not null', $field_properties)) $field[] = 'NOT NULL';
				if(!empty($field_properties['default'])){
					$field[] = 'DEFAULT';
					$field[] = $field_properties['default'];
				}
				if(in_arrayi('auto increment', $field_properties)){ // AUTOINCREMENT only supported in SQLite3
					$field_properties[] = 'primary key';
					$field['type'] = 'INTEGER';
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
			return implode(' || ', $args);
		}
	}
