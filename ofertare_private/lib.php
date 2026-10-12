<?php
// Bibliotecă comună pentru gatekeeper (ofertare.aiall.ro/index.php) și cockpit (ofertare.aiall.ro/admin.php).
// Stă în ofertare_private, în afara folderului public al subdomeniului. Nu se apelează direct din browser.
//
// Model de date:
//   materials           materialele (oferta + materiale suport), fișierele stau în ofertare_private/fisiere
//   templates           șabloanele de email (subiect + corp cu variabile)
//   template_materials  ce materiale trimite fiecare șablon, în ce ordine
//   deals               ofertele trimise (cardurile din pipeline): destinatar, etapă, valoare, datele firmei din ANAF
//   links               câte un link personal per material, per ofertă
//   otps / sessions     codul de acces și sesiunea, la nivel de ofertă (un cod deschide toate materialele)
//   access_log          jurnalul evenimentelor
//   catalog             catalogul de produse și servicii (tip, denumire, UM, perioada de valabilitate)
//   catalog_items       compoziția pachetelor: ce articole din catalog intră într-un pachet, cu cantitate
//   deal_items          livrabilele unei oferte: articole/pachete din catalog, cu denumire, UM, cantitate și preț
//                       copiate la momentul ofertei (modificările ulterioare din catalog nu schimbă ofertele trimise)

declare(strict_types=1);

const PRIV_DIR  = __DIR__;
const FILES_DIR = __DIR__ . '/fisiere';
const DATA_DIR  = __DIR__ . '/data';

// Valori per salariat mobil la 1.200 RON/lună (oferta e2e OPS Salarizare).
const ECONOMIE_AN_PER_SALARIAT  = 10368; // RON, economie brută client
const ONORARIU_AN1_PER_SALARIAT = 1649;  // RON fără TVA: implementare 1.037 + licență 612
const LICENTA_AN_PER_SALARIAT   = 612;   // RON fără TVA, din anul 2

// ---------------------------------------------------------------------------
// Configurare
// ---------------------------------------------------------------------------
function cfg(string $key)
{
    static $c = null;
    if ($c === null) {
        $file = PRIV_DIR . '/config.php';
        if (!is_file($file)) {
            http_response_code(500);
            exit('Configurare lipsă: copiază ofertare_private/config.sample.php ca ofertare_private/config.php.');
        }
        $c = require $file;
    }
    return $c[$key] ?? null;
}

