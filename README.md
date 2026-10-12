# Ofertare — smartBIZ CRM

Aplicația de ofertare de pe `ofertare.aiall.ro`: documente accesibile doar din linkul personal primit pe email,
cu cockpit e2e OPS Ofertare (pipeline, raportare, catalog de produse și servicii, șabloane email, materiale).

Destinatarul primește pe email, dintr-un șablon administrat în aplicație, câte un link personal pentru fiecare material
(ofertă și materiale suport), de forma `https://ofertare.aiall.ro/<token>`. Fiecare link deschide **un singur fișier**,
după un cod de 6 cifre trimis pe adresa destinatarului; codul deschide toate materialele din același email.
Fișierele stau în `ofertare_private`, în afara folderului public al subdomeniului: nu au URL propriu,
iar folderul nu poate fi răsfoit.

Administrarea are login separat, la `https://ofertare.aiall.ro/admin.php`, și este un cockpit e2e OPS Ofertare
cu șapte secțiuni: Pipeline (indicatori, pâlnie, kanban, ofertă nouă), Raportare, Catalog, Șabloane email, Materiale, Utilizatori și Ajutor.

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
   iar `ofertare_private/` în `/home/CONT/`, lângă el. Alternativ, din cPanel → Git Version Control, clonezi
   repo-ul și apeși „Deploy HEAD Commit”: `.cpanel.yml` copiază codul în cele două foldere, fără să atingă
   `config.php`, `data/` sau `fisiere/`.
4. **Configurare.** În `ofertare_private/`, copiezi `config.sample.php` ca `config.php` și completezi:
   - `secret`: `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"`
   - `admin_pass_hash`: `php -r "echo password_hash('parola-ta', PASSWORD_DEFAULT), PHP_EOL;"` — contul inițial;
     la prima autentificare este copiat în tabela `users`, iar de atunci parola se schimbă din cockpit → Utilizatori
   - `mail_from`: o căsuță existentă pe aiall.ro (ex. `oferte@aiall.ro`), ca emailurile să treacă de SPF/DKIM
   - `graph_tenant_id`, `graph_client_id`, `graph_client_secret`: trimitere prin Microsoft 365 (Graph API), recomandată,
     emailul aiall.ro fiind în M365 (vezi „Emailuri”); alternativ `smtp_*`; cu ambele goale, emailurile pleacă prin `mail()`
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

## Cockpit: Pipeline, Raportare, Catalog, Șabloane email, Materiale, Utilizatori, Ajutor

Cockpitul (`admin.php`) are șapte secțiuni.

### 1. Pipeline

| Etapă | Cum se setează |
| --- | --- |
| Trimisă | Automat, la crearea ofertei |
| Deschisă | Automat, la prima deschidere a unui material de către client |
| În negociere / Acceptată / Pierdută | Manual: tragi cardul în coloană sau alegi etapa din „Detalii” |

- Ofertă nouă: CUI, companie, persoană de contact, email, salariați mobili estimați, șablon de email și materialele trimise
  (bifate automat după șablon, modificabile). La trimitere, fiecare material primește linkul lui personal.
- CUI → ANAF: butonul „ANAF” (sau Enter / părăsirea câmpului) interoghează API-ul public ANAF v9 (`PlatitorTvaRest/v9/tva`)
  și completează denumirea, nr. Reg. Com., adresa (domiciliul fiscal), județul, CAEN, telefonul, starea de plătitor TVA
  și inactivitatea. Datele se salvează pe ofertă, apar pe card (Detalii) și în Raportare (coloane, căutare, grupare după județ).
  Serverul are nevoie de acces HTTPS către `webservicesp.anaf.ro` (curl sau `allow_url_fopen`).
- Fiecare card arată materialele trimise și de câte ori a fost deschis fiecare, valoarea, nota internă și jurnalul.
- Din „Detalii”: „Retrimite emailul (linkuri noi)” generează linkuri noi (cele vechi nu mai merg; valabilitatea se
  prelungește dacă expirase) și retrimite emailul; „Revocă toate linkurile ofertei” oprește accesul; „Șterge oferta”
  o elimină definitiv, cu confirmare, împreună cu linkurile, codurile, sesiunile și jurnalul ei.
- Rezultatul trimiterii apare cu transportul folosit și, la eșec, cu eroarea exactă a serverului; jurnalul ofertei
  reține „email trimis prin …” sau „email eșuat: motivul”.
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

### 3. Catalog produse și servicii

Gestiunea catalogului de produse și servicii, tabela `catalog` din `ofertare.db`. Fiecare articol are:

