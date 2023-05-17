<?php

/** The path of your config file , change to fit for your project.
 * 	If your local config file does not exist,this config file will be used.
 * **/

if(defined('PMO_CONFIG')){
    $local_config_file = PMO_CONFIG;
}else{
    $local_config_file = __DIR__."/../../../../application/config/pmo.php";
}

if (file_exists($local_config_file)) {
    $array = require($local_config_file);
    return $array;
}

/**
 *  --------------------------------------------------------------
 *  | You can create a local config file with the content below. |
 *  --------------------------------------------------------------
 *  If environment variable 'APPLICATION_ENV' is defined
 *  and your model $config is 'default',we use APPLICATION_ENV as the section name.
 */
return array(

   /* Configuration section name*/
    'default' => array(
        'connection' => array(
            'hostnames' => '127.0.0.1',
            'database'  => 'default',
// 			'username'  => ''
// 			'password'  => ''
        )
    ),
    'development' => array(
        'connection' => array(
            'hostnames' => '127.0.0.1',
            'database'  => 'development_db'
        )
    ),
    'testing' => array(
        'connection' => array(
            'hostnames' => '127.0.0.1',
            'database'  => 'test_db'
        )
    ),
    'production' => array(
        'connection' => array(
            'hostnames' => '127.0.0.1',
            'database'  => 'production_db'
        )
    )
);