// ---------------------------------------------------------------------------
// Bază de date (SQLite, creată și populată automat la prima rulare)
// ---------------------------------------------------------------------------
function db(): PDO
{
    static $pdo = null;
    if ($pdo) {
        return $pdo;
    }
    if (!is_dir(DATA_DIR)) {
        mkdir(DATA_DIR, 0700, true);
    }
    $pdo = new PDO('sqlite:' . DATA_DIR . '/ofertare.db', null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS materials (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            title       TEXT NOT NULL,
            kind        TEXT NOT NULL DEFAULT 'suport',
            file        TEXT NOT NULL,
            orig_name   TEXT NOT NULL DEFAULT '',
            active      INTEGER NOT NULL DEFAULT 1,
            updated_at  INTEGER NOT NULL
        );
        CREATE TABLE IF NOT EXISTS templates (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            name        TEXT NOT NULL,
            subject     TEXT NOT NULL,
            body        TEXT NOT NULL,
            updated_at  INTEGER NOT NULL
        );
        CREATE TABLE IF NOT EXISTS template_materials (
            template_id INTEGER NOT NULL REFERENCES templates(id) ON DELETE CASCADE,
            material_id INTEGER NOT NULL REFERENCES materials(id) ON DELETE CASCADE,
            ord         INTEGER NOT NULL DEFAULT 0,
            PRIMARY KEY (template_id, material_id)
        );
        CREATE TABLE IF NOT EXISTS deals (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            company      TEXT NOT NULL DEFAULT '',
            recipient    TEXT NOT NULL DEFAULT '',
            email        TEXT NOT NULL,
            angajati     INTEGER NOT NULL DEFAULT 0,
            template_id  INTEGER REFERENCES templates(id) ON DELETE SET NULL,
            subject      TEXT NOT NULL DEFAULT '',
            require_otp  INTEGER NOT NULL DEFAULT 1,
            stage        TEXT NOT NULL DEFAULT 'trimisa',
            reached      INTEGER NOT NULL DEFAULT 1,
            stage_at     INTEGER,
            nota         TEXT NOT NULL DEFAULT '',
            revoked      INTEGER NOT NULL DEFAULT 0,
            created_at   INTEGER NOT NULL,
            expires_at   INTEGER NOT NULL,
            first_open   INTEGER,
            last_open    INTEGER,
            opens        INTEGER NOT NULL DEFAULT 0,
            cui          TEXT NOT NULL DEFAULT '',
            reg_com      TEXT NOT NULL DEFAULT '',
            adresa       TEXT NOT NULL DEFAULT '',
            judet        TEXT NOT NULL DEFAULT '',
            caen         TEXT NOT NULL DEFAULT '',
            telefon      TEXT NOT NULL DEFAULT '',
            tva          INTEGER NOT NULL DEFAULT 0,
            inactiv      INTEGER NOT NULL DEFAULT 0
        );
        CREATE TABLE IF NOT EXISTS links (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            deal_id     INTEGER NOT NULL REFERENCES deals(id) ON DELETE CASCADE,
            material_id INTEGER NOT NULL REFERENCES materials(id),
            token_hash  TEXT NOT NULL UNIQUE,
            opens       INTEGER NOT NULL DEFAULT 0,
            last_open   INTEGER
        );
        CREATE TABLE IF NOT EXISTS otps (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            deal_id    INTEGER NOT NULL REFERENCES deals(id) ON DELETE CASCADE,
            code_hash  TEXT NOT NULL,
            created_at INTEGER NOT NULL,
            expires_at INTEGER NOT NULL,
            tries      INTEGER NOT NULL DEFAULT 0,
            used       INTEGER NOT NULL DEFAULT 0
        );
        CREATE TABLE IF NOT EXISTS sessions (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            deal_id    INTEGER NOT NULL REFERENCES deals(id) ON DELETE CASCADE,
            sess_hash  TEXT NOT NULL UNIQUE,
            expires_at INTEGER NOT NULL
        );
        CREATE TABLE IF NOT EXISTS access_log (
            id      INTEGER PRIMARY KEY AUTOINCREMENT,
            deal_id INTEGER NOT NULL REFERENCES deals(id) ON DELETE CASCADE,
            at      INTEGER NOT NULL,
            event   TEXT NOT NULL,
            ip      TEXT NOT NULL,
            ua      TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS users (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            username   TEXT NOT NULL UNIQUE,
            name       TEXT NOT NULL DEFAULT '',
            pass_hash  TEXT NOT NULL,
            active     INTEGER NOT NULL DEFAULT 1,
            created_at INTEGER NOT NULL,
            last_login INTEGER
        );
        CREATE TABLE IF NOT EXISTS catalog (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            type       TEXT NOT NULL DEFAULT 'serviciu',
            name       TEXT NOT NULL,
            um         TEXT NOT NULL DEFAULT 'buc',
            valid_from TEXT NOT NULL DEFAULT '',
            valid_to   TEXT NOT NULL DEFAULT '',
            price      REAL NOT NULL DEFAULT 0,
            currency   TEXT NOT NULL DEFAULT 'RON',
            created_at INTEGER NOT NULL,
            updated_at INTEGER NOT NULL
        );
        CREATE TABLE IF NOT EXISTS deal_items (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            deal_id    INTEGER NOT NULL REFERENCES deals(id) ON DELETE CASCADE,
            catalog_id INTEGER REFERENCES catalog(id) ON DELETE SET NULL,
            type       TEXT NOT NULL DEFAULT '',
            name       TEXT NOT NULL,
            um         TEXT NOT NULL DEFAULT 'buc',
            qty        REAL NOT NULL DEFAULT 1,
            price      REAL NOT NULL DEFAULT 0,
            currency   TEXT NOT NULL DEFAULT 'RON',
            details    TEXT NOT NULL DEFAULT '',
            ord        INTEGER NOT NULL DEFAULT 0
        );
        CREATE INDEX IF NOT EXISTS ix_deal_items ON deal_items(deal_id, ord);
        CREATE TABLE IF NOT EXISTS catalog_items (
            package_id INTEGER NOT NULL REFERENCES catalog(id) ON DELETE CASCADE,
            item_id    INTEGER NOT NULL REFERENCES catalog(id) ON DELETE CASCADE,
            qty        REAL NOT NULL DEFAULT 1,
            ord        INTEGER NOT NULL DEFAULT 0,
            PRIMARY KEY (package_id, item_id)
        );
        CREATE INDEX IF NOT EXISTS ix_links_deal ON links(deal_id);
        CREATE INDEX IF NOT EXISTS ix_log_deal ON access_log(deal_id, at);
        CREATE INDEX IF NOT EXISTS ix_otp_deal ON otps(deal_id, created_at);
    ");
    migrate_deals($pdo);
    migrate_catalog($pdo);
    seed_defaults($pdo);
    return $pdo;
}

// Prețul din catalog și valoarea cache a ofertei au apărut după prima versiune: ALTER TABLE pentru bazele existente.
function migrate_catalog(PDO $pdo): void
{
    $cols = [
        'catalog'    => ['price' => 'REAL NOT NULL DEFAULT 0', 'currency' => "TEXT NOT NULL DEFAULT 'RON'"],
        'deal_items' => ['currency' => "TEXT NOT NULL DEFAULT 'RON'"],
        'deals'      => ['value' => 'REAL NOT NULL DEFAULT 0', 'value_eur' => 'REAL NOT NULL DEFAULT 0'],
    ];
    foreach ($cols as $table => $defs) {
        $have = array_column($pdo->query("PRAGMA table_info($table)")->fetchAll(), 'name');
        foreach ($defs as $c => $def) {
            if (!in_array($c, $have, true)) {
                $pdo->exec("ALTER TABLE $table ADD COLUMN $c $def");
            }
        }
    }
}

// Valutele acceptate la prețuri; prima este implicită.
function currencies(): array
{
    return ['RON', 'EUR'];
}

function currency_ok(string $c): string
{
    return in_array($c, currencies(), true) ? $c : 'RON';
}

// Coloanele cu datele firmei (ANAF) au apărut după prima versiune: bazele existente le primesc prin ALTER TABLE.
function migrate_deals(PDO $pdo): void
{
    $have = array_column($pdo->query('PRAGMA table_info(deals)')->fetchAll(), 'name');
    $cols = [
        'cui'     => "TEXT NOT NULL DEFAULT ''",
        'reg_com' => "TEXT NOT NULL DEFAULT ''",
        'adresa'  => "TEXT NOT NULL DEFAULT ''",
        'judet'   => "TEXT NOT NULL DEFAULT ''",
        'caen'    => "TEXT NOT NULL DEFAULT ''",
        'telefon' => "TEXT NOT NULL DEFAULT ''",
        'tva'     => 'INTEGER NOT NULL DEFAULT 0',
        'inactiv' => 'INTEGER NOT NULL DEFAULT 0',
    ];
    foreach ($cols as $c => $def) {
        if (!in_array($c, $have, true)) {
            $pdo->exec("ALTER TABLE deals ADD COLUMN $c $def");
        }
    }
}

// La prima rulare: materialele din ofertare_private/fisiere și șablonul ofertei e2e OPS Salarizare.
function seed_defaults(PDO $pdo): void
{
    if ((int)$pdo->query('SELECT COUNT(*) FROM templates')->fetchColumn() > 0) {
        return;
    }
    $matIds = [];
    if ((int)$pdo->query('SELECT COUNT(*) FROM materials')->fetchColumn() === 0) {
        $ins = $pdo->prepare('INSERT INTO materials (title, kind, file, orig_name, updated_at) VALUES (?, ?, ?, ?, ?)');
        foreach (list_files() as $f) {
            $ext   = strtolower(pathinfo($f, PATHINFO_EXTENSION));
            $title = $ext === 'pdf' ? 'Oferta completă (PDF)' : ($ext === 'html' ? 'Oferta online' : pathinfo($f, PATHINFO_FILENAME));
            $ins->execute([$title, 'oferta', $f, $f, time()]);
            $matIds[] = (int)$pdo->lastInsertId();
        }
    }
    $pdo->prepare('INSERT INTO templates (name, subject, body, updated_at) VALUES (?, ?, ?, ?)')
        ->execute(['Ofertă e2e OPS Salarizare', '1.200 RON/lună pentru fiecare salariat mobil, cu cost fiscal zero și fără risc', default_template_body(), time()]);
    $tid = (int)$pdo->lastInsertId();
    $tm  = $pdo->prepare('INSERT INTO template_materials (template_id, material_id, ord) VALUES (?, ?, ?)');
    foreach ($matIds as $i => $mid) {
        $tm->execute([$tid, $mid, $i]);
    }
}

function default_template_body(): string
{
    return "{{salut}}\n\n"
        . "Vă propun o soluție simplă pentru o problemă pe care o au multe firme cu echipe pe teren:\n"
        . "✓ Fără plăți din dividende de 1.200 RON pe lună către angajați.\n"
        . "✓ Fără plăți recurente către angajați în afara statului de plată, cu risc fiscal.\n"
        . "✓ Cost fiscal zero pentru prestația suplimentară din clauza de mobilitate și pentru celelalte beneficii salariale, în limitele legale.\n"
        . "✓ Voi raportați manopera în timp real, noi facem restul.\n"
        . "✓ Zero efort de documentare justificativă, exact documentația pe care echipele de control ANAF o cer pentru justificarea încadrărilor fiscale reduse și a scutirilor de taxe și impozite. Dosarul probator se construiește automat din pontaj.\n"
        . "✓ Control în timp real al consumului de manoperă mobilă, pe salariat, proiect și șantier.\n"
        . "✓ Fără riscuri fiscale ascunse și fără ambiguități: fiecare plată are temei în Codul muncii și Codul fiscal și este verificată înainte de D112.\n\n"
        . "🎁 **Bonus:** generarea automată și păstrarea digitală a dosarului de personal, totul cu un click. Se descurcă și un copil mic.\n\n"
        . "**Cât economisiți:** aproximativ 10.368 RON pe an pentru fiecare salariat mobil. La 10 salariați, peste 100.000 RON pe an.\n\n"
        . "**Cât costă:** implementarea costă 10% din economia primului an, iar licența 10 EUR + TVA pe lună pentru fiecare salariat beneficiar. Plătiți doar dacă economisiți.\n\n"
        . "{{livrabile}}\n\n"
        . "Oferta completă, cu pașii de implementare, calculul economiei, condițiile de conformare și baza legală, este disponibilă la linkurile personale de mai jos:\n\n"
        . "{{materiale}}\n\n"
        . "Pentru întrebări, răspundeți direct la acest email.\n"
        . "Pentru a accepta oferta, răspundeți la acest email cu numele și CUI-ul companiei și datele persoanei de contact.\n\n"
        . "Cu stimă,\nGheorghe Sandru\nManaging Partner, AiALL S.R.L.\nsmartBIZ Copilot";
}

// ---------------------------------------------------------------------------
// Utilitare
// ---------------------------------------------------------------------------
function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function new_token(): string
{
    return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
}

// Hash cu cheie: un token sau cod furat din baza de date nu poate fi folosit.
function khash(string $value): string
{
    return hash_hmac('sha256', $value, (string)cfg('secret'));
}

function client_ip(): string
{
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64);
}

function client_ua(): string
{
    return substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 300);
}

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

