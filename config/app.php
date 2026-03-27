<?php

declare(strict_types=1);

use Dotenv\Dotenv;

// Bootstrap autoloader
$autoload = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($autoload)) {
    die('Eseguire: composer install');
}
require_once $autoload;

// Carica variabili d\'ambiente
$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
$dotenv->required([
    'DB_HOST', 'DB_NAME', 'DB_USER',
    'STORAGE_PATH', 'READPST_PATH',
]);

// Avvia sessione
session_name($_ENV['SESSION_NAME'] ?? 'mailbox_session');
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Costanti di configurazione
define('APP_NAME',    $_ENV['APP_NAME']    ?? 'Mailbox');
define('APP_URL',     $_ENV['APP_URL']     ?? '');
define('APP_DEBUG',   filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN));
define('STORAGE_PATH', rtrim($_ENV['STORAGE_PATH'], '/'));

// Gestione errori
if (APP_DEBUG) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(0);
    ini_set('display_errors', '0');
}

// Timezone
date_default_timezone_set('Europe/Rome');
