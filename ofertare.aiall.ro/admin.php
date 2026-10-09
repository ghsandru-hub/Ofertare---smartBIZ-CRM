<?php
// Cockpit e2e OPS Ofertare: https://ofertare.aiall.ro/admin.php
// Secțiuni: Pipeline (indicatori, pâlnie, kanban, ofertă nouă), Raportare, Șabloane email, Materiale, Utilizatori.
// Login separat de partea publică: sesiune proprie (cookie „ofadm”). Utilizatorii stau în tabela `users`;
// contul din ofertare_private/config.php este contul inițial și calea de recuperare (vezi autentificarea).

declare(strict_types=1);
require dirname(__DIR__) . '/ofertare_private/lib.php';

session_name('ofadm');
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/admin.php',
    'secure'   => is_https(),
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();

// ---------------------------------------------------------------------------
// CSRF
// ---------------------------------------------------------------------------
function csrf(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function csrf_ok(): bool
{
    return hash_equals((string)($_SESSION['csrf'] ?? ''), (string)($_POST['csrf'] ?? ''));
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . h(csrf()) . '">';
}

function redirect(string $to): void
{
    header('Location: ' . $to, true, 303);
    exit;
}

function flash(string $html): void
{
    $_SESSION['flash'] = $html;
}

// ---------------------------------------------------------------------------
// Utilizatori (tabela users; contul din config.php rămâne cale de intrare cât nu există în tabelă)
// ---------------------------------------------------------------------------
function find_user(string $username): ?array
{
    $q = db()->prepare('SELECT * FROM users WHERE username = ?');
    $q->execute([$username]);
    return $q->fetch() ?: null;
}

function current_user(): ?array
{
    if (empty($_SESSION['uid'])) {
        return null;
    }
    $q = db()->prepare('SELECT * FROM users WHERE id = ?');
    $q->execute([(int)$_SESSION['uid']]);
    return $q->fetch() ?: null;
}

function password_problem(string $pass, ?string $confirm = null): string
{
    if (strlen($pass) < 10) {
        return 'Parola trebuie să aibă cel puțin 10 caractere.';
    }
    if ($confirm !== null && $pass !== $confirm) {
        return 'Parolele nu coincid.';
    }
    return '';
}

// Parolă generată pentru un utilizator nou: 14 caractere, fără cele ușor de confundat (0/O, 1/l/I).
function new_password(): string
{
    $chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $out   = '';
    for ($i = 0; $i < 14; $i++) {
        $out .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $out;
}

// ---------------------------------------------------------------------------
// Autentificare
// ---------------------------------------------------------------------------
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = (string)($_POST['action'] ?? '');
$view   = in_array($_GET['v'] ?? '', ['pipeline', 'sabloane', 'materiale', 'raportare', 'utilizatori'], true) ? $_GET['v'] : 'pipeline';

if ($action === 'logout' && csrf_ok()) {
    $_SESSION = [];
    session_destroy();
    redirect('admin.php');
}

if (empty($_SESSION['admin']) || empty($_SESSION['uid'])) {
    $err = '';
    if ($method === 'POST' && $action === 'login') {
        $user = trim((string)($_POST['user'] ?? ''));
        $pass = (string)($_POST['pass'] ?? '');
        $row  = find_user($user);
        $ok   = false;
        if ($row) {
            // utilizator din tabela users (creat în cockpit sau copiat din config.php)
            $ok = (int)$row['active'] === 1 && password_verify($pass, (string)$row['pass_hash']);
        } elseif (cfg('admin_pass_hash') && hash_equals((string)cfg('admin_user'), $user)
            && password_verify($pass, (string)cfg('admin_pass_hash'))) {
            // Contul din config.php: valabil doar cât nu există în tabelă un utilizator cu același nume.
            // La prima autentificare îl copiem în tabelă, ca parola să se poată schimba din cockpit.
            db()->prepare('INSERT INTO users (username, name, pass_hash, created_at) VALUES (?, ?, ?, ?)')
                ->execute([$user, 'Administrator', (string)cfg('admin_pass_hash'), time()]);
            $row = find_user($user);
            $ok  = $row !== null;
        }
        if ($ok && csrf_ok()) {
            session_regenerate_id(true);
            $_SESSION['admin'] = true;
            $_SESSION['uid']   = (int)$row['id'];
            db()->prepare('UPDATE users SET last_login = ? WHERE id = ?')->execute([time(), (int)$row['id']]);
            redirect('admin.php');
        }
        sleep(2); // frânează încercările repetate
        $err = '<p class="err">Utilizator sau parolă greșite.</p>';
    }
    if (!cfg('admin_pass_hash') && (int)db()->query('SELECT COUNT(*) FROM users WHERE active = 1')->fetchColumn() === 0) {
        $err = '<p class="err">Setează admin_pass_hash în ofertare_private/config.php.</p>';
    }
    page('Autentificare · e2e OPS Ofertare', '<div class="brand">smartBIZ Copilot · e2e OPS Ofertare</div><div class="card"><h1>Autentificare</h1>' . $err
        . '<form method="post">' . csrf_field() . '<input type="hidden" name="action" value="login">'
        . '<label for="user">Utilizator</label><input id="user" name="user" autocomplete="username" required>'
        . '<label for="pass">Parolă</label><input id="pass" name="pass" type="password" autocomplete="current-password" required>'
        . '<button type="submit">Intră</button></form></div>');
}

// Utilizator șters sau dezactivat între timp: sesiunea nu mai e valabilă.
$me = current_user();
if (!$me || (int)$me['active'] !== 1) {
    $_SESSION = [];
    session_destroy();
    redirect('admin.php');
}

// Datele firmei de la ANAF pentru formularul „Ofertă nouă” (JSON, doar după autentificare).
if (($_GET['a'] ?? '') === 'anaf') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    exit(json_encode(anaf_lookup((string)($_GET['cui'] ?? '')), JSON_UNESCAPED_UNICODE));
}

// ---------------------------------------------------------------------------
// Acțiuni
// ---------------------------------------------------------------------------
$isAjax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';

if ($method === 'POST' && !csrf_ok()) {
    if ($isAjax) {
        http_response_code(403);
        exit('csrf');
    }
    flash('<div class="note err">Sesiune expirată. Reîncarcă pagina și încearcă din nou.</div>');
    redirect('admin.php?v=' . $view);
}

if ($method === 'POST') {
    switch ($action) {
        // --- Pipeline -------------------------------------------------------
        case 'create_deal':
            $tid       = (int)($_POST['template_id'] ?? 0);
            $email     = trim((string)($_POST['email'] ?? ''));
            $recipient = trim((string)($_POST['recipient'] ?? ''));
            $company   = trim((string)($_POST['company'] ?? ''));
            $angajati  = max(0, min(10000, (int)($_POST['angajati'] ?? 0)));
            $days      = max(1, min(365, (int)($_POST['days'] ?? cfg('default_days'))));
            $otp       = !empty($_POST['require_otp']) ? 1 : 0;
            $sendNow   = !empty($_POST['send']);
            $matSel    = array_map('intval', (array)($_POST['materials'] ?? []));
            $firma     = [ // completate din ANAF (sau manual) în formular
                'cui'     => anaf_cui((string)($_POST['cui'] ?? '')),
                'reg_com' => mb_substr(trim((string)($_POST['reg_com'] ?? '')), 0, 40),
                'adresa'  => mb_substr(trim((string)($_POST['adresa'] ?? '')), 0, 300),
                'judet'   => mb_substr(trim((string)($_POST['judet'] ?? '')), 0, 60),
                'caen'    => mb_substr(trim((string)($_POST['caen'] ?? '')), 0, 10),
                'telefon' => mb_substr(trim((string)($_POST['telefon'] ?? '')), 0, 40),
                'tva'     => !empty($_POST['tva']) ? 1 : 0,
                'inactiv' => !empty($_POST['inactiv']) ? 1 : 0,
            ];

            $tq = db()->prepare('SELECT * FROM templates WHERE id = ?');
            $tq->execute([$tid]);
            $tpl  = $tq->fetch();
            $mats = [];
            if ($matSel) {
                // ordinea șablonului, apoi materialele adăugate manual
                $order = array_flip(array_map(fn($m) => (int)$m['id'], $tpl ? template_materials((int)$tpl['id']) : []));
                usort($matSel, fn($a, $b) => ($order[$a] ?? 999) <=> ($order[$b] ?? 999));
                $in = implode(',', array_fill(0, count($matSel), '?'));
                $mq = db()->prepare("SELECT * FROM materials WHERE active = 1 AND id IN ($in)");
                $mq->execute($matSel);
                $byId = array_column($mq->fetchAll(), null, 'id');
                foreach ($matSel as $mid) {
                    if (isset($byId[$mid])) {
                        $mats[] = $byId[$mid];
                    }
                }
            }
            if (!$tpl) {
                flash('<div class="note err">Alege un șablon de email.</div>');
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                flash('<div class="note err">Adresa de email nu este validă.</div>');
            } elseif (!$mats) {
                flash('<div class="note err">Alege cel puțin un material.</div>');
            } else {
                $now = time();
                db()->prepare('INSERT INTO deals (company, recipient, email, angajati, template_id, subject, require_otp, stage, reached, stage_at, created_at, expires_at,
                                                  cui, reg_com, adresa, judet, caen, telefon, tva, inactiv)
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$company, $recipient, $email, $angajati, $tid, '', $otp, 'trimisa', 1, $now, $now, $now + 86400 * $days,
                               $firma['cui'], $firma['reg_com'], $firma['adresa'], $firma['judet'], $firma['caen'], $firma['telefon'], $firma['tva'], $firma['inactiv']]);
                $dealId = (int)db()->lastInsertId();
                $dq     = db()->prepare('SELECT * FROM deals WHERE id = ?');
                $dq->execute([$dealId]);
                $deal   = $dq->fetch();
                $linkQ  = db()->prepare('INSERT INTO links (deal_id, material_id, token_hash) VALUES (?, ?, ?)');
                $mlinks = [];
                foreach ($mats as $m) {
                    $tok = new_token();
                    $linkQ->execute([$dealId, (int)$m['id'], khash($tok)]);
                    $mlinks[] = ['title' => $m['title'], 'url' => base_url() . $tok];
                }
                [$subject, $html, $text] = render_email((string)$tpl['subject'], (string)$tpl['body'], $deal, $mlinks);
                db()->prepare('UPDATE deals SET subject = ? WHERE id = ?')->execute([$subject, $dealId]);
                log_event($dealId, 'creată din șablonul „' . $tpl['name'] . '”');
                $sent = '';
                if ($sendNow) {
                    $ok = send_mail($email, $subject, $html, $text);
                    log_event($dealId, $ok ? 'email trimis' : 'email eșuat');
                    $sent = $ok ? '<p class="ok">Emailul a fost trimis la ' . h($email) . '.</p>'
                                : '<p class="err">Trimiterea a eșuat. Copiază linkurile și trimite-le manual.</p>';
                }
                $list = '';
                foreach ($mlinks as $ml) {
                    $list .= '<div class="url"><b>' . h($ml['title']) . '</b><br>' . h($ml['url']) . '</div>';
                }
                flash('<div class="note"><strong>Ofertă creată pentru ' . h($company ?: $email) . '.</strong>' . $sent
                    . '<p class="muted">Linkurile se afișează o singură dată; în baza de date se păstrează doar amprentele lor.</p>' . $list . '</div>');
            }
            redirect('admin.php?v=pipeline');

        case 'stage':
            set_stage((int)($_POST['id'] ?? 0), (string)($_POST['stage'] ?? ''));
            if ($isAjax) {
                header('Content-Type: application/json');
                exit(json_encode(['ok' => true]));
            }
            redirect('admin.php?v=pipeline');

        case 'revoke':
            $id = (int)($_POST['id'] ?? 0);
            db()->prepare('UPDATE deals SET revoked = 1 WHERE id = ?')->execute([$id]);
            db()->prepare('DELETE FROM sessions WHERE deal_id = ?')->execute([$id]);
            log_event($id, 'linkuri revocate');
            flash('<div class="note">Linkurile ofertei au fost revocate. Accesul se oprește imediat.</div>');
            redirect('admin.php?v=pipeline');

        case 'nota':
            $id = (int)($_POST['id'] ?? 0);
            db()->prepare('UPDATE deals SET nota = ? WHERE id = ?')->execute([mb_substr(trim((string)($_POST['nota'] ?? '')), 0, 500), $id]);
            redirect('admin.php?v=pipeline#c' . $id);

        // --- Șabloane -------------------------------------------------------
        case 'tpl_save':
            $id      = (int)($_POST['id'] ?? 0);
            $name    = trim((string)($_POST['name'] ?? ''));
            $subject = trim((string)($_POST['subject'] ?? ''));
            $body    = str_replace("\r\n", "\n", (string)($_POST['body'] ?? ''));
            if ($name === '' || $subject === '' || trim($body) === '') {
                flash('<div class="note err">Numele, subiectul și textul șablonului sunt obligatorii.</div>');
                redirect('admin.php?v=sabloane&id=' . ($id ?: 'new'));
            }
            if ($id) {
                db()->prepare('UPDATE templates SET name = ?, subject = ?, body = ?, updated_at = ? WHERE id = ?')
                    ->execute([$name, $subject, $body, time(), $id]);
            } else {
                db()->prepare('INSERT INTO templates (name, subject, body, updated_at) VALUES (?, ?, ?, ?)')
                    ->execute([$name, $subject, $body, time()]);
                $id = (int)db()->lastInsertId();
            }
            db()->prepare('DELETE FROM template_materials WHERE template_id = ?')->execute([$id]);
            $ins = db()->prepare('INSERT INTO template_materials (template_id, material_id, ord) VALUES (?, ?, ?)');
            foreach (array_keys((array)($_POST['mat'] ?? [])) as $mid) {
                $ins->execute([$id, (int)$mid, (int)($_POST['ord'][$mid] ?? 0)]);
            }
            flash('<div class="note">Șablonul „' . h($name) . '” a fost salvat.</div>');
            redirect('admin.php?v=sabloane&id=' . $id);

        case 'tpl_copy':
            $src = db()->prepare('SELECT * FROM templates WHERE id = ?');
            $src->execute([(int)($_POST['id'] ?? 0)]);
            if ($t = $src->fetch()) {
                db()->prepare('INSERT INTO templates (name, subject, body, updated_at) VALUES (?, ?, ?, ?)')
                    ->execute([$t['name'] . ' (copie)', $t['subject'], $t['body'], time()]);
                $nid = (int)db()->lastInsertId();
                db()->prepare('INSERT INTO template_materials (template_id, material_id, ord) SELECT ?, material_id, ord FROM template_materials WHERE template_id = ?')
                    ->execute([$nid, (int)$t['id']]);
                flash('<div class="note">Am creat o copie a șablonului.</div>');
                redirect('admin.php?v=sabloane&id=' . $nid);
            }
            redirect('admin.php?v=sabloane');

        case 'tpl_delete':
            db()->prepare('DELETE FROM templates WHERE id = ?')->execute([(int)($_POST['id'] ?? 0)]);
            flash('<div class="note">Șablonul a fost șters. Ofertele deja trimise nu sunt afectate.</div>');
            redirect('admin.php?v=sabloane');

        // --- Materiale ------------------------------------------------------
        case 'mat_upload':
            [$file, $err] = store_upload($_FILES['file'] ?? []);
            $title = trim((string)($_POST['title'] ?? ''));
            $kind  = ($_POST['kind'] ?? '') === 'oferta' ? 'oferta' : 'suport';
            if ($err) {
                flash('<div class="note err">' . h($err) . '</div>');
            } else {
                db()->prepare('INSERT INTO materials (title, kind, file, orig_name, updated_at) VALUES (?, ?, ?, ?, ?)')
                    ->execute([$title ?: pathinfo((string)$_FILES['file']['name'], PATHINFO_FILENAME), $kind, $file, (string)$_FILES['file']['name'], time()]);
                flash('<div class="note">Materialul a fost încărcat. Îl poți adăuga acum în șabloane.</div>');
            }
            redirect('admin.php?v=materiale');

        case 'mat_update':
            $id    = (int)($_POST['id'] ?? 0);
            $title = trim((string)($_POST['title'] ?? ''));
            $kind  = ($_POST['kind'] ?? '') === 'oferta' ? 'oferta' : 'suport';
            $act   = !empty($_POST['active']) ? 1 : 0;
            $mq    = db()->prepare('SELECT * FROM materials WHERE id = ?');
            $mq->execute([$id]);
            if ($m = $mq->fetch()) {
                $file = $m['file'];
                $orig = $m['orig_name'];
                if (!empty($_FILES['file']['name'])) {
                    [$newFile, $err] = store_upload($_FILES['file']);
                    if ($err) {
                        flash('<div class="note err">' . h($err) . '</div>');
                        redirect('admin.php?v=materiale');
                    }
                    $file = $newFile;
                    $orig = (string)$_FILES['file']['name'];
                    if ($old = safe_file_path((string)$m['file'])) {
                        @unlink($old); // linkurile deja trimise deschid de acum noua versiune
                    }
                }
                db()->prepare('UPDATE materials SET title = ?, kind = ?, active = ?, file = ?, orig_name = ?, updated_at = ? WHERE id = ?')
                    ->execute([$title ?: $m['title'], $kind, $act, $file, $orig, time(), $id]);
                flash('<div class="note">Materialul „' . h($title ?: $m['title']) . '” a fost actualizat'
                    . ($file !== $m['file'] ? '; linkurile deja trimise deschid noua versiune' : '') . '.</div>');
            }
            redirect('admin.php?v=materiale');

        // --- Utilizatori ----------------------------------------------------
        case 'pw_change':
            $pass = (string)($_POST['pass'] ?? '');
            if (!password_verify((string)($_POST['current'] ?? ''), (string)$me['pass_hash'])) {
                flash('<div class="note err">Parola actuală nu este corectă.</div>');
            } elseif ($p = password_problem($pass, (string)($_POST['confirm'] ?? ''))) {
                flash('<div class="note err">' . h($p) . '</div>');
            } else {
                db()->prepare('UPDATE users SET pass_hash = ? WHERE id = ?')->execute([password_hash($pass, PASSWORD_DEFAULT), (int)$me['id']]);
                flash('<div class="note">Parola a fost schimbată.</div>');
            }
            redirect('admin.php?v=utilizatori');

        case 'user_add':
            $username = strtolower(trim((string)($_POST['username'] ?? '')));
            $name     = trim((string)($_POST['name'] ?? ''));
            $pass     = (string)($_POST['pass'] ?? '');
            $shown    = '';
            if ($pass === '') {
                $pass  = new_password();
                $shown = ' Parola: <code>' . h($pass) . '</code> — notează-o acum, nu se mai afișează.';
            }
            if (!preg_match('/^[a-z0-9._@-]{3,64}$/', $username)) {
                flash('<div class="note err">Numele de utilizator poate conține doar litere mici, cifre și . _ @ - (3–64 de caractere).</div>');
            } elseif ($p = password_problem($pass)) {
                flash('<div class="note err">' . h($p) . '</div>');
            } elseif (find_user($username)) {
                flash('<div class="note err">Există deja un utilizator „' . h($username) . '”.</div>');
            } else {
                db()->prepare('INSERT INTO users (username, name, pass_hash, created_at) VALUES (?, ?, ?, ?)')
                    ->execute([$username, $name, password_hash($pass, PASSWORD_DEFAULT), time()]);
                flash('<div class="note">Utilizatorul „' . h($username) . '” a fost creat.' . $shown . '</div>');
            }
            redirect('admin.php?v=utilizatori');

        case 'user_update':
            $id   = (int)($_POST['id'] ?? 0);
            $self = $id === (int)$me['id'];
            $name = trim((string)($_POST['name'] ?? ''));
            $act  = ($self || !empty($_POST['active'])) ? 1 : 0; // propriul cont rămâne activ
            $pass = (string)($_POST['pass'] ?? '');
            $uq   = db()->prepare('SELECT * FROM users WHERE id = ?');
            $uq->execute([$id]);
            if ($u = $uq->fetch()) {
                if ($pass !== '' && ($p = password_problem($pass))) {
                    flash('<div class="note err">' . h($p) . '</div>');
                    redirect('admin.php?v=utilizatori');
                }
                db()->prepare('UPDATE users SET name = ?, active = ?, pass_hash = ? WHERE id = ?')
                    ->execute([$name, $act, $pass !== '' ? password_hash($pass, PASSWORD_DEFAULT) : $u['pass_hash'], $id]);
                flash('<div class="note">Utilizatorul „' . h($u['username']) . '” a fost actualizat'
                    . ($pass !== '' ? '; parola a fost schimbată' : '') . '.</div>');
            }
            redirect('admin.php?v=utilizatori');

        case 'user_delete':
            $id = (int)($_POST['id'] ?? 0);
            if ($id === (int)$me['id']) {
                flash('<div class="note err">Nu îți poți șterge propriul cont.</div>');
            } else {
                db()->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
                flash('<div class="note">Utilizatorul a fost șters.</div>');
            }
            redirect('admin.php?v=utilizatori');
    }
}