function mask_email(string $email): string
{
    [$user, $domain] = array_pad(explode('@', $email, 2), 2, '');
    $shown = mb_substr($user, 0, min(2, mb_strlen($user)));
    return $shown . str_repeat('•', max(3, mb_strlen($user) - 2)) . '@' . $domain;
}

function fmt_time(?int $ts): string
{
    return $ts ? date('d.m.Y H:i', $ts) : '—';
}

function ron(int $v): string
{
    return number_format($v, 0, ',', '.');
}

function log_event(int $dealId, string $event): void
{
    db()->prepare('INSERT INTO access_log (deal_id, at, event, ip, ua) VALUES (?, ?, ?, ?, ?)')
        ->execute([$dealId, time(), $event, client_ip(), client_ua()]);
}

function base_url(): string
{
    return rtrim((string)cfg('base_url'), '/') . '/';
}

// ---------------------------------------------------------------------------
// Fișiere: doar din ofertare_private/fisiere, doar extensii permise
// ---------------------------------------------------------------------------
function allowed_types(): array
{
    return [
        'pdf'  => 'application/pdf',
        'html' => 'text/html; charset=utf-8',
        'htm'  => 'text/html; charset=utf-8',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'mp4'  => 'video/mp4',
    ];
}

function list_files(): array
{
    $out = [];
    if (!is_dir(FILES_DIR)) {
        return $out;
    }
    foreach (scandir(FILES_DIR) ?: [] as $f) {
        $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
        if ($f[0] !== '.' && is_file(FILES_DIR . '/' . $f) && isset(allowed_types()[$ext])) {
            $out[] = $f;
        }
    }
    return $out;
}

// Rezolvă un nume de fișier în calea reală, fără posibilitate de ieșire din fisiere/.
function safe_file_path(string $name): ?string
{
    $base = realpath(FILES_DIR);
    $path = realpath(FILES_DIR . '/' . basename($name));
    if (!$base || !$path || strpos($path, $base . DIRECTORY_SEPARATOR) !== 0 || !is_file($path)) {
        return null;
    }
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    return isset(allowed_types()[$ext]) ? $path : null;
}

// Salvează un fișier încărcat din cockpit; întoarce numele intern sau un mesaj de eroare.
function store_upload(array $f): array
{
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
        return [null, 'Fișierul nu a fost încărcat.'];
    }
    $maxMb = (int)(cfg('max_upload_mb') ?: 25);
    if ($f['size'] > $maxMb * 1024 * 1024) {
        return [null, "Fișierul depășește $maxMb MB."];
    }
    $ext = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
    if (!isset(allowed_types()[$ext])) {
        return [null, 'Tip de fișier nepermis. Acceptate: ' . implode(', ', array_keys(allowed_types())) . '.'];
    }
    if ($ext === 'pdf' && file_get_contents($f['tmp_name'], false, null, 0, 5) !== '%PDF-') {
        return [null, 'Fișierul nu este un PDF valid.'];
    }
    if (!is_dir(FILES_DIR)) {
        mkdir(FILES_DIR, 0700, true);
    }
    $stem = preg_replace('/[^A-Za-z0-9_-]+/', '_', pathinfo((string)$f['name'], PATHINFO_FILENAME));
    $name = date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '_' . substr($stem ?: 'material', 0, 60) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], FILES_DIR . '/' . $name)) {
        return [null, 'Fișierul nu a putut fi salvat pe server.'];
    }
    chmod(FILES_DIR . '/' . $name, 0600);
    return [$name, null];
}

// ---------------------------------------------------------------------------
// Pipeline: etape
// ---------------------------------------------------------------------------
// rank = poziția în pâlnie; „pierduta” iese din pâlnie, dar păstrează etapa maximă atinsă.
function stages(): array
{
    return [
        'trimisa'   => ['label' => 'Trimisă',      'rank' => 1],
        'deschisa'  => ['label' => 'Deschisă',     'rank' => 2],
        'negociere' => ['label' => 'În negociere', 'rank' => 3],
        'acceptata' => ['label' => 'Acceptată',    'rank' => 4],
        'pierduta'  => ['label' => 'Pierdută',     'rank' => 0],
    ];
}

// ---------------------------------------------------------------------------
// Catalog de produse și servicii
// ---------------------------------------------------------------------------
// Tipurile de articole din catalog: cheia se stochează în coloana `type`, eticheta se afișează în cockpit.
function catalog_types(): array
{
    return [
        'serviciu'         => 'Serviciu',
        'licenta'          => 'Licență',
        'serviciu_digital' => 'Serviciu digital',
        'produs'           => 'Produs',
        'pachet_servicii'  => 'Pachet servicii',
        'pachet_produse'   => 'Pachet produse',
    ];
}

