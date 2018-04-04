<?php

return array(

    /*
    |--------------------------------------------------------------------------
    | Routes group config
    |--------------------------------------------------------------------------
    |
    | The default group settings for the elFinder routes.
    |
    */
    'default_group' => 'json',
    'notify_miss_key_runtime' => true,

    'route' => [
        'prefix' => 'trans',
        'middleware' => 'auth',
    ],

	'paths' => [
        'app',
        'database',
        'resources',
        'routes',
        'tests'
    ],

	'cache' => [
	    'enable' => true,
        'tag' => 'trans'
    ],

	'database' => [
	    'table' => 'ltm_translations',
        'key_as_default_value' => true,
        'updated_column' => 'updated_at',
        'created_column' => 'created_at'
    ],

	/**
	 * Enable deletion of translations
	 *
	 * @type boolean
	 */
	'delete_enabled' => true,

	/**
	 * Exclude specific groups from Laravel Translation Manager. 
	 * This is useful if, for example, you want to avoid editing the official Laravel language files.
	 *
	 * @type array
	 *
	 * 	array(
	 *		'pagination',
	 *		'reminders',
	 *		'validation',
	 *	)
	 */
	'exclude_groups' => array(),

	/**
	 * Export translations with keys output alphabetically.
	 */
	'sort_keys ' => false,

);
