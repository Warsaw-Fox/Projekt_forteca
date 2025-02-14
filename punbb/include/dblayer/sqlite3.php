<?php
/**
 * A database layer class that relies on the SQLite PHP extension.
 *
 * @copyright (C) 2008-2012 PunBB, partially based on code (C) 2008-2009 FluxBB.org
 * @license http://www.gnu.org/licenses/gpl.html GPL version 2 or higher
 * @package PunBB
 */


// Make sure we have built in support for SQLite
if (!class_exists('SQLite3'))
	exit('This PHP environment doesn\'t have SQLite3 support built in. SQLite3 support is required if you want to use a SQLite3 database to run this forum. Consult the PHP documentation for further assistance.');

class DBLayer
{
    public $prefix;
    public $link_id;
    public $query_result;
    public $in_transaction = 0;

    public $saved_queries = array();
    public $num_queries = 0;

    public $error_no = false;
    public $error_msg = 'Unknown';

    public $datatype_transformations = array(
		'/^SERIAL$/'															=>	'INTEGER',
		'/^(TINY|SMALL|MEDIUM|BIG)?INT( )?(\\([0-9]+\\))?( )?(UNSIGNED)?$/i'	=>	'INTEGER',
		'/^(TINY|MEDIUM|LONG)?TEXT$/i'											=>	'TEXT'
	);

	public function __construct($db_host, $db_username, $db_password, $db_name, $db_prefix, $p_connect)
	{
		// Prepend $db_name with the path to the forum root directory
		$db_name = FORUM_ROOT.$db_name;

		$this->prefix = $db_prefix;

		if (!file_exists($db_name))
		{
			@/**/touch($db_name);
			@/**/chmod($db_name, 0666);
			if (!file_exists($db_name))
				error('Unable to create new database \''.$db_name.'\'. Permission denied.', __FILE__, __LINE__);
		}

		if (!is_readable($db_name))
			error('Unable to open database \''.$db_name.'\' for reading. Permission denied.', __FILE__, __LINE__);

		if (!is_writable($db_name))
			error('Unable to open database \''.$db_name.'\' for writing. Permission denied.', __FILE__, __LINE__);

		@/**/$this->link_id = new SQLite3($db_name, SQLITE3_OPEN_READWRITE);

		if (!$this->link_id)
			error('Unable to open database \''.$db_name.'\'.', __FILE__, __LINE__);
		else
			return $this->link_id;
	}

	public function start_transaction()
	{
		++$this->in_transaction;

		return ($this->link_id->exec('BEGIN TRANSACTION')) ? true : false;
	}

	public function end_transaction()
	{
		--$this->in_transaction;

		if ($this->link_id->exec('COMMIT'))
			return true;
		else
		{
			$this->link_id->exec('ROLLBACK');
			return false;
		}
	}

	public function query($sql, $unbuffered = false)
	{
		if (strlen($sql) > FORUM_DATABASE_QUERY_MAXIMUM_LENGTH)
			exit('Insane query. Aborting.');

		if (defined('FORUM_SHOW_QUERIES') || defined('FORUM_DEBUG'))
			$q_start = forum_microtime();

		$this->query_result = $this->link_id->query($sql);

		if ($this->query_result)
		{
			if (defined('FORUM_SHOW_QUERIES') || defined('FORUM_DEBUG'))
				$this->saved_queries[] = array($sql, sprintf('%.5f', forum_microtime() - $q_start));

			++$this->num_queries;

			return $this->query_result;
		}
		else
		{
			if (defined('FORUM_SHOW_QUERIES') || defined('FORUM_DEBUG'))
				$this->saved_queries[] = array($sql, 0);

			$this->error_no = $this->link_id->lastErrorCode();
			$this->error_msg = $this->link_id->lastErrorMsg();

			if ($this->in_transaction)
				$this->link_id->exec('ROLLBACK');

			--$this->in_transaction;

			return false;
		}
	}

