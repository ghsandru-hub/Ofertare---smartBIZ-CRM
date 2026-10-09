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
