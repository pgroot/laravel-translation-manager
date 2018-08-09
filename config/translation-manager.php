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
    'placeholder_all_locales' => true,

    'available_locales' => [
        'en-us',
        'zh-cn'
    ],
	'base_locale' => 'en-us',

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
        'updated_column' => 'updatedAt',
        'created_column' => 'createdAt',
        'time_format' => 'Y-m-d H:i:s'
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