// Data de valabilitate din formular: acceptă YYYY-MM-DD (input type=date) sau DD.MM.YYYY; altfel gol (= nelimitat).
function catalog_date(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }
    if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $raw, $m)) {
        $raw = sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
    }
    $d = DateTime::createFromFormat('!Y-m-d', $raw);
    return $d && $d->format('Y-m-d') === $raw ? $raw : '';
}

// Pachetele se compun din articole existente în catalog (nu din alte pachete).
function catalog_is_package(string $type): bool
{
    return $type === 'pachet_servicii' || $type === 'pachet_produse';
}

// Ce tipuri de articole pot intra într-un pachet: serviciile (clasice sau digitale) în pachetul de servicii,
// produsele în pachetul de produse; licențele pot intra în ambele.
function catalog_component_types(string $packageType): array
{
    switch ($packageType) {
        case 'pachet_servicii':
            return ['serviciu', 'serviciu_digital', 'licenta'];
        case 'pachet_produse':
            return ['produs', 'licenta'];
    }
    return [];
}

// Componentele unui pachet, cu datele articolului și cantitatea.
function catalog_components(int $packageId): array
{
    $q = db()->prepare('SELECT c.*, ci.qty FROM catalog_items ci JOIN catalog c ON c.id = ci.item_id
                        WHERE ci.package_id = ? ORDER BY ci.ord, c.name COLLATE NOCASE');
    $q->execute([$packageId]);
    return $q->fetchAll();
}

// Pachetele în care apare un articol (id => denumire), pentru a bloca ștergerea sau schimbarea tipului.
function catalog_packages_of(int $itemId): array
{
    $q = db()->prepare('SELECT c.id, c.name, c.type FROM catalog_items ci JOIN catalog c ON c.id = ci.package_id
                        WHERE ci.item_id = ? ORDER BY c.name COLLATE NOCASE');
    $q->execute([$itemId]);
    return $q->fetchAll();
}

// Cantitatea unei componente: număr pozitiv, cu maximum două zecimale; acceptă și virgula.
function catalog_qty(string $raw): float
{
    $v = (float)str_replace(',', '.', trim($raw));
    return $v > 0 ? round($v, 2) : 0.0;
}

// Cantitatea pentru un input numeric: cu punct zecimal și fără zerouri de prisos (10, 10.5, 0.25).
function fmt_qty(float $q): string
{
    return rtrim(rtrim(number_format($q, 2, '.', ''), '0'), '.');
}

// Preț din formular: număr nenegativ cu maximum două zecimale; acceptă virgula și separatorul de mii cu punct (1.037,50).
function catalog_money(string $raw): float
{
    $raw = trim($raw);
    if (preg_match('/^\d{1,3}(\.\d{3})+(,\d+)?$/', $raw)) {
        $raw = str_replace('.', '', $raw);
    }
    $v = (float)str_replace(',', '.', $raw);
    return $v > 0 ? round($v, 2) : 0.0;
}

// Sumă în RON pentru afișare: 1.037,50 (fără zecimale când sunt zero).
function fmt_money(float $v): string
{
    $s = number_format($v, 2, ',', '.');
    return substr($s, -3) === ',00' ? substr($s, 0, -3) : $s;
}

// Prețul efectiv al unui articol, în valuta lui: cel propriu sau, pentru un pachet fără preț propriu, suma componentelor
// (cantitate × preț) care au aceeași valută cu pachetul; componentele în altă valută nu pot fi însumate (vezi catalog_price_note).
function catalog_price(array $item): float
{
    if ((float)$item['price'] > 0 || !catalog_is_package((string)$item['type'])) {
        return (float)$item['price'];
    }
    $sum = 0.0;
    foreach (catalog_components((int)$item['id']) as $c) {
        if (($c['currency'] ?? 'RON') === ($item['currency'] ?? 'RON')) {
            $sum += (float)$c['qty'] * (float)$c['price'];
        }
    }
    return round($sum, 2);
}

// Componentele unui pachet aflate în altă valută decât pachetul (neincluse în prețul calculat), ca text.
function catalog_price_note(array $item): string
{
    if ((float)$item['price'] > 0 || !catalog_is_package((string)$item['type'])) {
        return '';
    }
    $other = [];
    foreach (catalog_components((int)$item['id']) as $c) {
        if (($c['currency'] ?? 'RON') !== ($item['currency'] ?? 'RON')) {
            $other[] = $c['name'] . ' (' . $c['currency'] . ')';
        }
    }
    return $other ? 'neincluse, altă valută: ' . implode(', ', $other) : '';
}

// Sumă cu valută: 1.037,50 RON.
function fmt_amount(float $v, string $cur): string
{
    return fmt_money($v) . ' ' . currency_ok($cur);
}

// Compoziția unui pachet ca text scurt („2 × Implementare…, 1 × Licență…”), păstrat pe linia ofertei.
function catalog_details(array $item): string
{
    if (!catalog_is_package((string)$item['type'])) {
        return '';
    }
    $parts = [];
    foreach (catalog_components((int)$item['id']) as $c) {
        $parts[] = fmt_qty((float)$c['qty']) . ' ' . $c['um'] . ' × ' . $c['name'];
    }
    return implode('; ', $parts);
}

// ---------------------------------------------------------------------------
// Livrabilele unei oferte
// ---------------------------------------------------------------------------
function deal_items(int $dealId): array
{
    $q = db()->prepare('SELECT * FROM deal_items WHERE deal_id = ? ORDER BY ord, id');
    $q->execute([$dealId]);
    return $q->fetchAll();
}

// Totalurile livrabilelor pe valută: ['RON' => 12979.5, 'EUR' => 100]; doar valutele cu linii.
function deal_items_totals(array $items): array
{
    $t = [];
    foreach ($items as $it) {
        $cur = currency_ok((string)($it['currency'] ?? 'RON'));
        $t[$cur] = round(($t[$cur] ?? 0) + (float)$it['qty'] * (float)$it['price'], 2);
    }
    return $t;
}

// Totalul în RON (compatibil cu valoarea cache a ofertei).
function deal_items_total(array $items): float
{
    return deal_items_totals($items)['RON'] ?? 0.0;
}

// „12.979,50 RON + 100 EUR” (sau „0 RON” fără linii).
function fmt_totals(array $totals): string
{
    $parts = [];
    foreach (currencies() as $cur) {
        if (isset($totals[$cur])) {
            $parts[] = fmt_amount($totals[$cur], $cur);
        }
    }
    return $parts ? implode(' + ', $parts) : '0 RON';
}

// Adaugă un articol din catalog pe ofertă, copiind denumirea, UM, prețul și compoziția; actualizează valoarea cache a ofertei.
function deal_item_add(int $dealId, array $item, float $qty): void
{
    $ord = (int)db()->query('SELECT COALESCE(MAX(ord), 0) + 1 FROM deal_items WHERE deal_id = ' . $dealId)->fetchColumn();
    db()->prepare('INSERT INTO deal_items (deal_id, catalog_id, type, name, um, qty, price, currency, details, ord) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$dealId, (int)$item['id'], (string)$item['type'], (string)$item['name'], (string)$item['um'], $qty, catalog_price($item),
                   currency_ok((string)($item['currency'] ?? 'RON')), catalog_details($item), $ord]);
    deal_value_refresh($dealId);
}

// Valorile cache ale ofertei: RON în `value`, EUR în `value_eur`.
function deal_value_refresh(int $dealId): array
{
    $t = deal_items_totals(deal_items($dealId));
    db()->prepare('UPDATE deals SET value = ?, value_eur = ? WHERE id = ?')->execute([$t['RON'] ?? 0, $t['EUR'] ?? 0, $dealId]);
    return $t;
}

// Livrabilele ca text, pentru varianta text a emailului și pentru raportare.
function deal_items_text(array $items): string
{
    $lines = [];
    foreach ($items as $it) {
        $lines[] = fmt_qty((float)$it['qty']) . ' ' . $it['um'] . ' × ' . $it['name'] . ' = ' . fmt_amount((float)$it['qty'] * (float)$it['price'], (string)($it['currency'] ?? 'RON'));
    }
    return implode("\n", $lines);
}

// Starea unui articol față de perioada de valabilitate: valid / viitor (nu a intrat în vigoare) / expirat.
function catalog_status(array $item, ?string $today = null): string
{
    $today = $today ?? date('Y-m-d');
    if ($item['valid_from'] !== '' && $item['valid_from'] > $today) {
        return 'viitor';
    }
    if ($item['valid_to'] !== '' && $item['valid_to'] < $today) {
        return 'expirat';
    }
    return 'valid';
}

function set_stage(int $dealId, string $stage): void
{
    $st = stages();
    if (!isset($st[$stage])) {
        return;
    }
    // Rangul se leagă explicit ca întreg: în SQLite, MAX() între text și număr ar alege textul.
    $q = db()->prepare('UPDATE deals SET stage = :stage, stage_at = :at, reached = MAX(reached, :rank) WHERE id = :id');
    $q->bindValue(':stage', $stage, PDO::PARAM_STR);
    $q->bindValue(':at', time(), PDO::PARAM_INT);
    $q->bindValue(':rank', (int)$st[$stage]['rank'], PDO::PARAM_INT);
    $q->bindValue(':id', $dealId, PDO::PARAM_INT);
    $q->execute();
    log_event($dealId, 'etapa:' . $stage);
}

// ---------------------------------------------------------------------------
// Șabloane de email: variabile și randare
// ---------------------------------------------------------------------------
// Format corp: rând gol = paragraf nou; rânduri consecutive = rânduri apropiate în același bloc;
// „✓ ” la început = bifă colorată; **text** = bold; {{materiale}} pe rând separat = butoanele cu linkuri.
function template_vars(array $deal): array
{
    $nume = trim((string)$deal['recipient']);
    $sal  = (int)$deal['angajati'];
    return [
        'salut'       => $nume !== '' ? 'Bună ziua, ' . $nume . ',' : 'Bună ziua,',
        'nume'        => $nume,
        'companie'    => (string)$deal['company'],
        'email'       => (string)$deal['email'],
        'salariati'   => (string)$sal,
        'economie_an' => ron($sal * ECONOMIE_AN_PER_SALARIAT) . ' RON',
        'expira'      => date('d.m.Y', (int)$deal['expires_at']),
        'valoare'     => fmt_totals(array_filter(['RON' => (float)($deal['value'] ?? 0), 'EUR' => (float)($deal['value_eur'] ?? 0)], fn($v) => $v > 0)),
    ];
}

function fill_vars(string $s, array $vars): string
{
    return preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/', fn($m) => in_array($m[1], ['materiale', 'livrabile'], true) ? $m[0] : ($vars[$m[1]] ?? $m[0]), $s);
}

// $materials = [['title' => ..., 'url' => ...], ...]
function render_email(string $subject, string $body, array $deal, array $materials, array $items = []): array
{
    if (!isset($deal['value'])) {
        $t = deal_items_totals($items);
        $deal['value']     = $t['RON'] ?? 0;
        $deal['value_eur'] = $t['EUR'] ?? 0;
    }
    $vars    = template_vars($deal);
    $subject = fill_vars($subject, $vars);
    $body    = str_replace("\r\n", "\n", fill_vars($body, $vars));
    $blocks  = preg_split("/\n\s*\n/", trim($body));

    $p = fn(string $inner, int $mb) => '<p style="margin:0 0 ' . $mb . 'px">' . $inner . '</p>';
    $inline = function (string $line): string {
        $x = h($line);
        $x = preg_replace('/\*\*(.+?)\*\*/u', '<b>$1</b>', $x);
        if (mb_substr($line, 0, 1) === '✓') {
            $x = '<span style="color:#2D8B8E;font-weight:bold">✓</span>' . mb_substr($x, 1);
        }
        return $x;
    };

    $html = '';
    $text = '';
    foreach ($blocks as $bi => $block) {
        $last = $bi === count($blocks) - 1;
        if (trim($block) === '{{livrabile}}') {
            // tabel cu livrabilele ofertate: denumire (+ compoziția pachetului), cantitate, UM, preț unitar, valoare
            if ($items) {
                $td  = 'padding:6px 8px;border-bottom:1px solid #e3e8ee;vertical-align:top';
                $th  = 'padding:6px 8px;border-bottom:2px solid #2D8B8E;text-align:left;font-size:12px;color:#5b6672';
                $tbl = '<table style="border-collapse:collapse;width:100%;font-size:13px;margin:4px 0 6px"><tr><th style="' . $th . '">Livrabil</th><th style="' . $th . ';text-align:right">Cantitate</th><th style="' . $th . '">UM</th><th style="' . $th . ';text-align:right">Preț unitar</th><th style="' . $th . ';text-align:right">Valoare</th></tr>';
                foreach ($items as $it) {
                    $tbl .= '<tr><td style="' . $td . '"><b>' . h($it['name']) . '</b>' . ($it['details'] !== '' ? '<br><span style="font-size:12px;color:#5b6672">' . h($it['details']) . '</span>' : '') . '</td>'
                        . '<td style="' . $td . ';text-align:right">' . h(fmt_qty((float)$it['qty'])) . '</td><td style="' . $td . '">' . h($it['um']) . '</td>'
                        . '<td style="' . $td . ';text-align:right;white-space:nowrap">' . h(fmt_amount((float)$it['price'], (string)($it['currency'] ?? 'RON'))) . '</td>'
                        . '<td style="' . $td . ';text-align:right;white-space:nowrap">' . h(fmt_amount((float)$it['qty'] * (float)$it['price'], (string)($it['currency'] ?? 'RON'))) . '</td></tr>';
                }
                $tbl .= '<tr><td colspan="4" style="padding:8px;text-align:right;font-weight:bold">Total fără TVA</td><td style="padding:8px;text-align:right;font-weight:bold;white-space:nowrap">' . h(fmt_totals(deal_items_totals($items))) . '</td></tr></table>';
                $html .= $p($tbl, 10);
                $text .= deal_items_text($items) . "\nTotal fără TVA: " . fmt_totals(deal_items_totals($items)) . "\n\n";
            }
            continue;
        }
        if (trim($block) === '{{materiale}}') {
            foreach ($materials as $i => $m) {
                $style = $i === 0
                    ? 'display:inline-block;background:#2D8B8E;color:#ffffff;text-decoration:none;font-weight:bold;padding:10px 18px;border-radius:4px'
                    : 'display:inline-block;color:#2D8B8E;text-decoration:none;font-weight:bold;padding:8px 18px;border:1px solid #2D8B8E;border-radius:4px';
                $html .= $p('<a href="' . h($m['url']) . '" style="' . $style . '">' . h($m['title']) . '</a>', 6);
                $text .= $m['title'] . ': ' . $m['url'] . "\n";
            }
            $html .= $p('<span style="color:#5b6672;font-size:12px">Linkurile sunt personale. La deschidere primiți un cod de acces pe această adresă de email.</span>', 10);
            $text .= "Linkurile sunt personale. La deschidere primiți un cod de acces pe această adresă de email.\n\n";
            continue;
        }
        $lines = explode("\n", $block);
        foreach ($lines as $li => $line) {
            $endOfBlock = $li === count($lines) - 1;
            $html .= $p($inline($line), $endOfBlock ? ($last ? 0 : 10) : 4);
            $text .= str_replace('**', '', $line) . "\n";
        }
        $text .= "\n";
    }
    $html = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.5;color:#1b2430">' . $html . '</div>';
    return [$subject, $html, rtrim($text) . "\n"];
}

// Materialele unui șablon, în ordine.
function template_materials(int $templateId): array
{
    $q = db()->prepare('SELECT m.* FROM template_materials tm JOIN materials m ON m.id = tm.material_id
                        WHERE tm.template_id = ? AND m.active = 1 ORDER BY tm.ord, m.id');
    $q->execute([$templateId]);
    return $q->fetchAll();
}

// ---------------------------------------------------------------------------
// ANAF: datele firmei după CUI (API public PlatitorTvaRest v9, fără autentificare, max. 1 cerere/secundă)
// ---------------------------------------------------------------------------
const ANAF_URL = 'https://webservicesp.anaf.ro/api/PlatitorTvaRest/v9/tva';

// „RO 1590082” → „1590082”; gol dacă nu arată a CUI.
function anaf_cui(string $raw): string
{
    $d = ltrim((string)preg_replace('/\D+/', '', $raw), '0');
    return ($d !== '' && strlen($d) <= 10) ? $d : '';
}

// ANAF scrie ș/ț cu sedilă (ş/ţ); le aducem la forma corectă, cu virgulă.
function ro_diacritice(string $s): string
{
    return strtr($s, ['ş' => 'ș', 'ţ' => 'ț', 'Ş' => 'Ș', 'Ţ' => 'Ț']);
}

function http_post_json(string $url, string $body, int $timeout = 12): ?string
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
        ]);
        $res = curl_exec($ch);
        curl_close($ch);
        return is_string($res) ? $res : null;
    }
    $ctx = stream_context_create(['http' => [
        'method'        => 'POST',
        'header'        => "Content-Type: application/json\r\nAccept: application/json\r\n",
        'content'       => $body,
        'timeout'       => $timeout,
        'ignore_errors' => true,
    ]]);
    $res = @file_get_contents($url, false, $ctx);
    return is_string($res) ? $res : null;
}