	public function query_build($query, $return_query_string = false, $unbuffered = false)
	{
		$sql = '';

		if (isset($query['SELECT']))
		{
			$sql = 'SELECT '.$query['SELECT'].' FROM '.(isset($query['PARAMS']['NO_PREFIX']) ? '' : $this->prefix).$query['FROM'];

			if (isset($query['JOINS']))
			{
				foreach ($query['JOINS'] as $cur_join)
					$sql .= ' '.key($cur_join).' '.(isset($query['PARAMS']['NO_PREFIX']) ? '' : $this->prefix).current($cur_join).' ON '.$cur_join['ON'];
			}

			if (!empty($query['WHERE']))
				$sql .= ' WHERE '.$query['WHERE'];
			if (!empty($query['GROUP BY']))
				$sql .= ' GROUP BY '.$query['GROUP BY'];
			if (!empty($query['HAVING']))
				$sql .= ' HAVING '.$query['HAVING'];
			if (!empty($query['ORDER BY']))
				$sql .= ' ORDER BY '.$query['ORDER BY'];
			if (!empty($query['LIMIT']))
				$sql .= ' LIMIT '.$query['LIMIT'];
		}
		else if (isset($query['INSERT']))
		{
			$sql = 'INSERT INTO '.(isset($query['PARAMS']['NO_PREFIX']) ? '' : $this->prefix).$query['INTO'];

			if (!empty($query['INSERT']))
				$sql .= ' ('.$query['INSERT'].')';

			if (is_array($query['VALUES']))
			{
				$new_query = $query;
				if ($return_query_string)
				{
					$query_set = array();
					foreach ($query['VALUES'] as $cur_values)
					{
						$new_query['VALUES'] = $cur_values;
						$query_set[] = $this->query_build($new_query, true, $unbuffered);
					}

					$sql = implode('; ', $query_set);
				}
				else
				{
					$result_set = null;
					foreach ($query['VALUES'] as $cur_values)
					{
						$new_query['VALUES'] = $cur_values;
						$result_set = $this->query_build($new_query, false, $unbuffered);
					}

					return $result_set;
				}
			}
			else
				$sql .= ' VALUES('.$query['VALUES'].')';
		}
		else if (isset($query['UPDATE']))
		{
			$query['UPDATE'] = (isset($query['PARAMS']['NO_PREFIX']) ? '' : $this->prefix).$query['UPDATE'];

			$sql = 'UPDATE '.$query['UPDATE'].' SET '.$query['SET'];

			if (!empty($query['WHERE']))
				$sql .= ' WHERE '.$query['WHERE'];
		}
		else if (isset($query['DELETE']))
		{
			$sql = 'DELETE FROM '.(isset($query['PARAMS']['NO_PREFIX']) ? '' : $this->prefix).$query['DELETE'];

			if (!empty($query['WHERE']))
				$sql .= ' WHERE '.$query['WHERE'];
		}
		else if (isset($query['REPLACE']))
		{
			$sql = 'REPLACE INTO '.(isset($query['PARAMS']['NO_PREFIX']) ? '' : $this->prefix).$query['INTO'];

			if (!empty($query['REPLACE']))
				$sql .= ' ('.$query['REPLACE'].')';

			$sql .= ' VALUES('.$query['VALUES'].')';
		}

		return ($return_query_string) ? $sql : $this->query($sql, $unbuffered);
	}

	public function result($query_id = 0, $row = 0, $col = 0)
	{
		if ($query_id)
		{
			if ($row != 0)
			{
				$result_rows = array();
				while ($cur_result_row = @/**/$query_id->fetchArray(SQLITE3_NUM))
				{
					$result_rows[] = $cur_result_row;
				}

				$cur_row = array_slice($result_rows, $row);
			}
			else
				$cur_row = @/**/$query_id->fetchArray(SQLITE3_NUM);

			return $cur_row[$col];
		}
		else
			return false;
	}

	public function fetch_assoc($query_id = 0)
	{
		if ($query_id)
		{
			$cur_row = @/**/$query_id->fetchArray(SQLITE3_ASSOC);
			if ($cur_row)
			{
				// Horrible hack to get rid of table names and table aliases from the array keys
				foreach ($cur_row as $key => $value)
				{
					$dot_spot = strpos($key, '.');
					if ($dot_spot !== false)
					{
						unset($cur_row[$key]);
						$key = substr($key, $dot_spot+1);
						$cur_row[$key] = $value;
					}
				}
			}

			return $cur_row;
		}
		else
			return false;
	}

