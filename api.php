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

function columnExists(PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column'
    );
    $stmt->execute([':table' => $table, ':column' => $column]);
    return (int) $stmt->fetchColumn() > 0;
}

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

/**
 * Crea la tabella dei libri se non esiste ancora, e la popola con i 3
 * libri di esempio se è completamente vuota. Serve a non dover importare
 * schema.sql a mano su un pannello come phpMyAdmin: al primo utilizzo del
 * sito su un database nuovo, questa funzione fa tutto da sola. Le chiamate
 * successive sono innocue: CREATE TABLE IF NOT EXISTS non fa nulla se la
 * tabella esiste già, e il controllo COUNT(*) evita di riseminare i libri
 * di esempio se sono già stati rimossi dal pannello autore.
 */
function ensureSchema(PDO $pdo): void {
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS books (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            title VARCHAR(255) NOT NULL,
            year VARCHAR(10) DEFAULT \'\',
            code VARCHAR(50) DEFAULT \'\',
            blurb TEXT,
            link VARCHAR(500) DEFAULT \'#\',
            cover VARCHAR(255) DEFAULT NULL,
            is_sample TINYINT(1) NOT NULL DEFAULT 0,
            sort_order INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    // Richieste del "Reader Team": arrivano dal modulo pubblico in home page
    // e restano qui finché l'autore non le processa (e le rimuove) dal
    // pannello autore. Non serve più aprire un'app di posta: il modulo
    // scrive direttamente in questa tabella.
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS reader_requests (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(255) NOT NULL,
            email VARCHAR(255) NOT NULL,
            book VARCHAR(255) DEFAULT \'\',
            message TEXT,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    // Migrazioni leggere e idempotenti per i database creati prima
    // dell'introduzione delle copie PDF: aggiungono le colonne mancanti
    // senza toccare i dati già presenti. Il MySQL di Railway non supporta
    // la sintassi "ADD COLUMN IF NOT EXISTS", quindi controlliamo prima
    // noi se la colonna c'è già.
    if (!columnExists($pdo, 'books', 'pdf')) {
        $pdo->exec('ALTER TABLE books ADD COLUMN pdf VARCHAR(255) DEFAULT NULL');
    }
    if (!columnExists($pdo, 'reader_requests', 'book_id')) {
        $pdo->exec('ALTER TABLE reader_requests ADD COLUMN book_id INT UNSIGNED DEFAULT NULL');
    }

    static $checkedSeed = false;
    if ($checkedSeed) {
        return;
    }
    $checkedSeed = true;

    $hasRows = (bool) $pdo->query('SELECT 1 FROM books LIMIT 1')->fetchColumn();
    if ($hasRows) {
        return;
    }

    $seed = $pdo->prepare(
        'INSERT INTO books (title, year, code, blurb, link, cover, is_sample, sort_order)
         VALUES (:title, :year, :code, :blurb, :link, NULL, 1, :sort_order)'
    );
    $samples = [
        ['title' => 'The Advantage Method', 'year' => '2024', 'code' => '338.04', 'blurb' => 'A practical five-step system for turning a business idea into a lasting competitive edge.', 'link' => '#', 'sort_order' => 1],
        ['title' => 'Thinking Like a System', 'year' => '2023', 'code' => '153.4', 'blurb' => 'How to see connections where others see isolated problems, so you can make better decisions under pressure.', 'link' => '#', 'sort_order' => 2],
        ['title' => "The Founder's Discipline", 'year' => '2022', 'code' => '658.11', 'blurb' => 'The everyday, often invisible habits that separate ventures built to last from the ones that fizzle out.', 'link' => '#', 'sort_order' => 3],
    ];
    foreach ($samples as $book) {
        $seed->execute($book);
    }
}

function out($data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

function isAdmin(): bool {
    return !empty($_SESSION['admin']);
}

function requireAdmin(): void {
    if (!isAdmin()) {
        out(['error' => 'unauthorized'], 401);
    }
}

function rowToBook(array $r): array {
    return [
        'id' => (int) $r['id'],
        'index' => $r['code'],
        'year' => $r['year'],
        'title' => $r['title'],
        'blurb' => $r['blurb'],
        'link' => $r['link'] ?: '#',
        'cover' => $r['cover'] ? UPLOAD_URL . $r['cover'] : null,
        'sample' => (bool) $r['is_sample'],
        // Il file PDF vero e proprio non è mai esposto qui: chi lo vuole
        // passa dal modulo "Join the Reader Team", che manda il link via
        // email. Questo flag serve solo al pannello autore, per mostrare
        // a colpo d'occhio quali libri hanno già una copia pronta.
        'hasPdf' => $r['pdf'] !== null && $r['pdf'] !== '',
    ];
}

/**
 * Ridimensiona e comprime la copertina caricata (max 640x900, JPEG 82%),
 * la salva in UPLOAD_DIR con un nome casuale e restituisce quel nome.
 * Restituisce false se il file non è un'immagine valida.
 */
function saveCoverUpload(array $file) {
    $allowed = ['image/jpeg', 'image/png', 'image/webp'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    if (!in_array($mime, $allowed, true)) {
        return false;
    }
    if ($file['size'] > 8 * 1024 * 1024) {
        return false;
    }

    switch ($mime) {
        case 'image/jpeg':
            $src = @imagecreatefromjpeg($file['tmp_name']);
            break;
        case 'image/png':
            $src = @imagecreatefrompng($file['tmp_name']);
            break;
        case 'image/webp':
            $src = @imagecreatefromwebp($file['tmp_name']);
            break;
        default:
            $src = false;
    }
    if (!$src) {
        return false;
    }

    $srcW = imagesx($src);
    $srcH = imagesy($src);
    $maxW = 640;
    $maxH = 900;
    $ratio = min(1, $maxW / $srcW, $maxH / $srcH);
    $w = max(1, (int) round($srcW * $ratio));
    $h = max(1, (int) round($srcH * $ratio));

    $dst = imagecreatetruecolor($w, $h);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $w, $h, $srcW, $srcH);

    if (!is_dir(UPLOAD_DIR)) {
        @mkdir(UPLOAD_DIR, 0755, true);
    }

    $name = bin2hex(random_bytes(12)) . '.jpg';
    $ok = imagejpeg($dst, UPLOAD_DIR . $name, 82);
    imagedestroy($src);
    imagedestroy($dst);

    return $ok ? $name : false;
}

function deleteCoverFile(?string $name): void {
    if ($name && is_file(UPLOAD_DIR . $name)) {
        @unlink(UPLOAD_DIR . $name);
    }
}

/**
 * Salva il PDF caricato per un libro dentro PDF_UPLOAD_DIR con un nome
 * casuale, e restituisce quel nome. Restituisce false se il file non è
 * davvero un PDF o supera il limite di dimensione.
 */
function savePdfUpload(array $file) {
    $looksLikePdf = false;
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        $looksLikePdf = ($mime === 'application/pdf');
    }
    if (!$looksLikePdf) {
        // Alcuni server segnalano i PDF con un mime-type generico: come
        // controllo di riserva, i PDF veri iniziano sempre con "%PDF".
        $head = @file_get_contents($file['tmp_name'], false, null, 0, 5);
        $looksLikePdf = is_string($head) && strncmp($head, '%PDF', 4) === 0;
    }
    if (!$looksLikePdf) {
        return false;
    }
    if ($file['size'] > 60 * 1024 * 1024) {
        return false;
    }

    if (!is_dir(PDF_UPLOAD_DIR)) {
        @mkdir(PDF_UPLOAD_DIR, 0755, true);
    }

    $name = bin2hex(random_bytes(16)) . '.pdf';
    if (!move_uploaded_file($file['tmp_name'], PDF_UPLOAD_DIR . $name)) {
        return false;
    }
    return $name;
}

function deletePdfFile(?string $name): void {
    if ($name && is_file(PDF_UPLOAD_DIR . $name)) {
        @unlink(PDF_UPLOAD_DIR . $name);
    }
}

/**
 * Manda un'email transazionale tramite l'API di Brevo. Se BREVO_API_KEY
 * non è impostata (perché non ancora configurata su Railway), non fa
 * nulla e restituisce false senza generare errori: la richiesta del
 * lettore resta comunque salvata nel pannello, l'unica cosa che manca è
 * l'email automatica.
 */
function sendEmail(string $toEmail, string $toName, string $subject, string $htmlBody): bool {
    if (BREVO_API_KEY === '') {
        error_log('sendEmail skipped: BREVO_API_KEY not configured');
        return false;
    }

    $payload = json_encode([
        'sender' => ['email' => EMAIL_FROM_ADDRESS, 'name' => EMAIL_FROM_NAME],
        'to' => [['email' => $toEmail, 'name' => $toName]],
        'subject' => $subject,
        'htmlContent' => $htmlBody,
    ]);

    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'accept: application/json',
            'api-key: ' . BREVO_API_KEY,
            'content-type: application/json',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
    ]);
    $result = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($status < 200 || $status >= 300) {
        error_log('sendEmail failed (status ' . $status . '): ' . ($curlErr ?: $result));
        return false;
    }
    return true;
}