// Adresa compusă din domiciliul fiscal (prefix „d”) sau sediul social (prefix „s”): stradă nr., detalii, localitate, județ.
function anaf_adresa(array $a, string $p): string
{
    $parts  = [];
    $strada = trim((string)($a[$p . 'denumire_Strada'] ?? ''));
    $nr     = trim((string)($a[$p . 'numar_Strada'] ?? ''));
    if ($strada !== '') {
        $parts[] = $strada . ($nr !== '' ? ' ' . $nr : '');
    }
    foreach ([$p . 'detalii_Adresa', $p . 'denumire_Localitate', $p . 'denumire_Judet'] as $k) {
        $v = trim((string)($a[$k] ?? ''));
        if ($v !== '') {
            $parts[] = $v;
        }
    }
    return ro_diacritice(implode(', ', $parts));
}

// ['ok' => true, 'firma' => [...]] sau ['ok' => false, 'error' => '...'] (mesaj pentru utilizator).
function anaf_lookup(string $raw): array
{
    $cui = anaf_cui($raw);
    if ($cui === '') {
        return ['ok' => false, 'error' => 'CUI invalid: introdu doar cifrele, cu sau fără RO.'];
    }
    $res = http_post_json(ANAF_URL, (string)json_encode([['cui' => (int)$cui, 'data' => date('Y-m-d')]]));
    $j   = $res !== null ? json_decode($res, true) : null;
    if (!is_array($j)) {
        return ['ok' => false, 'error' => 'ANAF nu răspunde acum. Completează datele manual sau încearcă din nou.'];
    }
    $f = $j['found'][0] ?? null;
    if (!$f || empty($f['date_generale'])) {
        return ['ok' => false, 'error' => !empty($j['notFound'])
            ? 'CUI-ul ' . $cui . ' nu există în evidența ANAF.'
            : 'Răspuns neașteptat de la ANAF (cod ' . (string)($j['cod'] ?? '?') . '). Încearcă din nou.'];
    }
    $g   = $f['date_generale'];
    $dom = $f['adresa_domiciliu_fiscal'] ?? [];
    $sed = $f['adresa_sediu_social'] ?? [];
    $adr = anaf_adresa($dom, 'd') ?: (anaf_adresa($sed, 's') ?: ro_diacritice(trim((string)($g['adresa'] ?? ''))));
    return ['ok' => true, 'firma' => [
        'cui'      => (string)$g['cui'],
        'denumire' => ro_diacritice(trim((string)($g['denumire'] ?? ''))),
        'reg_com'  => trim((string)($g['nrRegCom'] ?? '')),
        'adresa'   => $adr,
        'judet'    => ro_diacritice(trim((string)($dom['ddenumire_Judet'] ?? ($sed['sdenumire_Judet'] ?? '')))),
        'caen'     => trim((string)($g['cod_CAEN'] ?? '')),
        'telefon'  => trim((string)($g['telefon'] ?? '')),
        'tva'      => !empty($f['inregistrare_scop_Tva']['scpTVA']) ? 1 : 0,
        'inactiv'  => !empty($f['stare_inactiv']['statusInactivi']) ? 1 : 0,
        'stare'    => ro_diacritice(trim((string)($g['stare_inregistrare'] ?? ''))),
    ]];
}