// ---------------------------------------------------------------------------
// Componente comune
// ---------------------------------------------------------------------------
function ago(?int $ts): string
{
    if (!$ts) {
        return '—';
    }
    $d = time() - $ts;
    if ($d < 3600) {
        return max(1, intdiv($d, 60)) . ' min';
    }
    if ($d < 86400) {
        return intdiv($d, 3600) . ' h';
    }
    return intdiv($d, 86400) . ' z';
}

function human_size(int $b): string
{
    return $b >= 1048576 ? number_format($b / 1048576, 1, ',', '.') . ' MB' : max(1, (int)round($b / 1024)) . ' KB';
}

$csrf     = csrf();
$flashMsg = (string)($_SESSION['flash'] ?? '');
unset($_SESSION['flash']);

// ===========================================================================
// SECȚIUNEA PIPELINE
// ===========================================================================
function funnel_svg(array $reached): string
{
    $labels = [1 => 'Trimise', 2 => 'Deschise', 3 => 'În negociere', 4 => 'Acceptate'];
    $max    = max(1, $reached[1]);
    $W = 520; $rowH = 52; $gap = 6; $labelW = 150; $barMax = $W - $labelW - 70;
    $H = 4 * ($rowH + $gap);
    $svg = '<svg viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="Pâlnia ofertelor" class="funnel">';
    for ($k = 1; $k <= 4; $k++) {
        $y   = ($k - 1) * ($rowH + $gap);
        $w1  = max(8, $barMax * $reached[$k] / $max);
        $w2  = $k < 4 ? max(8, $barMax * $reached[$k + 1] / $max) : $w1;
        $cx  = $labelW + $barMax / 2;
        $pts = sprintf('%.1f,%d %.1f,%d %.1f,%d %.1f,%d',
            $cx - $w1 / 2, $y, $cx + $w1 / 2, $y, $cx + $w2 / 2, $y + $rowH, $cx - $w2 / 2, $y + $rowH);
        $pct = $k === 1 ? '' : ($reached[$k - 1] ? round(100 * $reached[$k] / $reached[$k - 1]) . '% din etapa anterioară' : '—');
        $svg .= '<polygon points="' . $pts . '" class="f' . $k . '"/>'
            . '<text x="0" y="' . ($y + 22) . '" class="fl">' . $labels[$k] . '</text>'
            . '<text x="0" y="' . ($y + 40) . '" class="fs">' . $pct . '</text>'
            . '<text x="' . $cx . '" y="' . ($y + $rowH / 2 + 6) . '" class="fv" text-anchor="middle">' . $reached[$k] . '</text>';
    }
    return $svg . '</svg>';
}