function rowToRequest(array $r): array {
    return [
        'id' => (int) $r['id'],
        'name' => $r['name'],
        'email' => $r['email'],
        'book' => $r['book'],
        'message' => $r['message'],
        'createdAt' => $r['created_at'],
    ];
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($action) {

        case 'list':
            $rows = db()->query('SELECT * FROM books ORDER BY sort_order ASC, id ASC')->fetchAll();
            out(['books' => array_map('rowToBook', $rows)]);
            break;

        case 'session':
            out(['admin' => isAdmin()]);
            break;

        case 'login':
            $password = (string) ($_POST['password'] ?? '');
            if ($password !== '' && password_verify($password, ADMIN_PASSWORD_HASH)) {
                session_regenerate_id(true);
                $_SESSION['admin'] = true;
                out(['ok' => true]);
            }
            out(['error' => 'wrong_password'], 401);
            break;

        case 'logout':
            $_SESSION = [];
            session_destroy();
            out(['ok' => true]);
            break;

        case 'add':
            requireAdmin();

            $title = trim((string) ($_POST['title'] ?? ''));
            $year = trim((string) ($_POST['year'] ?? ''));
            $code = trim((string) ($_POST['index'] ?? ''));
            $blurb = trim((string) ($_POST['blurb'] ?? ''));
            $link = trim((string) ($_POST['link'] ?? '')) ?: '#';

            $coverName = null;
            if (!empty($_FILES['cover']['tmp_name']) && $_FILES['cover']['error'] === UPLOAD_ERR_OK) {
                $coverName = saveCoverUpload($_FILES['cover']);
                if ($coverName === false) {
                    out(['error' => 'invalid_image'], 400);
                }
            }

            $pdfName = null;
            if (!empty($_FILES['pdf']['tmp_name']) && $_FILES['pdf']['error'] === UPLOAD_ERR_OK) {
                $pdfName = savePdfUpload($_FILES['pdf']);
                if ($pdfName === false) {
                    out(['error' => 'invalid_pdf'], 400);
                }
            }

            // Il titolo non è più obbligatorio: spesso è già leggibile sulla
            // copertina stessa, quindi scriverlo di nuovo sarebbe ridondante.
            // Serve però almeno uno tra titolo e copertina, altrimenti la
            // scheda del libro sarebbe completamente vuota.
            if ($title === '' && $coverName === null) {
                out(['error' => 'missing_title_or_cover'], 400);
            }

            $maxOrder = (int) db()->query('SELECT COALESCE(MAX(sort_order), 0) FROM books')->fetchColumn();

            $stmt = db()->prepare(
                'INSERT INTO books (title, year, code, blurb, link, cover, pdf, is_sample, sort_order)
                 VALUES (:title, :year, :code, :blurb, :link, :cover, :pdf, 0, :sort_order)'
            );
            $stmt->execute([
                ':title' => $title,
                ':year' => $year,
                ':code' => $code,
                ':blurb' => $blurb,
                ':link' => $link,
                ':cover' => $coverName,
                ':pdf' => $pdfName,
                ':sort_order' => $maxOrder + 1,
            ]);

            out(['ok' => true, 'id' => (int) db()->lastInsertId()]);
            break;

        case 'remove':
            requireAdmin();

            $id = (int) ($_POST['id'] ?? 0);
            if (!$id) {
                out(['error' => 'missing_id'], 400);
            }

            $stmt = db()->prepare('SELECT cover, pdf FROM books WHERE id = :id');
            $stmt->execute([':id' => $id]);
            $row = $stmt->fetch();

            if ($row) {
                deleteCoverFile($row['cover']);
                deletePdfFile($row['pdf']);
                $del = db()->prepare('DELETE FROM books WHERE id = :id');
                $del->execute([':id' => $id]);
            }

            out(['ok' => true]);
            break;

        case 'submit_request':
            // Modulo pubblico "Join the Reader Team": chiunque può inviarlo,
            // niente login richiesto. Il campo "hp_check" è un honeypot
            // invisibile ai visitatori umani (nascosto via CSS) ma spesso
            // compilato dai bot automatici: se arriva valorizzato, fingiamo
            // successo senza scrivere nulla, per non incoraggiare il bot a
            // ritentare con varianti. Il nome è volutamente generico (non
            // "website"/"url"/"company") per non farlo compilare per sbaglio
            // dall'autofill del browser o da un password manager.
            $honeypot = trim((string) ($_POST['hp_check'] ?? ''));
            if ($honeypot !== '') {
                out(['ok' => true]);
            }

            $name = trim((string) ($_POST['name'] ?? ''));
            $email = trim((string) ($_POST['email'] ?? ''));
            $book = trim((string) ($_POST['book'] ?? ''));
            $bookId = (int) ($_POST['book_id'] ?? 0);
            $message = trim((string) ($_POST['message'] ?? ''));

            if ($name === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                out(['error' => 'invalid_request'], 400);
            }

            $stmt = db()->prepare(
                'INSERT INTO reader_requests (name, email, book, book_id, message)
                 VALUES (:name, :email, :book, :book_id, :message)'
            );
            $stmt->execute([
                ':name' => $name,
                ':email' => $email,
                ':book' => $book,
                ':book_id' => $bookId ?: null,
                ':message' => $message,
            ]);

            // Il libro richiesto (titolo + eventuale PDF) serve per
            // l'email di conferma. Se l'id non corrisponde a nessun
            // libro (es. "Not sure / surprise me", o un libro nel
            // frattempo rimosso), il lettore riceve comunque una
            // conferma, solo senza link diretto: l'autore la troverà
            // nel pannello e potrà seguire a mano.
            $requestedBook = null;
            if ($bookId) {
                $bookStmt = db()->prepare('SELECT title, pdf FROM books WHERE id = :id');
                $bookStmt->execute([':id' => $bookId]);
                $requestedBook = $bookStmt->fetch() ?: null;
            }
            $bookTitle = ($requestedBook && $requestedBook['title']) ? $requestedBook['title'] : ($book ?: 'the book you requested');

            $scheme = $isHttps ? 'https' : 'http';
            $siteUrl = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');

            // --- Notifica all'autore ---
            $adminHtml = '<p>New Reader Team request:</p>' .
                '<p><strong>Name:</strong> ' . htmlspecialchars($name) . '<br>' .
                '<strong>Email:</strong> ' . htmlspecialchars($email) . '<br>' .
                '<strong>Book:</strong> ' . htmlspecialchars($book ?: 'Not sure / surprise me') . '</p>' .
                ($message !== '' ? '<p><strong>Message:</strong><br>' . nl2br(htmlspecialchars($message)) . '</p>' : '') .
                '<p><a href="' . htmlspecialchars($siteUrl) . '/#pannello-autore">Open the author panel</a></p>';
            sendEmail(ADMIN_NOTIFY_EMAIL, 'Micheal A. Collins', 'New Reader Team request — ' . $name, $adminHtml);

            // --- Conferma al lettore ---
            if ($requestedBook && $requestedBook['pdf']) {
                $downloadUrl = $siteUrl . '/' . PDF_UPLOAD_URL . $requestedBook['pdf'];
                $readerHtml = '<p>Hi ' . htmlspecialchars($name) . ',</p>' .
                    '<p>Thanks for joining the reader team! Here is your free copy of <strong>' . htmlspecialchars($bookTitle) . '</strong>:</p>' .
                    '<p><a href="' . htmlspecialchars($downloadUrl) . '">Download your copy</a></p>' .
                    '<p>Once you have had a chance to read it, I would really appreciate an honest review.</p>' .
                    '<p>Thanks again,<br>Micheal</p>';
            } else {
                $readerHtml = '<p>Hi ' . htmlspecialchars($name) . ',</p>' .
                    '<p>Thanks for joining the reader team! I have received your request for <strong>' . htmlspecialchars($bookTitle) . '</strong> and will send your free copy by email shortly.</p>' .
                    '<p>Thanks again,<br>Micheal</p>';
            }
            sendEmail($email, $name, 'Your free copy from Micheal A. Collins', $readerHtml);

            out(['ok' => true]);
            break;

        case 'list_requests':
            requireAdmin();

            $rows = db()->query('SELECT * FROM reader_requests ORDER BY created_at DESC, id DESC')->fetchAll();
            out(['requests' => array_map('rowToRequest', $rows)]);
            break;

        case 'remove_request':
            requireAdmin();

            $id = (int) ($_POST['id'] ?? 0);
            if (!$id) {
                out(['error' => 'missing_id'], 400);
            }

            $del = db()->prepare('DELETE FROM reader_requests WHERE id = :id');
            $del->execute([':id' => $id]);

            out(['ok' => true]);
            break;

        default:
            out(['error' => 'unknown_action'], 404);
    }
} catch (Throwable $e) {
    error_log('api.php error: ' . $e->getMessage());
    out(['error' => 'server_error'], 500);
}
