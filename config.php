<?php
/**
 * Registration Portal configuration.
 *
 * Edit the values below for your machine. For personal overrides that should
 * not be committed to Git, create config.local.php that returns an array with
 * only the keys you want to change (it is merged on top of this file).
 */
$config = [
    'app_name'   => 'Student Registration Portal',
    'university' => 'Enchong Dee University',

    // MySQL (XAMPP defaults: user "root", empty password)
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'registration_portal',
        'user' => 'root',
        'pass' => '',
    ],

    // Connection to the University Cashiering System (see API.md).
    // While Ramirez's system is not ready, point this to the bundled mock.
    'cashiering' => [
        'base_url' => 'http://localhost/registration-portal/mock-cashiering/api',
        'api_key'  => 'itec116-shared-secret',   // must match the Cashiering System
        'timeout'  => 5,                         // seconds before we treat it as "down"
    ],

    // Where users with the "cashier" role are sent after logging in.
    'cashier_app_url' => 'http://localhost/registration-portal/mock-cashiering/',

    // Shows the "Demo:" buttons on the Payment status screen (for the live demo).
    'demo_mode' => true,
];

$local = __DIR__ . '/config.local.php';
if (is_file($local)) {
    $config = array_replace_recursive($config, require $local);
}
return $config;
