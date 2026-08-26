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

            // Il titolo non è più obbligatorio: spesso è già leggibile sulla
            // copertina stessa, quindi scriverlo di nuovo sarebbe ridondante.
            // Serve però almeno uno tra titolo e copertina, altrimenti la
            // scheda del libro sarebbe completamente vuota.
            if ($title === '' && $coverName === null) {
                out(['error' => 'missing_title_or_cover'], 400);
            }

            $maxOrder = (int) db()->query('SELECT COALESCE(MAX(sort_order), 0) FROM books')->fetchColumn();

            $stmt = db()->prepare(
                'INSERT INTO books (title, year, code, blurb, link, cover, is_sample, sort_order)
                 VALUES (:title, :year, :code, :blurb, :link, :cover, 0, :sort_order)'
            );
            $stmt->execute([
                ':title' => $title,
                ':year' => $year,
                ':code' => $code,
                ':blurb' => $blurb,
                ':link' => $link,
                ':cover' => $coverName,
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

            $stmt = db()->prepare('SELECT cover FROM books WHERE id = :id');
            $stmt->execute([':id' => $id]);
            $row = $stmt->fetch();

            if ($row) {
                deleteCoverFile($row['cover']);
                $del = db()->prepare('DELETE FROM books WHERE id = :id');
                $del->execute([':id' => $id]);
            }

            out(['ok' => true]);
            break;

        default:
            out(['error' => 'unknown_action'], 404);
    }
} catch (Throwable $e) {
    error_log('api.php error: ' . $e->getMessage());
    out(['error' => 'server_error'], 500);
}
