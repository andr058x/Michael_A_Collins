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
    // 'paid' (link esterno, es. Amazon) oppure 'free' (PDF scaricabile
    // direttamente dalla scheda del libro, senza passare dal modulo
    // "Join the Reader Team"). Di default i libri già esistenti restano
    // 'paid', così non cambia nulla per loro finché non li modifichi.
    if (!columnExists($pdo, 'books', 'book_type')) {
        $pdo->exec("ALTER TABLE books ADD COLUMN book_type VARCHAR(10) NOT NULL DEFAULT 'paid'");
    }

    // Un download gratuito per indirizzo email (su tutti i libri "Free"
    // messi in promozione), tracciato qui. L'autore può sbloccarne un
    // secondo (o più) da pannello dopo aver ricevuto una recensione: quel
    // permesso extra vive nella tabella dei "grant" qui sotto.
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS free_downloads (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            email VARCHAR(255) NOT NULL,
            book_id INT UNSIGNED DEFAULT NULL,
            book_title VARCHAR(255) DEFAULT \'\',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY email_idx (email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS free_download_grants (
            email VARCHAR(255) NOT NULL,
            extra_allowed INT UNSIGNED NOT NULL DEFAULT 0,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS review_email_queue (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            email VARCHAR(255) NOT NULL,
            name VARCHAR(255) DEFAULT \'\',
            book_title VARCHAR(255) DEFAULT \'\',
            book_link VARCHAR(500) DEFAULT \'\',
            send_at DATETIME NOT NULL,
            sent TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY send_at_idx (sent, send_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    // Tabella delle migrazioni "una tantum" già applicate, per non ripetere
    // due volte un'operazione come "sostituisci il catalogo di prova con
    // quello vero" a ogni deploy.
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS migrations (
            name VARCHAR(100) NOT NULL,
            applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    runRealCatalogMigration($pdo);
    runCoverPaddingFixMigration($pdo);
    runBookTitlesFixMigration($pdo);
    runAllBooksFreeMigration($pdo);
    runFeatureSingleFreeBookMigration($pdo);

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

/**
 * Migrazione una tantum: toglie i 3 libri di prova (quelli con
 * is_sample = 1) e carica il catalogo vero dell'autore, con le copertine
 * reali. Le immagini sorgente vivono in /seed-covers (dentro il repository,
 * quindi arrivano con ogni deploy) e vengono copiate dentro UPLOAD_DIR — la
 * cartella collegata al Volume persistente — con lo stesso schema di nome
 * casuale usato per le copertine caricate a mano dal pannello. Il titolo è
 * lasciato vuoto di proposito: è già ben leggibile sulla copertina stessa.
 */
function runRealCatalogMigration(PDO $pdo): void {
    $migrationName = 'seed_real_catalog_v1';

    $check = $pdo->prepare('SELECT 1 FROM migrations WHERE name = :name');
    $check->execute([':name' => $migrationName]);
    if ($check->fetchColumn()) {
        return;
    }

    $oldRows = $pdo->query('SELECT id, cover, pdf FROM books WHERE is_sample = 1')->fetchAll();
    foreach ($oldRows as $old) {
        deleteCoverFile($old['cover']);
        deletePdfFile($old['pdf']);
    }
    $pdo->exec('DELETE FROM books WHERE is_sample = 1');

    $catalog = [
        ['source' => 'book-01.jpg', 'blurb' => 'A step-by-step, no-code walkthrough for building real income streams with simple AI tools — including the Realistic AI Income Framework, for people with zero technical background.'],
        ['source' => 'book-02.jpg', 'blurb' => 'Turn a plain-language idea into a working, deployed application by talking to AI — planning, building, previewing and shipping, with a Chat to Code & Cowork quick-start cheat sheet included.'],
        ['source' => 'book-03.jpg', 'blurb' => 'A practical framework for managing AI risk, staying compliant, and building accountability into everyday operations — with a 90-day rollout planner to put governance into practice.'],
        ['source' => 'book-04.jpg', 'blurb' => 'A hands-on blueprint for designing and automating intelligent file organization with Python and AI, from collecting and sorting files to monitoring and improving the system over time.'],
        ['source' => 'book-05.jpg', 'blurb' => 'The art and science of writing prompts that consistently get better results from ChatGPT, with a 7-day Craft System implementation planner to put the techniques into practice fast.'],
        ['source' => 'book-06.jpg', 'blurb' => 'A no-code guide to connecting your everyday tools and automating repetitive office workflows, so you get your time back — includes a ready-to-use workflow cheat sheet.'],
        ['source' => 'book-07.jpg', 'blurb' => 'Build intelligent agents and automate real workflows without writing code, using an Agentic AI Starter Kit of pre-built prompt templates for ten common business tasks.'],
        ['source' => 'book-08.jpg', 'blurb' => "A beginner-friendly 30-day system for using generative AI tools like ChatGPT with confidence — no jargon, no guesswork, just a clear starting point today."],
        ['source' => 'book-09.jpg', 'blurb' => 'A 60-day plan for automating the operations of a one-person business with AI, from marketing to customer service, without hiring or learning to code.'],
        ['source' => 'book-10.jpg', 'blurb' => 'A 30-day system for building an AI lead engine that finds, qualifies, and follows up with prospects automatically — no code and no cold-calling required.'],
    ];

    if (!is_dir(UPLOAD_DIR)) {
        @mkdir(UPLOAD_DIR, 0755, true);
    }

    $insert = $pdo->prepare(
        'INSERT INTO books (title, year, code, blurb, link, cover, pdf, book_type, is_sample, sort_order)
         VALUES (:title, :year, :code, :blurb, :link, :cover, NULL, :book_type, 0, :sort_order)'
    );

    $sortOrder = 1;
    foreach ($catalog as $entry) {
        $sourcePath = __DIR__ . '/seed-covers/' . $entry['source'];
        $coverName = null;
        if (is_file($sourcePath)) {
            $coverName = bin2hex(random_bytes(12)) . '.jpg';
            @copy($sourcePath, UPLOAD_DIR . $coverName);
        }

        $insert->execute([
            ':title' => '',
            ':year' => '2026',
            ':code' => '',
            ':blurb' => $entry['blurb'],
            ':link' => '#',
            ':cover' => $coverName,
            ':book_type' => 'paid',
            ':sort_order' => $sortOrder,
        ]);
        $sortOrder++;
    }

    $pdo->prepare('INSERT INTO migrations (name) VALUES (:name)')->execute([':name' => $migrationName]);
}

/**
 * Migrazione una tantum: alcune delle copertine caricate dalla migrazione
 * del catalogo reale (seed_real_catalog_v1) avevano un rapporto
 * larghezza/altezza leggermente diverso da libro a libro; il riquadro del
 * catalogo è invece fisso 2:3, quindi il browser ritagliava le copertine
 * più strette, rischiando di tagliare il titolo o il nome dell'autore
 * vicino ai bordi. I file dentro /seed-covers sono stati corretti per
 * essere tutti esattamente 2:3 (bordi aggiunti ai lati, colore preso dallo
 * sfondo della copertina stessa); qui li ricarichiamo al posto delle copie
 * già in uso, nello stesso ordine con cui sono stati inseriti.
 */
function runCoverPaddingFixMigration(PDO $pdo): void {
    $migrationName = 'fix_cover_padding_v1';

    $check = $pdo->prepare('SELECT 1 FROM migrations WHERE name = :name');
    $check->execute([':name' => $migrationName]);
    if ($check->fetchColumn()) {
        return;
    }

    // Se nel frattempo sono stati aggiunti o rimossi libri a mano dal
    // pannello, l'ordine per sort_order non corrisponde più in modo
    // affidabile alle 10 copertine originali: meglio non toccare nulla
    // piuttosto che rischiare di sovrascrivere la copertina di un libro
    // aggiunto a mano.
    $count = (int) $pdo->query('SELECT COUNT(*) FROM books WHERE is_sample = 0')->fetchColumn();
    if ($count === 10) {
        $rows = $pdo->query(
            'SELECT id, cover FROM books WHERE is_sample = 0 ORDER BY sort_order ASC, id ASC LIMIT 10'
        )->fetchAll();

        if (!is_dir(UPLOAD_DIR)) {
            @mkdir(UPLOAD_DIR, 0755, true);
        }

        $update = $pdo->prepare('UPDATE books SET cover = :cover WHERE id = :id');
        $position = 1;
        foreach ($rows as $row) {
            $sourcePath = __DIR__ . '/seed-covers/' . sprintf('book-%02d.jpg', $position);
            if (is_file($sourcePath)) {
                $newCoverName = bin2hex(random_bytes(12)) . '.jpg';
                if (@copy($sourcePath, UPLOAD_DIR . $newCoverName)) {
                    deleteCoverFile($row['cover']);
                    $update->execute([':cover' => $newCoverName, ':id' => $row['id']]);
                }
            }
            $position++;
        }
    }

    $pdo->prepare('INSERT INTO migrations (name) VALUES (:name)')->execute([':name' => $migrationName]);
}

/**
 * Migrazione una tantum: i 10 libri del catalogo reale sono stati inseriti
 * con il titolo vuoto di proposito (è già leggibile sulla copertina, quindi
 * la scheda del libro non lo ripete). Il menu a tendina "Book you're
 * interested in" nel form Reader Team, però, è testo puro e non può
 * mostrare la copertina: senza un titolo mostrava 10 volte la stessa voce
 * "Untitled book", rendendo impossibile scegliere il libro giusto. Qui
 * scriviamo il titolo vero nel database (nello stesso ordine della
 * migrazione del catalogo) solo per risolvere il menu — la scheda del
 * libro continua a non ripetere il titolo quando c'è una copertina.
 */
function runBookTitlesFixMigration(PDO $pdo): void {
    $migrationName = 'fix_missing_titles_v1';

    $check = $pdo->prepare('SELECT 1 FROM migrations WHERE name = :name');
    $check->execute([':name' => $migrationName]);
    if ($check->fetchColumn()) {
        return;
    }

    $titles = [
        'How to Create Passive Income Using AI',
        'Chat to Code',
        'AI Governance for Small Business',
        'The AI-Powered Python Folder Automation Blueprint',
        'ChatGPT Prompt Engineering',
        'AI Office Integration Made Simple',
        'Agentic AI for the Non Developer',
        'Artificial Intelligence: Stop Feeling Left Behind by AI',
        'The One-Person Business Machine',
        'AI Agents for Leads and Sales',
    ];

    // Stessa cautela della migrazione delle copertine: se sono stati
    // aggiunti o rimossi libri a mano dal pannello, meglio non toccare
    // nulla piuttosto che assegnare per sbaglio un titolo a un libro
    // aggiunto a mano.
    $count = (int) $pdo->query('SELECT COUNT(*) FROM books WHERE is_sample = 0')->fetchColumn();
    if ($count === 10) {
        $rows = $pdo->query(
            'SELECT id, title FROM books WHERE is_sample = 0 ORDER BY sort_order ASC, id ASC LIMIT 10'
        )->fetchAll();

        $update = $pdo->prepare('UPDATE books SET title = :title WHERE id = :id');
        $position = 0;
        foreach ($rows as $row) {
            // Non sovrascrive un titolo che l'autore avesse già scritto a
            // mano dal pannello nel frattempo.
            if (($row['title'] ?? '') === '' && isset($titles[$position])) {
                $update->execute([':title' => $titles[$position], ':id' => $row['id']]);
            }
            $position++;
        }
    }

    $pdo->prepare('INSERT INTO migrations (name) VALUES (:name)')->execute([':name' => $migrationName]);
}

/**
 * Migrazione una tantum: su richiesta dell'autore, tutti i libri del
 * catalogo passano da "a pagamento" a "gratis con download del PDF"
 * (il limite di un download a testa via email, con possibilità di
 * concedere download extra dal pannello, resta gestito da
 * request_free_download / set_download_limit). Non tocca i libri che non
 * hanno ancora un PDF caricato: restano "free" ma il pulsante di download
 * non funzionerà finché l'autore non carica il PDF dal pannello (modifica
 * libro -> campo PDF).
 */
function runAllBooksFreeMigration(PDO $pdo): void {
    $migrationName = 'set_all_books_free_v1';

    $check = $pdo->prepare('SELECT 1 FROM migrations WHERE name = :name');
    $check->execute([':name' => $migrationName]);
    if ($check->fetchColumn()) {
        return;
    }

    $pdo->exec("UPDATE books SET book_type = 'free' WHERE book_type <> 'free'");

    $pdo->prepare('INSERT INTO migrations (name) VALUES (:name)')->execute([':name' => $migrationName]);
}

/**
 * Migrazione una tantum: su richiesta dell'autore, torna a un solo libro
 * gratis in vetrina ("How to Create Passive Income Using AI", messo in
 * evidenza in apertura del sito) e tutti gli altri a pagamento per ora.
 * Gli altri libri restano comunque distribuibili gratis "a mano" tramite
 * il form Reader Team (che non guarda il tipo del libro): è lì che un
 * lettore può chiedere il libro successivo dopo aver lasciato una
 * recensione, come spiegato nella sezione "Join the Reader Team".
 */
function runFeatureSingleFreeBookMigration(PDO $pdo): void {
    $migrationName = 'feature_single_free_book_v1';

    $check = $pdo->prepare('SELECT 1 FROM migrations WHERE name = :name');
    $check->execute([':name' => $migrationName]);
    if ($check->fetchColumn()) {
        return;
    }

    $featuredTitle = 'How to Create Passive Income Using AI';

    $pdo->prepare("UPDATE books SET book_type = 'paid' WHERE title <> :title")
        ->execute([':title' => $featuredTitle]);
    $pdo->prepare("UPDATE books SET book_type = 'free' WHERE title = :title")
        ->execute([':title' => $featuredTitle]);

    $pdo->prepare('INSERT INTO migrations (name) VALUES (:name)')->execute([':name' => $migrationName]);
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
    $type = in_array($r['book_type'] ?? 'paid', ['free', 'paid'], true) ? $r['book_type'] : 'paid';
    $hasPdf = $r['pdf'] !== null && $r['pdf'] !== '';
    return [
        'id' => (int) $r['id'],
        'index' => $r['code'],
        'year' => $r['year'],
        'title' => $r['title'],
        'blurb' => $r['blurb'],
        'link' => $r['link'] ?: '#',
        'cover' => $r['cover'] ? UPLOAD_URL . $r['cover'] : null,
        'sample' => (bool) $r['is_sample'],
        'type' => $type,
        // Il link diretto al PDF non è mai incluso qui, nemmeno per i libri
        // gratuiti: altrimenti chiunque potrebbe scaricarlo aggirando il
        // limite di un download gratuito a persona. Il link vero arriva
        // solo dall'azione "request_free_download", dopo il controllo email.
        'hasPdf' => $hasPdf,
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

    $resized = imagecreatetruecolor($w, $h);
    imagecopyresampled($resized, $src, 0, 0, 0, 0, $w, $h, $srcW, $srcH);
    imagedestroy($src);

    // Le copertine vengono sempre mostrate in un riquadro 2:3 nel catalogo.
    // Se la copertina caricata ha un rapporto diverso (es. più stretta),
    // invece di lasciare che il CSS la ritagli — rischiando di tagliare il
    // titolo o il nome dell'autore vicino ai bordi — la incorniciamo qui su
    // una tela 2:3 esatta, riempiendo lo spazio in eccesso con un colore
    // preso dai bordi dell'immagine stessa (di solito lo sfondo della
    // copertina), così il bordo aggiunto si nota pochissimo.
    $targetRatio = 2 / 3;
    $currentRatio = $w / $h;
    if (abs($currentRatio - $targetRatio) > 0.002) {
        $fill = averageEdgeColor($resized, $w, $h);
        if ($currentRatio < $targetRatio) {
            $canvasW = max($w, (int) round($h * $targetRatio));
            $canvasH = $h;
        } else {
            $canvasW = $w;
            $canvasH = max($h, (int) round($w / $targetRatio));
        }
        $dst = imagecreatetruecolor($canvasW, $canvasH);
        $fillColor = imagecolorallocate($dst, $fill[0], $fill[1], $fill[2]);
        imagefill($dst, 0, 0, $fillColor);
        $offsetX = (int) round(($canvasW - $w) / 2);
        $offsetY = (int) round(($canvasH - $h) / 2);
        imagecopy($dst, $resized, $offsetX, $offsetY, 0, 0, $w, $h);
        imagedestroy($resized);
    } else {
        $dst = $resized;
    }

    if (!is_dir(UPLOAD_DIR)) {
        @mkdir(UPLOAD_DIR, 0755, true);
    }

    $name = bin2hex(random_bytes(12)) . '.jpg';
    $ok = imagejpeg($dst, UPLOAD_DIR . $name, 82);
    imagedestroy($dst);

    return $ok ? $name : false;
}

/**
 * Campiona i pixel lungo il bordo dell'immagine e ne restituisce il colore
 * medio [r, g, b]. Usato per riempire in modo discreto lo spazio aggiunto
 * quando una copertina viene incorniciata su una tela 2:3 (vedi sopra).
 */
function averageEdgeColor($im, int $w, int $h): array {
    $samples = [];
    $stepX = max(1, (int) floor($w / 40));
    $stepY = max(1, (int) floor($h / 40));
    for ($x = 0; $x < $w; $x += $stepX) {
        $samples[] = imagecolorat($im, $x, 0);
        $samples[] = imagecolorat($im, $x, $h - 1);
    }
    for ($y = 0; $y < $h; $y += $stepY) {
        $samples[] = imagecolorat($im, 0, $y);
        $samples[] = imagecolorat($im, $w - 1, $y);
    }
    $r = 0; $g = 0; $b = 0;
    foreach ($samples as $rgb) {
        $r += ($rgb >> 16) & 0xFF;
        $g += ($rgb >> 8) & 0xFF;
        $b += $rgb & 0xFF;
    }
    $count = max(1, count($samples));
    return [(int) round($r / $count), (int) round($g / $count), (int) round($b / $count)];
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

/**
 * Registra (o aggiorna, se già esiste) il lettore come contatto Brevo
 * dentro BREVO_READER_LIST_ID, con il titolo e il link del libro come
 * attributi personalizzati. Serve solo per l'automazione "chiedi la
 * recensione dopo qualche giorno" che si configura dentro Brevo — qui ci
 * limitiamo a mettere il contatto nella lista giusta con le informazioni
 * per personalizzare quell'email.
 */
function addBrevoContact(string $email, string $name, string $bookTitle, string $bookLink): bool {
    if (BREVO_API_KEY === '') {
        return false;
    }

    $payload = json_encode([
        'email' => $email,
        'attributes' => [
            'FIRSTNAME' => $name,
            'BOOK_TITLE' => $bookTitle,
            'BOOK_LINK' => $bookLink,
        ],
        'listIds' => [BREVO_READER_LIST_ID],
        'updateEnabled' => true,
    ]);

    $ch = curl_init('https://api.brevo.com/v3/contacts');
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
        error_log('addBrevoContact failed (status ' . $status . '): ' . ($curlErr ?: $result));
        return false;
    }
    return true;
}

/**
 * Crea e invia subito una campagna email a tutta la lista BREVO_READER_LIST_ID
 * ("Reader Team") per annunciare un nuovo libro appena aggiunto dal pannello
 * autore. Usa l'API "Campaigns" di Brevo, diversa dall'email transazionale
 * di sendEmail(): quella manda un solo messaggio a un solo destinatario,
 * questa manda un unico invio a tutti gli iscritti della lista in un colpo
 * solo. Se BREVO_API_KEY non è impostata, non fa nulla e torna false: il
 * libro viene comunque salvato normalmente, semplicemente non parte
 * l'annuncio via email.
 */
function sendBrevoNewBookCampaign(string $bookTitle, string $bookBlurb, ?string $coverUrl, string $bookLink): bool {
    if (BREVO_API_KEY === '') {
        error_log('sendBrevoNewBookCampaign skipped: BREVO_API_KEY not configured');
        return false;
    }

    $safeTitle = htmlspecialchars($bookTitle);
    $html = '<p>Hi,</p>' .
        '<p>I just published a brand new book: <strong>' . $safeTitle . '</strong>.</p>' .
        ($bookBlurb !== '' ? '<p>' . nl2br(htmlspecialchars($bookBlurb)) . '</p>' : '') .
        ($coverUrl ? '<p><img src="' . htmlspecialchars($coverUrl) . '" alt="' . $safeTitle . '" style="max-width:220px;height:auto;"></p>' : '') .
        ($bookLink && $bookLink !== '#' ? '<p><a href="' . htmlspecialchars($bookLink) . '">Get it here</a></p>' : '') .
        '<p>Thanks for being part of the Reader Team!<br>Michael</p>';

    $createPayload = json_encode([
        'name' => 'New book: ' . $bookTitle . ' (' . date('Y-m-d H:i') . ')',
        'subject' => 'New book just published: ' . $bookTitle,
        'sender' => ['email' => EMAIL_FROM_ADDRESS, 'name' => EMAIL_FROM_NAME],
        'type' => 'classic',
        'htmlContent' => $html,
        'recipients' => ['listIds' => [BREVO_READER_LIST_ID]],
    ]);

    $ch = curl_init('https://api.brevo.com/v3/emailCampaigns');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $createPayload,
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
        error_log('sendBrevoNewBookCampaign create failed (status ' . $status . '): ' . ($curlErr ?: $result));
        return false;
    }

    $created = json_decode((string) $result, true);
    $campaignId = $created['id'] ?? null;
    if (!$campaignId) {
        error_log('sendBrevoNewBookCampaign: no campaign id in response: ' . $result);
        return false;
    }

    $ch2 = curl_init('https://api.brevo.com/v3/emailCampaigns/' . $campaignId . '/sendNow');
    curl_setopt_array($ch2, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'accept: application/json',
            'api-key: ' . BREVO_API_KEY,
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
    ]);
    $result2 = curl_exec($ch2);
    $status2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
    $curlErr2 = curl_error($ch2);
    curl_close($ch2);

    if ($status2 < 200 || $status2 >= 300) {
        error_log('sendBrevoNewBookCampaign sendNow failed (status ' . $status2 . '): ' . ($curlErr2 ?: $result2));
        return false;
    }
    return true;
}

/**
 * Mette in coda l'email "chiedi la recensione", da spedire tra
 * REVIEW_EMAIL_DELAY_DAYS giorni (vedi action=send_due_review_emails più
 * sotto e il Cron Job su Railway che lo richiama ogni giorno). Non fa
 * nulla se manca un link valido del libro — senza link non ci sarebbe
 * dove mandare a lasciare la recensione — o se per quella email+libro
 * c'è già una mail in coda non ancora spedita, per evitare doppioni se la
 * stessa persona richiede lo stesso libro più volte.
 */
function enqueueReviewEmail(PDO $pdo, string $email, string $name, string $bookTitle, string $bookLink): void {
    if ($bookLink === '' || $bookLink === '#') {
        return;
    }

    $check = $pdo->prepare(
        'SELECT id FROM review_email_queue WHERE email = :email AND book_title = :title AND sent = 0 LIMIT 1'
    );
    $check->execute([':email' => $email, ':title' => $bookTitle]);
    if ($check->fetch()) {
        return;
    }

    $ins = $pdo->prepare(
        'INSERT INTO review_email_queue (email, name, book_title, book_link, send_at)
         VALUES (:email, :name, :title, :link, DATE_ADD(NOW(), INTERVAL ' . REVIEW_EMAIL_DELAY_DAYS . ' DAY))'
    );
    $ins->execute([
        ':email' => $email,
        ':name' => $name,
        ':title' => $bookTitle,
        ':link' => buildAmazonReviewLink($bookLink),
    ]);
}

/**
 * A partire dal link Amazon della pagina prodotto (es.
 * https://www.amazon.com/dp/B0HG6RLQGJ, lo stesso che inserisci nel
 * pannello autore), costruisce il link diretto alla pagina "Scrivi una
 * recensione cliente" invece della semplice pagina prodotto: così chi
 * clicca nell'email arriva già pronto a scrivere, senza dover cercare da
 * solo il pulsante sulla pagina del libro. Riconosce sia
 * amazon.com/dp/ASIN che amazon.com/Titolo-Libro/dp/ASIN e le varianti
 * /gp/product/ASIN, su qualunque dominio Amazon (.com, .co.uk, .it, ecc.).
 * Se il link non è in un formato riconosciuto, usa il link originale così
 * com'è: meglio un link che funziona comunque piuttosto che uno rotto.
 */
function buildAmazonReviewLink(string $productLink): string {
    if (preg_match('~^(https?://[^/]+)/(?:[^/]+/)?(?:dp|gp/product)/([A-Z0-9]{10})~i', $productLink, $m)) {
        return $m[1] . '/review/create-review?asin=' . strtoupper($m[2]);
    }
    return $productLink;
}

/**
 * Corpo HTML dell'email "chiedi la recensione": spiega subito che non
 * serve aver comprato il libro su Amazon per lasciare una recensione (le
 * recensioni "non verificate" sono permesse dalle regole di Amazon), poi
 * chiede una recensione onesta — mai una recensione positiva, che le
 * regole di Amazon vietano di richiedere.
 */
function reviewRequestEmailHtml(string $bookTitle, string $bookLink): string {
    $safeTitle = htmlspecialchars($bookTitle);
    $safeLink = htmlspecialchars($bookLink);
    return '<p>Hi,</p>' .
        '<p>A little while ago you grabbed a free copy of <strong>' . $safeTitle . '</strong> — I hope you have had a chance to dig into it.</p>' .
        '<p>If you have, I would love to ask you for something small: an honest review on Amazon. It makes a real difference for an independent author — reviews are how new readers decide whether to trust a book they have never heard of.</p>' .
        '<p>One thing a lot of people do not realize: <strong>you do not need to have bought the book on Amazon to leave a review there.</strong> Amazon lets anyone with an account in good standing post what is called an "unverified" review — it just will not carry the little "Verified Purchase" badge, but it counts exactly the same and is completely within Amazon\'s rules.</p>' .
        '<p>It does not need to be long. Two or three honest sentences about what you liked (or did not) are more than enough — and it does not have to be five stars. I would rather have a real opinion than a polite one.</p>' .
        '<p><a href="' . $safeLink . '"><strong>Leave your review here</strong></a></p>' .
        '<p>One more thing: once you have left it, just reply to this email and let me know — I will personally unlock a second book from my library for you, completely free, as a thank-you.</p>' .
        '<p>Thanks for reading,<br>Michael</p>';
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

/** Email in minuscolo e senza spazi, così "Mario@Gmail.com" e
 *  "mario@gmail.com " contano come la stessa persona ai fini del limite. */
function normalizeEmail(string $email): string {
    return strtolower(trim($email));
}

function freeDownloadsUsed(PDO $pdo, string $email): int {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM free_downloads WHERE email = :email');
    $stmt->execute([':email' => $email]);
    return (int) $stmt->fetchColumn();
}

function freeDownloadExtraAllowed(PDO $pdo, string $email): int {
    $stmt = $pdo->prepare('SELECT extra_allowed FROM free_download_grants WHERE email = :email');
    $stmt->execute([':email' => $email]);
    $value = $stmt->fetchColumn();
    return $value === false ? 0 : (int) $value;
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
            $type = strtolower(trim((string) ($_POST['type'] ?? 'paid')));
            if (!in_array($type, ['free', 'paid'], true)) {
                $type = 'paid';
            }

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
                'INSERT INTO books (title, year, code, blurb, link, cover, pdf, book_type, is_sample, sort_order)
                 VALUES (:title, :year, :code, :blurb, :link, :cover, :pdf, :book_type, 0, :sort_order)'
            );
            $stmt->execute([
                ':title' => $title,
                ':year' => $year,
                ':code' => $code,
                ':blurb' => $blurb,
                ':link' => $link,
                ':cover' => $coverName,
                ':pdf' => $pdfName,
                ':book_type' => $type,
                ':sort_order' => $maxOrder + 1,
            ]);

            $newBookId = (int) db()->lastInsertId();

            // Se l'autore ha lasciato la casella "Email the Reader Team"
            // spuntata (è il valore di default), avvisa tutta la lista che
            // è uscito un nuovo libro. Non blocca mai il salvataggio: se
            // Brevo non è configurata o la chiamata fallisce, il libro resta
            // comunque aggiunto normalmente.
            if (!empty($_POST['notify_list']) && BREVO_API_KEY !== '') {
                $scheme = $isHttps ? 'https' : 'http';
                $siteUrl = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
                $coverUrlForCampaign = $coverName ? $siteUrl . '/' . UPLOAD_URL . $coverName : null;
                $linkForCampaign = ($link && $link !== '#') ? $link : '';
                sendBrevoNewBookCampaign($title ?: 'New book', $blurb, $coverUrlForCampaign, $linkForCampaign);
            }

            out(['ok' => true, 'id' => $newBookId]);
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

        case 'update':
            // Modifica un libro già esistente (es. aggiungere il link Amazon
            // dopo la migrazione iniziale) senza doverlo cancellare e
            // ricreare, così copertina/pdf/blurb restano intatti se non
            // vengono toccati.
            requireAdmin();

            $id = (int) ($_POST['id'] ?? 0);
            if (!$id) {
                out(['error' => 'missing_id'], 400);
            }

            $stmt = db()->prepare('SELECT * FROM books WHERE id = :id');
            $stmt->execute([':id' => $id]);
            $existing = $stmt->fetch();
            if (!$existing) {
                out(['error' => 'not_found'], 404);
            }

            $title = trim((string) ($_POST['title'] ?? $existing['title']));
            $year = trim((string) ($_POST['year'] ?? $existing['year']));
            $code = trim((string) ($_POST['index'] ?? $existing['code']));
            $blurb = trim((string) ($_POST['blurb'] ?? $existing['blurb']));
            $link = trim((string) ($_POST['link'] ?? $existing['link']));
            if ($link === '') {
                $link = '#';
            }
            $type = strtolower(trim((string) ($_POST['type'] ?? $existing['book_type'])));
            if (!in_array($type, ['free', 'paid'], true)) {
                $type = $existing['book_type'];
            }

            if ($title === '' && empty($existing['cover']) && empty($_FILES['cover']['tmp_name'])) {
                out(['error' => 'missing_title_or_cover'], 400);
            }

            $coverName = $existing['cover'];
            if (!empty($_FILES['cover']['tmp_name']) && $_FILES['cover']['error'] === UPLOAD_ERR_OK) {
                $newCover = saveCoverUpload($_FILES['cover']);
                if ($newCover === false) {
                    out(['error' => 'invalid_image'], 400);
                }
                deleteCoverFile($existing['cover']);
                $coverName = $newCover;
            }

            $pdfName = $existing['pdf'];
            if (!empty($_FILES['pdf']['tmp_name']) && $_FILES['pdf']['error'] === UPLOAD_ERR_OK) {
                $newPdf = savePdfUpload($_FILES['pdf']);
                if ($newPdf === false) {
                    out(['error' => 'invalid_pdf'], 400);
                }
                deletePdfFile($existing['pdf']);
                $pdfName = $newPdf;
            }

            $stmt = db()->prepare(
                'UPDATE books SET title = :title, year = :year, code = :code, blurb = :blurb,
                 link = :link, cover = :cover, pdf = :pdf, book_type = :book_type WHERE id = :id'
            );
            $stmt->execute([
                ':title' => $title,
                ':year' => $year,
                ':code' => $code,
                ':blurb' => $blurb,
                ':link' => $link,
                ':cover' => $coverName,
                ':pdf' => $pdfName,
                ':book_type' => $type,
                ':id' => $id,
            ]);

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
                $bookStmt = db()->prepare('SELECT title, pdf, link FROM books WHERE id = :id');
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
            sendEmail(ADMIN_NOTIFY_EMAIL, 'Michael A. Collins', 'New Reader Team request — ' . $name, $adminHtml);

            // --- Conferma al lettore ---
            if ($requestedBook && $requestedBook['pdf']) {
                $downloadUrl = $siteUrl . '/' . PDF_UPLOAD_URL . $requestedBook['pdf'];
                $readerHtml = '<p>Hi ' . htmlspecialchars($name) . ',</p>' .
                    '<p>Thanks for joining the reader team! Here is your free copy of <strong>' . htmlspecialchars($bookTitle) . '</strong>:</p>' .
                    '<p><a href="' . htmlspecialchars($downloadUrl) . '">Download your copy</a></p>' .
                    '<p>Once you have had a chance to read it, I would really appreciate an honest review.</p>' .
                    '<p>Thanks again,<br>Michael</p>';
            } else {
                $readerHtml = '<p>Hi ' . htmlspecialchars($name) . ',</p>' .
                    '<p>Thanks for joining the reader team! I have received your request for <strong>' . htmlspecialchars($bookTitle) . '</strong> and will send your free copy by email shortly.</p>' .
                    '<p>Thanks again,<br>Michael</p>';
            }
            sendEmail($email, $name, 'Your free copy from Michael A. Collins', $readerHtml);

            // Contatto Brevo per l'automazione "chiedi la recensione dopo
            // qualche giorno" (configurata dentro Brevo, non qui).
            $bookLinkForBrevo = ($requestedBook && $requestedBook['link'] && $requestedBook['link'] !== '#')
                ? $requestedBook['link']
                : '';
            addBrevoContact($email, $name, $bookTitle, $bookLinkForBrevo);
            enqueueReviewEmail(db(), $email, $name, $bookTitle, $bookLinkForBrevo);

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

        case 'request_free_download':
            // Honeypot: stesso trucco usato in submit_request. Se il campo
            // arriva valorizzato è quasi certamente un bot: fingiamo
            // successo senza scrivere nulla né rivelare alcun link.
            $honeypot = trim((string) ($_POST['hp_check'] ?? ''));
            if ($honeypot !== '') {
                out(['ok' => true]);
            }

            $email = normalizeEmail((string) ($_POST['email'] ?? ''));
            $bookId = (int) ($_POST['book_id'] ?? 0);

            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || !$bookId) {
                out(['error' => 'invalid_request'], 400);
            }

            $pdo = db();

            $bookStmt = $pdo->prepare('SELECT id, title, link, pdf, book_type FROM books WHERE id = :id');
            $bookStmt->execute([':id' => $bookId]);
            $book = $bookStmt->fetch();

            if (!$book || $book['book_type'] !== 'free' || empty($book['pdf'])) {
                out(['error' => 'not_available'], 404);
            }

            $used = freeDownloadsUsed($pdo, $email);
            $allowed = 1 + freeDownloadExtraAllowed($pdo, $email);

            if ($used >= $allowed) {
                out(['error' => 'limit_reached'], 403);
            }

            $bookTitle = $book['title'] ?: ('Book #' . $book['id']);

            $ins = $pdo->prepare(
                'INSERT INTO free_downloads (email, book_id, book_title) VALUES (:email, :book_id, :book_title)'
            );
            $ins->execute([
                ':email' => $email,
                ':book_id' => (int) $book['id'],
                ':book_title' => $bookTitle,
            ]);

            // Notifica silenziosa all'autore: se Brevo non è configurato,
            // sendEmail non fa nulla e il download prosegue comunque.
            sendEmail(
                ADMIN_NOTIFY_EMAIL,
                EMAIL_FROM_NAME,
                'Free download used — ' . $bookTitle,
                '<p><strong>' . htmlspecialchars($email) . '</strong> just used a free download for <strong>' . htmlspecialchars($bookTitle) . '</strong>.</p>' .
                '<p>Downloads used so far by this address: ' . ($used + 1) . ' of ' . $allowed . ' allowed.</p>'
            );

            // Stesso contatto/lista Brevo usato dal form "Join the Reader
            // Team": così l'automazione "chiedi la recensione dopo qualche
            // giorno" configurata dentro Brevo parte anche per chi scarica
            // un libro gratis direttamente dal catalogo, non solo per chi
            // passa dal form Reader Team. Il modulo di download chiede solo
            // l'email (non il nome), quindi FIRSTNAME resta vuoto qui.
            $bookLinkForBrevo = ($book['link'] && $book['link'] !== '#') ? $book['link'] : '';
            addBrevoContact($email, '', $bookTitle, $bookLinkForBrevo);
            enqueueReviewEmail($pdo, $email, '', $bookTitle, $bookLinkForBrevo);

            // Il libro non si scarica più direttamente dal sito: il link
            // arriva via email (tramite Brevo), così il lettore deve avere
            // accesso reale alla casella indicata e il flusso somiglia a
            // quello di un vero invio "lead magnet" invece che a un
            // download istantaneo cliccabile da chiunque.
            $scheme = $isHttps ? 'https' : 'http';
            $siteUrl = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
            $downloadUrl = $siteUrl . '/' . PDF_UPLOAD_URL . $book['pdf'];

            $readerHtml = '<p>Hi,</p>' .
                '<p>Thanks for requesting <strong>' . htmlspecialchars($bookTitle) . '</strong> — here is your free copy:</p>' .
                '<p><a href="' . htmlspecialchars($downloadUrl) . '">Download your copy</a></p>' .
                '<p>Once you have had a chance to read it, I would really appreciate an honest review — just reply to this email and let me know, and I will send you the next book for free too.</p>' .
                '<p>Thanks,<br>Michael</p>';
            $emailSent = sendEmail($email, '', 'Your free copy of ' . $bookTitle, $readerHtml);

            if ($emailSent) {
                out(['ok' => true, 'emailSent' => true]);
            }

            // Se Brevo non è ancora configurato (o l'invio è fallito), non
            // lasciamo il lettore a mani vuote: torniamo comunque al vecchio
            // comportamento con il link diretto, così il download funziona
            // sempre anche prima di aver impostato BREVO_API_KEY.
            out(['ok' => true, 'emailSent' => false, 'pdfUrl' => PDF_UPLOAD_URL . $book['pdf']]);
            break;

        case 'send_due_review_emails':
            // Endpoint pensato per essere chiamato solo dal servizio Cron
            // Job su Railway (mai da un browser): niente sessione admin,
            // solo una chiave segreta condivisa. Se CRON_SECRET non è
            // configurata, l'endpoint resta disattivato per sicurezza.
            $providedKey = (string) ($_GET['key'] ?? $_POST['key'] ?? '');
            if (CRON_SECRET === '' || !hash_equals(CRON_SECRET, $providedKey)) {
                out(['error' => 'forbidden'], 403);
            }

            $pdo = db();
            $due = $pdo->query(
                'SELECT id, email, name, book_title, book_link FROM review_email_queue
                 WHERE sent = 0 AND send_at <= NOW()
                 ORDER BY send_at ASC
                 LIMIT 25'
            )->fetchAll();

            $sentCount = 0;
            foreach ($due as $row) {
                $ok = sendEmail(
                    $row['email'],
                    $row['name'] ?: '',
                    'Got a minute for an honest review?',
                    reviewRequestEmailHtml($row['book_title'], $row['book_link'])
                );
                if ($ok) {
                    $sentCount++;
                    $upd = $pdo->prepare('UPDATE review_email_queue SET sent = 1 WHERE id = :id');
                    $upd->execute([':id' => $row['id']]);
                } else {
                    error_log('send_due_review_emails: sendEmail failed for queue row ' . $row['id']);
                }
            }

            out(['ok' => true, 'due' => count($due), 'sent' => $sentCount]);
            break;

        case 'list_free_downloads':
            requireAdmin();

            $pdo = db();
            $rows = $pdo->query(
                'SELECT email,
                        COUNT(*) AS downloads_used,
                        MAX(created_at) AS last_download,
                        GROUP_CONCAT(book_title SEPARATOR \', \') AS books
                 FROM free_downloads
                 GROUP BY email
                 ORDER BY last_download DESC'
            )->fetchAll();

            $grantRows = $pdo->query('SELECT email, extra_allowed FROM free_download_grants')->fetchAll();
            $grants = [];
            foreach ($grantRows as $g) {
                $grants[$g['email']] = (int) $g['extra_allowed'];
            }

            $result = array_map(function (array $r) use ($grants): array {
                $extra = $grants[$r['email']] ?? 0;
                return [
                    'email' => $r['email'],
                    'downloadsUsed' => (int) $r['downloads_used'],
                    'extraAllowed' => $extra,
                    'allowed' => 1 + $extra,
                    'lastDownload' => $r['last_download'],
                    'books' => $r['books'],
                ];
            }, $rows);

            out(['downloads' => $result]);
            break;

        case 'grant_extra_download':
            requireAdmin();

            $email = normalizeEmail((string) ($_POST['email'] ?? ''));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                out(['error' => 'invalid_email'], 400);
            }

            $stmt = db()->prepare(
                'INSERT INTO free_download_grants (email, extra_allowed) VALUES (:email, 1)
                 ON DUPLICATE KEY UPDATE extra_allowed = extra_allowed + 1'
            );
            $stmt->execute([':email' => $email]);

            out(['ok' => true]);
            break;

        case 'set_download_limit':
            // Imposta direttamente il numero totale di libri gratuiti che
            // quell'indirizzo email può scaricare (1 = nessun extra, fino a
            // un massimo di 5), invece di dover cliccare "+1" più volte.
            // Usato ad esempio per dare 3-4-5 libri a chi lascia più
            // recensioni.
            requireAdmin();

            $email = normalizeEmail((string) ($_POST['email'] ?? ''));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                out(['error' => 'invalid_email'], 400);
            }

            $allowed = (int) ($_POST['allowed'] ?? 1);
            $allowed = max(1, min(5, $allowed));
            $extra = $allowed - 1;

            $stmt = db()->prepare(
                'INSERT INTO free_download_grants (email, extra_allowed) VALUES (:email, :extra)
                 ON DUPLICATE KEY UPDATE extra_allowed = :extra2'
            );
            $stmt->execute([':email' => $email, ':extra' => $extra, ':extra2' => $extra]);

            out(['ok' => true, 'allowed' => $allowed]);
            break;

        default:
            out(['error' => 'unknown_action'], 404);
    }
} catch (Throwable $e) {
    error_log('api.php error: ' . $e->getMessage());
    out(['error' => 'server_error'], 500);
}
