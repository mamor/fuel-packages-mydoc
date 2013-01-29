<?php
/**
 * Mydoc class
 *
 * @author    madroom project http://madroom-project.blogspot.jp/
 * @copyright 2013 madroom project
 * @license   MIT License http://www.opensource.org/licenses/mit-license.php
 */

namespace Fuel\Tasks;

class Mydoc
{

	/**
	 * Class initialization
	 */
	public function __construct()
	{
		// load the migrations config
		\Config::load('migrations', true);

		\Config::load('mydoc', true);
	}

	/**
	 * Show help.
	 *
	 * Usage (from command line):
	 *
	 * php oil refine mydoc
	 */
	public static function run()
	{
		static::help();
	}



	/**
	 * Show help.
	 *
	 * Usage (from command line):
	 *
	 * php oil refine mydoc:help
	 */
	public static function help()
	{
		$output = <<<HELP

Description:
  Generate MySQL Documentation.
  Database settings must be configured correctly for this to work.

Commands:
  php oil refine mydoc:html <table_schema> <output_dir>
  php oil refine mydoc:help

HELP;
		\Cli::write($output);
	}

	/**
	 * Generate MySQL Documentation for HTML.
	 *
	 * Usage (from command line):
	 *
	 * php oil refine mydoc:html <table_schema> <output_dir>
	 */
	public static function html($table_schema = null, $dir = null)
	{
		if (empty($table_schema))
		{
			static::help();
			exit();
		}

		empty($dir) and $dir = APPPATH.'tmp'.DS;
		$dir .= 'mydoc'.DS;

		/**
		 * connect to db
		 */
		$ret = static::connect($table_schema);

		/**
		 * delete and create mydoc dir
		 */
		if (file_exists($dir))
		{
			$ret = \File::delete_dir($dir);
			if ($ret === false)
			{
				\Cli::write("Could not delete directory \"{$dir}\"", 'red');
				exit();
			}
		}

		$ret = mkdir($dir, 0777, true);
		if ($ret === false)
		{
			\Cli::write("Could not create directory \"{$dir}\"", 'red');
			exit();
		}

		\File::copy_dir(__DIR__.DS.'..'.DS.'assets', $dir.'assets');

		/**
		 * generate index.html
		 */
		$migration_table_name = \Config::get('migrations.table', 'migration');
		$migration = array();
		if (\DBUtil::table_exists($migration_table_name))
		{
			$migration =
				\Db::select()->from($migration_table_name)
					->order_by('migration', 'desc')->limit(1)->execute()->as_array();
		}

		$html = \View::forge('mydoc/index', array('migration' => $migration))->render();
		\File::create($dir, 'index.html', $html);

		/**
		 * get tables
		 */
		$tables = array_flip(\DB::list_tables());

		/**
		 * unset ignore tables
		 */
		foreach (\Config::get('mydoc.ignore_tables', array()) as $ignore_table_name)
		{
			if (isset($tables[$ignore_table_name]))
			{
				unset($tables[$ignore_table_name]);
			}
		}

		$ignore_table_regex = \Config::get('mydoc.ignore_table_regex');
		foreach ($tables as $table_name => $tmp)
		{
			if ( ! empty($ignore_table_regex))
			{
				if (preg_match($ignore_table_regex, $table_name))
				{
					unset($tables[$table_name]);
					continue;
				}
			}

			$tables[$table_name] = array(
				'indexes' => array(),
				'foreign_keys' => array(),
				'triggers' => array(),
			);
		}

		/**
		 * check table count
		 */
		if(count($tables) === 0)
		{
			\Cli::write("No tables in \"{$table_schema}\"", 'red');
			exit();
		}

		/**
		 * get foreign keys
		 */
		$sql = 'select distinct
					table_name,
					column_name,
					referenced_table_name,
					referenced_column_name
				from
					information_schema.key_column_usage
				where
					referenced_table_name is not null
				and
					referenced_column_name is not null
				and
					table_schema = :table_schema';

		$foreign_keys = \Db::query($sql)->bind('table_schema', $table_schema)->execute()->as_array();
		foreach ($foreign_keys as $foreign_key)
		{
			if (isset($tables[$foreign_key['table_name']]))
			{
				$tables[$foreign_key['table_name']]['foreign_keys'][$foreign_key['column_name']] = $foreign_key;
			}
		}

		/**
		 * get indexes
		 */
		$sql = 'select distinct
					table_name,
					index_name,
					non_unique,
					column_name,
					comment
				from
					information_schema.statistics
				where
					table_schema = :table_schema';

		$indexes = \Db::query($sql)->bind('table_schema', $table_schema)->execute()->as_array();
		foreach ($indexes as $index)
		{
			if (isset($tables[$index['table_name']]))
			{
				$tables[$index['table_name']]['indexes'][$index['index_name']][$index['column_name']] = $index;
			}
		}

		/**
		 * get triggers
		 */
		$sql = 'select distinct
					trigger_name,
					event_manipulation,
					event_object_table,
					action_statement,
					action_timing,
					definer
				from
					information_schema.triggers
				where
					trigger_schema = :trigger_schema';

		$triggers = \Db::query($sql)->bind('trigger_schema', $table_schema)->execute()->as_array();
		foreach ($triggers as $trigger)
		{
			if (isset($tables[$trigger['event_object_table']]))
			{
				$tables[$trigger['event_object_table']]['triggers'][] = $trigger;
			}
		}

		/**
		 * generate tables.html
		 */
		$html = \View::forge('mydoc/tables', array('tables' => array_keys($tables)))->render();
		\File::create($dir, 'tables.html', $html);

		/**
		 * generate table_*.html
		 */
		foreach ($tables as $table_name => $infos)
		{
			$columns = \DB::list_columns($table_name);

			foreach ($columns as &$column)
			{
				// do we have a data_type defined? If not, use the generic type
				isset($column['data_type']) or $column['data_type'] = $column['type'];

				if ($column['data_type'] == 'enum')
				{
					$column['data_type'] .= "('".implode("', '", $column['options'])."')";
				}

				$column['_length'] = null;
				foreach (array('length', 'character_maximum_length', 'display') as $idx)
				{
					// check if we have such a column, and filter out some default values
					if (isset($column[$idx]) and ! in_array($column[$idx], array('65535', '16777215', '4294967295')))
					{
						$column['_length'] = $column[$idx];
						break;
					}
				}

				$column['_extras'] = array();

				if (strpos(\Str::lower($column['key']), 'pri') !== false)
				{
					$column['_extras'][] = 'PK';
				}

				if (strpos(\Str::lower($column['key']), 'uni') !== false)
				{
					$column['_extras'][] = 'UI';
				}

				if ( ! empty($column['extra']))
				{
					if (strpos($column['extra'], 'auto_increment') !== false)
					{
						$column['_extras'][] = 'AI';
					}
				}

				$column['_foreign_key'] = null;
				if ( ! empty($infos['foreign_keys']))
				{
					$foreign_key = \Arr::get($infos['foreign_keys'], $column['name'], array());
					if ( ! empty($foreign_key))
					{
						$column['_foreign_key'] = $foreign_key;
						$column['_extras'][] = 'FK';
					}
				}

				if ( ! empty($column['_foreign_key']))
				{
					$column['_parent_table_name'] = $column['_foreign_key']['referenced_table_name'];
				}
				else
				{
					$column['_foreign_key'] = array(
						'referenced_table_name' => null,
						'referenced_column_name' => null,
					);

					if (0 < preg_match('/^.+_id$/', $column['name']))
					{
						$parent_table_name = str_replace('_id', '', $column['name']);

						if(isset($tables[$parent_table_name = \Inflector::singularize($parent_table_name)]))
						{
							$column['_foreign_key'] = array(
								'referenced_table_name' => $parent_table_name,
								'referenced_column_name' => 'id',
							);
						}
						else if(isset($tables[$parent_table_name = \Inflector::pluralize($parent_table_name)]))
						{
							$column['_foreign_key'] = array(
								'referenced_table_name' => $parent_table_name,
								'referenced_column_name' => 'id',
							);
						}

					}
				}
			}

			$html = \View::forge('mydoc/table', array(
				'table_name' => $table_name,
				'columns' => $columns,
				'infos' => $infos
			))->render();
			\File::create($dir, 'table_'.$table_name.'.html', $html);

		}

		/**
		 * generate indexes.html
		 */
		$html = \View::forge('mydoc/indexes', array('tables' => $tables))->render();
		\File::create($dir, 'indexes.html', $html);

		/**
		 * generate triggers.html
		 */
		$html = \View::forge('mydoc/triggers', array('tables' => $tables))->render();
		\File::create($dir, 'triggers.html', $html);

		\Cli::write("Generated MySQL Documentation in \"{$dir}\"", 'green');
		exit();

	}

	/******************************************************
	 Private methods
	 *****************************************************/

	/**
	 * Connection to table schema
	 *
	 * @param  string $table_schema table schema
	 * @return bool
	 */
	private static function connect($table_schema)
	{
		\Config::load('db', true);

		$active = \Config::get('db.active');
		if (\Config::get('db.'.$active.'.type') == 'pdo')
		{
			\Cli::write('PDO Driver not supported.', 'red');
			exit();
		}

		$config = \Config::get('db');
		$config[$config['active']]['connection']['database'] = $table_schema;
		\Config::set('db', $config);

		\Database_Connection::$instances = array();
		\Database_Connection::instance($config['active'], $config[$config['active']]);
	}

}
/* End of file tasks/mydoc.php */
