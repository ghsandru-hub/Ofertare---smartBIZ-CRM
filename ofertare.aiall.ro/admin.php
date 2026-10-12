<?php
// Cockpit e2e OPS Ofertare: https://ofertare.aiall.ro/admin.php
// Secțiuni: Pipeline (indicatori, pâlnie, kanban, ofertă nouă), Raportare, Catalog (produse și servicii), Șabloane email, Materiale, Utilizatori, Ajutor.
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

// Inițialele pentru avatarul din antet: primele litere din primele două cuvinte ale numelui (sau ale utilizatorului).
function user_initials(array $u): string
{
    $src   = trim((string)($u['name'] !== '' ? $u['name'] : $u['username']));
    $words = preg_split('/[\s._@-]+/u', $src, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $ini   = '';
    foreach (array_slice($words, 0, 2) as $w) {
        $ini .= mb_substr($w, 0, 1);
    }
    return mb_strtoupper($ini !== '' ? $ini : '?');
}

// ---------------------------------------------------------------------------
// Autentificare
// ---------------------------------------------------------------------------
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = (string)($_POST['action'] ?? '');
$view   = in_array($_GET['v'] ?? '', ['pipeline', 'sabloane', 'materiale', 'raportare', 'catalog', 'utilizatori', 'ajutor'], true) ? $_GET['v'] : 'pipeline';

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

// Rezultatul unei trimiteri de email, pentru mesajul din cockpit: cu eroarea reală a serverului dacă a eșuat.
function mail_result_html(bool $ok, string $to): string
{
    if ($ok) {
        $note = mail_transport() === 'mail()'
            ? ' <span class="muted">mail() confirmă doar predarea către serverul local, nu livrarea. Dacă emailul nu ajunge, configurează SMTP în config.php (vezi Ajutor).</span>'
            : '';
        return '<p class="ok">Emailul a fost trimis la ' . h($to) . ' prin ' . h(mail_transport()) . '.</p>' . $note;
    }
    return '<p class="err">Trimiterea prin ' . h(mail_transport()) . ' a eșuat: ' . h(mail_error() ?: 'motiv necunoscut') . '</p>'
        . '<p class="muted">Copiază linkurile și trimite-le manual, sau rezolvă setările de email și folosește „Retrimite emailul” de pe card.</p>';
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

// Previzualizarea emailului din „Ofertă nouă”, cu datele completate și materialele bifate (JSON, fără să creeze oferta).
if ($method === 'POST' && $isAjax && $action === 'preview') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $tq = db()->prepare('SELECT * FROM templates WHERE id = ?');
    $tq->execute([(int)($_POST['template_id'] ?? 0)]);
    if (!($tpl = $tq->fetch())) {
        exit(json_encode(['ok' => false, 'error' => 'Alege un șablon de email.']));
    }
    $sel   = array_map('intval', (array)($_POST['materials'] ?? []));
    $order = array_flip(array_map(fn($m) => (int)$m['id'], template_materials((int)$tpl['id'])));
    usort($sel, fn($a, $b) => ($order[$a] ?? 999) <=> ($order[$b] ?? 999));
    $mats = [];
    if ($sel) {
        $mq = db()->prepare('SELECT id, title FROM materials WHERE active = 1 AND id IN (' . implode(',', array_fill(0, count($sel), '?')) . ')');
        $mq->execute($sel);
        $byId = array_column($mq->fetchAll(), 'title', 'id');
        foreach ($sel as $mid) {
            if (isset($byId[$mid])) {
                $mats[] = ['title' => $byId[$mid], 'url' => base_url() . str_repeat('x', 43)];
            }
        }
    }
    $days = max(1, min(365, (int)($_POST['days'] ?? cfg('default_days'))));
    $deal = ['company' => trim((string)($_POST['company'] ?? '')), 'recipient' => trim((string)($_POST['recipient'] ?? '')),
             'email' => trim((string)($_POST['email'] ?? '')), 'angajati' => max(0, (int)($_POST['angajati'] ?? 0)), 'expires_at' => time() + 86400 * $days];
    [$subject, $html] = render_email((string)$tpl['subject'], (string)$tpl['body'], $deal, $mats);
    exit(json_encode(['ok' => true, 'to' => ($deal['recipient'] !== '' ? $deal['recipient'] . ' <' . $deal['email'] . '>' : $deal['email']),
        'subject' => $subject, 'html' => $html, 'materials' => count($mats), 'transport' => mail_transport()], JSON_UNESCAPED_UNICODE));
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
                    log_event($dealId, $ok ? 'email trimis prin ' . mail_transport() : 'email eșuat: ' . mail_error());
                    $sent = mail_result_html($ok, $email);
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

        case 'deal_delete':
            $id = (int)($_POST['id'] ?? 0);
            $dq = db()->prepare('SELECT company, email FROM deals WHERE id = ?');
            $dq->execute([$id]);
            if ($d = $dq->fetch()) {
                // linkurile, codurile, sesiunile și jurnalul se șterg în cascadă; linkurile trimise nu mai funcționează
                db()->prepare('DELETE FROM deals WHERE id = ?')->execute([$id]);
                flash('<div class="note">Oferta pentru „' . h($d['company'] ?: $d['email']) . '” a fost ștearsă, cu tot cu linkurile ei.</div>');
            }
            redirect('admin.php?v=pipeline');

        // Retrimite emailul ofertei cu linkuri noi (tokenurile nu se păstrează, deci cele vechi nu pot fi reconstituite).
        case 'deal_resend':
            $id = (int)($_POST['id'] ?? 0);
            $dq = db()->prepare('SELECT * FROM deals WHERE id = ?');
            $dq->execute([$id]);
            $deal = $dq->fetch();
            if (!$deal) {
                redirect('admin.php?v=pipeline');
            }
            $tq = db()->prepare('SELECT * FROM templates WHERE id = ?');
            $tq->execute([(int)$deal['template_id']]);
            $tpl = $tq->fetch();
            $lq  = db()->prepare('SELECT l.id, m.title FROM links l JOIN materials m ON m.id = l.material_id WHERE l.deal_id = ? AND m.active = 1 ORDER BY l.id');
            $lq->execute([$id]);
            $links = $lq->fetchAll();
            if (!$tpl) {
                flash('<div class="note err">Șablonul acestei oferte a fost șters; creează o ofertă nouă.</div>');
            } elseif (!$links) {
                flash('<div class="note err">Oferta nu mai are materiale active de trimis.</div>');
            } else {
                $now = time();
                $exp = (int)$deal['expires_at'] < $now + 86400 ? $now + 86400 * (int)cfg('default_days') : (int)$deal['expires_at'];
                db()->prepare('UPDATE deals SET revoked = 0, expires_at = ? WHERE id = ?')->execute([$exp, $id]);
                db()->prepare('DELETE FROM sessions WHERE deal_id = ?')->execute([$id]);
                $upd    = db()->prepare('UPDATE links SET token_hash = ? WHERE id = ?');
                $mlinks = [];
                foreach ($links as $l) {
                    $tok = new_token();
                    $upd->execute([khash($tok), (int)$l['id']]);
                    $mlinks[] = ['title' => $l['title'], 'url' => base_url() . $tok];
                }
                $deal['expires_at'] = $exp;
                [$subject, $html, $text] = render_email((string)$tpl['subject'], (string)$tpl['body'], $deal, $mlinks);
                db()->prepare('UPDATE deals SET subject = ? WHERE id = ?')->execute([$subject, $id]);
                $ok = send_mail((string)$deal['email'], $subject, $html, $text);
                log_event($id, $ok ? 'email retrimis cu linkuri noi prin ' . mail_transport() : 'retrimitere eșuată: ' . mail_error());
                $list = '';
                foreach ($mlinks as $ml) {
                    $list .= '<div class="url"><b>' . h($ml['title']) . '</b><br>' . h($ml['url']) . '</div>';
                }
                flash('<div class="note"><strong>Linkuri noi pentru ' . h($deal['company'] ?: $deal['email']) . '.</strong> Linkurile trimise anterior nu mai funcționează; cele noi sunt valabile până la ' . h(fmt_time($exp)) . '.'
                    . mail_result_html($ok, (string)$deal['email'])
                    . '<p class="muted">Linkurile se afișează o singură dată.</p>' . $list . '</div>');
            }
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

        // Email de test: șablonul cu date de exemplu, la adresa ta, ca să verifici livrarea pe server.
        case 'tpl_test':
            $id = (int)($_POST['id'] ?? 0);
            $to = trim((string)($_POST['to'] ?? ''));
            $tq = db()->prepare('SELECT * FROM templates WHERE id = ?');
            $tq->execute([$id]);
            if (!($tpl = $tq->fetch())) {
                flash('<div class="note err">Salvează mai întâi șablonul.</div>');
                redirect('admin.php?v=sabloane');
            }
            if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
                flash('<div class="note err">Scrie o adresă de email validă pentru test.</div>');
                redirect('admin.php?v=sabloane&id=' . $id);
            }
            $mats = array_map(fn($m) => ['title' => $m['title'], 'url' => base_url() . 'test-' . str_repeat('x', 38)], template_materials($id));
            $sample = ['company' => 'EXEMPLU CONSTRUCT SRL', 'recipient' => 'Ion Popescu', 'email' => $to, 'angajati' => 10, 'expires_at' => time() + 30 * 86400];
            [$subject, $html, $text] = render_email('[TEST] ' . $tpl['subject'], (string)$tpl['body'], $sample, $mats);
            $ok = send_mail($to, $subject, $html, $text);
            flash('<div class="note' . ($ok ? '' : ' err') . '"><strong>Email de test</strong>' . mail_result_html($ok, $to)
                . ($ok ? '<p class="muted">Linkurile din emailul de test sunt fictive. Dacă emailul nu ajunge în câteva minute, verifică spam-ul și setările din config.php (vezi Ajutor).</p>' : '') . '</div>');
            redirect('admin.php?v=sabloane&id=' . $id);

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

        // --- Catalog produse și servicii -------------------------------------
        case 'cat_save':
            $id    = (int)($_POST['id'] ?? 0);
            $types = catalog_types();
            $type  = (string)($_POST['type'] ?? '');
            $name  = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 200);
            $um    = mb_substr(trim((string)($_POST['um'] ?? '')), 0, 20);
            $from  = catalog_date((string)($_POST['valid_from'] ?? ''));
            $to    = catalog_date((string)($_POST['valid_to'] ?? ''));
            $back  = 'admin.php?v=catalog' . ($id ? '#k' . $id : '');
            if (!isset($types[$type])) {
                flash('<div class="note err">Alege tipul articolului.</div>');
            } elseif ($name === '') {
                flash('<div class="note err">Denumirea este obligatorie.</div>');
            } elseif ($um === '') {
                flash('<div class="note err">Unitatea de măsură este obligatorie.</div>');
            } elseif ($from !== '' && $to !== '' && $to < $from) {
                flash('<div class="note err">„Valid până la” nu poate fi înainte de „Valid de la”.</div>');
            } elseif ($id) {
                // Schimbarea tipului nu poate strica o compoziție: un pachet cu componente rămâne pachet
                // (și componentele trebuie să fie eligibile pentru noul tip), iar un articol dintr-un pachet
                // nu poate deveni un tip pe care pachetul nu îl acceptă.
                $comps = catalog_components($id);
                $inPk  = catalog_packages_of($id);
                $bad   = '';
                if ($comps && !catalog_is_package($type)) {
                    $bad = 'Pachetul are ' . count($comps) . ' componente. Scoate-le mai întâi din compoziție, apoi schimbă tipul.';
                } elseif ($comps) {
                    $ok = catalog_component_types($type);
                    $ne = array_filter($comps, fn($c) => !in_array($c['type'], $ok, true));
                    if ($ne) {
                        $bad = 'Componentele „' . h(implode('”, „', array_column($ne, 'name'))) . '” nu pot intra într-un ' . h(mb_strtolower($types[$type])) . '. Scoate-le mai întâi din compoziție.';
                    }
                } elseif ($inPk) {
                    $ne = array_filter($inPk, fn($pk) => !in_array($type, catalog_component_types((string)$pk['type']), true));
                    if ($ne) {
                        $bad = 'Articolul este componentă în „' . h(implode('”, „', array_column($ne, 'name'))) . '”, iar un ' . h(mb_strtolower($types[$type])) . ' nu poate intra acolo. Scoate-l mai întâi din pachete.';
                    }
                }
                if ($bad !== '') {
                    flash('<div class="note err">' . $bad . '</div>');
                    redirect($back);
                }
                db()->prepare('UPDATE catalog SET type = ?, name = ?, um = ?, valid_from = ?, valid_to = ?, updated_at = ? WHERE id = ?')
                    ->execute([$type, $name, $um, $from, $to, time(), $id]);
                flash('<div class="note">Articolul „' . h($name) . '” a fost actualizat.</div>');
            } else {
                db()->prepare('INSERT INTO catalog (type, name, um, valid_from, valid_to, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$type, $name, $um, $from, $to, time(), time()]);
                $back = 'admin.php?v=catalog#k' . (int)db()->lastInsertId();
                flash('<div class="note">Articolul „' . h($name) . '” a fost adăugat în catalog.'
                    . (catalog_is_package($type) ? ' Adaugă acum componentele din secțiunea „Compoziție” a pachetului.' : '') . '</div>');
            }
            redirect($back);

        case 'cat_copy':
            $src = db()->prepare('SELECT * FROM catalog WHERE id = ?');
            $src->execute([(int)($_POST['id'] ?? 0)]);
            if ($c = $src->fetch()) {
                db()->prepare('INSERT INTO catalog (type, name, um, valid_from, valid_to, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$c['type'], $c['name'] . ' (copie)', $c['um'], $c['valid_from'], $c['valid_to'], time(), time()]);
                $nid = (int)db()->lastInsertId();
                // un pachet se copiază cu tot cu compoziție
                db()->prepare('INSERT INTO catalog_items (package_id, item_id, qty, ord) SELECT ?, item_id, qty, ord FROM catalog_items WHERE package_id = ?')
                    ->execute([$nid, (int)$c['id']]);
                flash('<div class="note">Am creat o copie a articolului „' . h($c['name']) . '”.</div>');
                redirect('admin.php?v=catalog#k' . $nid);
            }
            redirect('admin.php?v=catalog');

        case 'cat_delete':
            $id   = (int)($_POST['id'] ?? 0);
            $inPk = catalog_packages_of($id);
            if ($inPk) {
                flash('<div class="note err">Articolul este componentă în „' . h(implode('”, „', array_column($inPk, 'name'))) . '”. Scoate-l mai întâi din pachete.</div>');
                redirect('admin.php?v=catalog#k' . $id);
            }
            db()->prepare('DELETE FROM catalog WHERE id = ?')->execute([$id]); // compoziția unui pachet se șterge odată cu el
            flash('<div class="note">Articolul a fost șters din catalog.</div>');
            redirect('admin.php?v=catalog');

        // Compoziția pachetelor: componentele sunt articole existente în catalog, cu cantitate.
        case 'cat_item_add':
        case 'cat_item_qty':
        case 'cat_item_del':
            $pid = (int)($_POST['package_id'] ?? 0);
            $iid = (int)($_POST['item_id'] ?? 0);
            $qty = catalog_qty((string)($_POST['qty'] ?? '1'));
            $pq  = db()->prepare('SELECT * FROM catalog WHERE id = ?');
            $pq->execute([$pid]);
            $pk  = $pq->fetch();
            $pq->execute([$iid]);
            $it  = $pq->fetch();
            $back = 'admin.php?v=catalog#k' . $pid;
            if (!$pk || !catalog_is_package((string)$pk['type'])) {
                flash('<div class="note err">Pachetul nu există.</div>');
                redirect('admin.php?v=catalog');
            }
            if ($action === 'cat_item_del') {
                db()->prepare('DELETE FROM catalog_items WHERE package_id = ? AND item_id = ?')->execute([$pid, $iid]);
                flash('<div class="note">Componenta a fost scoasă din „' . h($pk['name']) . '”.</div>');
                redirect($back);
            }
            if (!$it || !in_array($it['type'], catalog_component_types((string)$pk['type']), true)) {
                flash('<div class="note err">Alege o componentă din catalog potrivită pentru acest tip de pachet.</div>');
            } elseif ($qty <= 0) {
                flash('<div class="note err">Cantitatea trebuie să fie un număr pozitiv.</div>');
            } elseif ($action === 'cat_item_qty') {
                db()->prepare('UPDATE catalog_items SET qty = ? WHERE package_id = ? AND item_id = ?')->execute([$qty, $pid, $iid]);
                flash('<div class="note">Cantitatea pentru „' . h($it['name']) . '” a fost salvată.</div>');
            } else {
                $ord = (int)db()->query('SELECT COALESCE(MAX(ord), 0) + 1 FROM catalog_items WHERE package_id = ' . $pid)->fetchColumn();
                db()->prepare('INSERT INTO catalog_items (package_id, item_id, qty, ord) VALUES (?, ?, ?, ?)
                               ON CONFLICT(package_id, item_id) DO UPDATE SET qty = excluded.qty')->execute([$pid, $iid, $qty, $ord]);
                db()->prepare('UPDATE catalog SET updated_at = ? WHERE id = ?')->execute([time(), $pid]);
                flash('<div class="note">„' . h($it['name']) . '” a fost adăugat în „' . h($pk['name']) . '”.</div>');
            }
            redirect($back);

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
    $acts = '<div class="cardacts">'
        . '<form method="post">' . $hid . '<input type="hidden" name="action" value="deal_resend">'
        . '<button class="lnk" type="submit" title="Generează linkuri noi și retrimite emailul la ' . h($d['email']) . '">Retrimite emailul (linkuri noi)</button></form>'
        . $revoke
        . '<form method="post" onsubmit="return confirm(\'Ștergi definitiv oferta pentru ' . h(addslashes($d['company'] ?: $d['email'])) . '? Linkurile trimise nu vor mai funcționa.\')">'
        . $hid . '<input type="hidden" name="action" value="deal_delete"><button class="lnk bad" type="submit">Șterge oferta</button></form></div>';

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
        . '<ul class="log">' . ($ev ?: '<li>Fără evenimente</li>') . '</ul>' . $acts
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
          . '<div class="fbtns"><button type="submit">Creează și trimite oferta</button>'
          . '<button type="button" class="sm" id="prevBtn" title="Vezi emailul exact așa cum îl primește clientul, înainte de trimitere">Previzualizează emailul</button></div>'
          . '<script type="application/json" id="tplMap">' . json_encode($tplMap) . '</script></form>'
          . '<dialog id="prevDlg" class="pdlg" aria-labelledby="prevTtl"><div class="pdh"><h2 id="prevTtl">Previzualizare email</h2>'
          . '<p class="muted" id="prevMeta"></p></div><iframe id="prevFrame" title="Emailul care va fi trimis" sandbox></iframe>'
          . '<div class="pdf"><button type="button" class="sm" id="prevClose">Închide</button><span class="sp"></span>'
          . '<button type="button" class="pri" id="prevSend">Creează și trimite oferta</button></div></dialog>'
        : '<p class="muted">Creează mai întâi un șablon de email în secțiunea „Șabloane email”.</p>';

    // Tabelul ofertelor: aceeași listă ca în kanban, un rând per ofertă, cu acțiunile de pe card.
    $trs = '';
    foreach ($deals as $d) {
        $id   = (int)$d['id'];
        $ls   = $links[$id] ?? [];
        $read = count(array_filter($ls, fn($l) => (int)$l['opens'] > 0));
        $st   = $d['revoked'] ? 'revocat' : ((int)$d['expires_at'] < time() ? 'expirat' : 'activ');
        $sal  = (int)$d['angajati'];
        $hid  = '<input type="hidden" name="csrf" value="' . h($csrf) . '"><input type="hidden" name="id" value="' . $id . '">';
        $opts = '';
        foreach ($stages as $k => $sg) {
            $opts .= '<option value="' . $k . '"' . ($k === $d['stage'] ? ' selected' : '') . '>' . $sg['label'] . '</option>';
        }
        $trs .= '<tr id="r' . $id . '"><td><a href="#c' . $id . '" class="tocard" data-id="' . $id . '">' . h($d['company'] ?: $d['email']) . '</a>'
            . ((string)$d['cui'] !== '' ? '<br><small class="muted">CUI ' . h($d['cui']) . '</small>' : '') . '</td>'
            . '<td>' . h($d['recipient'] ?: '—') . '<br><small class="muted">' . h($d['email']) . '</small></td>'
            . '<td><form method="post" class="trow">' . $hid . '<input type="hidden" name="action" value="stage"><select name="stage" aria-label="Etapă" onchange="this.form.submit()">' . $opts . '</select></form></td>'
            . '<td class="n">' . $read . '/' . count($ls) . '</td>'
            . '<td class="n">' . $sal . '</td><td class="n">' . ($sal ? ron($sal * ONORARIU_AN1_PER_SALARIAT) : '—') . '</td>'
            . '<td>' . h(date('d.m.Y', (int)$d['created_at'])) . '</td>'
            . '<td>' . ($d['first_open'] ? h(date('d.m.Y', (int)$d['first_open'])) : '—') . '</td>'
            . '<td><span class="lk l-' . $st . '">' . $st . '</span><br><small class="muted">până la ' . h(date('d.m.Y', (int)$d['expires_at'])) . '</small></td>'
            . '<td class="wrap">' . h($d['nota']) . '</td>'
            . '<td class="tacts"><form method="post">' . $hid . '<input type="hidden" name="action" value="deal_resend"><button class="lnk" type="submit" title="Linkuri noi + email la ' . h($d['email']) . '">Retrimite</button></form>'
            . ($d['revoked'] ? '' : '<form method="post">' . $hid . '<input type="hidden" name="action" value="revoke"><button class="lnk bad" type="submit">Revocă</button></form>')
            . '<form method="post" onsubmit="return confirm(\'Ștergi definitiv oferta pentru ' . h(addslashes($d['company'] ?: $d['email'])) . '? Linkurile trimise nu vor mai funcționa.\')">'
            . $hid . '<input type="hidden" name="action" value="deal_delete"><button class="lnk bad" type="submit">Șterge</button></form></td></tr>';
    }
    $table = '<div class="rtw dtw"><table class="rt dt"><thead><tr><th>Companie</th><th>Persoană / email</th><th>Etapă</th><th class="n">Citite</th><th class="n">Salariați</th><th class="n">Onorariu an 1</th>'
        . '<th>Creată</th><th>Deschisă</th><th>Linkuri</th><th>Notă</th><th>Acțiuni</th></tr></thead><tbody>'
        . ($trs ?: '<tr><td colspan="11" class="empty">Nicio ofertă.</td></tr>') . '</tbody></table></div>';

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
        . '<section class="panel" id="pipePanel"><div class="ph"><h2>Pipeline</h2><div class="acts seg" role="group" aria-label="Mod de afișare">'
        . '<button type="button" class="sm tgl" data-mode="kanban" aria-pressed="true" aria-label="Kanban" title="Kanban: carduri pe etape">'
        . '<svg viewBox="0 0 20 20" width="18" height="18" aria-hidden="true"><rect x="2" y="3" width="4.5" height="14" rx="1"/><rect x="7.75" y="3" width="4.5" height="9" rx="1"/><rect x="13.5" y="3" width="4.5" height="11" rx="1"/></svg></button>'
        . '<button type="button" class="sm tgl" data-mode="table" aria-pressed="false" aria-label="Tabel" title="Tabel: o ofertă pe rând">'
        . '<svg viewBox="0 0 20 20" width="18" height="18" aria-hidden="true"><rect x="2" y="3" width="16" height="3" rx="1"/><rect x="2" y="8.5" width="16" height="3" rx="1"/><rect x="2" y="14" width="16" height="3" rx="1"/></svg></button></div></div>'
        . '<p class="muted pm-kanban" style="margin:-6px 0 10px">Trage cardurile între coloane. „Deschisă” se setează automat la prima deschidere a unui material.</p>'
        . '<p class="muted pm-table" style="margin:-6px 0 10px">O ofertă pe rând, cu etapa, materialele citite și acțiunile. Clic pe companie deschide cardul în kanban.</p>'
        . '<div class="boardwrap pm-kanban"><div class="board">' . $board . '</div></div>'
        . '<div class="pm-table">' . $table . '</div></section>';
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
        . ($tpl['id'] ? '<form method="post" class="testf">' . $hid . '<input type="hidden" name="action" value="tpl_test">'
            . '<label for="testTo">Email de test (șablonul cu date de exemplu, prin ' . h(mail_transport()) . ')</label>'
            . '<div class="cuirow"><input id="testTo" name="to" type="email" placeholder="adresa ta" required><button type="submit" class="sm">Trimite testul</button></div></form>' : '')
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
// SECȚIUNEA CATALOG (produse și servicii: tip, denumire, UM, valid de la / până la)
// ===========================================================================
function catalog_type_options(string $selected): string
{
    $out = '';
    foreach (catalog_types() as $k => $label) {
        $out .= '<option value="' . $k . '"' . ($k === $selected ? ' selected' : '') . '>' . h($label) . '</option>';
    }
    return $out;
}

// Secțiunea „Compoziție” a unui pachet: componentele cu cantitate și formularul de adăugare.
function catalog_composition(array $pk, array $comps, array $all, string $today): string
{
    $types  = catalog_types();
    $pid    = (int)$pk['id'];
    $okT    = catalog_component_types((string)$pk['type']);
    $labels = ['valid' => 'valid', 'viitor' => 'din viitor', 'expirat' => 'expirat'];
    $have   = array_flip(array_map(fn($c) => (int)$c['id'], $comps));
    $hid    = csrf_field() . '<input type="hidden" name="package_id" value="' . $pid . '">';

    $rows = '';
    foreach ($comps as $c) {
        $st = catalog_status($c, $today);
        $rows .= '<form method="post" class="ci">' . $hid . '<input type="hidden" name="item_id" value="' . (int)$c['id'] . '">'
            . '<span class="kind kt-' . h($c['type']) . '">' . h($types[$c['type']] ?? $c['type']) . '</span>'
            . '<div class="mt"><b>' . h($c['name']) . '</b><small><span class="lk l-' . $st . '">' . $labels[$st] . '</span>'
            . ($c['valid_to'] !== '' ? ' · până la ' . h(date('d.m.Y', strtotime((string)$c['valid_to']))) : '') . '</small></div>'
            . '<input name="qty" type="number" step="0.01" min="0.01" value="' . h(fmt_qty((float)$c['qty'])) . '" aria-label="Cantitate">'
            . '<span class="um">' . h($c['um']) . '</span>'
            . '<button type="submit" class="sm" name="action" value="cat_item_qty">Salvează</button>'
            . '<button type="submit" class="sm bad" name="action" value="cat_item_del">Scoate</button></form>';
    }

    $opts = '';
    foreach ($okT as $t) {
        $grp = '';
        foreach ($all[$t] ?? [] as $c) {
            if (!isset($have[(int)$c['id']])) {
                $grp .= '<option value="' . (int)$c['id'] . '">' . h($c['name']) . ' (' . h($c['um']) . ')'
                    . (catalog_status($c, $today) === 'expirat' ? ' · expirat' : '') . '</option>';
            }
        }
        if ($grp !== '') {
            $opts .= '<optgroup label="' . h($types[$t]) . '">' . $grp . '</optgroup>';
        }
    }
    $allowed = implode(', ', array_map(fn($t) => mb_strtolower($types[$t]), $okT));
    $add = $opts
        ? '<form method="post" class="ci add">' . $hid . '<input type="hidden" name="action" value="cat_item_add">'
          . '<select name="item_id" aria-label="Componentă"><option value="">Alege din catalog: ' . h($allowed) . '…</option>' . $opts . '</select>'
          . '<input name="qty" type="number" step="0.01" min="0.01" value="1" aria-label="Cantitate">'
          . '<button type="submit" class="sm" title="Adaugă componenta">+ Adaugă</button></form>'
        : '<p class="muted">Nu mai există în catalog articole de tip ' . h($allowed) . ' care să poată fi adăugate.</p>';

    return '<details class="comp" id="comp' . $pid . '"><summary>Compoziție: <b>' . count($comps) . '</b> ' . (count($comps) === 1 ? 'componentă' : 'componente')
        . ' <span class="muted">(' . h($allowed) . ')</span></summary>'
        . '<div class="complist">' . ($rows ?: '<p class="muted">Pachetul nu are încă nicio componentă.</p>') . '</div>' . $add . '</details>';
}

function view_catalog(): string
{
    $types  = catalog_types();
    $rows   = db()->query('SELECT * FROM catalog ORDER BY type, name COLLATE NOCASE')->fetchAll();
    $today  = date('Y-m-d');
    $labels = ['valid' => 'valid', 'viitor' => 'din viitor', 'expirat' => 'expirat'];
    $count  = ['valid' => 0, 'viitor' => 0, 'expirat' => 0];
    $byType = array_fill_keys(array_keys($types), 0);
    $fmtD   = fn(string $d) => $d !== '' ? date('d.m.Y', strtotime($d)) : 'nelimitat';

    // Compoziția pachetelor și articolele grupate pe tip (pentru lista de componente disponibile).
    $all   = [];
    foreach ($rows as $c) {
        $all[$c['type']][] = $c;
    }
    $comps = []; // package_id => componente
    $used  = []; // item_id => [denumiri pachete]
    foreach (db()->query('SELECT ci.package_id, ci.qty, c.*, p.name AS package_name FROM catalog_items ci
                          JOIN catalog c ON c.id = ci.item_id JOIN catalog p ON p.id = ci.package_id
                          ORDER BY ci.ord, c.name COLLATE NOCASE')->fetchAll() as $ci) {
        $comps[(int)$ci['package_id']][] = $ci;
        $used[(int)$ci['id']][]          = (string)$ci['package_name'];
    }

    $list = '';
    foreach ($rows as $c) {
        $id   = (int)$c['id'];
        $st   = catalog_status($c, $today);
        $isPk = catalog_is_package((string)$c['type']);
        $count[$st]++;
        $byType[$c['type']] = ($byType[$c['type']] ?? 0) + 1;
        $extra = '';
        if ($isPk) {
            $n     = count($comps[$id] ?? []);
            $extra = ' · ' . $n . ($n === 1 ? ' componentă' : ' componente');
        } elseif (!empty($used[$id])) {
            $extra = ' · în ' . count($used[$id]) . (count($used[$id]) === 1 ? ' pachet' : ' pachete');
        }
        $q = $c['name'] . ' ' . $c['um'] . ' ' . ($types[$c['type']] ?? $c['type']);
        foreach ($comps[$id] ?? [] as $ci) {
            $q .= ' ' . $ci['name']; // un pachet se găsește și după componentele lui
        }
        $list .= '<div class="catrow st-' . $st . ($isPk ? ' pkg' : '') . '" id="k' . $id . '" data-type="' . h($c['type']) . '" data-status="' . $st . '" data-q="' . h(mb_strtolower($q)) . '">'
            . '<form method="post" class="cat">' . csrf_field() . '<input type="hidden" name="id" value="' . $id . '">'
            . '<select name="type" aria-label="Tip">' . catalog_type_options((string)$c['type']) . '</select>'
            . '<div class="mt"><input name="name" value="' . h($c['name']) . '" aria-label="Denumire" maxlength="200" required>'
            . '<small><span class="lk l-' . $st . '">' . $labels[$st] . '</span> · ' . h($fmtD((string)$c['valid_from'])) . ' → ' . h($fmtD((string)$c['valid_to']))
            . h($extra) . ' · actualizat ' . h(fmt_time((int)$c['updated_at'])) . '</small></div>'
            . '<input name="um" value="' . h($c['um']) . '" aria-label="UM" maxlength="20" required>'
            . '<input name="valid_from" type="date" value="' . h($c['valid_from']) . '" aria-label="Valid de la">'
            . '<input name="valid_to" type="date" value="' . h($c['valid_to']) . '" aria-label="Valid până la">'
            . '<button type="submit" class="sm" name="action" value="cat_save">Salvează</button>'
            . '<button type="submit" class="sm" name="action" value="cat_copy" title="Creează o copie">Copiază</button>'
            . '<button type="submit" class="sm bad" name="action" value="cat_delete">Șterge</button>'
            . '</form>'
            . ($isPk ? catalog_composition($c, $comps[$id] ?? [], $all, $today) : '')
            . '</div>';
    }

    $typeOpts = '';
    foreach ($types as $k => $label) {
        $typeOpts .= '<option value="' . $k . '">' . h($label) . ' (' . $byType[$k] . ')</option>';
    }

    return '<section class="panel"><h2>Articol nou în catalog</h2>'
        . '<form method="post" class="up catf">' . csrf_field() . '<input type="hidden" name="action" value="cat_save">'
        . '<div><label for="ctype">Tip</label><select id="ctype" name="type">' . catalog_type_options('serviciu') . '</select></div>'
        . '<div class="wide"><label for="cname">Denumire</label><input id="cname" name="name" maxlength="200" placeholder="ex. Implementare e2e OPS Salarizare" required></div>'
        . '<div><label for="cum">UM</label><input id="cum" name="um" maxlength="20" value="buc" list="umList" required>'
        . '<datalist id="umList"><option value="buc"><option value="ora"><option value="zi"><option value="luna"><option value="an"><option value="salariat"><option value="salariat/luna"><option value="utilizator"><option value="pachet"></datalist></div>'
        . '<div><label for="cfrom">Valid de la</label><input id="cfrom" name="valid_from" type="date" value="' . $today . '"></div>'
        . '<div><label for="cto">Valid până la</label><input id="cto" name="valid_to" type="date"></div>'
        . '<button type="submit" class="pri">Adaugă în catalog</button></form>'
        . '<p class="muted">Tipuri: serviciu, licență, serviciu digital, produs, pachet servicii, pachet produse. Lasă „Valid până la” gol pentru valabilitate nelimitată.'
        . ' Pachetele se compun din articole existente în catalog: pachetul de servicii din servicii, servicii digitale și licențe; pachetul de produse din produse și licențe.</p></section>'
        . '<section class="panel"><div class="ph"><h2>Catalog produse și servicii</h2>'
        . '<div class="rsum catsum"><span><b>' . count($rows) . '</b> articole</span><span><b class="ok">' . $count['valid'] . '</b> valide</span>'
        . '<span><b>' . $count['viitor'] . '</b> din viitor</span><span><b class="err">' . $count['expirat'] . '</b> expirate</span></div></div>'
        . '<div class="rtools cattools">'
        . '<div class="rsearch"><label for="kQ">Caută</label><input id="kQ" type="search" placeholder="denumire, UM, tip, componentă…"></div>'
        . '<div><label for="kType">Tip</label><select id="kType"><option value="">Toate tipurile</option>' . $typeOpts . '</select></div>'
        . '<div><label for="kSt">Valabilitate</label><select id="kSt"><option value="">Toate</option><option value="valid">Valide azi</option>'
        . '<option value="viitor">Din viitor</option><option value="expirat">Expirate</option></select></div></div>'
        . '<div class="cathead"><span>Tip</span><span>Denumire</span><span>UM</span><span>Valid de la</span><span>Valid până la</span><span></span><span></span><span></span></div>'
        . '<div class="matlist" id="kList">' . ($list ?: '<p class="muted">Catalogul este gol. Adaugă primul articol din formularul de mai sus.</p>') . '</div>'
        . '<p class="muted catempty" id="kEmpty" hidden>Niciun articol pentru filtrele alese.</p>'
        . '<p class="muted">Un articol expirat rămâne în catalog pentru istoric; ștergerea este definitivă. „Copiază” creează un articol nou cu aceleași date (la pachete, cu tot cu compoziție), util pentru o nouă perioadă de valabilitate.'
        . ' Un articol folosit într-un pachet nu poate fi șters și nu își poate schimba tipul cât timp este componentă.</p>'
        . '</section>';
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
// SECȚIUNEA RAPORTARE — tabel smartBIZ (după ConfigurableDataTableScreen din Time-Mobility-Tracker):
// panou de filtre facet, căutare, sortare în trei stări, grupare pe niveluri cu subtotaluri, KPI, coloane
// (afișare, ordine, fixare, lățime), selecție rânduri, export Excel / CSV / PDF, configurații salvate.
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

    // Pictograme inline (16px, currentColor), ca butoanele icon-only din tabelul smartBIZ.
    $svg = fn(string $d) => '<svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $d . '</svg>';
    $ic  = [
        'filter' => $svg('<path d="M2 3h12l-4.5 5.5v4L6.5 14V8.5L2 3z"/>'),
        'search' => $svg('<circle cx="7" cy="7" r="4.5"/><path d="M10.5 10.5L14 14"/>'),
        'save'   => $svg('<path d="M3 2h8l2.5 2.5V14H3z"/><path d="M5 2v4h5V2M5 14V9h6v5"/>'),
        'trash'  => $svg('<path d="M3 4h10M6 4V2.5h4V4M4.5 4l.7 9.5h5.6l.7-9.5M6.7 7v4M9.3 7v4"/>'),
        'cols'   => $svg('<rect x="2" y="3" width="12" height="10" rx="1.2"/><path d="M6.5 3v10M10.5 3v10"/>'),
        'more'   => $svg('<circle cx="3.5" cy="8" r="1.1" fill="currentColor"/><circle cx="8" cy="8" r="1.1" fill="currentColor"/><circle cx="12.5" cy="8" r="1.1" fill="currentColor"/>'),
    ];

    return '<section class="panel rep">'
        . '<div class="ph"><h2>Raportare oferte <button type="button" class="ico" id="rFiltBtn" title="Afișează filtrele" aria-label="Afișează filtrele" aria-expanded="false" aria-controls="rFilt">' . $ic['filter'] . '</button></h2>'
        . '<div class="rsearch">' . $ic['search'] . '<input id="rQ" type="search" placeholder="Caută în tabel" aria-label="Caută în tabel"></div>'
        . '<div class="acts">'
        . '<select id="rCfg" aria-label="Configurație tabel"><option value="">Configurație curentă</option></select>'
        . '<details class="rmenu rcfg"><summary class="ico" title="Salvează configurația" aria-label="Salvează configurația">' . $ic['save'] . '</summary>'
        . '<div><label for="rCfgName">Denumire</label><input id="rCfgName" maxlength="40" placeholder="ex. Oferte deschise pe județe"><button type="button" class="sm" id="rCfgSave">Salvează</button></div></details>'
        . '<button type="button" class="ico" id="rCfgDel" title="Șterge configurația" aria-label="Șterge configurația" disabled>' . $ic['trash'] . '</button>'
        . '<details class="rmenu rcols"><summary class="ico" title="Setări coloane" aria-label="Setări coloane">' . $ic['cols'] . '</summary><div id="rCols"></div></details>'
        . '<button type="button" class="sm" id="rReset">Resetează vederea</button>'
        . '<details class="rmenu rexp"><summary class="ico" title="Export" aria-label="Export">' . $ic['more'] . '</summary>'
        . '<div><button type="button" id="rXlsx">Export Excel</button><button type="button" id="rCsv">Export CSV</button><button type="button" id="rPdf">Export PDF</button></div></details>'
        . '</div></div>'
        . '<div class="rwrap" id="rWrap">'
        . '<aside class="rfilt" id="rFilt" hidden><div class="rfh"><span class="rft">Filtre <span class="badge" id="rFiltBadge"></span></span>'
        . '<button type="button" class="lnk" id="rFiltReset" disabled>Resetează filtrele</button></div>'
        . '<div id="rFacets"></div>'
        . '<section class="facet open"><div class="fh"><span class="ft">Creată</span></div><div class="fper">'
        . '<div><label for="rFrom">de la</label><input id="rFrom" type="date"></div><div><label for="rTo">până la</label><input id="rTo" type="date"></div></div></section>'
        . '</aside>'
        . '<div class="rmain">'
        . '<div class="rgroup"><span class="muted">Grupare</span><span id="rGroupChips"></span><select id="rGroupAdd" aria-label="Adaugă nivel de grupare"></select>'
        . '<label class="act"><input type="checkbox" id="rOnlyGroups" title="Ascunde rândurile detaliate și afișează numai grupările"> Numai grupările</label>'
        . '<button type="button" class="lnk" id="rCollapse" hidden>Restrânge tot</button></div>'
        . '<div class="kpis rk" id="rSum" aria-label="Indicatori raport"></div>'
        . '<div class="rsel" id="rSel" hidden></div>'
        . '<div class="rtw"><table class="rt" id="rTable"><thead></thead><tbody></tbody><tfoot></tfoot></table></div>'
        . '<p class="rfoot" id="rCount"></p>'
        . '<p class="muted">Valori în RON fără TVA. Onorariu an 1 = implementare + licență; economia client = 10.368 RON/an per salariat mobil.'
        . ' Exporturile conțin rândurile selectate sau, fără selecție, rândurile filtrate, în ordinea și cu coloanele afișate.</p>'
        . '</div></div>'
        . '<script type="application/json" id="repData">' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) . '</script>'
        . '<script>' . report_js() . '</script>'
        . '</section>';
}

function report_js(): string
{
    return <<<'JS'
(function () {
  var data = JSON.parse(document.getElementById('repData').textContent);
  // n = numeric, dec = zecimale, sum/avg = agregare în subtotaluri, chip = etichetă etapă,
  // f = filtru facet, g = grupabil, off = ascunsă implicit
  var COLS = [
    { k: 'id',         t: 'Nr.',                  n: true },
    { k: 'companie',   t: 'Companie',             f: true, g: true },
    { k: 'cui',        t: 'CUI' },
    { k: 'reg_com',    t: 'Nr. Reg. Com.',        off: true },
    { k: 'adresa',     t: 'Adresă',               off: true },
    { k: 'judet',      t: 'Județ',                off: true, f: true, g: true },
    { k: 'caen',       t: 'CAEN',                 off: true, f: true, g: true },
    { k: 'tva',        t: 'Plătitor TVA',         off: true, f: true, g: true },
    { k: 'inactiv',    t: 'Inactiv ANAF',         off: true, f: true, g: true },
    { k: 'persoana',   t: 'Persoană',             f: true, g: true },
    { k: 'email',      t: 'Email',                off: true },
    { k: 'sablon',     t: 'Șablon',               f: true, g: true },
    { k: 'mat',        t: 'Materiale citite' },
    { k: 'materiale',  t: 'Materiale trimise',    off: true },
    { k: 'etapa',      t: 'Etapă',                chip: true, f: true, g: true },
    { k: 'max',        t: 'Etapă maximă',         off: true, f: true, g: true },
    { k: 'creata',     t: 'Creată' },
    { k: 'luna',       t: 'Luna creării',         off: true, f: true, g: true },
    { k: 'deschisa',   t: 'Prima deschidere' },
    { k: 'zile',       t: 'Zile până la deschidere', n: true, dec: 1, avg: true },
    { k: 'ultima',     t: 'Ultima deschidere',    off: true },
    { k: 'deschideri', t: 'Deschideri',           n: true, sum: true },
    { k: 'salariati',  t: 'Salariați mobili',     n: true, sum: true },
    { k: 'onorariu',   t: 'Onorariu an 1',        n: true, sum: true },
    { k: 'licenta',    t: 'Licență / an',         n: true, sum: true, off: true },
    { k: 'economie',   t: 'Economie client / an', n: true, sum: true },
    { k: 'link',       t: 'Link',                 f: true, g: true },
    { k: 'expira',     t: 'Expiră',               off: true },
    { k: 'nota',       t: 'Notă',                 off: true }
  ];
  var BY = {}; COLS.forEach(function (c) { BY[c.k] = c; });
  var STAGE_K = {}; data.forEach(function (r) { STAGE_K[r.etapa] = r.etapa_k; });
  var EMPTY = 'Fără valoare', FACET_SEARCH_MIN = 8, MAX_PIN = 3;
  var KEY = 'ofertare_raport_v3', CFG_KEY = 'ofertare_raport_cfg';
  var def = { q: '', from: '', to: '', filters: {}, groups: [], onlyGroups: false, collapsed: [], sort: '', dir: 0,
              hidden: COLS.filter(function (c) { return c.off; }).map(function (c) { return c.k; }),
              order: COLS.map(function (c) { return c.k; }), pinned: [], widths: {}, panel: false, cfg: '' };
  var clone = function (o) { return JSON.parse(JSON.stringify(o)); };
  var st = clone(def);
  try { var saved = JSON.parse(localStorage.getItem(KEY) || 'null'); if (saved) { st = Object.assign(clone(def), saved); } } catch (e) {}
  var cfgs = {};
  try { cfgs = JSON.parse(localStorage.getItem(CFG_KEY) || '{}') || {}; } catch (e) {}
  var sel = {}, open = {}, fq = {}, pinCount = 0; // selecție, facet deschis, căutare în facet: nu se memorează

  var $ = function (id) { return document.getElementById(id); };
  var fmtN = new Intl.NumberFormat('ro-RO', { maximumFractionDigits: 0 });
  var fmt1 = new Intl.NumberFormat('ro-RO', { minimumFractionDigits: 1, maximumFractionDigits: 1 });
  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); };
  var low = function (s) { return String(s == null ? '' : s).toLocaleLowerCase('ro'); };
  var cmpS = function (a, b) { return String(a).localeCompare(String(b), 'ro', { sensitivity: 'base', numeric: true }); };
  var blank = function (v) { return v === null || v === undefined || String(v).trim() === ''; };
  var cell = function (c, v) {
    if (blank(v)) { return c.n ? '' : '—'; }
    if (c.n) { return c.dec ? fmt1.format(v) : fmtN.format(v); }
    return String(v);
  };
  var fval = function (r, k) { return blank(r[k]) ? EMPTY : String(r[k]).trim(); }; // valoarea pentru facet și grupare
  var save = function () { try { localStorage.setItem(KEY, JSON.stringify(st)); } catch (e) {} };
  var saveCfgs = function () { try { localStorage.setItem(CFG_KEY, JSON.stringify(cfgs)); } catch (e) {} };
  var activeFilters = function () { return Object.keys(st.filters).filter(function (k) { return BY[k] && st.filters[k].length; }); };
  var filterCount = function () { return activeFilters().length + ((st.from || st.to) ? 1 : 0); };

  // Coloanele vizibile, în ordine: cele fixate primele.
  function cols() {
    var ord = st.order.filter(function (k) { return BY[k]; });
    COLS.forEach(function (c) { if (ord.indexOf(c.k) === -1) { ord.push(c.k); } });
    var vis = ord.filter(function (k) { return st.hidden.indexOf(k) === -1; });
    var pin = st.pinned.filter(function (k) { return vis.indexOf(k) !== -1; });
    return pin.concat(vis.filter(function (k) { return pin.indexOf(k) === -1; })).map(function (k) { return BY[k]; });
  }

  // --- filtrare: perioadă, facete (OR în coloană, AND între coloane), căutare în textul tuturor coloanelor; sortare în trei stări
  function filtered() {
    var q = low(st.q.trim()), act = activeFilters();
    var rows = data.filter(function (r) {
      if (st.from && r.creata < st.from) { return false; }
      if (st.to && r.creata > st.to) { return false; }
      for (var i = 0; i < act.length; i++) { if (st.filters[act[i]].indexOf(fval(r, act[i])) === -1) { return false; } }
      if (q && !COLS.some(function (c) { return low(cell(c, r[c.k])).indexOf(q) !== -1; })) { return false; }
      return true;
    });
    if (st.sort && st.dir && BY[st.sort]) {
      var c = BY[st.sort];
      rows = rows.slice().sort(function (a, b) {
        var x = a[c.k], y = b[c.k], bx = blank(x), by = blank(y);
        if (bx && by) { return 0; }
        if (bx || by) { return (bx ? -1 : 1) * st.dir; } // valorile goale primele, ca în tabelul smartBIZ
        return (c.n ? x - y : cmpS(x, y)) * st.dir;
      });
    }
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
  var labelSpan = function (cs) { var s = 0; while (s < cs.length && !cs[s].sum && !cs[s].avg) { s++; } return Math.max(1, s); };
  function aggCells(rows, cs, from) {
    var t = totals(rows);
    return cs.slice(from).map(function (c) {
      return (c.sum || c.avg) ? '<td class="n">' + (t[c.k] === null ? '' : (c.avg ? '⌀ ' : '') + cell(c, t[c.k])) + '</td>' : '<td></td>';
    }).join('');
  }

  // --- rânduri și grupări (plain = pentru tipărire: fără selecție, fără coloane fixate, grupările extinse)
  function rowHtml(r, cs, plain) {
    var html = plain ? '<tr>' : '<tr data-id="' + r.id + '"' + (sel[r.id] ? ' class="on"' : '') + '><td class="sel"><input type="checkbox" data-sel="' + r.id + '" aria-label="Selectează rândul"' + (sel[r.id] ? ' checked' : '') + '></td>';
    cs.forEach(function (c, i) {
      var v = cell(c, r[c.k]);
      var cls = (c.n ? 'n' : (c.k === 'nota' || c.k === 'materiale' || c.k === 'adresa' ? 'wrap' : '')) + (!plain && i < pinCount ? ' pin' : '');
      if (c.chip) { html += '<td class="' + cls + '"><span class="chip s-' + esc(r.etapa_k) + '">' + esc(v) + '</span></td>'; }
      else if (c.k === 'link') { html += '<td class="' + cls + '"><span class="lk l-' + esc(r.link) + '">' + esc(v) + '</span></td>'; }
      else if (c.k === 'companie' && !plain) { html += '<td class="' + cls + '"><a href="admin.php?v=pipeline#c' + r.id + '">' + esc(v) + '</a></td>'; }
      else { html += '<td class="' + cls + '">' + esc(v) + '</td>'; }
    });
    return html + '</tr>';
  }
  function groupHtml(rows, cs, level, parent, plain) {
    if (level >= st.groups.length) { return st.onlyGroups ? '' : rows.map(function (r) { return rowHtml(r, cs, plain); }).join(''); }
    var k = st.groups[level], buckets = {}, keys = [];
    rows.forEach(function (r) { var g = fval(r, k); if (!buckets[g]) { buckets[g] = []; keys.push(g); } buckets[g].push(r); });
    keys.sort(cmpS);
    var span = labelSpan(cs), html = '';
    keys.forEach(function (g) {
      var gid = parent + '\u001f' + g, col = !plain && st.collapsed.indexOf(gid) !== -1;
      html += '<tr class="grp l' + level + (col ? ' col' : '') + '"' + (plain ? '' : ' data-gid="' + esc(gid) + '"') + '>' + (plain ? '' : '<td class="sel"></td>')
        + '<td colspan="' + span + '" style="padding-left:' + (10 + level * 16) + 'px">' + (plain ? '' : '<span class="caret" aria-hidden="true">' + (col ? '▸' : '▾') + '</span> ')
        + '<b>' + esc(BY[k].t) + ': ' + esc(g) + '</b> <span class="cnt">' + buckets[g].length + '</span></td>' + aggCells(buckets[g], cs, span) + '</tr>';
      if (!col) { html += groupHtml(buckets[g], cs, level + 1, gid, plain); }
    });
    return html;
  }
  function allGids(rows, level, parent, out) {
    if (level >= st.groups.length) { return out; }
    var k = st.groups[level], buckets = {};
    rows.forEach(function (r) { var g = fval(r, k); (buckets[g] = buckets[g] || []).push(r); });
    Object.keys(buckets).forEach(function (g) { var gid = parent + '\u001f' + g; out.push(gid); allGids(buckets[g], level + 1, gid, out); });
    return out;
  }
  function bodyHtml(rows, cs, plain) {
    return st.groups.length ? groupHtml(rows, cs, 0, '', plain) : (st.onlyGroups ? '' : rows.map(function (r) { return rowHtml(r, cs, plain); }).join(''));
  }
  function totalHtml(rows, cs, plain) {
    return '<tr class="tot">' + (plain ? '' : '<td class="sel"></td>') + '<td colspan="' + labelSpan(cs) + '"><b>Total</b> <span class="cnt">' + rows.length + '</span></td>' + aggCells(rows, cs, labelSpan(cs)) + '</tr>';
  }

  // --- antet: sortare, fixare, redimensionare, reordonare prin tragere
  var PIN = '<svg width="13" height="13" viewBox="0 0 16 16" aria-hidden="true"><path class="ph" d="M10 1.5l4.5 4.5-2.6.5L9.6 8.8 9 13 3 7l4.2-.6 2.3-2.3z"/><path class="pn" d="M5.8 10.2L1.5 14.5"/></svg>';
  var sortIcon = function (dir) {
    return '<svg class="si' + (dir ? ' on' : '') + '" width="12" height="12" viewBox="0 0 12 12" aria-hidden="true">'
      + (dir > 0 ? '<path d="M6 10V2M3 5l3-3 3 3"/>' : dir < 0 ? '<path d="M6 2v8M3 7l3 3 3-3"/>' : '<path d="M4 1v10M2 3l2-2 2 2M8 11V1M6 9l2 2 2-2"/>') + '</svg>';
  };
  function headHtml(cs, rows) {
    var all = rows.length > 0 && rows.every(function (r) { return sel[r.id]; });
    var h = '<tr><th class="sel pin" style="left:0"><input type="checkbox" id="rSelAll" aria-label="Selectează rândurile vizibile"' + (all ? ' checked' : '') + '></th>';
    cs.forEach(function (c, i) {
      var dir = st.sort === c.k ? st.dir : 0, w = st.widths[c.k], pinned = st.pinned.indexOf(c.k) !== -1;
      h += '<th class="' + (c.n ? 'n' : '') + (i < pinCount ? ' pin' : '') + '" data-k="' + c.k + '" draggable="true" tabindex="0"'
        + (w ? ' style="width:' + w + 'px;min-width:' + w + 'px;max-width:' + w + 'px"' : '')
        + ' aria-sort="' + (dir > 0 ? 'ascending' : dir < 0 ? 'descending' : 'none') + '" title="Sortează · trage pentru reordonare">'
        + '<span class="tht"><span class="thl">' + esc(c.t) + '</span>' + sortIcon(dir)
        + '<button type="button" class="pinb' + (pinned ? ' on' : '') + '" data-pin="' + c.k + '" title="' + (pinned ? 'Desprinde coloana' : 'Fixează coloana (max. ' + MAX_PIN + ')') + '" aria-label="' + (pinned ? 'Desprinde coloana' : 'Fixează coloana') + '">' + PIN + '</button></span>'
        + '<span class="rz" data-rz="' + c.k + '" aria-hidden="true"></span></th>';
    });
    return h + '</tr>';
  }
  function stickyOffsets() {
    var T = $('rTable'), ths = T.tHead.rows[0] ? T.tHead.rows[0].cells : [], left = 0;
    for (var i = 0; i < ths.length && ths[i].classList.contains('pin'); i++) {
      var l = left;
      Array.prototype.forEach.call(T.rows, function (tr) { var td = tr.cells[i]; if (td && td.classList.contains('pin')) { td.style.left = l + 'px'; } });
      left += ths[i].offsetWidth;
    }
  }

  // --- panoul de filtre facet
  function facetOptions(k) {
    var counts = {}, keys = [];
    data.forEach(function (r) { var v = fval(r, k); if (!(v in counts)) { counts[v] = 0; keys.push(v); } counts[v]++; }); // numărate pe toate rândurile
    (st.filters[k] || []).forEach(function (v) { if (!(v in counts)) { counts[v] = 0; keys.push(v); } }); // o valoare bifată care nu mai apare rămâne, ca să poată fi debifată
    keys.sort(cmpS);
    return keys.map(function (v) { return { v: v, n: counts[v] }; });
  }
  function facetOptionsHtml(k, opts) {
    var q = low((fq[k] || '').trim()), selv = st.filters[k] || [];
    var vis = q ? opts.filter(function (o) { return low(o.v).indexOf(q) !== -1; }) : opts;
    if (!vis.length) { return '<div class="fempty">Nu există valori.</div>'; }
    return vis.map(function (o) {
      var dot = '<span class="fdot' + (k === 'etapa' && STAGE_K[o.v] ? ' s-' + esc(STAGE_K[o.v]) : (k === 'link' ? ' l-' + esc(o.v) : '')) + '"></span>';
      return '<label class="fo"><input type="checkbox" data-f="' + esc(k) + '" value="' + esc(o.v) + '"' + (selv.indexOf(o.v) !== -1 ? ' checked' : '') + '>' + dot
        + '<span class="fl" title="' + esc(o.v) + '">' + esc(o.v) + '</span><span class="fc">' + o.n + '</span></label>';
    }).join('');
  }
  function renderFacets(rows) {
    $('rFacets').innerHTML = COLS.filter(function (c) { return c.f; }).map(function (c) {
      var opts = facetOptions(c.k), selv = st.filters[c.k] || [], isOpen = !!open[c.k], searchable = opts.length > FACET_SEARCH_MIN;
      return '<section class="facet' + (isOpen ? ' open' : '') + '"><div class="fh" data-facet="' + c.k + '">'
        + '<button type="button" class="ftog" aria-expanded="' + isOpen + '"><span class="ft">' + esc(c.t) + '</span></button><span class="fa">'
        + (selv.length ? '<button type="button" class="lnk fr" data-freset="' + c.k + '">Reset</button><span class="badge">' + selv.length + '/' + opts.length + '</span>' : '')
        + '<span class="chev" aria-hidden="true"></span></span></div>'
        + (isOpen ? '<div class="fb">' + (searchable ? '<input type="search" class="fs" data-fs="' + c.k + '" value="' + esc(fq[c.k] || '') + '" placeholder="Caută ' + esc(c.t.toLowerCase()) + '" aria-label="Caută ' + esc(c.t.toLowerCase()) + '">' : '')
          + '<div class="fos" data-fos="' + c.k + '">' + facetOptionsHtml(c.k, opts) + '</div></div>' : '')
        + '</section>';
    }).join('');
    var n = filterCount();
    $('rFiltReset').textContent = 'Resetează filtrele' + (n ? ' (' + n + ')' : '');
    $('rFiltReset').disabled = !n;
    $('rFiltBadge').textContent = rows.length + '/' + data.length;
    $('rFiltBtn').classList.toggle('on', n > 0);
    $('rFiltBtn').title = $('rFiltBtn').getAttribute('aria-label') + (n ? ' · ' + n + ' active' : '');
  }
  function syncPanel() {
    $('rFilt').hidden = !st.panel;
    $('rWrap').classList.toggle('open', st.panel);
    var b = $('rFiltBtn'), label = st.panel ? 'Ascunde filtrele' : 'Afișează filtrele';
    b.setAttribute('aria-expanded', st.panel ? 'true' : 'false'); b.setAttribute('aria-label', label); b.title = label;
  }

  // --- grupare, KPI, coloane, selecție, subsol
  function renderGroups() {
    $('rGroupChips').innerHTML = st.groups.map(function (k, i) {
      return '<span class="gchip"><span class="gi">' + (i + 1) + '</span>' + esc(BY[k].t) + '<button type="button" class="gx" data-gdel="' + k + '" aria-label="Scoate nivelul ' + esc(BY[k].t) + '">×</button></span>';
    }).join('');
    $('rGroupAdd').innerHTML = '<option value="">' + (st.groups.length ? '+ nivel…' : 'Fără grupare') + '</option>'
      + COLS.filter(function (c) { return c.g && st.groups.indexOf(c.k) === -1; }).map(function (c) { return '<option value="' + c.k + '">' + esc(c.t) + '</option>'; }).join('');
    $('rOnlyGroups').checked = st.onlyGroups; $('rOnlyGroups').disabled = !st.groups.length;
    $('rCollapse').hidden = !st.groups.length;
    $('rCollapse').textContent = allCollapsed() ? 'Extinde tot' : 'Restrânge tot';
  }
  // „Restrânge tot” cât timp mai există un grup deschis; „Extinde tot” doar când toate sunt restrânse.
  function allCollapsed() {
    var all = allGids(filtered(), 0, '', []);
    return all.length > 0 && all.every(function (g) { return st.collapsed.indexOf(g) !== -1; });
  }
  function renderKpi(rows) {
    var t = totals(rows), acc = rows.filter(function (r) { return r.etapa_k === 'acceptata'; }).length, opened = rows.filter(function (r) { return r.deschisa; }).length;
    var tile = function (l, v, s, cls) { return '<div class="kpi' + (cls ? ' ' + cls : '') + '"><span>' + l + '</span><b>' + v + '</b><small>' + (s || '&nbsp;') + '</small></div>'; };
    $('rSum').innerHTML = tile('Oferte', rows.length, 'din ' + data.length)
      + tile('Deschise', (rows.length ? Math.round(100 * opened / rows.length) : 0) + '%', opened + ' oferte')
      + tile('Acceptate', acc, (rows.length ? Math.round(100 * acc / rows.length) : 0) + '% conversie')
      + tile('Salariați mobili', fmtN.format(t.salariati || 0), '')
      + tile('Onorariu an 1', fmtN.format(t.onorariu || 0) + ' RON', '', 'pipe')
      + tile('Economie client / an', fmtN.format(t.economie || 0) + ' RON', '', 'won');
  }
  function renderCols() {
    var ord = st.order.filter(function (k) { return BY[k]; });
    $('rCols').innerHTML = '<div class="fhdr">Afișare</div>' + ord.map(function (k) {
      return '<label><input type="checkbox" data-k="' + k + '"' + (st.hidden.indexOf(k) === -1 ? ' checked' : '') + '> ' + esc(BY[k].t) + '</label>';
    }).join('') + '<p class="help">Trage antetele pentru ordine, pictograma de fixare ține coloana la stânga (max. ' + MAX_PIN + '), marginea dreaptă a antetului schimbă lățimea.</p>';
  }
  function renderCfg() {
    var names = Object.keys(cfgs).sort(cmpS);
    if (st.cfg && !cfgs[st.cfg]) { st.cfg = ''; }
    $('rCfg').innerHTML = '<option value="">Configurație curentă</option>' + names.map(function (n) { return '<option value="' + esc(n) + '"' + (n === st.cfg ? ' selected' : '') + '>' + esc(n) + '</option>'; }).join('');
    $('rCfgDel').disabled = !st.cfg;
  }
  function renderSel(rows) {
    var ids = rows.filter(function (r) { return sel[r.id]; }).length;
    $('rSel').hidden = !ids;
    $('rSel').innerHTML = ids ? '<b>' + ids + '</b> rânduri selectate · exporturile iau doar selecția <button type="button" class="lnk" id="rSelNone">Deselectează</button>' : '';
    $('rCount').textContent = 'În baza de date: ' + data.length + ' · Afișate: ' + rows.length + (ids ? ' · Selectate: ' + ids : '');
  }

  function render() {
    var rows = filtered(), cs = cols(), T = $('rTable');
    pinCount = cs.filter(function (c) { return st.pinned.indexOf(c.k) !== -1; }).length;
    T.tHead.innerHTML = headHtml(cs, rows);
    T.tBodies[0].innerHTML = bodyHtml(rows, cs, false)
      || '<tr><td colspan="' + (cs.length + 1) + '" class="empty">' + (rows.length ? 'Rândurile sunt ascunse: „Numai grupările” este bifat.' : 'Nu există înregistrări pentru criteriul curent.') + '</td></tr>';
    T.tFoot.innerHTML = rows.length ? totalHtml(rows, cs, false) : '';
    stickyOffsets();
    renderKpi(rows); renderFacets(rows); renderGroups(); renderCols(); renderCfg(); renderSel(rows);
    save();
  }
  function sync() {
    $('rQ').value = st.q; $('rFrom').value = st.from; $('rTo').value = st.to;
    syncPanel();
  }
  function apply(state) { st = Object.assign(clone(def), clone(state)); sel = {}; sync(); render(); }

  // --- evenimente
  $('rQ').addEventListener('input', function (e) { st.q = e.target.value; render(); });
  $('rFrom').addEventListener('change', function (e) { st.from = e.target.value; render(); });
  $('rTo').addEventListener('change', function (e) { st.to = e.target.value; render(); });
  $('rFiltBtn').addEventListener('click', function () { st.panel = !st.panel; syncPanel(); save(); });
  $('rFiltReset').addEventListener('click', function () { st.filters = {}; st.from = ''; st.to = ''; fq = {}; sync(); render(); });
  $('rFacets').addEventListener('click', function (e) {
    var reset = e.target.closest('[data-freset]');
    if (reset) { delete st.filters[reset.dataset.freset]; render(); return; }
    var fh = e.target.closest('.fh');
    if (fh) { open[fh.dataset.facet] = !open[fh.dataset.facet]; render(); }
  });
  $('rFacets').addEventListener('change', function (e) {
    var cb = e.target.closest('input[data-f]');
    if (!cb) { return; }
    var k = cb.dataset.f, v = cb.value, cur = st.filters[k] || [];
    st.filters[k] = cb.checked ? cur.concat([v]) : cur.filter(function (x) { return x !== v; });
    if (!st.filters[k].length) { delete st.filters[k]; }
    render();
  });
  $('rFacets').addEventListener('input', function (e) {
    var fs = e.target.closest('input[data-fs]');
    if (!fs) { return; }
    fq[fs.dataset.fs] = fs.value; // doar lista de valori se reîmprospătează, ca să nu se piardă focusul
    $('rFacets').querySelector('[data-fos="' + fs.dataset.fs + '"]').innerHTML = facetOptionsHtml(fs.dataset.fs, facetOptions(fs.dataset.fs));
  });
  $('rGroupAdd').addEventListener('change', function (e) { if (e.target.value) { st.groups.push(e.target.value); st.collapsed = []; render(); } });
  $('rGroupChips').addEventListener('click', function (e) {
    var b = e.target.closest('[data-gdel]');
    if (b) { st.groups = st.groups.filter(function (k) { return k !== b.dataset.gdel; }); st.collapsed = []; if (!st.groups.length) { st.onlyGroups = false; } render(); }
  });
  $('rOnlyGroups').addEventListener('change', function (e) { st.onlyGroups = e.target.checked; render(); });
  $('rCollapse').addEventListener('click', function () { st.collapsed = allCollapsed() ? [] : allGids(filtered(), 0, '', []); render(); });
  $('rTable').tBodies[0].addEventListener('click', function (e) {
    if (e.target.closest('a, input')) { return; }
    var g = e.target.closest('tr.grp');
    if (g) {
      var gid = g.dataset.gid, i = st.collapsed.indexOf(gid);
      if (i === -1) { st.collapsed.push(gid); } else { st.collapsed.splice(i, 1); }
      render();
    }
  });
  $('rTable').addEventListener('change', function (e) {
    var cb = e.target.closest('input[data-sel]');
    if (cb) { if (cb.checked) { sel[cb.dataset.sel] = true; } else { delete sel[cb.dataset.sel]; } render(); return; }
    if (e.target.id === 'rSelAll') {
      var rows = filtered();
      rows.forEach(function (r) { if (e.target.checked) { sel[r.id] = true; } else { delete sel[r.id]; } });
      render();
    }
  });
  $('rSel').addEventListener('click', function (e) { if (e.target.id === 'rSelNone') { sel = {}; render(); } });
  $('rCols').addEventListener('change', function (e) {
    var k = e.target.dataset.k;
    if (!k) { return; }
    st.hidden = e.target.checked ? st.hidden.filter(function (x) { return x !== k; }) : st.hidden.concat([k]);
    render();
  });

  // antet: sortare în trei stări (crescător → descrescător → fără), fixare, reordonare, lățime
  var head = $('rTable').tHead, dragK = null;
  var sortBy = function (th) {
    if (!th || !th.dataset.k) { return; }
    if (st.sort !== th.dataset.k) { st.sort = th.dataset.k; st.dir = 1; }
    else if (st.dir === 1) { st.dir = -1; }
    else { st.sort = ''; st.dir = 0; }
    render();
  };
  head.addEventListener('click', function (e) {
    var pin = e.target.closest('[data-pin]');
    if (pin) {
      var k = pin.dataset.pin, i = st.pinned.indexOf(k);
      if (i !== -1) { st.pinned.splice(i, 1); } else if (st.pinned.length < MAX_PIN) { st.pinned.push(k); }
      render(); return;
    }
    if (e.target.closest('.rz') || e.target.id === 'rSelAll') { return; }
    sortBy(e.target.closest('th'));
  });
  head.addEventListener('keydown', function (e) { if ((e.key === 'Enter' || e.key === ' ') && e.target.tagName === 'TH') { e.preventDefault(); sortBy(e.target); } });
  head.addEventListener('dragstart', function (e) {
    var th = e.target.closest('th[data-k]');
    if (!th || e.target.closest('.rz')) { e.preventDefault(); return; }
    dragK = th.dataset.k; e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', dragK); th.classList.add('drag');
  });
  head.addEventListener('dragover', function (e) { var th = e.target.closest('th[data-k]'); if (dragK && th && th.dataset.k !== dragK) { e.preventDefault(); th.classList.add('over'); } });
  head.addEventListener('dragleave', function (e) { var th = e.target.closest('th'); if (th) { th.classList.remove('over'); } });
  head.addEventListener('dragend', function () { dragK = null; Array.prototype.forEach.call(head.querySelectorAll('.drag, .over'), function (x) { x.classList.remove('drag', 'over'); }); });
  head.addEventListener('drop', function (e) {
    var th = e.target.closest('th[data-k]');
    if (!dragK || !th || th.dataset.k === dragK) { return; }
    e.preventDefault();
    var ord = st.order.filter(function (k) { return k !== dragK; }), at = ord.indexOf(th.dataset.k);
    ord.splice(at + (e.offsetX > th.offsetWidth / 2 ? 1 : 0), 0, dragK);
    st.order = ord; dragK = null; render();
  });
  head.addEventListener('mousedown', function (e) {
    var rz = e.target.closest('.rz');
    if (!rz) { return; }
    e.preventDefault();
    var th = rz.parentNode, k = rz.dataset.rz, x0 = e.clientX, w0 = th.offsetWidth;
    var move = function (ev) { var w = Math.max(60, Math.min(600, w0 + ev.clientX - x0)); th.style.width = th.style.minWidth = th.style.maxWidth = w + 'px'; st.widths[k] = w; };
    var up = function () { document.removeEventListener('mousemove', move); document.removeEventListener('mouseup', up); render(); };
    document.addEventListener('mousemove', move); document.addEventListener('mouseup', up);
  });

  // configurații salvate (în browser)
  $('rReset').addEventListener('click', function () { var panel = st.panel; apply(Object.assign(clone(def), { panel: panel })); });
  $('rCfg').addEventListener('change', function (e) {
    var name = e.target.value;
    if (!name) { apply(Object.assign(clone(def), { panel: st.panel })); return; }
    if (cfgs[name]) { apply(Object.assign(clone(cfgs[name]), { cfg: name, panel: st.panel })); }
  });
  $('rCfgSave').addEventListener('click', function () {
    var name = $('rCfgName').value.trim();
    if (!name) { $('rCfgName').focus(); return; }
    st.cfg = name; cfgs[name] = clone(st); saveCfgs();
    $('rCfgName').value = ''; document.querySelector('.rcfg').open = false; render();
  });
  $('rCfgDel').addEventListener('click', function () {
    if (!st.cfg) { return; }
    delete cfgs[st.cfg]; saveCfgs(); apply(Object.assign(clone(def), { panel: st.panel }));
  });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { Array.prototype.forEach.call(document.querySelectorAll('details.rmenu[open]'), function (d) { d.open = false; }); } });
  document.addEventListener('click', function (e) { Array.prototype.forEach.call(document.querySelectorAll('details.rmenu[open]'), function (d) { if (!d.contains(e.target)) { d.open = false; } }); });

  // --- export: Excel (xlsx nativ, fără biblioteci), CSV, PDF (tipărire); selecția are prioritate
  var exportRows = function () { var rows = filtered(), s = rows.filter(function (r) { return sel[r.id]; }); return s.length ? s : rows; };
  var hasSel = function () { return filtered().some(function (r) { return sel[r.id]; }); };
  var fileName = function (ext) { return 'raport_ofertare_' + (hasSel() ? 'selectie' : 'lista') + '_' + new Date().toISOString().slice(0, 10) + '.' + ext; };
  var download = function (blob, name) {
    var a = document.createElement('a');
    a.href = URL.createObjectURL(blob); a.download = name;
    document.body.appendChild(a); a.click(); a.remove();
    setTimeout(function () { URL.revokeObjectURL(a.href); }, 1000);
  };
  var closeMenus = function () { Array.prototype.forEach.call(document.querySelectorAll('details.rmenu[open]'), function (d) { d.open = false; }); };

  var CRC = (function () { var t = [], c; for (var n = 0; n < 256; n++) { c = n; for (var k = 0; k < 8; k++) { c = c & 1 ? 0xEDB88320 ^ (c >>> 1) : c >>> 1; } t[n] = c >>> 0; } return t; })();
  var crc32 = function (b) { var c = -1; for (var i = 0; i < b.length; i++) { c = CRC[(c ^ b[i]) & 0xff] ^ (c >>> 8); } return (c ^ -1) >>> 0; };
  // Arhivă zip fără compresie (metoda „store”): suficientă pentru un xlsx și nu are nevoie de biblioteci.
  function zipStore(files) {
    var enc = new TextEncoder(), parts = [], cd = [], off = 0, n = 0, d = new Date();
    var dt = ((d.getFullYear() - 1980) << 9) | ((d.getMonth() + 1) << 5) | d.getDate(), tm = (d.getHours() << 11) | (d.getMinutes() << 5) | (d.getSeconds() >> 1);
    var u16 = function (v) { return [v & 255, (v >>> 8) & 255]; }, u32 = function (v) { return [v & 255, (v >>> 8) & 255, (v >>> 16) & 255, (v >>> 24) & 255]; };
    Object.keys(files).forEach(function (name) {
      var nm = enc.encode(name), body = enc.encode(files[name]), crc = crc32(body);
      var head = new Uint8Array([].concat(u32(0x04034b50), u16(20), u16(0x0800), u16(0), u16(tm), u16(dt), u32(crc), u32(body.length), u32(body.length), u16(nm.length), u16(0)));
      parts.push(head, nm, body);
      cd.push(new Uint8Array([].concat(u32(0x02014b50), u16(20), u16(20), u16(0x0800), u16(0), u16(tm), u16(dt), u32(crc), u32(body.length), u32(body.length), u16(nm.length), u16(0), u16(0), u16(0), u16(0), u32(0), u32(off))), nm);
      off += head.length + nm.length + body.length; n++;
    });
    var cdLen = cd.reduce(function (s, p) { return s + p.length; }, 0);
    parts = parts.concat(cd, [new Uint8Array([].concat(u32(0x06054b50), u16(0), u16(0), u16(n), u16(n), u32(cdLen), u32(off), u16(0)))]);
    return new Blob(parts, { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' });
  }
  function xlsx(cs, rows) {
    var colL = function (i) { var s = ''; i++; while (i) { var m = (i - 1) % 26; s = String.fromCharCode(65 + m) + s; i = (i - m - 1) / 26; } return s; };
    var xe = function (s) { return String(s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }).replace(/[\u0000-\u0008\u000b\u000c\u000e-\u001f]/g, ''); };
    var last = colL(cs.length - 1) + (rows.length + 1);
    var sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
      + '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
      + '<cols>' + cs.map(function (c, i) { return '<col min="' + (i + 1) + '" max="' + (i + 1) + '" width="' + Math.min(50, Math.max(10, c.t.length + 4)) + '" customWidth="1"/>'; }).join('') + '</cols><sheetData>'
      + '<row r="1">' + cs.map(function (c, i) { return '<c r="' + colL(i) + '1" t="inlineStr" s="1"><is><t>' + xe(c.t) + '</t></is></c>'; }).join('') + '</row>';
    rows.forEach(function (r, ri) {
      sheet += '<row r="' + (ri + 2) + '">' + cs.map(function (c, i) {
        var v = r[c.k], ref = colL(i) + (ri + 2);
        if (blank(v)) { return '<c r="' + ref + '"/>'; }
        if (c.n) { return '<c r="' + ref + '"' + (c.dec ? ' s="2"' : '') + '><v>' + v + '</v></c>'; } // numerele rămân numere
        return '<c r="' + ref + '" t="inlineStr"><is><t xml:space="preserve">' + xe(v) + '</t></is></c>';
      }).join('') + '</row>';
    });
    sheet += '</sheetData><autoFilter ref="A1:' + last + '"/></worksheet>';
    var NS = 'http://schemas.openxmlformats.org/';
    return zipStore({
      '[Content_Types].xml': '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="' + NS + 'package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>',
      '_rels/.rels': '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="' + NS + 'package/2006/relationships"><Relationship Id="rId1" Type="' + NS + 'officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
      'xl/workbook.xml': '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="' + NS + 'spreadsheetml/2006/main" xmlns:r="' + NS + 'officeDocument/2006/relationships"><sheets><sheet name="Raport oferte" sheetId="1" r:id="rId1"/></sheets></workbook>',
      'xl/_rels/workbook.xml.rels': '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="' + NS + 'package/2006/relationships"><Relationship Id="rId1" Type="' + NS + 'officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="' + NS + 'officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',
      'xl/styles.xml': '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="' + NS + 'spreadsheetml/2006/main"><numFmts count="1"><numFmt numFmtId="164" formatCode="#,##0.0"/></numFmts><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellXfs count="3"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" applyFont="1"/><xf numFmtId="164" fontId="0" fillId="0" borderId="0" applyNumberFormat="1"/></cellXfs></styleSheet>',
      'xl/worksheets/sheet1.xml': sheet
    });
  }
  $('rXlsx').addEventListener('click', function () { closeMenus(); download(xlsx(cols(), exportRows()), fileName('xlsx')); });

  $('rCsv').addEventListener('click', function () {
    closeMenus();
    var cs = cols(), rows = exportRows();
    var q = function (v) { v = v === null || v === undefined ? '' : String(v); return /[;"\n]/.test(v) ? '"' + v.replace(/"/g, '""') + '"' : v; };
    var num = function (c, v) { return v === null || v === undefined || v === '' ? '' : (c.dec ? String(v).replace('.', ',') : String(v)); };
    var lines = [cs.map(function (c) { return q(c.t); }).join(';')];
    rows.forEach(function (r) { lines.push(cs.map(function (c) { return c.n ? num(c, r[c.k]) : q(r[c.k]); }).join(';')); });
    var t = totals(rows);
    lines.push(cs.map(function (c, i) { return i === 0 ? 'Total ' + rows.length : (c.sum ? String(t[c.k]) : ''); }).join(';'));
    download(new Blob(['﻿' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8' }), fileName('csv'));
  });

  // PDF: fereastră de tipărire (Print / Save as PDF) cu KPI, grupări cu subtotaluri și total; linii punctate subțiri.
  $('rPdf').addEventListener('click', function () {
    closeMenus();
    var cs = cols(), rows = exportRows(), w = window.open('', '_blank');
    if (!w) { alert('Browserul a blocat fereastra de tipărire. Permite ferestrele pop-up pentru acest site.'); return; }
    var meta = [new Date().toLocaleString('ro-RO'), 'rânduri: ' + rows.length + (hasSel() ? ' (selecție)' : ''), filterCount() ? 'filtre active: ' + filterCount() : '', st.q ? 'căutare: „' + st.q + '”' : '',
                st.groups.length ? 'grupare: ' + st.groups.map(function (k) { return BY[k].t; }).join(' › ') : ''].filter(Boolean).join(' · ');
    var kpi = $('rSum').innerHTML;
    var html = '<!doctype html><html lang="ro"><head><meta charset="utf-8"><title>Raport oferte</title><style>'
      + '@page{size:A4 landscape;margin:12mm}body{margin:0;color:#1b2430;font:11px/1.4 "IBM Plex Sans",Arial,sans-serif}h1{font-size:16px;margin:0 0 2px;color:#1F4788}'
      + '.meta{font-size:10px;color:#5b6672;margin:0 0 10px}.kpis{display:flex;flex-wrap:wrap;gap:8px;margin:0 0 12px}.kpi{border:1px dotted #9aa6b3;border-radius:4px;padding:6px 10px;min-width:130px}'
      + '.kpi span{display:block;font-size:9px;color:#5b6672}.kpi b{display:block;font-size:14px}.kpi small{font-size:9px;color:#5b6672}'
      + 'table{border-collapse:collapse;width:100%}th,td{border-bottom:1px dotted #9aa6b3;padding:4px 6px;text-align:left;vertical-align:top}th{color:#1F4788;border-bottom:1px solid #1F4788}'
      + 'thead{display:table-header-group}tr{page-break-inside:avoid}.n{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}tr.grp td{background:#eef2f5;font-weight:600}tr.grp.l1 td{background:#f6f8fa}'
      + 'tr.tot td{border-top:1px solid #1F4788;border-bottom:0;font-weight:700}.cnt{font-size:9px;color:#5b6672}.chip,.lk{font-size:10px}.wrap{min-width:160px}'
      + '</style></head><body><h1>Raport oferte · smartBIZ Copilot · e2e OPS Ofertare</h1><p class="meta">' + esc(meta) + '</p><div class="kpis">' + kpi + '</div>'
      + '<table><thead><tr>' + cs.map(function (c) { return '<th class="' + (c.n ? 'n' : '') + '">' + esc(c.t) + '</th>'; }).join('') + '</tr></thead>'
      + '<tbody>' + (bodyHtml(rows, cs, true) || '<tr><td colspan="' + cs.length + '">Nu există înregistrări pentru criteriul curent.</td></tr>') + '</tbody>'
      + (rows.length ? '<tfoot>' + totalHtml(rows, cs, true) + '</tfoot>' : '') + '</table></body></html>';
    w.document.open(); w.document.write(html); w.document.close();
    w.addEventListener('load', function () { w.focus(); w.print(); });
  });

  sync();
  render();
})();
JS;
}

// ===========================================================================
// SECȚIUNEA AJUTOR (ghid de utilizare; pasul cu pasul: cum ajung materialele în email)
// ===========================================================================
function view_help(): string
{
    $maxMb   = (int)(cfg('max_upload_mb') ?: 25);
    $otpMin  = (int)cfg('otp_minutes');
    $otpTry  = (int)cfg('otp_max_tries');
    $otpSend = (int)cfg('otp_max_sends');
    $sessH   = (int)cfg('session_hours');
    $days    = (int)cfg('default_days');
    $notify  = (string)cfg('notify_email');
    $base    = h(base_url());
    $notifyTxt = $notify !== '' ? 'Prima deschidere a unei oferte trimite o notificare la <b>' . h($notify) . '</b>.'
                                : 'Notificarea la prima deschidere este oprită (<code>notify_email</code> gol în <code>config.php</code>).';

    $toc = '<nav class="hnav" aria-label="Secțiuni ajutor" id="hNav">'
        . '<a href="#h-flux">Fluxul pe scurt</a><a href="#h-materiale">Materialele în email</a><a href="#h-pipeline">Pipeline</a>'
        . '<a href="#h-raportare">Raportare</a><a href="#h-catalog">Catalog</a><a href="#h-sabloane">Șabloane email</a>'
        . '<a href="#h-mat">Materiale</a><a href="#h-utilizatori">Utilizatori</a><a href="#h-email">Livrarea emailurilor</a><a href="#h-probleme">Probleme frecvente</a></nav>';

    return '<section class="panel hp"><div class="ph"><h2>Ajutor</h2></div>' . $toc

        . '<div class="hsecs"><section class="hsec" id="h-flux"><h3>Fluxul pe scurt</h3>'
        . '<ol class="steps">'
        . '<li><b>Materiale</b>: încarci oferta și materialele suport (PDF, HTML, DOCX, XLSX, PPTX, PNG, JPG, MP4).</li>'
        . '<li><b>Șabloane email</b>: scrii textul emailului, pui <code>{{materiale}}</code> pe un rând separat și bifezi materialele trimise cu șablonul, în ordine.</li>'
        . '<li><b>Pipeline → Ofertă nouă</b>: CUI (date de la ANAF), persoana, emailul, salariații mobili, șablonul; materialele se bifează automat după șablon.</li>'
        . '<li>La trimitere, fiecare material primește un <b>link personal</b> care apare în email ca buton. Clientul deschide linkul cu un <b>cod de 6 cifre</b> primit pe emailul lui.</li>'
        . '<li>Cardul trece automat în <b>Deschisă</b> la prima deschidere; negocierea, acceptarea sau pierderea le setezi tu, din kanban sau din „Detalii”.</li>'
        . '</ol>'

        . '</section><section class="hsec" id="h-materiale"><h3>Cum ajung materialele suport în email, pas cu pas</h3>'
        . '<p>Un material ajunge în email numai dacă trece prin toate cele trei locuri: este <b>încărcat și activ</b> în Materiale, este <b>bifat în șablon</b> (sau bifat manual la oferta nouă) și șablonul conține <b>rândul <code>{{materiale}}</code></b>, care spune unde se pun butoanele.</p>'
        . '<ol class="steps">'
        . '<li><b>Încarcă materialul</b> în secțiunea <a href="admin.php?v=materiale">Materiale</a> → „Încarcă material”.'
        . '<ul><li><b>Titlu afișat în email</b>: exact textul butonului pe care îl vede clientul (ex. „Studiu de caz construcții”). Dacă îl lași gol, se folosește numele fișierului.</li>'
        . '<li><b>Tip</b>: „Ofertă” pentru documentul principal, „Material suport” pentru restul. Tipul este o etichetă de organizare (apare lângă nume în liste); în email, toate materialele arată la fel, iar ceea ce contează este <b>ordinea</b> (pasul 2).</li>'
        . '<li><b>Fișier</b>: maximum ' . $maxMb . ' MB. HTML-ul se deschide direct în browser, PDF-ul la fel; DOCX/XLSX/PPTX se descarcă.</li></ul></li>'
        . '<li><b>Adaugă-l în șablon</b>, în <a href="admin.php?v=sabloane">Șabloane email</a>, la „Materiale trimise cu acest șablon (bifă + ordine)”.'
        . '<ul><li>Bifezi materialul și completezi <b>ordinea</b>: 1, 2, 3… <b>Materialul cu ordinea cea mai mică devine butonul principal</b>, colorat (de regulă oferta); următoarele apar ca butoane cu contur, în ordinea dată.</li>'
        . '<li>Apasă „Salvează șablonul”. Lista arată doar materialele <b>active</b>; un material dezactivat dispare din șabloane.</li></ul></li>'
        . '<li><b>Pune <code>{{materiale}}</code> în textul șablonului</b>, acolo unde vrei butoanele.'
        . '<ul><li>Trebuie să fie <b>singur pe rând, cu un rând gol înainte și unul după</b> (adică un paragraf de sine stătător). Exemplu:</li></ul>'
        . "<pre>Oferta completă este disponibilă la linkurile personale de mai jos:\n\n{{materiale}}\n\nPentru întrebări, răspundeți direct la acest email.</pre>"
        . '<ul><li>Dacă <code>{{materiale}}</code> lipsește, <b>emailul pleacă fără niciun link</b>, chiar dacă materialele sunt bifate. Dacă îl scrii în interiorul unui paragraf, rămâne ca text și butoanele nu apar.</li>'
        . '<li>Sub butoane se adaugă automat textul „Linkurile sunt personale. La deschidere primiți un cod de acces pe această adresă de email.”</li>'
        . '<li>Panoul „Previzualizare” arată emailul cu date de exemplu și cu butoanele materialelor bifate, după ultima salvare.</li></ul></li>'
        . '<li><b>Trimite oferta</b> din <a href="admin.php?v=pipeline">Pipeline → Ofertă nouă</a>.'
        . '<ul><li>Alegi șablonul: materialele lui se <b>bifează automat</b> în „Materiale trimise”. Poți debifa un material sau bifa altele <b>doar pentru oferta aceasta</b>, fără să modifici șablonul.</li>'
        . '<li>Ordinea în email: întâi materialele șablonului, în ordinea lor, apoi cele bifate suplimentar.</li>'
        . '<li>„Trimite acum emailul” bifat trimite imediat; debifat, oferta se creează și primești linkurile ca să le trimiți tu.</li>'
        . '<li>La creare, <b>fiecare material bifat primește un link personal</b> de forma <code>' . $base . 'abc…</code>. Linkurile se afișează <b>o singură dată</b> în mesajul de confirmare; în baza de date se păstrează doar amprentele lor.</li></ul></li>'
        . '<li><b>Ce vede clientul.</b> În email, butoanele cu titlurile materialelor (în varianta text, „Titlu: link”). La primul click primește pe <b>adresa lui</b> un cod de 6 cifre, valabil ' . $otpMin . ' minute (maximum ' . $otpTry . ' încercări per cod și ' . $otpSend . ' coduri pe oră). '
        . 'Un singur cod deschide <b>toate materialele din același email</b> timp de ' . $sessH . ' ore. Linkurile expiră după numărul de zile ales la ofertă (implicit ' . $days . '). '
        . 'Un link redirecționat altcuiva ajunge tot la pagina de cod, iar codul pleacă tot la destinatarul inițial. Dacă debifezi „Cod de acces pe email”, linkurile se deschid direct.</li>'
        . '<li><b>Ce vezi tu după trimitere.</b> Pe card, fiecare material cu numărul de deschideri („necitit” / „2×”); cardul trece automat în „Deschisă”. ' . $notifyTxt
        . ' În Materiale vezi, per material, în câte șabloane apare, de câte ori a fost trimis și deschis.</li>'
        . '<li><b>Întreținere.</b> „Înlocuiește fișierul” <b>păstrează linkurile deja trimise</b>: clienții deschid noua versiune. Debifarea „Activ” oprește deschiderea materialului și îl scoate din șabloane. '
        . 'Pe card, în Detalii: „Retrimite emailul (linkuri noi)” generează linkuri noi și retrimite emailul (cele vechi nu mai merg), „Revocă toate linkurile ofertei” oprește imediat accesul, „Șterge oferta” o elimină definitiv, cu tot cu linkuri și jurnal.</li>'
        . '</ol>'

        . '</section><section class="hsec" id="h-email"><h3>Livrarea emailurilor: Microsoft 365, SMTP sau mail()</h3>'
        . '<p>Acum emailurile pleacă prin <b>' . h(mail_transport()) . '</b>. Expeditorul este <code>' . h((string)cfg('mail_from')) . '</code>.</p>'
        . '<ul><li><b>Microsoft 365 prin Graph API</b> (recomandat, emailul aiall.ro este în M365): emailul pleacă din căsuța <code>mail_from</code> din M365, cu SPF/DKIM/DMARC ale domeniului, exact ca în portalul ContaCheck. '
        . 'Cockpitul primește răspunsul Graph: „trimis” = acceptat de Microsoft, o eroare = textul ei exact. Se configurează în <code>ofertare_private/config.php</code>: <code>graph_tenant_id</code>, <code>graph_client_id</code>, <code>graph_client_secret</code> '
        . '(Entra ID → App registrations → aplicația folosită și de ContaCheck → Tenant ID, Client ID, un Client secret) și permisiunea de aplicație Graph <b>Mail.Send</b> cu consimțământ de administrator. Căsuța <code>mail_from</code> trebuie să existe în M365.</li>'
        . '<li><b>SMTP autentificat</b> (dacă Graph nu este configurat): <code>smtp_host</code>, <code>smtp_port</code>, <code>smtp_secure</code>, <code>smtp_user</code>, <code>smtp_pass</code>. Pentru M365: <code>smtp.office365.com</code>, 587, <code>tls</code>, căsuța și parola ei, cu „Authenticated SMTP” activ pe căsuță (Microsoft dezactivează treptat autentificarea de bază, de aceea Graph este varianta sigură).</li>'
        . '<li><b>mail()</b> (dacă nimic nu este configurat) predă mesajul serverului local al hostingului și raportează „trimis” când acesta l-a <b>pus în coadă</b>, nu când a fost livrat. '
        . 'Cu emailul domeniului în M365, SPF-ul domeniului nu autorizează serverul web, deci destinatarii resping sau marchează ca spam mesajele, deși cockpitul a afișat „trimis”.</li>'
        . '<li><code>mail_reply_to</code> (unde ajung răspunsurile clienților) și <code>mail_bcc</code> (copie ascunsă a fiecărui email trimis) sunt opționale și merg cu toate cele trei moduri.</li>'
        . '<li><b>Verifică livrarea</b> cu „Email de test” din <a href="admin.php?v=sabloane">Șabloane email</a> (panoul Previzualizare): trimite șablonul cu date de exemplu la adresa ta și arată rezultatul real.</li>'
        . '<li>Jurnalul fiecărei oferte (card → Detalii) reține „email trimis prin …” sau „email eșuat: motivul”.</li></ul>'

        . '</section><section class="hsec" id="h-pipeline"><h3>Pipeline</h3>'
        . '<ul><li><b>Indicatorii</b> de sus: oferte în lucru, rată de deschidere, conversie, pipeline și câștigat (onorariu an 1), economie generată clienților. Valorile pornesc de la „Salariați mobili”: ' . ron(ONORARIU_AN1_PER_SALARIAT) . ' RON onorariu an 1, ' . ron(LICENTA_AN_PER_SALARIAT) . ' RON licență din anul 2, ' . ron(ECONOMIE_AN_PER_SALARIAT) . ' RON economie client, per salariat.</li>'
        . '<li><b>Pâlnia</b> numără etapa maximă atinsă; o ofertă pierdută rămâne numărată la etapa la care a ajuns.</li>'
        . '<li><b>Ofertă nouă</b>: scrii CUI-ul și apeși „ANAF” (sau Enter): se completează denumirea, Reg. Com., adresa, județul, CAEN, telefonul, TVA și inactivitatea. Datele apar pe card și în Raportare.</li>'
        . '<li><b>Kanban</b>: tragi cardul între coloane sau alegi etapa din „Detalii”. „Trimisă” și „Deschisă” se setează automat. În „Detalii” ai nota internă, jurnalul evenimentelor, retrimiterea emailului cu linkuri noi, revocarea linkurilor și ștergerea ofertei (cu confirmare).</li></ul>'

        . '</section><section class="hsec" id="h-raportare"><h3>Raportare</h3>'
        . '<ul><li>Tabel cu toate ofertele: căutare liberă, filtre pe etapă, șablon, stare link și perioada creării; sortare pe orice coloană (clic pe antet); grupare cu subtotaluri; alegerea coloanelor.</li>'
        . '<li>Vederea (filtre, sortare, grupare, coloane) se păstrează în browser; „Resetează vederea” o aduce la implicit.</li>'
        . '<li>„Export CSV (Excel)” exportă rândurile filtrate, în ordinea și cu coloanele afișate (UTF-8, separator „;”).</li></ul>'

        . '</section><section class="hsec" id="h-catalog"><h3>Catalog</h3>'
        . '<ul><li>Articole cu tip (serviciu, licență, serviciu digital, produs, pachet servicii, pachet produse), denumire, UM și perioadă de valabilitate; „Valid până la” gol = nelimitat.</li>'
        . '<li>Starea față de azi: valid, din viitor, expirat. Căutare și filtre pe tip și valabilitate.</li>'
        . '<li><b>Pachetele</b> se compun din articole existente: pachetul de servicii din servicii, servicii digitale și licențe; pachetul de produse din produse și licențe. În secțiunea „Compoziție” a pachetului adaugi componente cu cantitate.</li>'
        . '<li>Un articol folosit într-un pachet nu poate fi șters și nu își poate schimba tipul într-unul neacceptat; mesajul de eroare spune ce trebuie scos mai întâi. „Copiază” pe un pachet copiază și compoziția.</li></ul>'

        . '</section><section class="hsec" id="h-sabloane"><h3>Șabloane email</h3>'
        . '<ul><li><b>Format text</b>: rând gol = paragraf nou; rânduri consecutive = listă compactă; <code>✓ </code> la începutul rândului = bifă colorată; <code>**text**</code> = bold.</li>'
        . '<li><b>Variabile</b>: <code>{{salut}}</code> („Bună ziua, Nume,”), <code>{{nume}}</code>, <code>{{companie}}</code>, <code>{{email}}</code>, <code>{{salariati}}</code>, <code>{{economie_an}}</code> (salariați × ' . ron(ECONOMIE_AN_PER_SALARIAT) . ' RON), <code>{{expira}}</code> (data expirării linkurilor); <code>{{materiale}}</code> pe rând separat = butoanele cu linkurile personale. Variabilele merg și în subiect.</li>'
        . '<li>Șabloanele se pot copia și șterge; ofertele deja trimise păstrează emailul și linkurile lor.</li></ul>'

        . '</section><section class="hsec" id="h-mat"><h3>Materiale</h3>'
        . '<ul><li>Încărcare: PDF, HTML, DOCX, XLSX, PPTX, PNG, JPG, MP4, maximum ' . $maxMb . ' MB. Fișierele stau în <code>ofertare_private/fisiere</code>, în afara folderului public, și se servesc doar prin linkurile personale.</li>'
        . '<li>Pentru fiecare material: titlu, tip, statistici (șabloane, trimiteri, deschideri), „Înlocuiește fișierul”, „Activ”. Un fișier lipsă de pe server este marcat cu roșu.</li></ul>'

        . '</section><section class="hsec" id="h-utilizatori"><h3>Utilizatori</h3>'
        . '<ul><li>„Parola mea”: parola actuală + parola nouă (minimum 10 caractere), repetată.</li>'
        . '<li>„Utilizator nou”: nume de utilizator (litere mici, cifre, . _ @ -), nume afișat, parolă; lăsată goală, parola se generează și se afișează o singură dată.</li>'
        . '<li>Un utilizator dezactivat nu se mai poate autentifica. Propriul cont nu poate fi dezactivat sau șters. Contul din <code>config.php</code> rămâne cale de recuperare cât timp nu există în listă un utilizator cu același nume.</li></ul>'

        . '</section><section class="hsec" id="h-probleme"><h3>Probleme frecvente</h3>'
        . '<ul>'
        . '<li><b>Cockpitul arată „trimis”, dar emailul nu ajunge.</b> Cu mail(), „trimis” înseamnă doar pus în coada serverului local, iar cu emailul domeniului în Microsoft 365 mesajele plecate de pe serverul web pică la SPF. Configurează Microsoft 365 prin Graph (secțiunea „Livrarea emailurilor”), trimite un „Email de test” din Șabloane email, apoi retrimite ofertele de pe card cu „Retrimite emailul (linkuri noi)”.</li>'
        . '<li><b>Emailul a plecat fără butoane.</b> Șablonul nu are <code>{{materiale}}</code> singur pe rând, sau niciun material nu era bifat la trimitere. Verifică în Previzualizare și pe card (lista materialelor).</li>'
        . '<li><b>Un material nu apare în șablon sau la „Ofertă nouă”.</b> Este dezactivat. Bifează „Activ” în Materiale și salvează.</li>'
        . '<li><b>Clientul spune că linkul nu merge.</b> Linkurile sunt expirate (data de expirare de pe card), revocate, sau materialul a fost dezactivat. Trimite o ofertă nouă sau reactivează materialul.</li>'
        . '<li><b>Clientul nu primește codul.</b> Codul pleacă doar la adresa din ofertă; verifică folderul de spam, limita de ' . $otpSend . ' coduri pe oră și ca expeditorul (<code>mail_from</code>) să fie o căsuță reală pe domeniu, ca să treacă de SPF/DKIM.</li>'
        . '<li><b>Vreau să schimb documentul fără să retrimit emailul.</b> „Înlocuiește fișierul” la material: linkurile deja trimise deschid noua versiune.</li>'
        . '<li><b>Vreau să opresc accesul la o ofertă.</b> Pe card, Detalii → „Revocă toate linkurile ofertei”.</li>'
        . '</ul>'
        . '</section></div><p class="muted">Documentația completă, inclusiv instalarea și structura pe server, este în README-ul proiectului.</p>'
        . '</section>';
}

// ===========================================================================
// Randare pagină
// ===========================================================================
$views   = [
    'pipeline'  => ['Pipeline',       fn() => view_pipeline($csrf)],
    'raportare' => ['Raportare',      fn() => view_report()],
    'catalog'   => ['Catalog',        fn() => view_catalog()],
    'sabloane'  => ['Șabloane email', fn() => view_templates()],
    'materiale' => ['Materiale',      fn() => view_materials()],
    'utilizatori' => ['Utilizatori',  fn() => view_users($me)],
    'ajutor'    => ['Ajutor',         fn() => view_help()],
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
.top{background:linear-gradient(115deg,#1F4788 0%,#24608f 55%,#2D8B8E 100%);color:#fff;box-shadow:0 4px 24px rgba(13,21,32,.22);position:sticky;top:0;z-index:50}
.top .in{max-width:1440px;margin:0 auto;padding:10px 20px 0;display:flex;align-items:center;gap:14px;flex-wrap:wrap}
.top .logo{display:flex;align-items:center;gap:11px}
.top .mark{width:34px;height:34px;border-radius:9px;display:grid;place-items:center;background:linear-gradient(135deg,#2D8B8E,#1F4788);border:1px solid rgba(255,255,255,.28);font:700 13px var(--mono);color:#fff;letter-spacing:-.02em}
.top .ttl{line-height:1.15}
.sbc-title{font-weight:700;font-size:16px;letter-spacing:-.3px}
.sbc-smart{color:#F2F6FA;font-weight:600}.sbc-biz{color:#3aacb0;font-weight:700}.sbc-copilot{color:#f0a94a;font-weight:600}
.top h1{font:500 12px var(--sans);margin:1px 0 0;opacity:.85}
.top .sp{flex:1}
/* Utilizatorul și ieșirea, într-un pill ca în celelalte aplicații smartBIZ */
.hd-pill{display:inline-flex;align-items:stretch;background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.22);border-radius:9px;overflow:hidden;backdrop-filter:blur(6px)}
.hd-pill .who{display:inline-flex;align-items:center;gap:8px;padding:3px 11px 3px 4px;color:#fff;text-decoration:none;font:500 12px var(--sans);transition:.15s}
.hd-pill .who:hover{background:rgba(255,255,255,.14)}
.hd-pill .av{width:26px;height:26px;border-radius:50%;display:grid;place-items:center;background:linear-gradient(135deg,#2D8B8E,#1F4788);border:1px solid rgba(255,255,255,.28);font:700 11px var(--mono);letter-spacing:-.02em}
.hd-pill .nm{max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.hd-pill form{display:flex;margin:0}
.top .hd-pill button{background:transparent;border:0;border-left:1px solid rgba(255,255,255,.22);border-radius:0;color:rgba(255,255,255,.85);font:600 11px/1 var(--mono);padding:0 12px;cursor:pointer;transition:.15s}
.top .hd-pill button:hover{color:#fff;background:rgba(255,255,255,.14)}
@media (max-width:600px){.hd-pill .nm{display:none}.hd-pill .who{padding-right:6px}}
.foot{border-top:1px solid var(--line);margin-top:8px}
.foot .in{max-width:1440px;margin:0 auto;padding:14px 20px;display:flex;justify-content:flex-end;font-size:12px;color:var(--muted)}
.top button{background:transparent;border:1px solid rgba(255,255,255,.5);color:#fff;border-radius:4px;padding:4px 12px;font:500 13px var(--sans);cursor:pointer}
.top nav{flex-basis:100%;display:flex;gap:4px;margin-top:8px;overflow-x:auto}
.top nav a{color:#fff;opacity:.75;text-decoration:none;padding:8px 14px;border-radius:4px 4px 0 0;font-weight:500;white-space:nowrap}
.top nav a:hover{opacity:1}
.top nav a[aria-current]{opacity:1;background:var(--soft);color:var(--navy);font-weight:600;box-shadow:inset 0 3px 0 var(--teal)}
main{max-width:1440px;margin:0 auto;padding:0 20px 20px}
/* Conținutul secțiunii alese stă într-un container distinct, legat vizual de tab-ul activ din meniu */
.tabpanel{background:var(--soft);border:1px solid var(--line);border-top:0;border-radius:0 0 8px 8px;padding:14px 18px 18px;display:grid;gap:18px}
.tabtitle{font-size:13px;font-weight:600;color:var(--navy);margin:0;padding-bottom:10px;border-bottom:1px solid var(--line);letter-spacing:.02em;text-transform:uppercase}
@media (max-width:980px){.tabpanel{padding:12px 12px 14px}}
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
.newf .fbtns>button:first-child,.pri{margin-top:12px;background:var(--teal);color:var(--surface);border:0;border-radius:4px;padding:8px 16px}
.fbtns{display:flex;gap:10px;align-items:center;flex-wrap:wrap}.fbtns .sm{margin-top:12px;padding:8px 14px}
/* Previzualizarea emailului înainte de trimitere */
.pdlg{width:min(760px,96vw);border:1px solid var(--line);border-radius:8px;padding:0;background:var(--surface);color:var(--ink);box-shadow:0 20px 60px rgba(0,0,0,.3)}
.pdlg::backdrop{background:rgba(13,21,32,.55)}
.pdh{padding:14px 18px 10px;border-bottom:1px solid var(--line)}.pdh h2{margin:0 0 4px;font-size:15px;color:var(--navy)}.pdh p{margin:0;overflow-wrap:anywhere}
.pdlg iframe{display:block;width:100%;height:min(62vh,760px);border:0;background:#fff}
.pdf{display:flex;gap:10px;align-items:center;padding:10px 18px;border-top:1px solid var(--line)}.pdf .sp{flex:1}.pdf .pri{margin:0}
/* Pipeline: kanban sau tabel */
.seg{gap:0}.seg .sm{border-radius:0;margin:0}.seg .sm:first-child{border-radius:4px 0 0 4px}.seg .sm:last-child{border-radius:0 4px 4px 0;border-left:0}
.seg .sm.tgl{padding:5px 9px;display:inline-flex;align-items:center;line-height:0;color:var(--navy)}.seg .sm.tgl svg{width:18px;height:18px;flex:none;fill:currentColor}
.seg .sm[aria-pressed="true"]{background:var(--teal);color:var(--surface)}
#pipePanel.mode-table .pm-kanban,#pipePanel:not(.mode-table) .pm-table{display:none}
.dtw{max-height:none}.dt td{white-space:normal;vertical-align:middle}.dt td.wrap{min-width:160px}
.dt .trow select{padding:4px 26px 4px 8px;font-size:12px;min-width:130px}
.dt .tacts{white-space:nowrap}.dt .tacts form{display:inline-block;margin-right:10px}.dt .tacts .lnk{margin:0}
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
.lnk{background:none;border:0;padding:0;margin-top:6px;font-size:12px;color:var(--teal)}.lnk.bad{color:var(--red)}
.cardacts{display:flex;flex-wrap:wrap;gap:4px 14px;margin-top:4px;padding-top:6px;border-top:1px dashed var(--line)}
.testf{margin:0 0 10px}.testf label{margin-bottom:3px}
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
/* Catalog produse și servicii */
.catf{grid-template-columns:170px minmax(240px,3fr) 120px 160px 160px auto}
@media (max-width:1100px){.catf{grid-template-columns:repeat(auto-fit,minmax(200px,1fr))}.catf .wide{grid-column:1/-1}}
.catsum{border:0;padding:0 0 8px;margin:0}
.cattools{grid-template-columns:minmax(220px,2fr) repeat(2,minmax(160px,1fr))}
@media (max-width:1200px){.cattools{grid-template-columns:repeat(auto-fit,minmax(160px,1fr))}}
.cathead,.cat{display:grid;grid-template-columns:170px minmax(220px,3fr) 110px 150px 150px auto auto auto;gap:10px;align-items:center}
.cathead{font-size:12px;color:var(--navy);font-weight:600;padding:0 12px 4px}
.catrow{border:1px solid var(--line);border-radius:5px}
.cat{padding:10px 12px}
.catrow.st-expirat{opacity:.65}.catrow.st-viitor{border-left:3px solid var(--amber)}.catrow.st-valid{border-left:3px solid var(--green)}
.catrow.hide{display:none}
.lk.l-valid{color:var(--green)}.lk.l-viitor{color:var(--amber)}
.comp{margin:0;border-top:1px dashed var(--line);background:var(--soft);padding:6px 12px 10px;border-radius:0 0 5px 5px}
.comp summary{font-size:12px;color:var(--navy)}.comp summary b{font-family:var(--mono)}
.complist{display:grid;gap:4px;margin:8px 0}
.ci{display:grid;grid-template-columns:130px minmax(200px,1fr) 90px 90px auto auto;gap:8px;align-items:center;background:var(--surface);border:1px solid var(--line);border-radius:4px;padding:6px 10px}
.ci .mt b{font-size:13px}.ci .um{font-size:12px;color:var(--muted);overflow-wrap:anywhere}
.ci.add{grid-template-columns:minmax(260px,1fr) 90px auto;background:transparent;border:0;padding:0}
.ci input,.ci select{padding:5px 8px;font-size:13px}.ci select{padding-right:28px}
.kt-serviciu,.kt-serviciu_digital{color:var(--teal)}.kt-licenta{color:var(--amber)}.kt-produs{color:var(--navy3)}
@media (max-width:1100px){.cathead{display:none}.cat{grid-template-columns:1fr 1fr}.cat .mt{grid-column:1/-1}.ci{grid-template-columns:1fr 1fr}.ci .mt{grid-column:1/-1}.ci.add{grid-template-columns:1fr}}
/* Ajutor */
.hp{max-width:980px;line-height:1.6}
.hnav{display:flex;flex-wrap:wrap;gap:6px;margin:-4px 0 14px}
.hnav a{font-size:12px;font-weight:600;color:var(--teal);text-decoration:none;border:1px solid var(--line);border-radius:12px;padding:3px 10px;background:var(--bg)}
.hnav a:hover{border-color:var(--teal)}.hnav a[aria-current]{background:var(--teal);color:var(--surface);border-color:var(--teal)}
/* Fiecare secțiune de ajutor stă în containerul ei și se vede doar când este aleasă din bara de mai sus */
.hsec{display:none;background:var(--bg);border:1px solid var(--line);border-radius:6px;padding:12px 18px 6px}.hsec.on{display:block}
.hp h3{font-size:15px;color:var(--navy);margin:0 0 10px;scroll-margin-top:12px}
.hp p{margin:0 0 10px}
.hp ul,.hp ol{margin:0 0 10px;padding-left:22px}.hp li{margin:0 0 6px}.hp li ul{margin:4px 0 0}.hp li ul li{margin:0 0 3px;color:var(--muted)}
.hp li ul li b,.hp li ul li code{color:var(--ink)}
.steps>li::marker{color:var(--teal);font-weight:700}
.hp pre{font:12px/1.6 var(--mono);background:var(--bg);border:1px solid var(--line);border-radius:4px;padding:8px 12px;margin:6px 0 8px;white-space:pre-wrap}
.hp a{color:var(--teal)}
/* Raportare — tabel smartBIZ */
.rep .ph{align-items:center;gap:10px}.rep .ph h2{display:flex;align-items:center;gap:8px}
.ico{background:transparent;border:1px solid var(--line);color:var(--muted);border-radius:4px;width:30px;height:30px;display:inline-grid;place-items:center;padding:0;cursor:pointer;flex:none}
.ico:hover,.ico.on{color:var(--teal);border-color:var(--teal)}.ico:disabled{opacity:.4;cursor:default}.ico svg{width:15px;height:15px}
.rsearch{position:relative;flex:1;min-width:200px;max-width:380px}.rsearch svg{position:absolute;left:9px;top:50%;transform:translateY(-50%);width:14px;height:14px;color:var(--muted);pointer-events:none}
.rsearch input{padding-left:30px}
.rep .acts{align-items:center;flex-wrap:wrap}.rep .acts select{width:auto;max-width:220px;padding:5px 28px 5px 8px;font-size:12px}.sm2{margin:0;padding:6px 12px}
.rmenu{position:relative}.rmenu summary{list-style:none}.rmenu summary::-webkit-details-marker{display:none}
.rmenu>div{position:absolute;right:0;top:34px;z-index:6;background:var(--surface);border:1px solid var(--line);border-radius:5px;padding:8px 10px;display:grid;gap:5px;min-width:220px;box-shadow:0 6px 18px rgba(0,0,0,.12)}
.rcols>div{max-height:60vh;overflow:auto}.rcols label{display:flex;gap:6px;align-items:center;color:var(--ink);font-size:13px;margin:0}.rcols input{width:auto}.fhdr{font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.04em}
.rexp>div button{background:none;border:0;text-align:left;padding:6px 8px;border-radius:4px;color:var(--ink);font:500 13px var(--sans);cursor:pointer}.rexp>div button:hover{background:var(--soft)}
.rcfg>div{min-width:260px}.rcfg label{margin:0}.rcfg .sm{justify-self:start}
.rwrap{display:grid;grid-template-columns:minmax(0,1fr);gap:14px;align-items:start}.rwrap.open{grid-template-columns:250px minmax(0,1fr)}
@media (max-width:900px){.rwrap.open{grid-template-columns:1fr}}
.rfilt{border:1px solid var(--line);border-radius:5px;padding:4px 12px 8px;max-height:78vh;overflow:auto;background:var(--surface)}
.rfh{display:flex;justify-content:space-between;align-items:center;gap:8px;padding:8px 0;border-bottom:1px solid var(--line)}.rft{font-weight:600;font-size:13px;display:inline-flex;align-items:center;gap:8px}
.badge{min-width:26px;height:18px;border-radius:999px;background:color-mix(in srgb,var(--teal) 14%,transparent);color:var(--teal);display:inline-flex;align-items:center;justify-content:center;font:600 11px var(--mono);padding:0 6px}
.facet{border-bottom:1px solid var(--line);padding:8px 0}.facet:last-child{border-bottom:0}
.fh{display:flex;align-items:center;justify-content:space-between;gap:8px;cursor:pointer}.ftog{background:none;border:0;padding:0;font:inherit;color:var(--ink);text-align:left;min-width:0;flex:1;cursor:pointer}
.ft{font-size:13px}.fa{display:inline-flex;align-items:center;gap:8px;flex:none}.fr{margin:0;font-size:12px;color:var(--teal)}
.chev{width:7px;height:7px;border-right:1.6px solid var(--muted);border-bottom:1.6px solid var(--muted);transform:rotate(45deg);margin:-4px 4px 0 2px;transition:transform .15s}.facet.open .chev{transform:rotate(-135deg);margin-top:4px}
.fb{margin-top:8px}.fs{margin-bottom:8px;font-size:12px;padding:5px 8px}.fos{display:grid;gap:6px;max-height:210px;overflow:auto;padding-right:4px}
.fo{display:grid;grid-template-columns:16px 8px minmax(0,1fr) auto;align-items:center;gap:6px;font-size:13px;color:var(--ink);margin:0;cursor:pointer}.fo input{width:16px;height:16px;margin:0}
.fdot{width:8px;height:8px;border-radius:999px}.fdot.s-trimisa{background:var(--navy3)}.fdot.s-deschisa{background:var(--navy2)}.fdot.s-negociere{background:var(--amber)}.fdot.s-acceptata{background:var(--green)}.fdot.s-pierduta{background:var(--red)}
.fdot.l-activ{background:var(--green)}.fdot.l-expirat{background:var(--muted)}.fdot.l-revocat{background:var(--red)}
.fl{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.fc{font:12px var(--mono);color:var(--muted)}.fempty{font-size:12px;color:var(--muted)}
.fper{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:8px}.fper input{font-size:12px;padding:5px 8px}
.rmain{display:grid;gap:12px;min-width:0}
.rgroup{display:flex;align-items:center;gap:8px;flex-wrap:wrap;font-size:13px}.rgroup select{width:auto;padding:5px 28px 5px 8px;font-size:12px}
.gchip{display:inline-flex;align-items:center;gap:6px;border:1px solid var(--teal);color:var(--teal);border-radius:999px;padding:2px 6px 2px 4px;font-size:12px}
.gi{background:var(--teal);color:var(--surface);border-radius:999px;width:16px;height:16px;display:inline-grid;place-items:center;font:600 10px var(--mono)}
.gx{background:none;border:0;color:inherit;font-size:14px;line-height:1;padding:0 2px;cursor:pointer}
.kpis.rk{grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:8px}.rk .kpi{padding:8px 12px}.rk .kpi b{font-size:18px}
.rsel{display:flex;gap:10px;align-items:center;font-size:13px;padding:6px 10px;border:1px dashed var(--teal);border-radius:4px}.rsel[hidden]{display:none}.rsel .lnk{margin:0}
.rtw{overflow:auto;max-height:70vh;border:1px solid var(--line);border-radius:5px}
.rt{border-collapse:separate;border-spacing:0;width:100%;font-size:13px}
.rt th{position:sticky;top:0;z-index:2;background:var(--soft);color:var(--navy);text-align:left;font-weight:600;padding:8px 10px;border-bottom:1px solid var(--line);white-space:nowrap;cursor:pointer;user-select:none}
.rt th.drag{opacity:.5}.rt th.over{box-shadow:inset 3px 0 0 var(--teal)}
.rt th .tht{display:inline-flex;align-items:center;gap:4px;max-width:100%}.thl{overflow:hidden;text-overflow:ellipsis}
.si{color:var(--muted);opacity:.45;flex:none}.si.on{opacity:1;color:var(--teal)}.si path{fill:none;stroke:currentColor;stroke-width:1.4;stroke-linecap:round;stroke-linejoin:round}
.pinb{background:none;border:0;padding:0 2px;color:var(--muted);opacity:0;cursor:pointer;line-height:0}.pinb svg{display:block}
.pinb svg .ph{fill:currentColor;stroke:currentColor;stroke-width:.8;stroke-linejoin:round}.pinb svg .pn{fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round}
.rt th:hover .pinb,.rt th:focus-within .pinb,.pinb.on{opacity:1}.pinb.on{color:var(--teal)}
.rz{position:absolute;right:0;top:0;width:6px;height:100%;cursor:col-resize}.rz:hover{background:color-mix(in srgb,var(--teal) 30%,transparent)}
.rt td{padding:7px 10px;border-bottom:1px dotted var(--line);vertical-align:top;white-space:nowrap;background:var(--surface)}
.rt td.wrap{white-space:normal;min-width:220px}
.rt .n{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}
.rt th.sel,.rt td.sel{width:28px;padding:6px 4px;text-align:center}.rt td.sel input,.rt th.sel input{width:auto;margin:0}
.rt th.pin,.rt td.pin{position:sticky;left:0;z-index:1}.rt th.pin{z-index:3}
.rt tbody tr:hover td{background:color-mix(in srgb,var(--teal) 6%,var(--surface))}.rt tbody tr.on td{background:color-mix(in srgb,var(--teal) 12%,var(--surface))}
.rt td a{color:var(--ink);font-weight:600;text-decoration:none}.rt td a:hover{color:var(--teal)}
.rt tr.grp{cursor:pointer}.rt tr.grp td{background:var(--soft);font-size:12px;color:var(--navy);border-top:1px solid var(--line)}.rt tr.grp.l1 td{background:color-mix(in srgb,var(--soft) 55%,var(--surface))}.rt tr.grp.l2 td{background:color-mix(in srgb,var(--soft) 30%,var(--surface))}
.rt tbody tr.grp:hover td{background:color-mix(in srgb,var(--teal) 10%,var(--soft))}.caret{display:inline-block;width:12px;color:var(--teal)}
.rt tfoot td{position:sticky;bottom:0;background:var(--surface);border-top:2px solid var(--navy);border-bottom:0;font-weight:600}
.rt .cnt{font:11px var(--mono);color:var(--muted);margin-left:4px}
.rt .empty{text-align:center;color:var(--muted);padding:24px}
.rfoot{font:12px var(--mono);color:var(--muted);margin:0}
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
  <div class="hd-pill user-pill">
    <a class="who" href="admin.php?v=utilizatori" title="Contul meu: <?= h($me['username']) ?> · parola se schimbă în Utilizatori"><span class="av" aria-hidden="true"><?= h(user_initials($me)) ?></span><span class="nm"><?= h($me['name'] !== '' ? $me['name'] : $me['username']) ?></span></a>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="logout"><button type="submit" title="Ieșire din cockpit">Ieșire</button></form>
  </div>
  <nav aria-label="Secțiuni"><?= $nav ?></nav>
</div></header>
<main>
<section class="tabpanel" aria-label="<?= h($tabs[$view]) ?>">
<h2 class="tabtitle"><?= h($tabs[$view]) ?></h2>
<?= $flashMsg ?>
<?= $content ?>
</section>
</main>
<footer class="foot"><div class="in">
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

  // Ofertă nouă: previzualizarea emailului cu datele completate și materialele bifate, înainte de trimitere
  var form = document.getElementById('newDeal'), dlg = document.getElementById('prevDlg');
  if (form && dlg && typeof dlg.showModal === 'function') {
    var frame = document.getElementById('prevFrame'), meta = document.getElementById('prevMeta');
    document.getElementById('prevBtn').addEventListener('click', function () {
      if (!form.reportValidity()) { return; }
      var fd = new FormData(form);
      fd.set('action', 'preview');
      fetch('admin.php', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'fetch' }, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (!j.ok) { alert(j.error); return; }
          meta.textContent = 'Către: ' + j.to + ' · Subiect: ' + j.subject + ' · ' + j.materials + (j.materials === 1 ? ' material' : ' materiale') + ' · prin ' + j.transport
            + (j.materials ? '' : ' · ATENȚIE: niciun material bifat');
          frame.srcdoc = '<!doctype html><meta charset="utf-8"><body style="margin:0;padding:18px;background:#fff">' + j.html + '</body>';
          dlg.showModal();
        })
        .catch(function () { alert('Previzualizarea nu a reușit. Reîncarcă pagina.'); });
    });
    document.getElementById('prevClose').addEventListener('click', function () { dlg.close(); });
    document.getElementById('prevSend').addEventListener('click', function () { dlg.close(); form.requestSubmit ? form.requestSubmit() : form.submit(); });
  }

  // Ajutor: o singură secțiune vizibilă, aleasă din bară (sau din #hash / ultima alegere)
  var hNav = document.getElementById('hNav');
  if (hNav) {
    var HKEY = 'ofertare_help_sec';
    var showSec = function (id, push) {
      var sec = document.getElementById(id);
      if (!sec) { return false; }
      document.querySelectorAll('.hsec').forEach(function (x) { x.classList.toggle('on', x === sec); });
      hNav.querySelectorAll('a').forEach(function (a) { if (a.getAttribute('href') === '#' + id) { a.setAttribute('aria-current', 'true'); } else { a.removeAttribute('aria-current'); } });
      try { localStorage.setItem(HKEY, id); } catch (e) {}
      if (push) { history.replaceState(null, '', '#' + id); }
      return true;
    };
    document.querySelector('.hp').addEventListener('click', function (e) {
      var a = e.target.closest('a[href^="#h-"]');
      if (a && showSec(a.getAttribute('href').slice(1), true)) { e.preventDefault(); if (!hNav.contains(a)) { hNav.scrollIntoView({ block: 'nearest' }); } }
    });
    var first = hNav.querySelector('a').getAttribute('href').slice(1), want = '';
    if (location.hash && /^#h-[a-z]+$/.test(location.hash)) { want = location.hash.slice(1); }
    else { try { want = localStorage.getItem(HKEY) || ''; } catch (e) {} }
    if (!showSec(want, false)) { showSec(first, false); }
    window.addEventListener('hashchange', function () { if (/^#h-[a-z]+$/.test(location.hash)) { showSec(location.hash.slice(1), false); } });
  }

  // Pipeline: kanban sau tabel, cu alegerea păstrată în browser
  var pipe = document.getElementById('pipePanel');
  if (pipe) {
    var KEY = 'ofertare_pipeline_view';
    var setMode = function (m) {
      pipe.classList.toggle('mode-table', m === 'table');
      pipe.querySelectorAll('.seg .sm').forEach(function (b) { b.setAttribute('aria-pressed', b.dataset.mode === m ? 'true' : 'false'); });
      try { localStorage.setItem(KEY, m); } catch (e) {}
    };
    var saved = 'kanban';
    try { saved = localStorage.getItem(KEY) || 'kanban'; } catch (e) {}
    if (location.hash && /^#c\d+$/.test(location.hash)) { saved = 'kanban'; } // un link către card deschide kanban-ul
    setMode(saved);
    pipe.querySelectorAll('.seg .sm').forEach(function (b) { b.addEventListener('click', function () { setMode(b.dataset.mode); }); });
    pipe.querySelectorAll('a.tocard').forEach(function (a) {
      a.addEventListener('click', function () {
        setMode('kanban');
        var c = document.getElementById('c' + a.dataset.id);
        if (c) { var d = c.querySelector('details'); if (d) { d.open = true; } }
      });
    });
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

  // Catalog: filtrare pe tip, valabilitate și text, fără reîncărcare
  var kList = document.getElementById('kList');
  if (kList) {
    var kQ = document.getElementById('kQ'), kType = document.getElementById('kType'), kSt = document.getElementById('kSt'), kEmpty = document.getElementById('kEmpty');
    var items = Array.prototype.slice.call(kList.querySelectorAll('.catrow'));
    var kFilter = function () {
      var q = kQ.value.trim().toLowerCase(), shown = 0;
      items.forEach(function (it) {
        var ok = (!kType.value || it.dataset.type === kType.value) && (!kSt.value || it.dataset.status === kSt.value)
          && (!q || it.dataset.q.indexOf(q) !== -1);
        it.classList.toggle('hide', !ok);
        if (ok) { shown++; }
      });
      kEmpty.hidden = shown > 0 || items.length === 0;
    };
    [kQ, kType, kSt].forEach(function (el) { el.addEventListener('input', kFilter); el.addEventListener('change', kFilter); });
    if (location.hash && /^#k\d+$/.test(location.hash)) {
      var tgt = document.querySelector(location.hash);
      if (tgt) {
        var comp = tgt.querySelector('details.comp');
        if (comp) { comp.open = true; } // după o acțiune pe compoziție, pachetul rămâne desfăcut
        tgt.scrollIntoView({ block: 'center' });
      }
    }
  }
})();
</script>
</body>
</html>