	public function fetch_row($query_id = 0)
	{
		return ($query_id) ? @/**/$query_id->fetchArray(SQLITE3_NUM) : false;
	}

	public function num_rows($query_id = 0)
	{
		return false;
	}

	public function affected_rows()
	{
		return ($this->query_result) ? $this->link_id->changes() : false;
	}

	public function insert_id()
	{
		return ($this->link_id) ? $this->link_id->lastInsertRowID() : false;
	}

	public function get_num_queries()
	{
		return $this->num_queries;
	}

	public function get_saved_queries()
	{
		return $this->saved_queries;
	}

	public function free_result($query_id = false)
	{
		if ($query_id)
		{
			@/**/$query_id->finalize();
		}

		return true;
	}

	public function escape($str)
	{
		return is_array($str) ? '' : $this->link_id->escapeString($str);
	}

	public function error()
	{
		$result['error_sql'] = @/**/current(@/**/end($this->saved_queries));
		$result['error_no'] = $this->error_no;
		$result['error_msg'] = $this->error_msg;

		return $result;
	}

	public function close()
	{
		if ($this->link_id)
		{
			if ($this->in_transaction)
			{
				if (defined('FORUM_SHOW_QUERIES') || defined('FORUM_DEBUG'))
					$this->saved_queries[] = array('COMMIT', 0);

				$this->link_id->exec('COMMIT');
			}

			return @/**/$this->link_id->close();
		}
		else
			return false;
	}

	public function set_names($names)
	{
		return;
	}

	public function get_version()
	{
		$info = SQLite3::version();

		return array(
			'name'		=> 'SQLite3',
			'version'	=> $info['versionString']
		);
	}

	public function table_exists($table_name, $no_prefix = false)
	{
		$result = $this->query('SELECT COUNT(type) FROM sqlite_master WHERE name = \''.($no_prefix ? '' : $this->prefix).$this->escape($table_name).'\' AND type=\'table\'');
		$table_exists = (intval($this->result($result)) > 0);

		// Free results for DROP
		if ($result instanceof Sqlite3Result)
		{
			$this->free_result($result);
		}

		return $table_exists;
	}

	public function field_exists($table_name, $field_name, $no_prefix = false)
	{
		$result = $this->query('SELECT sql FROM sqlite_master WHERE name = \''.($no_prefix ? '' : $this->prefix).$this->escape($table_name).'\' AND type=\'table\'');
		$sql = $this->result($result);

		if (is_null($sql) || $sql === false)
			return false;

		return (preg_match('/[\r\n]'.preg_quote($field_name).' /', $sql) === 1);
	}

	public function index_exists($table_name, $index_name, $no_prefix = false)
	{
		$result = $this->query('SELECT COUNT(type) FROM sqlite_master WHERE tbl_name = \''.($no_prefix ? '' : $this->prefix).$this->escape($table_name).'\' AND name = \''.($no_prefix ? '' : $this->prefix).$this->escape($table_name).'_'.$this->escape($index_name).'\' AND type=\'index\'');
		$index_exists = (intval($this->result($result)) > 0);

		// Free results for DROP
		if ($result instanceof Sqlite3Result)
		{
			$this->free_result($result);
		}

		return $index_exists;
	}

	public function create_table($table_name, $schema, $no_prefix = false)
	{
		if ($this->table_exists($table_name, $no_prefix))
			return;

		$query = 'CREATE TABLE '.($no_prefix ? '' : $this->prefix).$table_name." (\n";

		// Go through every schema element and add it to the query
		foreach ($schema['FIELDS'] as $field_name => $field_data)
		{
			$field_data['datatype'] = preg_replace(array_keys($this->datatype_transformations), array_values($this->datatype_transformations), $field_data['datatype']);

			$query .= $field_name.' '.$field_data['datatype'];

			if (!$field_data['allow_null'])
				$query .= ' NOT NULL';

			if (isset($field_data['default']))
				$query .= ' DEFAULT '.$field_data['default'];

			$query .= ",\n";
		}

		// If we have a primary key, add it
		if (isset($schema['PRIMARY KEY']))
			$query .= 'PRIMARY KEY ('.implode(',', $schema['PRIMARY KEY']).'),'."\n";

		// Add unique keys
		if (isset($schema['UNIQUE KEYS']))
		{
			foreach ($schema['UNIQUE KEYS'] as $key_name => $key_fields)
				$query .= 'UNIQUE ('.implode(',', $key_fields).'),'."\n";
		}

		// We remove the last two characters (a newline and a comma) and add on the ending
		$query = substr($query, 0, strlen($query) - 2)."\n".')';

		$this->query($query) or error(__FILE__, __LINE__);

		// Add indexes
		if (isset($schema['INDEXES']))
		{
			foreach ($schema['INDEXES'] as $index_name => $index_fields)
				$this->add_index($table_name, $index_name, $index_fields, false, $no_prefix);
		}
	}

