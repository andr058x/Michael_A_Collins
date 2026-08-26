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
    }
    return $pdo;
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
            if ($title === '') {
                out(['error' => 'missing_title'], 400);
            }
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
    out(['error' => 'server_error'], 500);
}