// ---------------------------------------------------------------------------
// Email (mail() din PHP, cu antete UTF-8 corecte)
// ---------------------------------------------------------------------------
// Ultima eroare de trimitere (SMTP sau mail()), pentru mesajul din cockpit și jurnalul ofertei.
function mail_error(): string
{
    return $GLOBALS['ofertare_mail_error'] ?? '';
}

// Cum pleacă emailurile, în ordinea priorității:
//   1. Microsoft 365 prin Graph API (graph_tenant_id / graph_client_id / graph_client_secret în config.php):
//      emailul pleacă din căsuța mail_from din M365, cu SPF/DKIM/DMARC ale domeniului; răspunsul Graph este real.
//   2. SMTP autentificat (smtp_host): răspunsul serverului de email.
//   3. mail() din PHP: „adevărat” înseamnă doar că serverul local a pus mesajul în coadă, nu că a fost livrat;
//      când domeniul are emailul în M365, SPF-ul respinge mesajele plecate de pe serverul web.
function graph_configured(): bool
{
    return (string)cfg('graph_tenant_id') !== '' && (string)cfg('graph_client_id') !== '' && (string)cfg('graph_client_secret') !== '';
}

function mail_transport(): string
{
    if (graph_configured()) {
        return 'Microsoft 365 (Graph, ' . cfg('mail_from') . ')';
    }
    return (string)cfg('smtp_host') !== '' ? 'SMTP ' . cfg('smtp_host') . ':' . (int)(cfg('smtp_port') ?: 465) : 'mail()';
}