function deal_card(array $d, array $stages, array $links, string $csrf): string
{
    $id      = (int)$d['id'];
    $expired = (int)$d['expires_at'] < time();
    $flags   = '';
    if ($d['revoked']) {
        $flags .= '<span class="tag bad">linkuri revocate</span>';
    } elseif ($expired) {
        $flags .= '<span class="tag">linkuri expirate</span>';
    }
    if ((int)$d['inactiv']) {
        $flags .= '<span class="tag bad">inactiv ANAF</span>';
    }
    $cui   = (string)$d['cui'];
    $firma = '';
    if ($cui !== '') {
        $firma = '<div class="who">CUI ' . h($cui) . ((string)$d['reg_com'] !== '' ? ' · ' . h($d['reg_com']) : '') . '</div>';
    }
    $opts = '';
    foreach ($stages as $k => $s) {
        $opts .= '<option value="' . $k . '"' . ($k === $d['stage'] ? ' selected' : '') . '>' . $s['label'] . '</option>';
    }
    $sal = (int)$d['angajati'];
    $val = $sal ? '<div class="val"><span>' . $sal . ' salariați mobili</span><b>' . ron($sal * ONORARIU_AN1_PER_SALARIAT) . ' RON</b></div>' : '';

    $mats = '';
    foreach ($links as $l) {
        $mats .= '<li class="' . ((int)$l['opens'] ? 'seen' : '') . '"><span>' . h($l['title']) . '</span><em>'
            . ((int)$l['opens'] ? (int)$l['opens'] . '×' : 'necitit') . '</em></li>';
    }

    $log = db()->prepare('SELECT at, event FROM access_log WHERE deal_id = ? ORDER BY id DESC LIMIT 10');
    $log->execute([$id]);
    $ev = '';
    foreach ($log->fetchAll() as $l) {
        $ev .= '<li><span>' . h(fmt_time((int)$l['at'])) . '</span> ' . h(str_replace('etapa:', '→ ', $l['event'])) . '</li>';
    }
    $hid    = '<input type="hidden" name="csrf" value="' . h($csrf) . '"><input type="hidden" name="id" value="' . $id . '">';
    $revoke = $d['revoked'] ? '' : '<form method="post">' . $hid . '<input type="hidden" name="action" value="revoke">'
        . '<button class="lnk bad" type="submit">Revocă toate linkurile ofertei</button></form>';

    return '<article class="card" draggable="true" data-id="' . $id . '" id="c' . $id . '">'
        . '<header><strong>' . h($d['company'] ?: $d['email']) . '</strong><small>' . h(ago($d['stage_at'] ? (int)$d['stage_at'] : (int)$d['created_at'])) . '</small></header>'
        . '<div class="who">' . h($d['recipient'] ?: '—') . ' · ' . h($d['email']) . '</div>' . $firma
        . '<ul class="mats">' . $mats . '</ul>'
        . $val
        . ($flags ? '<div class="flags">' . $flags . '</div>' : '')
        . ($d['nota'] !== '' ? '<p class="nota">' . h($d['nota']) . '</p>' : '')
        . '<details><summary>Detalii</summary>'
        . '<p class="meta">Subiect: ' . h($d['subject']) . '</p>'
        . ($cui !== '' ? '<p class="meta">Firmă (ANAF): ' . h($d['adresa'] ?: '—') // adresa conține deja județul
            . ((string)$d['caen'] !== '' ? ' · CAEN ' . h($d['caen']) : '')
            . ((string)$d['telefon'] !== '' ? ' · tel. ' . h($d['telefon']) : '') . ' · ' . ((int)$d['tva'] ? 'plătitor TVA' : 'neplătitor TVA') . '</p>' : '')
        . '<form method="post" class="mv">' . $hid . '<input type="hidden" name="action" value="stage">'
        . '<label for="st' . $id . '">Etapă</label><select id="st' . $id . '" name="stage">' . $opts . '</select>'
        . '<button type="submit" class="sm">Mută</button></form>'
        . '<form method="post" class="mv">' . $hid . '<input type="hidden" name="action" value="nota">'
        . '<label for="n' . $id . '">Notă internă</label><input id="n' . $id . '" name="nota" value="' . h($d['nota']) . '" maxlength="500">'
        . '<button type="submit" class="sm">Salvează</button></form>'
        . '<p class="meta">Creată ' . h(fmt_time((int)$d['created_at'])) . ' · expiră ' . h(fmt_time((int)$d['expires_at']))
        . ' · ' . ($d['require_otp'] ? 'cu cod de acces' : 'fără cod') . '</p>'
        . '<ul class="log">' . ($ev ?: '<li>Fără evenimente</li>') . '</ul>' . $revoke
        . '</details></article>';
}

function view_pipeline(string $csrf): string
{
    $stages = stages();
    $deals  = db()->query('SELECT * FROM deals ORDER BY COALESCE(stage_at, created_at) DESC LIMIT 500')->fetchAll();
    $links  = [];
    foreach (db()->query('SELECT l.deal_id, l.opens, m.title FROM links l JOIN materials m ON m.id = l.material_id ORDER BY l.id')->fetchAll() as $l) {
        $links[(int)$l['deal_id']][] = $l;
    }

    $byStage  = array_fill_keys(array_keys($stages), []);
    $salStage = array_fill_keys(array_keys($stages), 0);
    $reached  = [1 => 0, 2 => 0, 3 => 0, 4 => 0];
    foreach ($deals as $d) {
        $byStage[$d['stage']][] = $d;
        $salStage[$d['stage']] += (int)$d['angajati'];
        for ($k = 1; $k <= min(4, (int)$d['reached']); $k++) {
            $reached[$k]++;
        }
    }
    $total     = count($deals);
    $active    = count($byStage['trimisa']) + count($byStage['deschisa']) + count($byStage['negociere']);
    $salActive = $salStage['trimisa'] + $salStage['deschisa'] + $salStage['negociere'];
    $salWon    = $salStage['acceptata'];
    $conv      = $reached[1] ? round(100 * $reached[4] / $reached[1]) : 0;
    $openRate  = $reached[1] ? round(100 * $reached[2] / $reached[1]) : 0;

    $board = '';
    foreach ($stages as $k => $s) {
        $cards = '';
        foreach ($byStage[$k] as $d) {
            $cards .= deal_card($d, $stages, $links[(int)$d['id']] ?? [], $csrf);
        }
        $sal = $salStage[$k];
        $board .= '<section class="col s-' . $k . '" data-stage="' . $k . '">'
            . '<h3><span>' . $s['label'] . '</span><em>' . count($byStage[$k]) . '</em></h3>'
            . '<p class="colsum">' . ($sal ? $sal . ' salariați · ' . ron($sal * ONORARIU_AN1_PER_SALARIAT) . ' RON' : '&nbsp;') . '</p>'
            . '<div class="drop">' . ($cards ?: '<p class="empty">Nicio ofertă</p>') . '</div></section>';
    }

    // Formular ofertă nouă: materialele se bifează automat după șablonul ales.
    $templates = db()->query('SELECT id, name FROM templates ORDER BY name')->fetchAll();
    $materials = db()->query('SELECT id, title, kind FROM materials WHERE active = 1 ORDER BY kind, title')->fetchAll();
    $tplMap    = [];
    $tplOpts   = '';
    foreach ($templates as $t) {
        $tplMap[(int)$t['id']] = array_map(fn($m) => (int)$m['id'], template_materials((int)$t['id']));
        $tplOpts .= '<option value="' . (int)$t['id'] . '">' . h($t['name']) . '</option>';
    }
    $matChecks = '';
    foreach ($materials as $m) {
        $matChecks .= '<label><input type="checkbox" name="materials[]" value="' . (int)$m['id'] . '"> ' . h($m['title'])
            . ' <span class="kind k-' . h($m['kind']) . '">' . ($m['kind'] === 'oferta' ? 'ofertă' : 'suport') . '</span></label>';
    }
    $form = $templates
        ? '<form method="post" class="newf" id="newDeal">' . csrf_field() . '<input type="hidden" name="action" value="create_deal">'
          . '<div class="g"><div><label for="cui">CUI</label><div class="cuirow"><input id="cui" name="cui" inputmode="numeric" autocomplete="off" placeholder="ex. 1590082">'
          . '<button type="button" class="sm" id="cuiBtn" title="Preia datele firmei de la ANAF">ANAF</button></div></div>'
          . '<div><label for="company">Companie</label><input id="company" name="company" required></div>'
          . '<div><label for="recipient">Persoană de contact</label><input id="recipient" name="recipient"></div>'
          . '<div><label for="email">Email</label><input id="email" name="email" type="email" required></div>'
          . '<div><label for="angajati">Salariați mobili (estimat)</label><input id="angajati" name="angajati" type="number" min="0" value="0"></div>'
          . '<div><label for="days">Linkuri valabile (zile)</label><input id="days" name="days" type="number" min="1" max="365" value="' . (int)cfg('default_days') . '"></div>'
          . '<div class="wide"><label for="template_id">Șablon email</label><select id="template_id" name="template_id">' . $tplOpts . '</select></div></div>'
          . '<p class="anaf" id="anafInfo" aria-live="polite"></p>'
          . '<input type="hidden" name="reg_com" id="f_reg_com"><input type="hidden" name="adresa" id="f_adresa"><input type="hidden" name="judet" id="f_judet">'
          . '<input type="hidden" name="caen" id="f_caen"><input type="hidden" name="telefon" id="f_telefon"><input type="hidden" name="tva" id="f_tva" value="0"><input type="hidden" name="inactiv" id="f_inactiv" value="0">'
          . '<fieldset class="mats-pick"><legend>Materiale trimise</legend>' . ($matChecks ?: '<p class="muted">Nu există materiale active.</p>') . '</fieldset>'
          . '<div class="chks"><label><input type="checkbox" name="require_otp" value="1" checked> Cod de acces pe email</label>'
          . '<label><input type="checkbox" name="send" value="1" checked> Trimite acum emailul</label></div>'
          . '<button type="submit">Creează și trimite oferta</button>'
          . '<script type="application/json" id="tplMap">' . json_encode($tplMap) . '</script></form>'
        : '<p class="muted">Creează mai întâi un șablon de email în secțiunea „Șabloane email”.</p>';

    return '<section class="kpis" aria-label="Indicatori">'
        . '<div class="kpi"><span>Oferte în lucru</span><b>' . $active . '</b><small>din ' . $total . ' trimise</small></div>'
        . '<div class="kpi"><span>Rată de deschidere</span><b>' . $openRate . '%</b><small>' . $reached[2] . ' din ' . $reached[1] . ' oferte</small></div>'
        . '<div class="kpi"><span>Conversie</span><b>' . $conv . '%</b><small>' . $reached[4] . ' acceptate</small></div>'
        . '<div class="kpi pipe"><span>Pipeline, onorariu an 1</span><b>' . ron($salActive * ONORARIU_AN1_PER_SALARIAT) . ' RON</b><small>' . $salActive . ' salariați mobili în lucru</small></div>'
        . '<div class="kpi won"><span>Câștigat, onorariu an 1</span><b>' . ron($salWon * ONORARIU_AN1_PER_SALARIAT) . ' RON</b><small>apoi ' . ron($salWon * LICENTA_AN_PER_SALARIAT) . ' RON/an licență</small></div>'
        . '<div class="kpi"><span>Economie anuală generată clienților</span><b>' . ron($salWon * ECONOMIE_AN_PER_SALARIAT) . ' RON</b><small>la ofertele acceptate</small></div>'
        . '</section>'
        . '<div class="row2"><section class="panel"><h2>Pâlnia ofertelor</h2>' . funnel_svg($reached)
        . '<p class="fnote">Etapa maximă atinsă de fiecare ofertă; ofertele pierdute rămân numărate la etapa la care au ajuns. Pierdute: ' . count($byStage['pierduta']) . '.</p></section>'
        . '<section class="panel"><h2>Ofertă nouă</h2>' . $form . '</section></div>'
        . '<section class="panel"><h2>Pipeline</h2><p class="muted" style="margin:-6px 0 10px">Trage cardurile între coloane. „Deschisă” se setează automat la prima deschidere a unui material.</p>'
        . '<div class="boardwrap"><div class="board">' . $board . '</div></div></section>';
}