| Câmp | Conținut |
| --- | --- |
| Tip | Serviciu, Licență, Serviciu digital, Produs, Pachet servicii sau Pachet produse (pachetele au o compoziție, vezi mai jos) |
| Denumire | numele articolului (max. 200 de caractere) |
| UM | unitatea de măsură (ex. buc, oră, lună, salariat, salariat/lună, pachet); câmpul propune valorile uzuale |
| Valid de la / Valid până la | perioada de valabilitate; „până la” gol = nelimitat |

- „Articol nou în catalog” adaugă un articol; lista de dedesubt editează fiecare articol pe loc (tip, denumire, UM, perioadă),
  cu „Salvează”, „Copiază” (articol nou cu aceleași date, util pentru o nouă perioadă de valabilitate) și „Șterge” (definitiv).
- Starea față de data de azi: **valid**, **din viitor** (nu a intrat încă în vigoare) sau **expirat**; sumarul numără articolele pe stări.
- Căutare liberă și filtre pe tip și valabilitate, fără reîncărcarea paginii.
- Validări: tipul trebuie să fie unul din listă, denumirea și UM sunt obligatorii, „până la” nu poate fi înaintea lui „de la”.
  Datele se acceptă ca `AAAA-LL-ZZ` sau `ZZ.LL.AAAA` și se păstrează ca `AAAA-LL-ZZ`.

**Pachete.** Un pachet se compune din articole existente în catalog (tabela `catalog_items`: pachet, componentă, cantitate):

| Tip de pachet | Componente acceptate |
| --- | --- |
| Pachet servicii | Serviciu, Serviciu digital, Licență |
| Pachet produse | Produs, Licență |

- Fiecare pachet are în listă o secțiune „Compoziție”: componentele cu cantitatea (în UM-ul componentei), „Salvează” pentru
  cantitate, „Scoate” pentru eliminare, plus un selector cu articolele din catalog potrivite pentru tipul pachetului și cantitatea.
- Un pachet nu poate conține alte pachete. Căutarea găsește un pachet și după numele componentelor.
- Un articol folosit într-un pachet nu poate fi șters și nu își poate schimba tipul într-unul pe care pachetul nu îl acceptă;
  un pachet cu componente nu își poate schimba tipul decât dacă toate componentele rămân acceptate. Mesajul de eroare spune ce trebuie scos mai întâi.
- „Copiază” pe un pachet copiază și compoziția. Ștergerea unui pachet șterge doar compoziția lui, nu și articolele componente.

### 4. Șabloane email

- Nume intern, subiect și textul emailului, cu previzualizare pe date de exemplu.
- Format text: rând gol = paragraf nou; rânduri consecutive = listă compactă; `✓ ` la început = bifă colorată; `**text**` = bold.
- Variabile: `{{salut}}`, `{{nume}}`, `{{companie}}`, `{{email}}`, `{{salariati}}`, `{{economie_an}}`, `{{expira}}`;
  `{{materiale}}` pe rând separat inserează butoanele cu linkurile personale.
- Fiecare șablon are lista lui de materiale, cu ordine. Șabloanele se pot copia și șterge; ofertele deja trimise nu sunt afectate.
- „Email de test” (panoul Previzualizare): trimite șablonul cu date de exemplu și linkuri fictive la adresa ta și arată
  rezultatul real al trimiterii; este cel mai simplu mod de a verifica livrarea pe server.
- Cum ajung materialele în email: materialul este încărcat și activ în „Materiale”; este bifat în șablon, cu ordinea lui
  (ordinea cea mai mică = butonul principal, colorat; următoarele = butoane cu contur); iar textul șablonului conține
  `{{materiale}}` singur pe rând, cu rând gol înainte și după. Fără acest rând emailul pleacă fără linkuri, iar scris în
  interiorul unui paragraf rămâne text. La „Ofertă nouă”, materialele șablonului se bifează automat și pot fi ajustate
  doar pentru oferta respectivă; fiecare material bifat primește linkul lui personal. Pasul cu pasul: cockpit → Ajutor.
- La prima rulare se creează automat șablonul „Ofertă e2e OPS Salarizare”, cu materialele din `ofertare_private/fisiere`.

### 5. Materiale

- Încărcare din cockpit: PDF, HTML, DOCX, XLSX, PPTX, PNG, JPG, MP4 (limita din `max_upload_mb`, implicit 25 MB).
- Tip: Ofertă sau Material suport. Titlul este textul butonului din email.
- „Înlocuiește fișierul” păstrează linkurile deja trimise: clienții deschid noua versiune.
- Un material dezactivat nu mai poate fi deschis și nu mai apare în șabloane.
- Pentru fiecare material vezi în câte șabloane apare, de câte ori a fost trimis și de câte ori a fost deschis.

