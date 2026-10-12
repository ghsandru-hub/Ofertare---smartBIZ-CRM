<?php
// Copiază acest fișier ca config.php și completează valorile.
// Fișierul stă în ofertare_private, în afara folderului public și nu poate fi accesat din browser.
return [
    // Adresa publică a gatekeeper-ului, cu slash la final.
    'base_url'        => 'https://ofertare.aiall.ro/',

    // Cheie secretă aleatoare (minim 32 de caractere). Generare:
    // php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
    'secret'          => 'SCHIMBA-MA-CU-O-CHEIE-ALEATOARE-DE-64-DE-CARACTERE',

    // Autentificare panou de administrare. Hash generat cu:
    // php -r "echo password_hash('parola-ta', PASSWORD_DEFAULT), PHP_EOL;"
    'admin_user'      => 'admin',
    'admin_pass_hash' => '',

    // Expeditorul emailurilor (cod de acces, invitații). Trebuie să fie o
    // căsuță existentă pe domeniul aiall.ro, ca emailul să treacă de SPF/DKIM.
    'mail_from'       => 'oferte@aiall.ro',
    'mail_from_name'  => 'AiALL S.R.L.',

    // Răspunsurile clienților (gol = mail_from) și o copie ascunsă a fiecărui email trimis (gol = fără copie).
    'mail_reply_to'   => '',
    'mail_bcc'        => '',

    // 1) Microsoft 365 prin Graph API (recomandat când emailul domeniului este în M365, ca la aiall.ro):
    //    emailul pleacă din căsuța mail_from, cu SPF/DKIM/DMARC ale domeniului. Aceeași aplicație Azure ca în
    //    portalul ContaCheck: Entra ID → App registrations → aplicația → Tenant ID, Client ID, un Client secret
    //    (Certificates & secrets) și permisiunea de aplicație Microsoft Graph → Mail.Send, cu Grant admin consent.
    //    Căsuța mail_from trebuie să existe în M365 (poate fi un shared mailbox, fără licență).
    'graph_tenant_id'     => '',
    'graph_client_id'     => '',
    'graph_client_secret' => '',
    'graph_save_to_sent'  => true, // păstrează o copie în „Sent Items” al căsuței mail_from

    // 2) SMTP autentificat, folosit doar dacă Graph nu este configurat. Pentru M365: smtp.office365.com, port 587,
    //    smtp_secure 'tls', smtp_user = căsuța, smtp_pass = parola ei (necesită „Authenticated SMTP” activ pe căsuță;
    //    Microsoft dezactivează treptat autentificarea de bază, de aceea Graph este varianta sigură).
    //    Pentru căsuțe pe cPanel: serverul de email al contului (ex. mail.domeniu.ro), 465 cu 'ssl'.
    // 3) Cu graph_* și smtp_host goale, emailurile pleacă prin mail() din PHP, care raportează „trimis” când
    //    serverul local a pus mesajul în coadă, nu când a fost livrat; cu emailul domeniului în M365 ele pică la SPF.
    'smtp_host'       => '',
    'smtp_port'       => 465,
    'smtp_secure'     => 'ssl',  // 'ssl' | 'tls' | ''
    'smtp_user'       => '',
    'smtp_pass'       => '',

    // Unde primești notificarea la prima deschidere a unui link (gol = fără notificare).
    'notify_email'    => '',

    // Durate
    'default_days'    => 30,   // valabilitatea implicită a unui link
    'session_hours'   => 12,   // cât rămâne deschis accesul după introducerea codului
    'otp_minutes'     => 10,   // valabilitatea codului de acces
    'otp_max_sends'   => 5,    // coduri trimise pe oră, per link
    'otp_max_tries'   => 5,    // încercări per cod
    'max_upload_mb'   => 25,   // dimensiunea maximă a unui material încărcat din cockpit
];