	public function drop_table($table_name, $no_prefix = false)
	{
		if (!$this->table_exists($table_name, $no_prefix))
			return;

		$this->query('DROP TABLE '.($no_prefix ? '' : $this->prefix).$table_name) or error(__FILE__, __LINE__);
	}

	public function get_table_info($table_name, $no_prefix = false)
	{
		// Grab table info
		$result = $this->query('SELECT sql FROM sqlite_master WHERE tbl_name = \''.($no_prefix ? '' : $this->prefix).$this->escape($table_name).'\' ORDER BY type DESC') or error(__FILE__, __LINE__);

		$table = array();
		$table['indices'] = array();
		$num_rows = 0;

		while ($cur_index = $this->fetch_assoc($result))
		{
			if (!isset($table['sql']))
				$table['sql'] = $cur_index['sql'];
			else
				$table['indices'][] = $cur_index['sql'];

			++$num_rows;
		}

		// Check for empty
		if ($num_rows < 1)
			return;


		// Work out the columns in the table currently
		$table_lines = explode("\n", $table['sql']);
		$table['columns'] = array();
		foreach ($table_lines as $table_line)
		{
			$table_line = forum_trim($table_line);
			if (substr($table_line, 0, 12) == 'CREATE TABLE')
				continue;
			else if (substr($table_line, 0, 11) == 'PRIMARY KEY')
				$table['primary_key'] = $table_line;
			else if (substr($table_line, 0, 6) == 'UNIQUE')
				$table['unique'] = $table_line;
			else if (substr($table_line, 0, strpos($table_line, ' ')) != '')
				$table['columns'][substr($table_line, 0, strpos($table_line, ' '))] = forum_trim(substr($table_line, strpos($table_line, ' ')));
		}

		return $table;
	}

	public function add_field($table_name, $field_name, $field_type, $allow_null, $default_value = null, $after_field = 0, $no_prefix = false)
	{
		if ($this->field_exists($table_name, $field_name, $no_prefix))
			return;

		$table = $this->get_table_info($table_name, $no_prefix);

		// Create temp table
		$now = time();
		$tmptable = str_replace('CREATE TABLE '.($no_prefix ? '' : $this->prefix).$this->escape($table_name).' (', 'CREATE TABLE '.($no_prefix ? '' : $this->prefix).$this->escape($table_name).'_t'.$now.' (', $table['sql']);
		$this->query($tmptable) or error(__FILE__, __LINE__);
		$this->query('INSERT INTO '.($no_prefix ? '' : $this->prefix).$this->escape($table_name).'_t'.$now.' SELECT * FROM '.($no_prefix ? '' : $this->prefix).$this->escape($table_name)) or error(__FILE__, __LINE__);

		// Create new table sql
		$field_type = preg_replace(array_keys($this->datatype_transformations), array_values($this->datatype_transformations), $field_type);
		$query = $field_type;
		if (!$allow_null)
			$query .= ' NOT NULL';
		if ($default_value === null || $default_value === '')
			$default_value = '\'\'';

		$query .= ' DEFAULT '.$default_value;

		$old_columns = array_keys($table['columns']);
		array_insert($table['columns'], $after_field, $query.',', $field_name);

		$new_table = 'CREATE TABLE '.($no_prefix ? '' : $this->prefix).$this->escape($table_name).' (';

		foreach ($table['columns'] as $cur_column => $column_details)
			$new_table .= "\n".$cur_column.' '.$column_details;

		if (isset($table['unique']))
			$new_table .= "\n".$table['unique'].',';

		if (isset($table['primary_key']))
			$new_table .= "\n".$table['primary_key'];

		$new_table = trim($new_table, ',')."\n".');';

		// Drop old table
		$this->drop_table($table_name, $no_prefix);

		// Create new table
		$this->query($new_table) or error(__FILE__, __LINE__);

		// Recreate indexes
		if (!empty($table['indices']))
		{
			foreach ($table['indices'] as $cur_index)
				$this->query($cur_index) or error(__FILE__, __LINE__);
		}

		//Copy content back
		$this->query('INSERT INTO '.($no_prefix ? '' : $this->prefix).$this->escape($table_name).' ('.implode(', ', $old_columns).') SELECT * FROM '.($no_prefix ? '' : $this->prefix).$this->escape($table_name).'_t'.$now) or error(__FILE__, __LINE__);

		// Drop temp table
		$this->drop_table($table_name.'_t'.$now, $no_prefix);
	}

