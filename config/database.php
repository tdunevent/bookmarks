<?php

declare(strict_types=1);

$defaults = [
    'host' => '127.0.0.1',
    'port' => '3306',
    'dbname' => 'bookmarks',
    'user' => 'root',
    'pass' => '',
    'charset' => 'utf8mb4',
];

$localFile = __DIR__ . '/database.local.php';
if (is_file($localFile)) {
    $local = require $localFile;
    if (is_array($local)) {
        $defaults = array_merge($defaults, $local);
    }
}

return $defaults;
