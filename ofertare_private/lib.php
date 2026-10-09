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
        CREATE INDEX IF NOT EXISTS ix_links_deal ON links(deal_id);
        CREATE INDEX IF NOT EXISTS ix_log_deal ON access_log(deal_id, at);
        CREATE INDEX IF NOT EXISTS ix_otp_deal ON otps(deal_id, created_at);
    ");
    migrate_deals($pdo);
    seed_defaults($pdo);
    return $pdo;
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
    ];
}

function fill_vars(string $s, array $vars): string
{
    return preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/', fn($m) => $m[1] === 'materiale' ? $m[0] : ($vars[$m[1]] ?? $m[0]), $s);
}

// $materials = [['title' => ..., 'url' => ...], ...]
function render_email(string $subject, string $body, array $deal, array $materials): array
{
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
function send_mail(string $to, string $subject, string $html, string $text): bool
{
    $boundary = 'b' . bin2hex(random_bytes(12));
    $fromName = '=?UTF-8?B?' . base64_encode((string)cfg('mail_from_name')) . '?=';
    $headers  = [
        'MIME-Version: 1.0',
        'From: ' . $fromName . ' <' . cfg('mail_from') . '>',
        'Reply-To: ' . cfg('mail_from'),
        'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
    ];
    $body = "--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode($text))
        . "--$boundary\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode($html))
        . "--$boundary--\r\n";
    $subj = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    if (getenv('OFERTE_MAIL_LOG')) { // doar pentru teste locale
        file_put_contents(getenv('OFERTE_MAIL_LOG'), "TO: $to\nSUBJECT: $subject\n$text\n-----\n", FILE_APPEND);
        return true;
    }
    return mail($to, $subj, $body, implode("\r\n", $headers), '-f' . cfg('mail_from'));
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
