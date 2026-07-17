<?php
declare(strict_types=1);

/**
 * KX-Web configuration.
 * YOU MUST EDIT THIS FILE :-) Adjust db settings according to your environment. base_path is as we recommend, but you can change it if you wish.
 */

return [
    'debug' => false,

    'base_path' => '/kx-results',

    'db' => [
        'dsn'      => 'mysql:host=localhost;dbname=<databasename>;charset=utf8mb4',
        'user'     => '<user-name-of-your-database>',
        'password' => '<password-of-your-database>',
    ],

];