// ===========================================================================
// SECȚIUNEA ȘABLOANE EMAIL
// ===========================================================================
function view_templates(): string
{
    $templates = db()->query('SELECT t.*, (SELECT COUNT(*) FROM deals d WHERE d.template_id = t.id) AS used FROM templates t ORDER BY t.name')->fetchAll();
    $selId     = (string)($_GET['id'] ?? ($templates[0]['id'] ?? 'new'));
    $tpl       = null;
    foreach ($templates as $t) {
        if ((string)$t['id'] === $selId) {
            $tpl = $t;
        }
    }
    if (!$tpl) {
        $tpl = ['id' => 0, 'name' => '', 'subject' => '', 'body' => default_template_body(), 'used' => 0];
    }

    $list = '';
    foreach ($templates as $t) {
        $list .= '<a class="tli' . ((int)$t['id'] === (int)$tpl['id'] ? ' on' : '') . '" href="admin.php?v=sabloane&id=' . (int)$t['id'] . '">'
            . '<b>' . h($t['name']) . '</b><span>' . h($t['subject']) . '</span><small>' . (int)$t['used'] . ' oferte trimise · actualizat ' . h(fmt_time((int)$t['updated_at'])) . '</small></a>';
    }
    $list .= '<a class="tli add' . ((int)$tpl['id'] === 0 ? ' on' : '') . '" href="admin.php?v=sabloane&id=new">+ Șablon nou</a>';

    // Materialele șablonului, cu ordinea lor
    $sel = [];
    if ($tpl['id']) {
        foreach (template_materials((int)$tpl['id']) as $i => $m) {
            $sel[(int)$m['id']] = $i + 1;
        }
    }
    $matRows  = '';
    $previewM = [];
    foreach (db()->query('SELECT * FROM materials WHERE active = 1 ORDER BY kind, title')->fetchAll() as $m) {
        $mid = (int)$m['id'];
        $on  = isset($sel[$mid]);
        $matRows .= '<div class="mrow"><label><input type="checkbox" name="mat[' . $mid . ']" value="1"' . ($on ? ' checked' : '') . '> '
            . h($m['title']) . ' <span class="kind k-' . h($m['kind']) . '">' . ($m['kind'] === 'oferta' ? 'ofertă' : 'suport') . '</span></label>'
            . '<input class="ord" type="number" name="ord[' . $mid . ']" value="' . ($sel[$mid] ?? 0) . '" min="0" max="99" aria-label="Ordine ' . h($m['title']) . '"></div>';
        if ($on) {
            $previewM[$sel[$mid]] = ['title' => $m['title'], 'url' => base_url() . str_repeat('x', 43)];
        }
    }
    ksort($previewM);
    $sample = ['company' => 'EXEMPLU CONSTRUCT SRL', 'recipient' => 'Ion Popescu', 'email' => 'ion.popescu@exemplu.ro',
               'angajati' => 10, 'expires_at' => time() + 30 * 86400];
    [$pSubj, $pHtml] = render_email((string)$tpl['subject'], (string)$tpl['body'], $sample, array_values($previewM));
    $preview = '<!doctype html><meta charset="utf-8"><body style="margin:0;padding:18px;background:#fff">'
        . '<div style="font:13px Arial;color:#5b6672;border-bottom:1px solid #ddd;padding-bottom:8px;margin-bottom:14px">'
        . '<b>Către:</b> Ion Popescu &lt;ion.popescu@exemplu.ro&gt;<br><b>Subiect:</b> ' . h($pSubj) . '</div>' . $pHtml . '</body>';

    $hid  = csrf_field() . '<input type="hidden" name="id" value="' . (int)$tpl['id'] . '">';
    $side = $tpl['id']
        ? '<form method="post" class="inline">' . $hid . '<input type="hidden" name="action" value="tpl_copy"><button class="sm" type="submit">Copiază</button></form>'
          . '<form method="post" class="inline">' . $hid . '<input type="hidden" name="action" value="tpl_delete"><button class="sm bad" type="submit">Șterge</button></form>'
        : '';

    return '<div class="tplgrid"><aside class="panel tlist"><h2>Șabloane email</h2>' . $list . '</aside>'
        . '<section class="panel"><div class="ph"><h2>' . ($tpl['id'] ? 'Editează: ' . h($tpl['name']) : 'Șablon nou') . '</h2><div class="acts">' . $side . '</div></div>'
        . '<form method="post" class="tplf">' . $hid . '<input type="hidden" name="action" value="tpl_save">'
        . '<div class="g2"><div><label for="name">Nume intern</label><input id="name" name="name" value="' . h($tpl['name']) . '" required></div>'
        . '<div><label for="subject">Subiect email</label><input id="subject" name="subject" value="' . h($tpl['subject']) . '" required></div></div>'
        . '<label for="body">Textul emailului</label><textarea id="body" name="body" rows="24" spellcheck="true">' . h($tpl['body']) . '</textarea>'
        . '<div class="help"><b>Format:</b> rând gol = paragraf nou · rânduri consecutive = listă compactă · <code>✓ </code> la început = bifă colorată · <code>**text**</code> = bold.<br>'
        . '<b>Variabile:</b> <code>{{salut}}</code> <code>{{nume}}</code> <code>{{companie}}</code> <code>{{email}}</code> <code>{{salariati}}</code> <code>{{economie_an}}</code> <code>{{expira}}</code> · '
        . '<code>{{materiale}}</code> pe rând separat = butoanele cu linkurile personale.</div>'
        . '<fieldset class="mats-pick"><legend>Materiale trimise cu acest șablon (bifă + ordine)</legend>' . ($matRows ?: '<p class="muted">Încarcă materiale în secțiunea „Materiale”.</p>') . '</fieldset>'
        . '<button type="submit" class="pri">Salvează șablonul</button></form></section>'
        . '<section class="panel prev"><h2>Previzualizare</h2><p class="muted">Cu date de exemplu, după ultima salvare.</p>'
        . '<iframe title="Previzualizare email" sandbox srcdoc="' . h($preview) . '"></iframe></section></div>';
}

