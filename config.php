<?php
/**
 * Configurazione del backend.
 *
 * Su Railway non serve compilare nulla qui: quando colleghi il servizio
 * MySQL al servizio del sito, Railway inietta automaticamente le
 * variabili MYSQLHOST / MYSQLPORT / MYSQLDATABASE / MYSQLUSER /
 * MYSQLPASSWORD, e questo file le legge da sole. I valori scritti sotto
 * come fallback servono solo se un giorno pubblichi lo stesso codice su
 * un hosting classico senza quelle variabili d'ambiente.
 */

// --- Connessione al database MySQL ---
define('DB_HOST', getenv('MYSQLHOST') ?: 'localhost');
define('DB_PORT', getenv('MYSQLPORT') ?: '3306');
define('DB_NAME', getenv('MYSQLDATABASE') ?: 'inserisci_nome_database');
define('DB_USER', getenv('MYSQLUSER') ?: 'inserisci_utente_database');
define('DB_PASS', getenv('MYSQLPASSWORD') ?: 'inserisci_password_database');

// --- Password del pannello autore ---
// Su Railway, il modo consigliato per cambiarla è impostare la variabile
// d'ambiente ADMIN_PASSWORD_HASH nel pannello del servizio (Variables),
// così non devi mai toccare il codice. Se quella variabile non è
// impostata, viene usato il valore qui sotto: è già l'hash sicuro della
// password "Figlidipitagora7@@" che hai scelto.
define('ADMIN_PASSWORD_HASH', getenv('ADMIN_PASSWORD_HASH') ?: '$2y$12$i2pcKOjScX10BNVXNldYledLBsT8qQQ/0IlYT1QQt2Y9GnhFqVBk6');

// --- Copertine caricate ---
// IMPORTANTE su Railway: questa cartella vive nel filesystem del
// container, che viene ricreato da zero ad ogni deploy. Senza un Volume
// collegato qui, le copertine caricate spariscono al prossimo deploy.
// Vedi RAILWAY.md per come aggiungere il Volume (due click, nessun
// codice da scrivere).
define('UPLOAD_DIR', __DIR__ . '/uploads/');
define('UPLOAD_URL', 'uploads/');