### Accesul clientului

Un singur cod de acces, trimis pe emailul destinatarului, deschide toate materialele din același email,
timp de 12 ore. Un link redirecționat ajunge la pagina de cod, iar codul pleacă tot la destinatarul inițial.


### 6. Utilizatori

- Fiecare utilizator are nume de utilizator, nume afișat, parolă (bcrypt) și stare activ/inactiv; tabela `users` din `ofertare.db`.
- „Parola mea”: schimbarea propriei parole (parola actuală + parola nouă de minimum 10 caractere, repetată).
- „Utilizator nou”: nume de utilizator (litere mici, cifre, `. _ @ -`), nume și parolă; lăsată goală, parola se
  generează automat și se afișează o singură dată.
- Lista: redenumire, activare/dezactivare, parolă nouă (opțional) și ștergere; propriul cont nu poate fi dezactivat sau șters.
- Contul din `config.php` (`admin_user` / `admin_pass_hash`) intră doar dacă nu există în tabelă un utilizator cu același
  nume; la prima autentificare este copiat în tabelă. Recuperare dacă nimeni nu mai poate intra: pui în `config.php`
  un `admin_user` nou (ex. `recuperare`) cu hash-ul lui, te autentifici cu el și resetezi parolele din listă.

### 7. Ajutor

Ghid de utilizare în cockpit: fluxul pe scurt, cum ajung materialele suport în email pas cu pas (încărcare, bifare în șablon
cu ordine, rândul `{{materiale}}`, oferta nouă, ce vede clientul, ce vezi tu, întreținere), câte o secțiune pentru fiecare tab
și o listă de probleme frecvente. Valorile (limita de încărcare, valabilitatea codului, numărul de coduri și de încercări,
durata sesiunii) se citesc din `config.php`.

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

Trei moduri de trimitere, alese din `config.php`, în această ordine de prioritate:

| Mod | Când | Ce înseamnă „trimis” |
| --- | --- | --- |
| Microsoft 365 prin Graph API | `graph_tenant_id`, `graph_client_id`, `graph_client_secret` completate | Microsoft a acceptat mesajul; emailul pleacă din căsuța `mail_from` din M365, cu SPF/DKIM/DMARC ale domeniului; orice eroare apare cu mesajul Graph |
| SMTP autentificat | `smtp_host` completat | serverul de email a acceptat mesajul; un refuz apare cu răspunsul lui exact (ex. `535 authentication failed`) |
| `mail()` din PHP | nimic configurat | serverul local a pus mesajul în coadă; dacă îl respinge ulterior sau destinatarul îl refuză la SPF (domeniul are emailul în M365, nu pe serverul web), cockpitul a afișat deja „trimis” |

**Microsoft 365 (recomandat).** Același mecanism ca în portalul ContaCheck: o aplicație în Entra ID (App registrations)
cu permisiunea de aplicație Microsoft Graph `Mail.Send` și consimțământ de administrator; în `config.php` pui Tenant ID,
Client ID și un Client secret, iar `mail_from` este o căsuță existentă în M365 (poate fi un shared mailbox). Trimiterea
face `POST https://graph.microsoft.com/v1.0/users/{mail_from}/sendMail` cu un token de aplicație; `graph_save_to_sent`
păstrează o copie în „Sent Items”. Serverul are nevoie de acces HTTPS către `login.microsoftonline.com` și
`graph.microsoft.com` (curl sau `allow_url_fopen`). Dacă vrei ca aplicația să poată trimite doar din anumite căsuțe,
limitezi în Exchange Online cu o *application access policy*.

**SMTP.** Pentru M365: `smtp.office365.com`, port 587, `smtp_secure` `tls`, căsuța și parola ei, cu „Authenticated SMTP”
activ pe căsuță (Microsoft dezactivează treptat autentificarea de bază). Pentru căsuțe pe cPanel: serverul de email al
contului, 465 cu `ssl`. Clientul SMTP este în `smtp_send()` din `ofertare_private/lib.php`, fără dependențe externe.

Opțional, pentru toate modurile: `mail_reply_to` (unde ajung răspunsurile; gol = `mail_from`) și `mail_bcc`
(copie ascunsă a fiecărui email trimis). Verifici livrarea cu „Email de test” din Șabloane email.

## Actualizări ulterioare

Urci doar fișierele de cod (`index.php`, `admin.php`, `lib.php`). Nu suprascrie `config.php`
și nu atinge `ofertare_private/data/` și `ofertare_private/fisiere/`, unde stau baza de date și materialele.
