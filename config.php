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

// --- Copie PDF dei libri (per il Reader Team) ---
// Stessa cartella delle copertine, così restano entrambe dentro il Volume
// persistente e sopravvivono ai deploy. UPLOAD_URL non basta da sola
// perché i PDF non devono essere linkabili dal catalogo pubblico: il link
// di download arriva solo via email a chi lo richiede (vedi api.php).
define('PDF_UPLOAD_DIR', UPLOAD_DIR . 'pdfs/');
define('PDF_UPLOAD_URL', UPLOAD_URL . 'pdfs/');

// --- Email ---
// L'indirizzo che riceve una notifica ogni volta che qualcuno si iscrive
// al Reader Team. Cambialo impostando la variabile d'ambiente
// ADMIN_NOTIFY_EMAIL su Railway, se un giorno serve un indirizzo diverso.
define('ADMIN_NOTIFY_EMAIL', getenv('ADMIN_NOTIFY_EMAIL') ?: 'andrea.mirenna@gmail.com');
// Mittente con cui partono le email (deve essere un mittente verificato
// sul tuo account Brevo). Se non imposti EMAIL_FROM_ADDRESS, si usa lo
// stesso indirizzo che riceve le notifiche.
define('EMAIL_FROM_ADDRESS', getenv('EMAIL_FROM_ADDRESS') ?: ADMIN_NOTIFY_EMAIL);
define('EMAIL_FROM_NAME', getenv('EMAIL_FROM_NAME') ?: 'Micheal A. Collins');
// La chiave API di Brevo: la imposti come variabile d'ambiente
// BREVO_API_KEY su Railway (mai nel codice). Finché non è impostata, le
// richieste del Reader Team continuano a salvarsi regolarmente nel
// pannello autore — semplicemente non parte nessuna email.
define('BREVO_API_KEY', getenv('BREVO_API_KEY') ?: '');
// La lista Brevo ("Reader Team") in cui finisce ogni persona che richiede
// un libro: serve per l'automazione che chiede la recensione qualche
// giorno dopo, impostata direttamente dentro Brevo (non nel codice).
define('BREVO_READER_LIST_ID', (int) (getenv('BREVO_READER_LIST_ID') ?: 2));