// Cerere HTTP cu antete proprii (curl sau stream); întoarce [cod HTTP, corp] sau [0, eroare de rețea].
function http_request(string $method, string $url, array $headers, string $body = '', int $timeout = 30): array
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        $res  = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        return is_string($res) ? [$code, $res] : [0, $err];
    }
    $ctx = stream_context_create(['http' => ['method' => $method, 'header' => implode("\r\n", $headers) . "\r\n",
        'content' => $body, 'timeout' => $timeout, 'ignore_errors' => true]]);
    $res  = @file_get_contents($url, false, $ctx);
    $code = 0;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
            $code = (int)$m[1];
        }
    }
    return is_string($res) ? [$code, $res] : [0, 'conexiune eșuată'];
}

// Token de aplicație (client credentials) pentru Graph; e valabil ~1 oră, dar îl cerem la fiecare trimitere (rar).
function graph_token(): ?string
{
    $login = rtrim((string)(cfg('graph_login_url') ?: 'https://login.microsoftonline.com'), '/');
    [$code, $res] = http_request('POST', $login . '/' . rawurlencode((string)cfg('graph_tenant_id')) . '/oauth2/v2.0/token',
        ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
        http_build_query(['client_id' => cfg('graph_client_id'), 'client_secret' => cfg('graph_client_secret'),
                          'scope' => 'https://graph.microsoft.com/.default', 'grant_type' => 'client_credentials']));
    $j = json_decode((string)$res, true);
    if ($code !== 200 || empty($j['access_token'])) {
        $GLOBALS['ofertare_mail_error'] = 'Token Microsoft 365 eșuat' . ($code ? ' (HTTP ' . $code . ')' : '') . ': '
            . (string)($j['error_description'] ?? $j['error'] ?? ($code ? mb_substr((string)$res, 0, 200) : $res));
        return null;
    }
    return (string)$j['access_token'];
}

// Trimitere prin Microsoft Graph: POST /users/{mail_from}/sendMail, ca în portalul ContaCheck.
// Aplicația Azure are nevoie de permisiunea de aplicație Mail.Send (cu consimțământ de administrator).
function graph_send(string $to, string $subject, string $html, string $text): bool
{
    $token = graph_token();
    if ($token === null) {
        return false;
    }
    $from = (string)cfg('mail_from');
    $msg  = [
        'subject'      => $subject,
        'body'         => ['contentType' => 'HTML', 'content' => $html],
        'toRecipients' => [['emailAddress' => ['address' => $to]]],
        'replyTo'      => [['emailAddress' => ['address' => (string)(cfg('mail_reply_to') ?: $from)]]],
    ];
    $bcc = array_filter(array_map('trim', explode(',', (string)cfg('mail_bcc'))));
    if ($bcc) {
        $msg['bccRecipients'] = array_map(fn($a) => ['emailAddress' => ['address' => $a]], array_values($bcc));
    }
    $api = rtrim((string)(cfg('graph_api_url') ?: 'https://graph.microsoft.com/v1.0'), '/');
    [$code, $res] = http_request('POST', $api . '/users/' . rawurlencode($from) . '/sendMail',
        ['Authorization: Bearer ' . $token, 'Content-Type: application/json', 'Accept: application/json'],
        json_encode(['message' => $msg, 'saveToSentItems' => cfg('graph_save_to_sent') === null ? true : (bool)cfg('graph_save_to_sent')], JSON_UNESCAPED_UNICODE), 45);
    if ($code === 202 || $code === 200) {
        return true;
    }
    $j = json_decode((string)$res, true);
    $GLOBALS['ofertare_mail_error'] = 'Graph sendMail eșuat' . ($code ? ' (HTTP ' . $code . ')' : '') . ': '
        . (string)($j['error']['message'] ?? ($code ? mb_substr((string)$res, 0, 200) : $res));
    return false;
}

function send_mail(string $to, string $subject, string $html, string $text): bool
{
    $GLOBALS['ofertare_mail_error'] = '';
    $boundary = 'b' . bin2hex(random_bytes(12));
    $from     = (string)cfg('mail_from');
    $fromName = '=?UTF-8?B?' . base64_encode((string)cfg('mail_from_name')) . '?=';
    $subj     = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $headers  = [
        'MIME-Version: 1.0',
        'From: ' . $fromName . ' <' . $from . '>',
        'Reply-To: ' . (string)(cfg('mail_reply_to') ?: $from),
        'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
    ];
    $body = "--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode($text))
        . "--$boundary\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode($html))
        . "--$boundary--\r\n";

    if (getenv('OFERTE_MAIL_LOG')) { // doar pentru teste locale
        file_put_contents(getenv('OFERTE_MAIL_LOG'), "TO: $to\nSUBJECT: $subject\n$text\n-----\n", FILE_APPEND);
        return true;
    }
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        $GLOBALS['ofertare_mail_error'] = 'Adresă de destinație invalidă.';
        return false;
    }
    if (graph_configured()) {
        return graph_send($to, $subject, $html, $text);
    }
    $bcc = array_values(array_filter(array_map('trim', explode(',', (string)cfg('mail_bcc')))));
    if ((string)cfg('smtp_host') !== '') {
        return smtp_send($from, $to, array_merge(['To: <' . $to . '>', 'Subject: ' . $subj, 'Date: ' . date(DATE_RFC2822),
            'Message-ID: <' . bin2hex(random_bytes(10)) . '@' . substr(strrchr($from, '@') ?: '@localhost', 1) . '>'], $headers), $body, $bcc);
    }
    if ($bcc) {
        $headers[] = 'Bcc: ' . implode(', ', $bcc);
    }
    $ok = @mail($to, $subj, $body, implode("\r\n", $headers), '-f' . $from);
    if (!$ok) {
        $e = error_get_last();
        $GLOBALS['ofertare_mail_error'] = 'mail() a refuzat mesajul' . ($e && $e['message'] ? ': ' . $e['message'] : '') . '.';
    }
    return $ok;
}