	public function alter_field($table_name, $field_name, $field_type, $allow_null, $default_value = null, $after_field = 0, $no_prefix = false)
	{
		return;
	}

	public function drop_field($table_name, $field_name, $no_prefix = false)
	{
		if (!$this->field_exists($table_name, $field_name, $no_prefix))
			return;

		$table = $this->get_table_info($table_name, $no_prefix);

		// Create temp table
		$now = time();
		$tmptable = str_replace('CREATE TABLE '.($no_prefix ? '' : $this->prefix).$this->escape($table_name).' (', 'CREATE TABLE '.($no_prefix ? '' : $this->prefix).$this->escape($table_name).'_t'.$now.' (', $table['sql']);
		$this->query($tmptable) or error(__FILE__, __LINE__);
		$this->query('INSERT INTO '.($no_prefix ? '' : $this->prefix).$this->escape($table_name).'_t'.$now.' SELECT * FROM '.($no_prefix ? '' : $this->prefix).$this->escape($table_name)) or error(__FILE__, __LINE__);

		// Work out the columns we need to keep and the sql for the new table
		unset($table['columns'][$field_name]);
		$new_columns = array_keys($table['columns']);

		$new_table = 'CREATE TABLE '.($no_prefix ? '' : $this->prefix).$this->escape($table_name).' (';

		foreach ($table['columns'] as $cur_column => $column_details)
			$new_table .= "\n".$cur_column.' '.$column_details;

		if (isset($table['unique']))
			$new_table .= "\n".$table['unique'].',';

		if (isset($table['primary_key']))
			$new_table .= "\n".$table['primary_key'];

		$new_table = trim($new_table, ',')."\n".');';

		// Drop old table
		$this->drop_table($table_name, $no_prefix);

		// Create new table
		$this->query($new_table) or error(__FILE__, __LINE__);

		// Recreate indexes
		if (!empty($table['indices']))
		{
			foreach ($table['indices'] as $cur_index)
				if (!preg_match('#\(.*'.$field_name.'.*\)#', $cur_index))
					$this->query($cur_index) or error(__FILE__, __LINE__);
		}

		//Copy content back
		$this->query('INSERT INTO '.($no_prefix ? '' : $this->prefix).$this->escape($table_name).' SELECT '.implode(', ', $new_columns).' FROM '.($no_prefix ? '' : $this->prefix).$this->escape($table_name).'_t'.$now) or error(__FILE__, __LINE__);

		// Drop temp table
		$this->drop_table($table_name.'_t'.$now, $no_prefix);
	}

	public function add_index($table_name, $index_name, $index_fields, $unique = false, $no_prefix = false)
	{
		if ($this->index_exists($table_name, $index_name, $no_prefix))
			return;

		$this->query('CREATE '.($unique ? 'UNIQUE ' : '').'INDEX '.($no_prefix ? '' : $this->prefix).$table_name.'_'.$index_name.' ON '.($no_prefix ? '' : $this->prefix).$table_name.'('.implode(',', $index_fields).')') or error(__FILE__, __LINE__);
	}

	public function drop_index($table_name, $index_name, $no_prefix = false)
	{
		if (!$this->index_exists($table_name, $index_name, $no_prefix))
			return;

		$this->query('DROP INDEX '.($no_prefix ? '' : $this->prefix).$table_name.'_'.$index_name) or error(__FILE__, __LINE__);
	}
}