// ===========================================================================
// SECȚIUNEA MATERIALE
// ===========================================================================
function view_materials(): string
{
    $rows = db()->query('SELECT m.*,
            (SELECT COUNT(*) FROM template_materials tm WHERE tm.material_id = m.id) AS in_tpl,
            (SELECT COUNT(*) FROM links l WHERE l.material_id = m.id) AS sent,
            (SELECT COALESCE(SUM(l.opens), 0) FROM links l WHERE l.material_id = m.id) AS opened
            FROM materials m ORDER BY m.active DESC, m.kind, m.title')->fetchAll();
    $maxMb = (int)(cfg('max_upload_mb') ?: 25);
    $tbl   = '';
    foreach ($rows as $m) {
        $id   = (int)$m['id'];
        $path = safe_file_path((string)$m['file']);
        $ext  = strtoupper(pathinfo((string)$m['file'], PATHINFO_EXTENSION));
        $tbl .= '<form method="post" enctype="multipart/form-data" class="mat' . ($m['active'] ? '' : ' off') . '">' . csrf_field()
            . '<input type="hidden" name="action" value="mat_update"><input type="hidden" name="id" value="' . $id . '">'
            . '<div class="mt"><input name="title" value="' . h($m['title']) . '" aria-label="Titlu">'
            . '<small><span class="ext">' . h($ext) . '</span> ' . h($m['orig_name'] ?: $m['file']) . ' · '
            . ($path ? h(human_size((int)filesize($path))) : '<span class="err">fișier lipsă</span>')
            . ' · actualizat ' . h(fmt_time((int)$m['updated_at'])) . '</small></div>'
            . '<select name="kind" aria-label="Tip"><option value="oferta"' . ($m['kind'] === 'oferta' ? ' selected' : '') . '>Ofertă</option>'
            . '<option value="suport"' . ($m['kind'] === 'suport' ? ' selected' : '') . '>Material suport</option></select>'
            . '<div class="stats"><b>' . (int)$m['in_tpl'] . '</b> șabloane<br><b>' . (int)$m['sent'] . '</b> trimiteri · <b>' . (int)$m['opened'] . '</b> deschideri</div>'
            . '<label class="rep">Înlocuiește fișierul<input type="file" name="file"></label>'
            . '<label class="act"><input type="checkbox" name="active" value="1"' . ($m['active'] ? ' checked' : '') . '> Activ</label>'
            . '<button type="submit" class="sm">Salvează</button></form>';
    }

    return '<section class="panel"><h2>Încarcă material</h2>'
        . '<form method="post" enctype="multipart/form-data" class="up">' . csrf_field() . '<input type="hidden" name="action" value="mat_upload">'
        . '<div><label for="mtitle">Titlu afișat în email</label><input id="mtitle" name="title" placeholder="ex. Studiu de caz construcții"></div>'
        . '<div><label for="mkind">Tip</label><select id="mkind" name="kind"><option value="suport">Material suport</option><option value="oferta">Ofertă</option></select></div>'
        . '<div><label for="mfile">Fișier (max. ' . $maxMb . ' MB)</label><input id="mfile" type="file" name="file" required accept=".pdf,.html,.htm,.docx,.xlsx,.pptx,.png,.jpg,.jpeg,.mp4"></div>'
        . '<button type="submit" class="pri">Încarcă</button></form>'
        . '<p class="muted">Fișierele se salvează în ofertare_private/fisiere și sunt servite doar prin linkurile personale.</p></section>'
        . '<section class="panel"><h2>Materiale</h2>'
        . '<p class="muted" style="margin:-6px 0 12px">Înlocuirea fișierului păstrează linkurile deja trimise: clienții deschid noua versiune. Un material dezactivat nu mai poate fi deschis și nu mai apare în șabloane.</p>'
        . '<div class="matlist">' . ($tbl ?: '<p class="muted">Niciun material încărcat.</p>') . '</div></section>';
}

// ===========================================================================
// SECȚIUNEA UTILIZATORI (parola mea, utilizator nou, lista cu nume / activ / resetare parolă / ștergere)
// ===========================================================================
function view_users(array $me): string
{
    $rows = db()->query('SELECT * FROM users ORDER BY active DESC, username')->fetchAll();
    $list = '';
    foreach ($rows as $u) {
        $id   = (int)$u['id'];
        $self = $id === (int)$me['id'];
        $list .= '<form method="post" class="usr' . ($u['active'] ? '' : ' off') . '">' . csrf_field()
            . '<input type="hidden" name="id" value="' . $id . '">'
            . '<div class="mt"><b class="un">' . h($u['username']) . ($self ? ' <span class="tag">eu</span>' : '') . '</b>'
            . '<small>creat ' . h(fmt_time((int)$u['created_at'])) . ' · ultima autentificare '
            . h(fmt_time($u['last_login'] !== null ? (int)$u['last_login'] : null)) . '</small></div>'
            . '<input name="name" value="' . h($u['name']) . '" aria-label="Nume" placeholder="Nume">'
            . '<input name="pass" type="password" autocomplete="new-password" minlength="10" aria-label="Parolă nouă" placeholder="Parolă nouă (opțional)">'
            . '<label class="act"><input type="checkbox" name="active" value="1"' . ($u['active'] ? ' checked' : '') . ($self ? ' disabled' : '') . '> Activ</label>'
            . '<button type="submit" class="sm" name="action" value="user_update">Salvează</button>'
            . ($self ? '<span></span>' : '<button type="submit" class="sm bad" name="action" value="user_delete">Șterge</button>')
            . '</form>';
    }

    return '<div class="row2">'
        . '<section class="panel"><h2>Parola mea</h2>'
        . '<form method="post" class="pwf">' . csrf_field() . '<input type="hidden" name="action" value="pw_change">'
        . '<div><label for="pw0">Parola actuală</label><input id="pw0" name="current" type="password" autocomplete="current-password" required></div>'
        . '<div><label for="pw1">Parola nouă (min. 10 caractere)</label><input id="pw1" name="pass" type="password" autocomplete="new-password" minlength="10" required></div>'
        . '<div><label for="pw2">Repetă parola nouă</label><input id="pw2" name="confirm" type="password" autocomplete="new-password" minlength="10" required></div>'
        . '<button type="submit" class="pri">Schimbă parola</button></form></section>'
        . '<section class="panel"><h2>Utilizator nou</h2>'
        . '<form method="post" class="pwf">' . csrf_field() . '<input type="hidden" name="action" value="user_add">'
        . '<div><label for="nu">Utilizator (litere mici, cifre, . _ @ -)</label><input id="nu" name="username" pattern="[a-z0-9._@-]{3,64}" autocomplete="off" required></div>'
        . '<div><label for="nn">Nume</label><input id="nn" name="name" placeholder="ex. Ana Pop"></div>'
        . '<div><label for="np">Parolă (gol = generată automat, afișată o singură dată)</label><input id="np" name="pass" type="password" autocomplete="new-password" minlength="10"></div>'
        . '<button type="submit" class="pri">Adaugă utilizatorul</button></form></section></div>'
        . '<section class="panel"><h2>Utilizatori</h2>'
        . '<p class="muted" style="margin:-6px 0 12px">Un utilizator dezactivat nu se mai poate autentifica. Parola nouă se aplică doar dacă completezi câmpul.'
        . ' Nu îți poți dezactiva sau șterge propriul cont. Contul din <code>config.php</code> rămâne cale de recuperare: intră doar dacă nu există în listă un utilizator cu același nume.</p>'
        . '<div class="matlist">' . $list . '</div></section>';
}

// ===========================================================================
// SECȚIUNEA RAPORTARE (tabel smartBIZ: căutare, filtre, sortare, grupare, totaluri, export CSV)
// ===========================================================================
function view_report(): string
{
    $stages = stages();
    $rows   = db()->query("
        SELECT d.*, t.name AS template_name,
               (SELECT COUNT(*) FROM links l WHERE l.deal_id = d.id) AS mat_sent,
               (SELECT COUNT(*) FROM links l WHERE l.deal_id = d.id AND l.opens > 0) AS mat_opened,
               (SELECT GROUP_CONCAT(m.title, ' · ') FROM links l JOIN materials m ON m.id = l.material_id WHERE l.deal_id = d.id) AS mat_titles
        FROM deals d LEFT JOIN templates t ON t.id = d.template_id
        ORDER BY d.created_at DESC")->fetchAll();

    $now  = time();
    $data = [];
    foreach ($rows as $r) {
        $sal    = (int)$r['angajati'];
        $status = $r['revoked'] ? 'revocat' : ((int)$r['expires_at'] < $now ? 'expirat' : 'activ');
        $data[] = [
            'id'        => (int)$r['id'],
            'companie'  => (string)$r['company'],
            'cui'       => (string)$r['cui'],
            'reg_com'   => (string)$r['reg_com'],
            'adresa'    => (string)$r['adresa'],
            'judet'     => (string)$r['judet'],
            'caen'      => (string)$r['caen'],
            'tva'       => (string)$r['cui'] !== '' ? ((int)$r['tva'] ? 'da' : 'nu') : '',
            'inactiv'   => (int)$r['inactiv'] ? 'da' : '',
            'persoana'  => (string)$r['recipient'],
            'email'     => (string)$r['email'],
            'sablon'    => (string)($r['template_name'] ?? '—'),
            'materiale' => (string)$r['mat_titles'],
            'mat'       => (int)$r['mat_opened'] . '/' . (int)$r['mat_sent'],
            'etapa'     => $stages[$r['stage']]['label'] ?? $r['stage'],
            'etapa_k'   => (string)$r['stage'],
            'max'       => [1 => 'Trimisă', 2 => 'Deschisă', 3 => 'În negociere', 4 => 'Acceptată'][(int)$r['reached']] ?? '—',
            'creata'    => date('Y-m-d', (int)$r['created_at']),
            'luna'      => date('Y-m', (int)$r['created_at']),
            'deschisa'  => $r['first_open'] ? date('Y-m-d', (int)$r['first_open']) : '',
            'zile'      => $r['first_open'] ? round(((int)$r['first_open'] - (int)$r['created_at']) / 86400, 1) : null,
            'ultima'    => $r['last_open'] ? date('Y-m-d H:i', (int)$r['last_open']) : '',
            'deschideri'=> (int)$r['opens'],
            'salariati' => $sal,
            'onorariu'  => $sal * ONORARIU_AN1_PER_SALARIAT,
            'licenta'   => $sal * LICENTA_AN_PER_SALARIAT,
            'economie'  => $sal * ECONOMIE_AN_PER_SALARIAT,
            'link'      => $status,
            'expira'    => date('Y-m-d', (int)$r['expires_at']),
            'nota'      => (string)$r['nota'],
        ];
    }
    $tplNames = array_values(array_unique(array_map(fn($d) => $d['sablon'], $data)));
    sort($tplNames);

    $stageOpts = '';
    foreach ($stages as $k => $s) {
        $stageOpts .= '<option value="' . $k . '">' . $s['label'] . '</option>';
    }
    $tplOpts = '';
    foreach ($tplNames as $t) {
        $tplOpts .= '<option>' . h($t) . '</option>';
    }

    return '<section class="panel rep">'
        . '<div class="ph"><h2>Raportare oferte</h2><div class="acts">'
        . '<button type="button" class="sm" id="rReset">Resetează vederea</button>'
        . '<button type="button" class="pri sm2" id="rCsv">Export CSV (Excel)</button></div></div>'
        . '<div class="rtools">'
        . '<div class="rsearch"><label for="rQ">Caută</label><input id="rQ" type="search" placeholder="companie, CUI, persoană, email, notă…"></div>'
        . '<div><label for="rStage">Etapă</label><select id="rStage"><option value="">Toate</option>' . $stageOpts . '</select></div>'
        . '<div><label for="rTpl">Șablon</label><select id="rTpl"><option value="">Toate</option>' . $tplOpts . '</select></div>'
        . '<div><label for="rLink">Link</label><select id="rLink"><option value="">Toate</option><option>activ</option><option>expirat</option><option>revocat</option></select></div>'
        . '<div><label for="rFrom">Creată de la</label><input id="rFrom" type="date"></div>'
        . '<div><label for="rTo">până la</label><input id="rTo" type="date"></div>'
        . '<div><label for="rGroup">Grupare</label><select id="rGroup"><option value="">Fără</option><option value="etapa">Etapă</option><option value="judet">Județ</option>'
        . '<option value="sablon">Șablon</option><option value="luna">Luna creării</option><option value="link">Stare link</option></select></div>'
        . '<details class="rcols"><summary>Coloane</summary><div id="rCols"></div></details>'
        . '</div>'
        . '<div class="rsum" id="rSum" aria-live="polite"></div>'
        . '<div class="rtw"><table class="rt" id="rTable"><thead></thead><tbody></tbody><tfoot></tfoot></table></div>'
        . '<p class="muted">Valori în RON fără TVA. Onorariu an 1 = implementare + licență; economia client = 10.368 RON/an per salariat mobil.'
        . ' Exportul conține rândurile filtrate, în ordinea și cu coloanele afișate.</p>'
        . '<script type="application/json" id="repData">' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) . '</script>'
        . '<script>' . report_js() . '</script>'
        . '</section>';
}

function report_js(): string
{
    return <<<'JS'
(function () {
  var data = JSON.parse(document.getElementById('repData').textContent);
  var COLS = [
    { k: 'id',         t: 'Nr.',               n: true },
    { k: 'companie',   t: 'Companie' },
    { k: 'cui',        t: 'CUI' },
    { k: 'reg_com',    t: 'Nr. Reg. Com.',      off: true },
    { k: 'adresa',     t: 'Adresă',             off: true },
    { k: 'judet',      t: 'Județ',              off: true },
    { k: 'caen',       t: 'CAEN',               off: true },
    { k: 'tva',        t: 'Plătitor TVA',       off: true },
    { k: 'inactiv',    t: 'Inactiv ANAF',       off: true },
    { k: 'persoana',   t: 'Persoană' },
    { k: 'email',      t: 'Email',             off: true },
    { k: 'sablon',     t: 'Șablon' },
    { k: 'mat',        t: 'Materiale citite' },
    { k: 'materiale',  t: 'Materiale trimise', off: true },
    { k: 'etapa',      t: 'Etapă',             chip: true },
    { k: 'max',        t: 'Etapă maximă',      off: true },
    { k: 'creata',     t: 'Creată' },
    { k: 'deschisa',   t: 'Prima deschidere' },
    { k: 'zile',       t: 'Zile până la deschidere', n: true, dec: 1, avg: true },
    { k: 'ultima',     t: 'Ultima deschidere', off: true },
    { k: 'deschideri', t: 'Deschideri',        n: true, sum: true },
    { k: 'salariati',  t: 'Salariați mobili',  n: true, sum: true },
    { k: 'onorariu',   t: 'Onorariu an 1',     n: true, sum: true },
    { k: 'licenta',    t: 'Licență / an',      n: true, sum: true, off: true },
    { k: 'economie',   t: 'Economie client / an', n: true, sum: true },
    { k: 'link',       t: 'Link' },
    { k: 'expira',     t: 'Expiră',            off: true },
    { k: 'nota',       t: 'Notă',              off: true }
  ];
  var KEY = 'ofertare_raport_v2'; // v2: coloanele firmei (ANAF)
  var def = { q: '', stage: '', tpl: '', link: '', from: '', to: '', group: '', sort: 'id', dir: -1,
              hidden: COLS.filter(function (c) { return c.off; }).map(function (c) { return c.k; }) };
  var st = Object.assign({}, def);
  try { var saved = JSON.parse(localStorage.getItem(KEY) || 'null'); if (saved) { st = Object.assign(st, saved); } } catch (e) {}

  var $ = function (id) { return document.getElementById(id); };
  var fmtN = new Intl.NumberFormat('ro-RO', { maximumFractionDigits: 0 });
  var fmt1 = new Intl.NumberFormat('ro-RO', { minimumFractionDigits: 1, maximumFractionDigits: 1 });
  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); };
  var cell = function (c, v) {
    if (v === null || v === undefined || v === '') { return c.n ? '' : '—'; }
    if (c.n) { return c.dec ? fmt1.format(v) : fmtN.format(v); }
    return String(v);
  };
  var visible = function () { return COLS.filter(function (c) { return st.hidden.indexOf(c.k) === -1; }); };
  var save = function () { try { localStorage.setItem(KEY, JSON.stringify(st)); } catch (e) {} };

  function filtered() {
    var q = st.q.trim().toLowerCase();
    var rows = data.filter(function (r) {
      if (st.stage && r.etapa_k !== st.stage) { return false; }
      if (st.tpl && r.sablon !== st.tpl) { return false; }
      if (st.link && r.link !== st.link) { return false; }
      if (st.from && r.creata < st.from) { return false; }
      if (st.to && r.creata > st.to) { return false; }
      if (q && [r.companie, r.cui, r.reg_com, r.adresa, r.judet, r.persoana, r.email, r.nota, r.sablon, r.materiale].join(' ').toLowerCase().indexOf(q) === -1) { return false; }
      return true;
    });
    var col = COLS.filter(function (c) { return c.k === st.sort; })[0] || COLS[0];
    rows.sort(function (a, b) {
      var x = a[col.k], y = b[col.k];
      if (x === y) { return 0; }
      if (x === null || x === '') { return 1; }
      if (y === null || y === '') { return -1; }
      return (col.n ? x - y : String(x).localeCompare(String(y), 'ro')) * st.dir;
    });
    return rows;
  }

  function totals(rows) {
    var t = {};
    COLS.forEach(function (c) {
      if (c.sum) { t[c.k] = rows.reduce(function (s, r) { return s + (r[c.k] || 0); }, 0); }
      if (c.avg) {
        var v = rows.map(function (r) { return r[c.k]; }).filter(function (x) { return x !== null; });
        t[c.k] = v.length ? v.reduce(function (s, x) { return s + x; }, 0) / v.length : null;
      }
    });
    return t;
  }

  // Rând de total / subtotal: eticheta ocupă coloanele dinaintea primei coloane cu valori agregate.
  function totalRow(rows, label, cls) {
    var t = totals(rows), cols = visible();
    var span = 0;
    while (span < cols.length && !cols[span].sum && !cols[span].avg) { span++; }
    span = Math.max(1, span);
    var html = '<tr class="' + cls + '"><td colspan="' + span + '"><b>' + esc(label) + '</b> <span class="cnt">' + rows.length + '</span></td>';
    cols.slice(span).forEach(function (c) {
      if (c.sum || c.avg) { html += '<td class="n">' + (t[c.k] === null ? '' : (c.avg ? '⌀ ' : '') + cell(c, t[c.k])) + '</td>'; }
      else { html += '<td></td>'; }
    });
    return html + '</tr>';
  }

  function render() {
    var rows = filtered(), cols = visible();
    $('rTable').tHead.innerHTML = '<tr>' + cols.map(function (c) {
      var arrow = st.sort === c.k ? (st.dir > 0 ? ' ▲' : ' ▼') : '';
      return '<th class="' + (c.n ? 'n' : '') + '" data-k="' + c.k + '" tabindex="0" aria-sort="' + (st.sort === c.k ? (st.dir > 0 ? 'ascending' : 'descending') : 'none') + '">' + esc(c.t) + arrow + '</th>';
    }).join('') + '</tr>';

    var body = '';
    var rowHtml = function (r) {
      return '<tr>' + cols.map(function (c) {
        var v = cell(c, r[c.k]);
        if (c.chip) { return '<td><span class="chip s-' + esc(r.etapa_k) + '">' + esc(v) + '</span></td>'; }
        if (c.k === 'link') { return '<td><span class="lk l-' + esc(r.link) + '">' + esc(v) + '</span></td>'; }
        if (c.k === 'companie') { return '<td><a href="admin.php?v=pipeline#c' + r.id + '">' + esc(v) + '</a></td>'; }
        return '<td class="' + (c.n ? 'n' : (c.k === 'nota' || c.k === 'materiale' ? 'wrap' : '')) + '">' + esc(v) + '</td>';
      }).join('') + '</tr>';
    };
    if (st.group) {
      var groups = {}, order = [];
      rows.forEach(function (r) {
        var g = st.group === 'etapa' ? r.etapa : r[st.group];
        if (!groups[g]) { groups[g] = []; order.push(g); }
        groups[g].push(r);
      });
      order.forEach(function (g) {
        body += totalRow(groups[g], g || '—', 'grp');
        body += groups[g].map(rowHtml).join('');
      });
    } else {
      body = rows.map(rowHtml).join('');
    }
    $('rTable').tBodies[0].innerHTML = body || '<tr><td colspan="' + cols.length + '" class="empty">Nicio ofertă pentru filtrele alese.</td></tr>';
    $('rTable').tFoot.innerHTML = rows.length ? totalRow(rows, 'Total', 'tot') : '';

    var t = totals(rows);
    var acc = rows.filter(function (r) { return r.etapa_k === 'acceptata'; }).length;
    var opened = rows.filter(function (r) { return r.deschisa; }).length;
    $('rSum').innerHTML =
      '<span><b>' + rows.length + '</b> oferte</span>' +
      '<span><b>' + (rows.length ? Math.round(100 * opened / rows.length) : 0) + '%</b> deschise</span>' +
      '<span><b>' + acc + '</b> acceptate</span>' +
      '<span><b>' + fmtN.format(t.salariati || 0) + '</b> salariați mobili</span>' +
      '<span><b>' + fmtN.format(t.onorariu || 0) + ' RON</b> onorariu an 1</span>' +
      '<span><b>' + fmtN.format(t.economie || 0) + ' RON</b> economie client / an</span>';
    save();
  }

  function sync() {
    $('rQ').value = st.q; $('rStage').value = st.stage; $('rTpl').value = st.tpl; $('rLink').value = st.link;
    $('rFrom').value = st.from; $('rTo').value = st.to; $('rGroup').value = st.group;
    $('rCols').innerHTML = COLS.map(function (c) {
      return '<label><input type="checkbox" data-k="' + c.k + '"' + (st.hidden.indexOf(c.k) === -1 ? ' checked' : '') + '> ' + esc(c.t) + '</label>';
    }).join('');
  }

  [['rQ', 'q', 'input'], ['rStage', 'stage', 'change'], ['rTpl', 'tpl', 'change'], ['rLink', 'link', 'change'],
   ['rFrom', 'from', 'change'], ['rTo', 'to', 'change'], ['rGroup', 'group', 'change']].forEach(function (b) {
    $(b[0]).addEventListener(b[2], function (e) { st[b[1]] = e.target.value; render(); });
  });
  $('rCols').addEventListener('change', function (e) {
    var k = e.target.dataset.k;
    st.hidden = e.target.checked ? st.hidden.filter(function (x) { return x !== k; }) : st.hidden.concat([k]);
    render();
  });
  var sortBy = function (th) {
    if (!th || !th.dataset.k) { return; }
    if (st.sort === th.dataset.k) { st.dir = -st.dir; } else { st.sort = th.dataset.k; st.dir = 1; }
    render();
  };
  $('rTable').tHead.addEventListener('click', function (e) { sortBy(e.target.closest('th')); });
  $('rTable').tHead.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); sortBy(e.target.closest('th')); } });
  $('rReset').addEventListener('click', function () { st = JSON.parse(JSON.stringify(def)); sync(); render(); });

  $('rCsv').addEventListener('click', function () {
    var cols = visible(), rows = filtered();
    var q = function (v) { v = v === null || v === undefined ? '' : String(v); return /[;"\n]/.test(v) ? '"' + v.replace(/"/g, '""') + '"' : v; };
    var num = function (c, v) { return v === null || v === undefined || v === '' ? '' : (c.dec ? String(v).replace('.', ',') : String(v)); };
    var lines = [cols.map(function (c) { return q(c.t); }).join(';')];
    rows.forEach(function (r) { lines.push(cols.map(function (c) { return c.n ? num(c, r[c.k]) : q(r[c.k]); }).join(';')); });
    var t = totals(rows);
    lines.push(cols.map(function (c, i) { return i === 0 ? 'Total ' + rows.length : (c.sum ? String(t[c.k]) : ''); }).join(';'));
    var blob = new Blob(['﻿' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8' });
    var a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'raport_ofertare_' + new Date().toISOString().slice(0, 10) + '.csv';
    document.body.appendChild(a); a.click(); a.remove();
    setTimeout(function () { URL.revokeObjectURL(a.href); }, 1000);
  });

  sync();
  render();
})();
JS;
}

// ===========================================================================
// Randare pagină
// ===========================================================================
$views   = [
    'pipeline'  => ['Pipeline',       fn() => view_pipeline($csrf)],
    'raportare' => ['Raportare',      fn() => view_report()],
    'sabloane'  => ['Șabloane email', fn() => view_templates()],
    'materiale' => ['Materiale',      fn() => view_materials()],
    'utilizatori' => ['Utilizatori',  fn() => view_users($me)],
];
$content = $views[$view][1]();
$tabs    = array_map(fn($v) => $v[0], $views);
$nav     = '';
foreach ($tabs as $k => $label) {
    $nav .= '<a href="admin.php?v=' . $k . '"' . ($k === $view ? ' aria-current="page"' : '') . '>' . $label . '</a>';
}

security_headers();
header('Content-Type: text/html; charset=utf-8');
?><!doctype html>
<html lang="ro">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>e2e OPS Ofertare</title>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500&display=swap">
<style>
/* Cockpit: bară smartBIZ cu secțiuni, apoi conținutul secțiunii (pipeline / șabloane / materiale) */
:root{
  --bg:#f5f6f7;--surface:#ffffff;--ink:#1b2430;--muted:#5b6672;--line:#d9dee4;--soft:#eef2f5;
  --navy:#1F4788;--navy2:#2A5899;--navy3:#4A90B5;--teal:#2D8B8E;--amber:#d97706;--green:#16a34a;--red:#dc2626;
  --sans:"IBM Plex Sans",system-ui,-apple-system,"Segoe UI",Arial,sans-serif;--mono:"IBM Plex Mono",ui-monospace,Menlo,Consolas,monospace;
}
@media (prefers-color-scheme:dark){:root:not([data-theme="light"]){
  --bg:#0d1520;--surface:#121c29;--ink:#e3e8ee;--muted:#9aa6b3;--line:#263445;--soft:#18263a;
  --navy:#7fa6e6;--navy2:#6f9ad8;--navy3:#5fb0d4;--teal:#5cc0c3;--amber:#f0a94a;--green:#4ade80;--red:#f87171;color-scheme:dark}}
:root[data-theme="dark"]{
  --bg:#0d1520;--surface:#121c29;--ink:#e3e8ee;--muted:#9aa6b3;--line:#263445;--soft:#18263a;
  --navy:#7fa6e6;--navy2:#6f9ad8;--navy3:#5fb0d4;--teal:#5cc0c3;--amber:#f0a94a;--green:#4ade80;--red:#f87171;color-scheme:dark}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--ink);font:14px/1.5 var(--sans)}
.top{background:linear-gradient(115deg,#1F4788 0%,#24608f 55%,#2D8B8E 100%);color:#fff;box-shadow:0 4px 24px rgba(13,21,32,.22)}
.top .in{max-width:1440px;margin:0 auto;padding:10px 20px 0;display:flex;align-items:center;gap:14px;flex-wrap:wrap}
.top .logo{display:flex;align-items:center;gap:11px}
.top .mark{width:34px;height:34px;border-radius:9px;display:grid;place-items:center;background:linear-gradient(135deg,#2D8B8E,#1F4788);border:1px solid rgba(255,255,255,.28);font:700 13px var(--mono);color:#fff;letter-spacing:-.02em}
.top .ttl{line-height:1.15}
.sbc-title{font-weight:700;font-size:16px;letter-spacing:-.3px}
.sbc-smart{color:#F2F6FA;font-weight:600}.sbc-biz{color:#3aacb0;font-weight:700}.sbc-copilot{color:#f0a94a;font-weight:600}
.top h1{font:500 12px var(--sans);margin:1px 0 0;opacity:.85}
.top .sp{flex:1}
.top .me{font:500 12px var(--mono);opacity:.85;overflow-wrap:anywhere}
.foot{border-top:1px solid var(--line);margin-top:8px}
.foot .in{max-width:1440px;margin:0 auto;padding:14px 20px;display:flex;justify-content:space-between;gap:6px 18px;flex-wrap:wrap;font-size:12px;color:var(--muted)}
.foot .sbc-title{font-size:13px}.foot .sbc-smart{color:var(--ink)}.foot .sbc-biz{color:var(--teal)}.foot .sbc-copilot{color:var(--amber)}
.top button{background:transparent;border:1px solid rgba(255,255,255,.5);color:#fff;border-radius:4px;padding:4px 12px;font:500 13px var(--sans);cursor:pointer}
.top nav{flex-basis:100%;display:flex;gap:4px;margin-top:8px;overflow-x:auto}
.top nav a{color:#fff;opacity:.75;text-decoration:none;padding:8px 14px;border-radius:4px 4px 0 0;font-weight:500;white-space:nowrap}
.top nav a:hover{opacity:1}
.top nav a[aria-current]{opacity:1;background:var(--bg);color:var(--ink)}
main{max-width:1440px;margin:0 auto;padding:20px;display:grid;gap:18px}
.kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px}
.kpi{background:var(--surface);border:1px solid var(--line);border-radius:6px;padding:14px 16px}
.kpi span{display:block;font-size:12px;color:var(--muted)}
.kpi b{display:block;font:600 24px/1.2 var(--sans);font-variant-numeric:tabular-nums;margin-top:4px}
.kpi small{color:var(--muted);font-size:12px}
.kpi.won b{color:var(--green)}.kpi.pipe b{color:var(--teal)}
.row2{display:grid;grid-template-columns:minmax(0,5fr) minmax(0,7fr);gap:18px}
@media (max-width:980px){.row2{grid-template-columns:1fr}}
.panel{background:var(--surface);border:1px solid var(--line);border-radius:6px;padding:16px 18px;min-width:0}
.panel h2{font-size:15px;margin:0 0 12px;color:var(--navy)}
.funnel{width:100%;height:auto;display:block}
.funnel polygon.f1{fill:var(--navy3)}.funnel polygon.f2{fill:var(--navy2)}.funnel polygon.f3{fill:var(--amber)}.funnel polygon.f4{fill:var(--green)}
.funnel .fl{fill:var(--ink);font:600 13px var(--sans)}.funnel .fs{fill:var(--muted);font:12px var(--sans)}.funnel .fv{fill:#fff;font:700 16px var(--sans)}
.fnote{font-size:12px;color:var(--muted);margin:8px 0 0}
label{display:block;font-size:12px;color:var(--muted);margin:0 0 3px}
input,select,textarea{width:100%;padding:7px 9px;border:1px solid var(--line);border-radius:4px;background:var(--bg);color:var(--ink);font:inherit}
textarea{font:13px/1.55 var(--mono);resize:vertical}
/* Controale native în stil smartBIZ: select cu săgeată proprie, file cu buton teal, bife teal */
select{appearance:none;-webkit-appearance:none;padding-right:28px;background-image:url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'><path d='M2 4l4 4 4-4' fill='none' stroke='%238a96a3' stroke-width='1.6' stroke-linecap='round' stroke-linejoin='round'/></svg>");background-repeat:no-repeat;background-position:right 9px center;background-size:12px}
input[type=file]{padding:4px 6px;cursor:pointer;font-size:12px;color:var(--muted)}
input[type=file]::file-selector-button{font:600 12px var(--sans);color:var(--teal);background:var(--surface);border:1px solid var(--teal);border-radius:4px;padding:5px 10px;margin-right:10px;cursor:pointer}
input[type=file]::file-selector-button:hover{background:color-mix(in srgb,var(--teal) 10%,var(--surface))}
input[type=checkbox],input[type=radio]{accent-color:var(--teal)}
input[type=search]::-webkit-search-cancel-button{cursor:pointer}
code{font:12px var(--mono);background:var(--soft);padding:0 4px;border-radius:3px}
.g{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px}
.g2{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:10px;margin-bottom:10px}
.cuirow{display:grid;grid-template-columns:1fr auto;gap:6px}.cuirow .sm{padding:6px 10px;white-space:nowrap}
.g .wide{grid-column:1/-1;min-width:280px}.g .wide select{min-width:280px;font-size:14px;padding:9px 32px 9px 10px}
.anaf{font-size:12px;color:var(--muted);margin:8px 0 0;min-height:1em;overflow-wrap:anywhere}.anaf b{color:var(--ink)}.anaf .tag{margin-left:4px}
.chks{display:flex;gap:18px;flex-wrap:wrap;margin-top:10px}
.chks label,.mats-pick label{display:flex;gap:6px;align-items:center;color:var(--ink);font-size:13px;margin:0}
.chks input,.mats-pick input[type=checkbox],.act input{width:auto}
fieldset.mats-pick{border:1px solid var(--line);border-radius:4px;padding:8px 12px 10px;margin:12px 0 0;display:grid;gap:6px}
fieldset legend{font-size:12px;color:var(--muted);padding:0 4px}
.kind{font:500 10px var(--mono);text-transform:uppercase;letter-spacing:.04em;border-radius:8px;padding:0 6px;border:1px solid currentColor}
.k-oferta{color:var(--teal)}.k-suport{color:var(--navy3)}
button{font:600 13px var(--sans);cursor:pointer}
.newf>button,.pri{margin-top:12px;background:var(--teal);color:var(--surface);border:0;border-radius:4px;padding:8px 16px}
button:focus-visible,input:focus-visible,select:focus-visible,textarea:focus-visible,summary:focus-visible,a:focus-visible{outline:2px solid var(--navy);outline-offset:2px}
.note{background:var(--surface);border:1px solid var(--line);border-left:3px solid var(--teal);border-radius:4px;padding:12px 14px}
.note.err{border-left-color:var(--red)}.note p{margin:6px 0 0}
.ok{color:var(--green)}.err{color:var(--red)}.muted{color:var(--muted);font-size:12px}
.url{font:12px var(--mono);word-break:break-all;background:var(--bg);border:1px solid var(--line);border-radius:4px;padding:8px;margin-top:6px}
.url b{font-family:var(--sans)}
.boardwrap{overflow-x:auto;padding-bottom:6px}
.board{display:grid;grid-template-columns:repeat(5,minmax(230px,1fr));gap:12px;min-width:1200px}
.col{background:var(--soft);border-radius:6px;padding:10px;min-width:0;border-top:3px solid var(--line)}
.col.s-trimisa{border-top-color:var(--navy3)}.col.s-deschisa{border-top-color:var(--navy2)}.col.s-negociere{border-top-color:var(--amber)}
.col.s-acceptata{border-top-color:var(--green)}.col.s-pierduta{border-top-color:var(--red)}
.col h3{display:flex;justify-content:space-between;align-items:center;font-size:13px;margin:2px 2px 0}
.col h3 em{font:600 12px var(--mono);font-style:normal;background:var(--surface);border:1px solid var(--line);border-radius:10px;padding:0 8px}
.colsum{font-size:12px;color:var(--muted);margin:2px 2px 8px;font-variant-numeric:tabular-nums}
.drop{display:grid;gap:8px;min-height:80px;border-radius:4px}
.drop.over{background:color-mix(in srgb,var(--teal) 12%,transparent)}
.empty{font-size:12px;color:var(--muted);text-align:center;margin:20px 0}
.card{background:var(--surface);border:1px solid var(--line);border-radius:5px;padding:10px 11px;cursor:grab}
.card.drag{opacity:.5}
.card header{display:flex;justify-content:space-between;gap:8px}.card header strong{font-size:13px;overflow-wrap:anywhere}
.card header small{color:var(--muted);font:11px var(--mono);white-space:nowrap}
.who{font-size:12px;color:var(--muted);overflow-wrap:anywhere}
ul.mats{list-style:none;padding:0;margin:6px 0 0;display:grid;gap:2px}
ul.mats li{display:flex;justify-content:space-between;gap:6px;font-size:12px;color:var(--muted)}
ul.mats li em{font:11px var(--mono);font-style:normal}ul.mats li.seen{color:var(--ink)}ul.mats li.seen em{color:var(--green)}
.val{display:flex;justify-content:space-between;font-size:12px;margin-top:6px;padding-top:6px;border-top:1px dashed var(--line)}
.val b{font-variant-numeric:tabular-nums}
.flags{display:flex;gap:4px;flex-wrap:wrap;margin-top:6px}
.tag{font:500 11px var(--mono);border:1px solid var(--line);border-radius:10px;padding:0 7px;color:var(--muted)}
.tag.bad{color:var(--red);border-color:currentColor}
.nota{font-size:12px;margin:6px 0 0;padding:5px 7px;background:var(--bg);border-radius:3px}
details{margin-top:6px}summary{font-size:12px;color:var(--teal);cursor:pointer}
.mv{display:grid;grid-template-columns:1fr auto;gap:4px 6px;align-items:end;margin-top:8px}.mv label{grid-column:1/-1;margin:0}
.sm{background:transparent;border:1px solid var(--teal);color:var(--teal);border-radius:4px;padding:6px 10px}
.sm.bad{border-color:var(--red);color:var(--red)}
.meta{font-size:11px;color:var(--muted);margin:8px 0 4px;overflow-wrap:anywhere}
.log{list-style:none;padding:0;margin:0;font-size:11px;color:var(--muted)}.log span{font-family:var(--mono)}
.lnk{background:none;border:0;padding:0;margin-top:6px;font-size:12px}.lnk.bad{color:var(--red)}
/* Șabloane */
.tplgrid{display:grid;grid-template-columns:260px minmax(0,1fr) minmax(0,1fr);gap:18px;align-items:start}
@media (max-width:1200px){.tplgrid{grid-template-columns:1fr}}
.tlist{display:grid;gap:6px}
.tli{display:grid;gap:2px;text-decoration:none;color:var(--ink);border:1px solid var(--line);border-radius:5px;padding:8px 10px}
.tli span{font-size:12px;color:var(--muted);overflow-wrap:anywhere}.tli small{font-size:11px;color:var(--muted)}
.tli.on{border-color:var(--teal);box-shadow:inset 3px 0 0 var(--teal)}
.tli.add{color:var(--teal);font-weight:600}
.ph{display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap}.ph h2{margin:0 0 12px}
.acts{display:flex;gap:6px}.inline{display:inline}
.help{font-size:12px;color:var(--muted);margin:6px 0 0;line-height:1.7}
.mrow{display:flex;justify-content:space-between;align-items:center;gap:10px}
.mrow .ord{width:64px}
.prev iframe{width:100%;height:1240px;border:1px solid var(--line);border-radius:4px;background:#fff}
/* Materiale */
.up{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px;align-items:end}
.up .pri{margin:0}
.matlist{display:grid;gap:8px}
.mat{display:grid;grid-template-columns:minmax(220px,3fr) 150px 150px minmax(170px,2fr) auto auto;gap:10px;align-items:center;border:1px solid var(--line);border-radius:5px;padding:10px 12px}
.mat.off{opacity:.6}
@media (max-width:1100px){.mat{grid-template-columns:1fr 1fr}}
.mt{display:grid;gap:3px;min-width:0}.mt small{font-size:11px;color:var(--muted);overflow-wrap:anywhere}
.ext{font:600 10px var(--mono);border:1px solid var(--line);border-radius:3px;padding:0 4px}
.stats{font-size:12px;color:var(--muted)}.stats b{color:var(--ink)}
.rep{font-size:12px;margin:0}.rep input{margin-top:3px;font-size:12px;padding:4px}
.act{display:flex;gap:6px;align-items:center;margin:0;font-size:13px;color:var(--ink)}
/* Utilizatori */
.pwf{display:grid;gap:10px}.pwf .pri{margin:0;justify-self:start}
.usr{display:grid;grid-template-columns:minmax(200px,2fr) minmax(160px,2fr) minmax(170px,2fr) auto auto minmax(64px,auto);gap:10px;align-items:center;border:1px solid var(--line);border-radius:5px;padding:10px 12px}
.usr.off{opacity:.6}
@media (max-width:1100px){.usr{grid-template-columns:1fr 1fr}}
.un{font:600 13px var(--mono);overflow-wrap:anywhere}.un .tag{font-size:10px;vertical-align:middle}
/* Raportare */
.rep .ph{align-items:center}.sm2{margin:0;padding:6px 12px}
.rtools{display:grid;grid-template-columns:minmax(220px,2fr) repeat(6,minmax(120px,1fr)) auto;gap:10px;align-items:end;margin-bottom:10px}
@media (max-width:1200px){.rtools{grid-template-columns:repeat(auto-fit,minmax(160px,1fr))}}
.rcols{position:relative;align-self:end}.rcols summary{border:1px solid var(--line);border-radius:4px;padding:7px 10px;color:var(--ink);background:var(--bg);white-space:nowrap}
.rcols>div{position:absolute;right:0;z-index:5;background:var(--surface);border:1px solid var(--line);border-radius:5px;padding:8px 12px;display:grid;gap:4px;min-width:220px;box-shadow:0 6px 18px rgba(0,0,0,.12)}
.rcols label{display:flex;gap:6px;align-items:center;color:var(--ink);font-size:13px;margin:0}.rcols input{width:auto}
.rsum{display:flex;flex-wrap:wrap;gap:6px 18px;font-size:13px;color:var(--muted);padding:8px 0 10px;border-bottom:1px solid var(--line);margin-bottom:8px}
.rsum b{color:var(--ink);font-variant-numeric:tabular-nums}
.rtw{overflow:auto;max-height:70vh;border:1px solid var(--line);border-radius:5px}
.rt{border-collapse:collapse;width:100%;font-size:13px}
.rt th{position:sticky;top:0;z-index:2;background:var(--soft);color:var(--navy);text-align:left;font-weight:600;padding:8px 10px;border-bottom:1px solid var(--line);white-space:nowrap;cursor:pointer;user-select:none}
.rt td{padding:7px 10px;border-bottom:1px dotted var(--line);vertical-align:top;white-space:nowrap}
.rt td.wrap{white-space:normal;min-width:220px}
.rt .n{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}
.rt tbody tr:hover td{background:color-mix(in srgb,var(--teal) 6%,transparent)}
.rt td a{color:var(--ink);font-weight:600;text-decoration:none}.rt td a:hover{color:var(--teal)}
.rt tr.grp td{background:var(--soft);font-size:12px;color:var(--navy);border-top:1px solid var(--line)}
.rt tfoot td{position:sticky;bottom:0;background:var(--surface);border-top:2px solid var(--navy);font-weight:600}
.rt .cnt{font:11px var(--mono);color:var(--muted);margin-left:4px}
.rt .empty{text-align:center;color:var(--muted);padding:24px}
.chip{font:500 11px var(--mono);border-radius:10px;padding:1px 8px;border:1px solid currentColor;white-space:nowrap}
.chip.s-trimisa{color:var(--navy3)}.chip.s-deschisa{color:var(--navy2)}.chip.s-negociere{color:var(--amber)}.chip.s-acceptata{color:var(--green)}.chip.s-pierduta{color:var(--red)}
.lk{font-size:12px}.lk.l-activ{color:var(--green)}.lk.l-expirat{color:var(--muted)}.lk.l-revocat{color:var(--red)}
</style>
</head>
<body>
<header class="top"><div class="in">
  <div class="logo">
    <span class="mark" aria-hidden="true">sB</span>
    <div class="ttl"><b class="sbc-title"><span class="sbc-smart">smart</span><span class="sbc-biz">BIZ</span> <span class="sbc-copilot">Copilot</span></b><h1>Cockpit e2e OPS Ofertare</h1></div>
  </div>
  <span class="sp"></span>
  <span class="me" title="<?= h($me['username']) ?>"><?= h($me['name'] !== '' ? $me['name'] : $me['username']) ?></span>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="logout"><button type="submit">Ieșire</button></form>
  <nav aria-label="Secțiuni"><?= $nav ?></nav>
</div></header>
<main>
<?= $flashMsg ?>
<?= $content ?>
</main>
<footer class="foot"><div class="in">
  <span><b class="sbc-title"><span class="sbc-smart">smart</span><span class="sbc-biz">BIZ</span> <span class="sbc-copilot">Copilot</span></b> · e2e OPS Ofertare</span>
  <span>AiALL S.R.L. · ofertare.aiall.ro · <?= date('Y') ?></span>
</div></footer>
<script>
(function () {
  var csrf = <?= json_encode($csrf) ?>;

  // Kanban: drag & drop între etape
  var dragged = null;
  document.querySelectorAll('.card').forEach(function (c) {
    c.addEventListener('dragstart', function (e) { dragged = c; c.classList.add('drag'); e.dataTransfer.effectAllowed = 'move'; });
    c.addEventListener('dragend', function () { c.classList.remove('drag'); dragged = null; });
  });
  document.querySelectorAll('.col').forEach(function (col) {
    var zone = col.querySelector('.drop');
    col.addEventListener('dragover', function (e) { if (dragged) { e.preventDefault(); zone.classList.add('over'); } });
    col.addEventListener('dragleave', function () { zone.classList.remove('over'); });
    col.addEventListener('drop', function (e) {
      e.preventDefault(); zone.classList.remove('over');
      if (!dragged) { return; }
      var body = new URLSearchParams({ action: 'stage', id: dragged.dataset.id, stage: col.dataset.stage, csrf: csrf });
      fetch('admin.php', { method: 'POST', body: body, headers: { 'X-Requested-With': 'fetch' }, credentials: 'same-origin' })
        .then(function (r) { if (!r.ok) { throw new Error(r.status); } location.reload(); })
        .catch(function () { alert('Mutarea nu a reușit. Reîncarcă pagina.'); });
    });
  });

  // Ofertă nouă: bifează materialele șablonului ales
  var map = document.getElementById('tplMap');
  var sel = document.getElementById('template_id');
  if (map && sel) {
    var tpl = JSON.parse(map.textContent);
    var sync = function () {
      var ids = (tpl[sel.value] || []).map(String);
      document.querySelectorAll('#newDeal input[name="materials[]"]').forEach(function (cb) { cb.checked = ids.indexOf(cb.value) !== -1; });
    };
    sel.addEventListener('change', sync);
    sync();
  }

  // Ofertă nouă: datele firmei de la ANAF după CUI (buton, Enter sau la părăsirea câmpului)
  var cuiIn = document.getElementById('cui'), cuiBtn = document.getElementById('cuiBtn'), info = document.getElementById('anafInfo');
  if (cuiIn && cuiBtn && info) {
    var last = '';
    var txt = function (s) { return document.createTextNode(s); };
    var tag = function (s, bad) { var e = document.createElement('span'); e.className = 'tag' + (bad ? ' bad' : ''); e.textContent = s; return e; };
    var setInfo = function (cls, parts) {
      info.className = 'anaf' + (cls ? ' ' + cls : '');
      info.textContent = '';
      parts.forEach(function (p) { info.appendChild(p); });
    };
    var lookup = function () {
      var cui = cuiIn.value.replace(/\D/g, '');
      if (!cui) { setInfo('', []); last = ''; return; }
      if (cui === last) { return; }
      last = cui;
      setInfo('', [txt('Se interoghează ANAF…')]);
      fetch('admin.php?a=anaf&cui=' + encodeURIComponent(cui), { headers: { 'X-Requested-With': 'fetch' }, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (!j.ok) { last = ''; setInfo('err', [txt(j.error)]); return; }
          var f = j.firma;
          cuiIn.value = f.cui;
          document.getElementById('company').value = f.denumire;
          ['reg_com', 'adresa', 'judet', 'caen', 'telefon', 'tva', 'inactiv'].forEach(function (k) { document.getElementById('f_' + k).value = f[k]; });
          var b = document.createElement('b'); b.textContent = f.denumire;
          var parts = [b, txt(' · ' + (f.reg_com || 'fără nr. Reg. Com.') + ' · ' + f.adresa
            + (f.caen ? ' · CAEN ' + f.caen : '') + (f.telefon ? ' · tel. ' + f.telefon : '') + ' '),
            tag(f.tva ? 'plătitor TVA' : 'neplătitor TVA', false)];
          if (f.inactiv) { parts.push(tag('inactiv', true)); }
          if (f.stare) { parts.push(txt(' · ' + f.stare)); }
          setInfo('', parts);
        })
        .catch(function () { last = ''; setInfo('err', [txt('ANAF nu răspunde. Completează datele manual.')]); });
    };
    cuiBtn.addEventListener('click', lookup);
    cuiIn.addEventListener('change', lookup);
    cuiIn.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); lookup(); } });
  }
})();
</script>
</body>
</html>