// Client SMTP minimal: SSL (port 465), STARTTLS (587) sau necriptat (25/localhost), AUTH LOGIN opțional.
// Întoarce false și pune în mail_error() răspunsul serverului la primul pas eșuat.
function smtp_send(string $from, string $to, array $headers, string $body, array $bcc = []): bool
{
    $host   = (string)cfg('smtp_host');
    $port   = (int)(cfg('smtp_port') ?: 465);
    $secure = strtolower((string)(cfg('smtp_secure') ?? ($port === 465 ? 'ssl' : ($port === 587 ? 'tls' : ''))));
    $user   = (string)cfg('smtp_user');
    $pass   = (string)cfg('smtp_pass');
    $fail   = function (string $msg) {
        $GLOBALS['ofertare_mail_error'] = $msg;
        return false;
    };
    $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true]]);
    $fp  = @stream_socket_client(($secure === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) {
        return $fail("Nu mă pot conecta la $host:$port: $errstr ($errno).");
    }
    stream_set_timeout($fp, 15);
    $read = function () use ($fp): array { // [cod, text]; răspunsurile pe mai multe rânduri au „-” după cod
        $text = '';
        while (($line = fgets($fp, 1024)) !== false) {
            $text .= $line;
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }
        return [(int)substr($text, 0, 3), trim($text)];
    };
    $cmd = function (string $c, int $expect, string $step) use ($fp, $read, $fail): bool {
        fwrite($fp, $c . "\r\n");
        [$code, $text] = $read();
        if ($code !== $expect) {
            $fail("$step: " . ($text !== '' ? $text : 'serverul nu răspunde'));
            return false;
        }
        return true;
    };
    $ehloHost = substr(strrchr($from, '@') ?: '@localhost', 1);
    [$code, $text] = $read();
    if ($code !== 220) {
        fclose($fp);
        return $fail('Serverul SMTP nu răspunde: ' . $text);
    }
    $ok = $cmd('EHLO ' . $ehloHost, 250, 'EHLO');
    if ($ok && $secure === 'tls') {
        $ok = $cmd('STARTTLS', 220, 'STARTTLS')
            && (@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) === true || $fail('STARTTLS: criptarea nu a reușit (certificat?).') )
            && $cmd('EHLO ' . $ehloHost, 250, 'EHLO');
    }
    if ($ok && $user !== '') {
        $ok = $cmd('AUTH LOGIN', 334, 'AUTH LOGIN') && $cmd(base64_encode($user), 334, 'Utilizator SMTP')
            && $cmd(base64_encode($pass), 235, 'Parolă SMTP');
    }
    $ok = $ok && $cmd('MAIL FROM:<' . $from . '>', 250, 'MAIL FROM') && $cmd('RCPT TO:<' . $to . '>', 250, 'RCPT TO');
    foreach ($bcc as $b) {
        $ok = $ok && $cmd('RCPT TO:<' . $b . '>', 250, 'RCPT TO (Bcc)');
    }
    $ok = $ok && $cmd('DATA', 354, 'DATA');
    if ($ok) {
        $data = implode("\r\n", $headers) . "\r\n\r\n" . preg_replace('/^\./m', '..', $body);
        $ok   = $cmd(rtrim($data, "\r\n") . "\r\n.", 250, 'Mesaj');
    }
    fwrite($fp, "QUIT\r\n");
    fclose($fp);
    return $ok;
}

// ---------------------------------------------------------------------------
// Antete de securitate comune
// ---------------------------------------------------------------------------
function security_headers(): void
{
    header('X-Robots-Tag: noindex, nofollow, noarchive');
    header('Cache-Control: no-store, private');
    header('Pragma: no-cache');
    header('Referrer-Policy: no-referrer');      // tokenul din URL nu pleacă spre alte site-uri
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
}

// ---------------------------------------------------------------------------
// Machetă de pagină simplă (paginile clientului și autentificarea)
// ---------------------------------------------------------------------------
function page(string $title, string $body, int $status = 200): void
{
    http_response_code($status);
    security_headers();
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="ro"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<meta name="robots" content="noindex,nofollow">'
        . '<title>' . h($title) . '</title><style>'
        . ':root{--bg:#f5f6f7;--card:#fff;--ink:#1b2430;--muted:#5b6672;--line:#d9dee4;--navy:#1F4788;--teal:#2D8B8E;--err:#b4232a;--ok:#16794c}'
        . '@media (prefers-color-scheme:dark){:root{--bg:#0d1520;--card:#121c29;--ink:#e3e8ee;--muted:#9aa6b3;--line:#263445;--navy:#7fa6e6;--teal:#5cc0c3;--err:#ff7b7f;--ok:#5ad19a;color-scheme:dark}}'
        . '*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:15px/1.55 "IBM Plex Sans",system-ui,-apple-system,"Segoe UI",Arial,sans-serif;padding:32px 16px}'
        . '.wrap{max-width:460px;margin:0 auto}.brand{font:600 12px/1 ui-monospace,Menlo,Consolas,monospace;letter-spacing:.08em;text-transform:uppercase;color:var(--navy);margin-bottom:14px}'
        . '.card{background:var(--card);border:1px solid var(--line);border-radius:6px;padding:24px}h1{font-size:20px;margin:0 0 10px;color:var(--navy);overflow-wrap:anywhere}'
        . 'p{margin:0 0 12px}.muted{color:var(--muted);font-size:13px}.err{color:var(--err)}.ok{color:var(--ok)}'
        . 'label{display:block;font-size:13px;color:var(--muted);margin:10px 0 4px}input{width:100%;padding:9px 10px;border:1px solid var(--line);border-radius:4px;background:var(--bg);color:var(--ink);font:inherit}'
        . 'input.code{font:600 24px ui-monospace,Menlo,Consolas,monospace;letter-spacing:.3em;text-align:center}'
        . 'button{display:inline-block;margin-top:14px;padding:9px 16px;border-radius:4px;border:1px solid var(--teal);background:var(--teal);color:var(--card);font:600 14px inherit;cursor:pointer}'
        . 'button.sec{background:transparent;color:var(--teal)}'
        . 'button:focus-visible,input:focus-visible{outline:2px solid var(--navy);outline-offset:2px}'
        . 'ul.mats{list-style:none;padding:0;margin:0 0 8px}ul.mats li{margin:0 0 6px}ul.mats a{color:var(--teal);font-weight:600}'
        . '</style></head><body><div class="wrap">' . $body . '</div></body></html>';
    exit;
}
