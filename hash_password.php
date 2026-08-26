<?php
/**
 * Genera l'hash bcrypt di una password, da incollare in config.php
 * come valore di ADMIN_PASSWORD_HASH.
 *
 * Uso da riga di comando (se hai accesso SSH al tuo hosting):
 *   php hash_password.php "LaTuaNuovaPassword"
 *
 * Se non hai accesso SSH, carica questo file sul server ed eseguilo
 * dal browser una sola volta come:
 *   https://tuosito.com/hash_password.php?password=LaTuaNuovaPassword
 * poi CANCELLA il file dal server: farebbe girare la password in
 * chiaro nella cronologia del browser e nei log del server finché resta lì.
 */

$password = null;
if (php_sapi_name() === 'cli') {
    $password = $argv[1] ?? null;
} else {
    $password = $_GET['password'] ?? null;
}

if (!$password) {
    if (php_sapi_name() === 'cli') {
        fwrite(STDERR, "Uso: php hash_password.php \"LaTuaPassword\"\n");
        exit(1);
    }
    header('Content-Type: text/plain; charset=utf-8');
    echo "Aggiungi ?password=LaTuaPassword all'indirizzo per generare l'hash.\n";
    exit;
}

$hash = password_hash($password, PASSWORD_DEFAULT);

if (php_sapi_name() === 'cli') {
    echo $hash . "\n";
} else {
    header('Content-Type: text/plain; charset=utf-8');
    echo "Incolla questo valore in config.php come ADMIN_PASSWORD_HASH:\n\n" . $hash . "\n\n";
    echo "Poi CANCELLA questo file (hash_password.php) dal server.\n";
}
