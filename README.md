# Ofertare — smartBIZ CRM

Aplicația de ofertare de pe `ofertare.aiall.ro`: documente accesibile doar din linkul personal primit pe email,
cu cockpit e2e OPS Ofertare (pipeline, raportare, șabloane email, materiale).

Destinatarul primește pe email, dintr-un șablon administrat în aplicație, câte un link personal pentru fiecare material
(ofertă și materiale suport), de forma `https://ofertare.aiall.ro/<token>`. Fiecare link deschide **un singur fișier**,
după un cod de 6 cifre trimis pe adresa destinatarului; codul deschide toate materialele din același email.
Fișierele stau în `ofertare_private`, în afara folderului public al subdomeniului: nu au URL propriu,
iar folderul nu poate fi răsfoit.

Administrarea are login separat, la `https://ofertare.aiall.ro/admin.php`, și este un cockpit e2e OPS Ofertare
cu patru secțiuni: Pipeline (indicatori, pâlnie, kanban, ofertă nouă), Raportare, Șabloane email și Materiale.

## Structura pe server (cPanel)

```
/home/CONT/
├── ofertare_private/          ← privat, inaccesibil din browser
│   ├── config.php             ← creat din config.sample.php
│   ├── lib.php
│   ├── fisiere/               ← materialele (încărcate din cockpit sau urcate manual)
│   └── data/ofertare.db       ← creată automat la prima rulare
└── ofertare.aiall.ro/         ← Document Root al subdomeniului
    ├── .htaccess              ← /<token> spre index.php, doar HTTPS, restul 404
    ├── index.php              ← gatekeeper pentru clienți
    ├── admin.php              ← administrare, cu login separat
    └── robots.txt             ← interzice indexarea
```

`index.php` și `admin.php` încarcă biblioteca din `../ofertare_private/lib.php`.
Cele două foldere trebuie să fie frați în directorul contului.

## Instalare

1. **Subdomeniul.** cPanel → Domains → Create a New Domain: `ofertare.aiall.ro`, cu Document Root `ofertare.aiall.ro`
   (calea completă `/home/CONT/ofertare.aiall.ro`, nu în `public_html`).
2. **SSL.** cPanel → SSL/TLS Status → Run AutoSSL, ca subdomeniul să aibă certificat.
3. **Fișierele.** Urci conținutul folderului `ofertare.aiall.ro/` în Document Root-ul subdomeniului,
   iar `ofertare_private/` în `/home/CONT/`, lângă el.
4. **Configurare.** În `ofertare_private/`, copiezi `config.sample.php` ca `config.php` și completezi:
   - `secret`: `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"`
   - `admin_pass_hash`: `php -r "echo password_hash('parola-ta', PASSWORD_DEFAULT), PHP_EOL;"`
   - `mail_from`: o căsuță existentă pe aiall.ro (ex. `oferte@aiall.ro`), ca emailurile să treacă de SPF/DKIM
   - `notify_email`: adresa ta, pentru notificarea la prima deschidere
5. **Drepturi.** `chmod 700 ofertare_private ofertare_private/data` și `chmod 600 ofertare_private/config.php`.
6. **Test.** Deschizi `https://ofertare.aiall.ro/admin.php`, te autentifici și creezi un link către propria adresă.

Cerințe: PHP 8.0+ cu `pdo_sqlite`, Apache cu `mod_rewrite` (standard pe cPanel), funcția `mail()` activă.


### Ce nu se află în repo

Repo-ul este public, așa că nu conține:
- `ofertare_private/config.php` (cheia secretă și parola de admin): se creează pe server din `config.sample.php`;
- `ofertare_private/data/` (baza de date cu ofertele și jurnalul de acces): se creează automat la prima rulare;
- `ofertare_private/fisiere/` (oferta și materialele suport): se încarcă din cockpit, secțiunea „Materiale”,
  sau se urcă direct pe server. Dacă folderul este gol la prima rulare, șablonul implicit se creează fără materiale
  și le asociezi după încărcare.

## Cockpit: Pipeline, Raportare, Șabloane email, Materiale

Cockpitul (`admin.php`) are patru secțiuni.

### 1. Pipeline

| Etapă | Cum se setează |
| --- | --- |
| Trimisă | Automat, la crearea ofertei |
| Deschisă | Automat, la prima deschidere a unui material de către client |
| În negociere / Acceptată / Pierdută | Manual: tragi cardul în coloană sau alegi etapa din „Detalii” |

- Ofertă nouă: companie, persoană de contact, email, salariați mobili estimați, șablon de email și materialele trimise
  (bifate automat după șablon, modificabile). La trimitere, fiecare material primește linkul lui personal.
