<?php
/**
 * API del catalogo libri. Un unico endpoint, azioni distinte via
 * ?action=... . La lettura del catalogo (action=list) è pubblica;
 * aggiungere/rimuovere libri richiede di aver effettuato il login
 * (action=login) con la password del pannello autore.
 */
 
declare(strict_types=1);
 
require __DIR__ . '/config.php';
 
header('Content-Type: application/json; charset=utf-8');
 
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();
 
function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );
        ensureSchema($pdo);
    }
    return $pdo;
}