<?php

return array(
//	'webfont' => 'Roboto Condensed',
	'ignore_tables' => array(
		Config::get('migrations.table', 'migration'),
	),
	'ignore_table_regex' => '',
);