- Fiecare card arată materialele trimise și de câte ori a fost deschis fiecare, valoarea, nota internă și jurnalul.
- Pâlnia numără etapa maximă atinsă; o ofertă pierdută rămâne numărată la etapa la care a ajuns.
- Valorile în RON pornesc de la „Salariați mobili”: onorariu an 1 = 1.649 RON/salariat (implementare 1.037 + licență 612),
  licență din anul 2 = 612 RON/salariat, economie client = 10.368 RON/salariat/an (constante în `lib.php`).

### 2. Raportare

Tabel smartBIZ cu toate ofertele: o ofertă pe rând, cu companie, persoană, șablon, materiale citite, etapă și etapă maximă,
date (creare, prima și ultima deschidere, expirare), zile până la deschidere, deschideri, salariați mobili, onorariu an 1,
licență, economie client, stare link și notă.

- Căutare liberă și filtre pe etapă, șablon, stare link și perioada creării.
- Sortare pe orice coloană (clic pe antet), grupare pe etapă, șablon, luna creării sau stare link, cu subtotaluri.
- Alegerea coloanelor afișate; vederea (filtre, sortare, grupare, coloane) se păstrează în browser.
- Rând de total și sumar: număr de oferte, procent deschise, acceptate, salariați, onorariu an 1, economie client.
- Export CSV pentru Excel (UTF-8, separator „;”), cu rândurile filtrate, în ordinea și cu coloanele afișate.

### 3. Șabloane email

- Nume intern, subiect și textul emailului, cu previzualizare pe date de exemplu.
- Format text: rând gol = paragraf nou; rânduri consecutive = listă compactă; `✓ ` la început = bifă colorată; `**text**` = bold.
- Variabile: `{{salut}}`, `{{nume}}`, `{{companie}}`, `{{email}}`, `{{salariati}}`, `{{economie_an}}`, `{{expira}}`;
  `{{materiale}}` pe rând separat inserează butoanele cu linkurile personale.
- Fiecare șablon are lista lui de materiale, cu ordine. Șabloanele se pot copia și șterge; ofertele deja trimise nu sunt afectate.
- La prima rulare se creează automat șablonul „Ofertă e2e OPS Salarizare”, cu materialele din `ofertare_private/fisiere`.

### 4. Materiale

- Încărcare din cockpit: PDF, HTML, DOCX, XLSX, PPTX, PNG, JPG, MP4 (limita din `max_upload_mb`, implicit 25 MB).
- Tip: Ofertă sau Material suport. Titlul este textul butonului din email.
- „Înlocuiește fișierul” păstrează linkurile deja trimise: clienții deschid noua versiune.
- Un material dezactivat nu mai poate fi deschis și nu mai apare în șabloane.
- Pentru fiecare material vezi în câte șabloane apare, de câte ori a fost trimis și de câte ori a fost deschis.

### Accesul clientului

Un singur cod de acces, trimis pe emailul destinatarului, deschide toate materialele din același email,
timp de 12 ore. Un link redirecționat ajunge la pagina de cod, iar codul pleacă tot la destinatarul inițial.

## Ce protejează

| Risc | Protecție |
| --- | --- |
| Accesul la folder sau la alte fișiere | Fișierele stau în `ofertare_private`; linkul conține doar un token, iar `basename` + `realpath` blochează căile de tip `../` |
| Link redirecționat către altcineva | Codul de acces ajunge doar la emailul destinatarului inițial |
| Ghicirea linkului | Token aleator de 256 de biți; un token inexistent primește 404 |
| Furtul bazei de date | Se păstrează doar amprente HMAC ale tokenurilor, codurilor și sesiunilor |
| Încercarea repetată a codurilor | Maximum 5 încercări per cod, 5 coduri pe oră per link, cod valabil 10 minute |
| Acces la administrare | Login separat, sesiune proprie limitată la `admin.php`, protecție CSRF, întârziere la parolă greșită |
| Scurgerea tokenului spre alte site-uri | `Referrer-Policy: no-referrer` pe toate răspunsurile |
| Indexare în motoare de căutare | `robots.txt`, `X-Robots-Tag: noindex`, `Cache-Control: no-store` |

Limită: după deschidere, destinatarul poate salva sau retrimite documentul. Sistemul controlează accesul la link,
nu ce face destinatarul cu fișierul.

## Emailuri

Trimiterea folosește `mail()` din PHP. Dacă hostingul cere SMTP autentificat sau emailurile ajung în spam,
funcția `send_mail()` din `ofertare_private/lib.php` este singurul loc de modificat (de exemplu cu PHPMailer).

## Actualizări ulterioare

Urci doar fișierele de cod (`index.php`, `admin.php`, `lib.php`). Nu suprascrie `config.php`
și nu atinge `ofertare_private/data/` și `ofertare_private/fisiere/`, unde stau baza de date și materialele.
