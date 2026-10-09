<?php
// Gatekeeper: https://ofertare.aiall.ro/<token>
// Fiecare token deschide un singur material al unei oferte. Codul de acces se trimite pe emailul
// destinatarului ofertei și, odată confirmat, deschide toate materialele aceleiași oferte.
// Structură pe server: /home/CONT/ofertare.aiall.ro (public) și /home/CONT/ofertare_private (privat).

declare(strict_types=1);
require dirname(__DIR__) . '/ofertare_private/lib.php';

const BRAND = '<div class="brand">AiALL S.R.L. · smartBIZ Copilot</div>';

function not_found(): void
{
    page('Link indisponibil', BRAND . '<div class="card"><h1>Link indisponibil</h1>'
        . '<p>Linkul nu este valid, a expirat sau a fost revocat.</p>'
        . '<p class="muted">Dacă ai nevoie de document, răspunde la emailul în care ai primit linkul.</p></div>', 404);
}

// ---------------------------------------------------------------------------
// Identificarea linkului, a materialului și a ofertei
// ---------------------------------------------------------------------------
$token = (string)($_GET['t'] ?? '');
if (!preg_match('/^[A-Za-z0-9_-]{40,64}$/', $token)) {
    not_found();
}

$st = db()->prepare('SELECT l.id AS link_id, l.material_id, d.*, m.title AS m_title, m.file AS m_file, m.active AS m_active
                     FROM links l JOIN deals d ON d.id = l.deal_id JOIN materials m ON m.id = l.material_id
                     WHERE l.token_hash = ?');
$st->execute([khash($token)]);
$row = $st->fetch();
if (!$row || $row['revoked'] || !$row['m_active'] || (int)$row['expires_at'] < time()) {
    not_found();
}
$dealId     = (int)$row['id'];
$cookieName = 'ofs_' . $dealId;
$selfUrl    = base_url() . $token;

// ---------------------------------------------------------------------------
// Sesiune deja validată prin cod (la nivel de ofertă)?
// ---------------------------------------------------------------------------
function has_session(int $dealId, string $cookieName): bool
{
    $raw = (string)($_COOKIE[$cookieName] ?? '');
    if ($raw === '' || !preg_match('/^[A-Za-z0-9_-]{40,64}$/', $raw)) {
        return false;
    }
    $st = db()->prepare('SELECT 1 FROM sessions WHERE deal_id = ? AND sess_hash = ? AND expires_at > ?');
    $st->execute([$dealId, khash($raw), time()]);
    return (bool)$st->fetchColumn();
}

// ---------------------------------------------------------------------------
// Livrarea materialului
// ---------------------------------------------------------------------------
function serve_material(array $row): void
{
    $path = safe_file_path((string)$row['m_file']);
    if (!$path) {
        not_found();
    }
    $dealId = (int)$row['id'];
    $now    = time();
    db()->prepare('UPDATE deals SET opens = opens + 1, last_open = ?, first_open = COALESCE(first_open, ?) WHERE id = ?')
        ->execute([$now, $now, $dealId]);
    db()->prepare('UPDATE links SET opens = opens + 1, last_open = ? WHERE id = ?')->execute([$now, (int)$row['link_id']]);
    log_event($dealId, 'deschis: ' . $row['m_title']);

    // Pipeline: prima deschidere mută oferta din „Trimisă” în „Deschisă”.
    if ($row['stage'] === 'trimisa') {
        set_stage($dealId, 'deschisa');
    }

    if (empty($row['first_open']) && cfg('notify_email')) {
        $txt = "Oferta către {$row['company']} a fost deschisă prima dată.\n"
            . "Destinatar: {$row['recipient']} <{$row['email']}>\nMaterial: {$row['m_title']}\n"
            . 'Data: ' . date('d.m.Y H:i', $now) . "\nIP: " . client_ip();
        send_mail((string)cfg('notify_email'), 'Ofertă deschisă: ' . ($row['company'] ?: $row['email']), nl2br(h($txt)), $txt);
    }

    $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $type = allowed_types()[$ext];
    $name = trim(preg_replace('/[^A-Za-z0-9._-]+/', '_', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', (string)$row['m_title']) ?: 'material'), '_');
    $name = ($name ?: 'material') . '.' . $ext;

    security_headers();
    header('Content-Type: ' . $type);
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: inline; filename="' . $name . '"');
    if ($ext === 'html' || $ext === 'htm') {
        // Pagina HTML nu poate încărca resurse de pe alte domenii, cu excepția fonturilor Google.
        header("Content-Security-Policy: default-src 'none'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; "
            . "font-src https://fonts.gstatic.com; img-src data:; script-src 'unsafe-inline'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'");
    }
    readfile($path);
    exit;
}

if (!(int)$row['require_otp'] || has_session($dealId, $cookieName)) {
    serve_material($row);
}

// ---------------------------------------------------------------------------
// Verificare prin cod trimis pe email
// ---------------------------------------------------------------------------
$masked       = mask_email((string)$row['email']);
$message      = '';
$showCodeForm = false;
$method       = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action       = (string)($_POST['action'] ?? '');

if ($method === 'POST' && $action === 'send') {
    $st = db()->prepare('SELECT COUNT(*) FROM otps WHERE deal_id = ? AND created_at > ?');
    $st->execute([$dealId, time() - 3600]);
    if ((int)$st->fetchColumn() >= (int)cfg('otp_max_sends')) {
        $message = '<p class="err">Ai cerut prea multe coduri în ultima oră. Încearcă mai târziu.</p>';
        log_event($dealId, 'cod: limită atinsă');
    } else {
        $code = (string)random_int(100000, 999999);
        db()->prepare('UPDATE otps SET used = 1 WHERE deal_id = ? AND used = 0')->execute([$dealId]);
        db()->prepare('INSERT INTO otps (deal_id, code_hash, created_at, expires_at) VALUES (?, ?, ?, ?)')
            ->execute([$dealId, khash($dealId . ':' . $code), time(), time() + 60 * (int)cfg('otp_minutes')]);
        $min  = (int)cfg('otp_minutes');
        $text = "Codul de acces la documentele AiALL este: $code\n\nCodul este valabil $min minute. "
            . "Dacă nu ai cerut acest cod, ignoră mesajul.\n\nAiALL S.R.L.";
        $html = '<div style="font-family:Arial,sans-serif;font-size:14px;color:#1b2430">'
            . '<p>Codul de acces la documentele AiALL este:</p>'
            . '<p style="font-size:26px;font-weight:bold;letter-spacing:6px;color:#1F4788">' . $code . '</p>'
            . '<p>Codul este valabil ' . $min . ' minute. Dacă nu ai cerut acest cod, ignoră mesajul.</p><p>AiALL S.R.L.</p></div>';
        send_mail((string)$row['email'], 'Cod de acces documente AiALL: ' . $code, $html, $text);
        log_event($dealId, 'cod: trimis');
        $message = '<p class="ok">Am trimis un cod de 6 cifre la ' . h($masked) . '.</p>';
        $showCodeForm = true;
    }
}

if ($method === 'POST' && $action === 'verify') {
    $showCodeForm = true;
    $code = preg_replace('/\D/', '', (string)($_POST['code'] ?? ''));
    $st = db()->prepare('SELECT * FROM otps WHERE deal_id = ? AND used = 0 AND expires_at > ? ORDER BY id DESC LIMIT 1');
    $st->execute([$dealId, time()]);
    $otp = $st->fetch();

    if (!$otp) {
        $message = '<p class="err">Codul a expirat. Cere un cod nou.</p>';
        $showCodeForm = false;
    } elseif ((int)$otp['tries'] >= (int)cfg('otp_max_tries')) {
        db()->prepare('UPDATE otps SET used = 1 WHERE id = ?')->execute([$otp['id']]);
        $message = '<p class="err">Prea multe încercări greșite. Cere un cod nou.</p>';
        $showCodeForm = false;
        log_event($dealId, 'cod: blocat');
    } elseif (strlen($code) === 6 && hash_equals((string)$otp['code_hash'], khash($dealId . ':' . $code))) {
        db()->prepare('UPDATE otps SET used = 1 WHERE id = ?')->execute([$otp['id']]);
        $sess = new_token();
        $exp  = time() + 3600 * (int)cfg('session_hours');
        db()->prepare('INSERT INTO sessions (deal_id, sess_hash, expires_at) VALUES (?, ?, ?)')
            ->execute([$dealId, khash($sess), $exp]);
        setcookie($cookieName, $sess, [
            'expires'  => $exp,
            'path'     => '/',
            'secure'   => is_https(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        log_event($dealId, 'cod: valid');
        header('Location: ' . $selfUrl, true, 303);
        exit;
    } else {
        db()->prepare('UPDATE otps SET tries = tries + 1 WHERE id = ?')->execute([$otp['id']]);
        $left = (int)cfg('otp_max_tries') - (int)$otp['tries'] - 1;
        $message = '<p class="err">Cod incorect. Mai ai ' . max(0, $left) . ' încercări.</p>';
        log_event($dealId, 'cod: greșit');
    }
}

if ($method === 'GET') {
    log_event($dealId, 'pagina de cod: ' . $row['m_title']);
}

$intro = '<h1>' . h((string)$row['m_title']) . '</h1>'
    . '<p>Documentul este personal. Pentru acces, trimitem un cod de 6 cifre la adresa pe care ai primit linkul: <strong>'
    . h($masked) . '</strong>. Codul deschide toate documentele din același email.</p>';

$form = $showCodeForm
    ? '<form method="post" autocomplete="off"><input type="hidden" name="action" value="verify">'
      . '<label for="code">Codul primit pe email</label>'
      . '<input class="code" id="code" name="code" inputmode="numeric" pattern="[0-9 ]{6,7}" maxlength="7" required autofocus>'
      . '<button type="submit">Deschide documentul</button></form>'
      . '<form method="post"><input type="hidden" name="action" value="send"><button class="sec" type="submit">Trimite alt cod</button></form>'
    : '<form method="post"><input type="hidden" name="action" value="send"><button type="submit">Trimite codul pe email</button></form>';

page('Acces document AiALL', BRAND . '<div class="card">' . $intro . $message . $form
    . '<p class="muted" style="margin-top:16px">Dacă linkul ți-a fost redirecționat de altcineva, codul ajunge doar la destinatarul inițial.</p></div>');
