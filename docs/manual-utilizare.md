# Manual de utilizare — Ofertare · smartBIZ CRM

Aplicația de pe `https://ofertare.aiall.ro` trimite oferte și materiale suport prin linkuri personale, urmărește cine le-a
deschis și ține pipeline-ul ofertelor. Acest manual este pentru cei care lucrează în cockpit (echipa de vânzări / OPS).
Instalarea și configurarea pe server sunt descrise în `README.md`.

## Cuprins

1. [Autentificare](#1-autentificare)
2. [Structura cockpitului](#2-structura-cockpitului)
3. [Pipeline](#3-pipeline)
   - [Ofertă nouă](#31-ofertă-nouă)
   - [Datele firmei din ANAF (CUI)](#32-datele-firmei-din-anaf-cui)
   - [Cardul ofertei](#33-cardul-ofertei)
   - [Etapele și kanban-ul](#34-etapele-și-kanban-ul)
   - [Indicatori și pâlnie](#35-indicatori-și-pâlnie)
4. [Raportare (tabel smartBIZ)](#4-raportare-tabel-smartbiz)
5. [Catalog produse și servicii](#5-catalog-produse-și-servicii)
6. [Șabloane email](#6-șabloane-email)
7. [Materiale](#7-materiale)
8. [Utilizatori](#8-utilizatori)
9. [Ajutor](#9-ajutor)
10. [Ce vede clientul](#10-ce-vede-clientul)
11. [Întrebări frecvente](#11-întrebări-frecvente)

---

## 1. Autentificare

- Adresa cockpitului: `https://ofertare.aiall.ro/admin.php`.
- Introdu utilizatorul și parola primite de la administrator. După 2 secunde de pauză la fiecare încercare greșită,
  poți încerca din nou.
- Sesiunea se închide la „Ieșire” (dreapta sus) sau când închizi browserul. Dacă administratorul îți dezactivează sau
  șterge contul, ești deconectat automat la următoarea acțiune.
- Prima dată după instalare se intră cu contul definit în `config.php`; după aceea parola se schimbă din secțiunea
  **Utilizatori** (vezi [8](#8-utilizatori)).

## 2. Structura cockpitului

Bara de sus poartă identitatea smartBIZ Copilot, numele utilizatorului conectat și butonul „Ieșire”. Sub ea sunt cele
șapte secțiuni:

| Secțiune | La ce folosește |
| --- | --- |
| **Pipeline** | indicatori, pâlnia ofertelor, formularul „Ofertă nouă” și kanban-ul cu carduri |
| **Raportare** | tabelul smartBIZ cu toate ofertele: filtre, sortare, grupare, totaluri, export |
| **Catalog** | produsele, serviciile, licențele și pachetele, cu unitate de măsură și perioadă de valabilitate |
| **Șabloane email** | textul emailurilor și materialele pe care le trimite fiecare șablon |
| **Materiale** | fișierele (ofertă, studii de caz, prezentări) servite prin linkurile personale |
| **Utilizatori** | parola proprie și conturile colegilor |
| **Ajutor** | ghidul de utilizare din aplicație, cu valorile de configurare curente |

Tema (deschisă / închisă) urmează setarea sistemului de operare. Pe ecrane înguste, bara de secțiuni se derulează orizontal,
iar kanban-ul se derulează lateral.

## 3. Pipeline

### 3.1 Ofertă nouă

Panoul „Ofertă nouă” (dreapta, lângă pâlnie):

| Câmp | Ce completezi |
| --- | --- |
| **CUI** | codul fiscal al clientului; butonul **ANAF** (sau Enter / părăsirea câmpului) aduce datele firmei — vezi 3.2 |
| **Companie** | se completează din ANAF; poți corecta manual (obligatoriu) |
| **Persoană de contact** | numele destinatarului; apare în salutul emailului (`{{nume}}`) |
| **Email** | adresa la care pleacă oferta și, mai târziu, codul de acces (obligatoriu) |
| **Salariați mobili (estimat)** | baza de calcul a valorilor: onorariu an 1 = 1.649 RON/salariat, licență din anul 2 = 612 RON/salariat, economie client = 10.368 RON/salariat/an |
| **Linkuri valabile (zile)** | cât timp pot fi deschise materialele (implicit 30 de zile, maximum 365) |
| **Șablon email** | textul emailului; bifează automat materialele lui în lista de mai jos |
| **Materiale trimise** | poți scoate sau adăuga materiale față de șablon; fiecare bifă = un link personal în email |
| **Cod de acces pe email** | bifat: clientul primește un cod de 6 cifre la prima deschidere (recomandat); debifat: linkul deschide direct |
| **Trimite acum emailul** | bifat: emailul pleacă imediat; debifat: oferta se creează, iar linkurile le trimiți tu |

Apasă **Creează și trimite oferta**. Mesajul de confirmare afișează linkurile personale **o singură dată** (în baza de date
rămân doar amprentele lor) — copiază-le dacă trimiți manual. Oferta apare în coloana „Trimisă”.

### 3.2 Datele firmei din ANAF (CUI)

1. Scrie CUI-ul în câmpul **CUI** — merge cu sau fără `RO`, cu spații (ex. `RO 1590082`).
2. Apasă **ANAF**, Enter sau ieși din câmp. Sub formular apare „Se interoghează ANAF…”, apoi linia cu datele găsite:
   **denumirea**, nr. Reg. Com., adresa domiciliului fiscal, CAEN, telefonul (dacă există), eticheta
   `plătitor TVA` / `neplătitor TVA`, eticheta roșie `inactiv` dacă firma e declarată inactivă și starea înregistrării.
3. Câmpul **Companie** se completează cu denumirea oficială; o poți edita.
4. Datele se salvează pe ofertă la creare și apar pe card (CUI · Reg. Com. sub destinatar, „Firmă (ANAF): …” în Detalii)
   și în Raportare (coloane, căutare, grupare după județ).

Mesaje posibile: „CUI invalid” (doar cifre, maximum 10), „CUI-ul … nu există în evidența ANAF”, „ANAF nu răspunde acum”
(serviciul public are pauze; completează manual și încearcă mai târziu). ANAF acceptă o interogare pe secundă.

### 3.3 Cardul ofertei

Fiecare ofertă este un card în kanban:

- **antet**: compania și de cât timp e în etapa curentă (`3 z`, `2 h`, `15 min`);
- destinatar · email; CUI · nr. Reg. Com. (dacă există);
- **materialele** trimise și starea fiecăruia: `necitit` sau `3×` (de câte ori a fost deschis);
- **valoarea**: salariați mobili și onorariul anului 1;
- etichete: `linkuri expirate`, `linkuri revocate`, `inactiv ANAF`;
- **nota internă** (dacă există);
- **Detalii** (pliabil): subiectul emailului, datele firmei, schimbarea etapei, nota internă (max. 500 de caractere), data
  creării și a expirării, cod de acces da/nu, ultimele 10 evenimente din jurnal (email trimis, deschideri, coduri,
  mutări de etapă) și butonul **Revocă toate linkurile ofertei**.

Revocarea oprește imediat accesul clientului la toate materialele ofertei (inclusiv sesiunile deja deschise) și nu se poate
anula; pentru a retrimite, creezi o ofertă nouă.

### 3.4 Etapele și kanban-ul

| Etapă | Cum se setează |
| --- | --- |
| **Trimisă** | automat, la crearea ofertei |
| **Deschisă** | automat, la prima deschidere a unui material de către client |
| **În negociere**, **Acceptată**, **Pierdută** | manual: tragi cardul în coloană (drag & drop) sau alegi etapa în „Detalii” → **Mută** |

Fiecare coloană arată numărul de oferte, salariații și onorariul însumat. Mutarea înapoi (ex. din „Acceptată” în
„În negociere”) este permisă; etapa maximă atinsă rămâne memorată pentru pâlnie.

### 3.5 Indicatori și pâlnie

- **Oferte în lucru** (Trimisă + Deschisă + În negociere) din totalul trimis;
- **Rată de deschidere**: oferte deschise / oferte trimise;
- **Conversie**: acceptate / trimise;
- **Pipeline, onorariu an 1**: valoarea ofertelor în lucru;
- **Câștigat, onorariu an 1** și licența anuală din anul 2 pentru ofertele acceptate;
- **Economie anuală generată clienților** la ofertele acceptate.

Pâlnia numără, pentru fiecare etapă, câte oferte au ajuns **cel puțin** acolo; o ofertă pierdută rămâne numărată la etapa
maximă la care a ajuns.

## 4. Raportare (tabel smartBIZ)

Toate ofertele, una pe rând, în tabelul smartBIZ: coloane despre companie (CUI, Reg. Com., adresă, județ, CAEN, TVA,
inactiv), persoană, email, șablon, materiale trimise și citite, etapă și etapă maximă, date (creată, luna creării, prima și
ultima deschidere, expiră), zile până la deschidere, deschideri, salariați mobili, onorariu an 1, licență, economie client,
stare link și notă. Compania este link către cardul din Pipeline.

### 4.1 Bara de instrumente

| Control | Ce face |
| --- | --- |
| pictograma **pâlnie** (lângă titlu) | deschide / închide panoul de filtre din stânga; devine colorată când există filtre active |
| **Caută în tabel** | caută textul în toate coloanele (și în cele ascunse): companie, CUI, adresă, email, notă… |
| **Configurație curentă** ▾ | alege o configurație salvată; „Configurație curentă” revine la vederea implicită |
| pictograma **salvare** | salvează vederea de acum (filtre, căutare, sortare, grupare, coloane, lățimi, fixări) sub o denumire |
| pictograma **coș** | șterge configurația selectată |
| pictograma **coloane** | **Setări coloane**: bifezi coloanele afișate |
| **Resetează vederea** | revine la vederea implicită (nu șterge configurațiile salvate) |
| **…** | meniul **Export**: Excel, CSV, PDF |

Configurațiile și vederea curentă se memorează în browserul tău (nu pe server): pe alt calculator pornești de la vederea
implicită.

### 4.2 Filtre (panoul din stânga)

- Antetul arată **Filtre** și câte rânduri sunt afișate din total (ex. `3/12`); **Resetează filtrele (n)** șterge toate filtrele.
- Fiecare coloană text are o secțiune pliabilă (Companie, Județ, CAEN, Plătitor TVA, Inactiv ANAF, Persoană, Șablon,
  Etapă, Etapă maximă, Luna creării, Link). Clic pe titlu o deschide: lista valorilor, fiecare cu numărul de oferte.
- Bifezi valorile dorite: în aceeași coloană valorile se adună (**sau**), între coloane filtrele se intersectează (**și**).
  Ex.: Etapă = Trimisă + Deschisă, Județ = Cluj → ofertele din Cluj aflate în oricare dintre cele două etape.
- Rândurile fără valoare apar ca **Fără valoare** și pot fi filtrate.
- Peste 8 valori apare un câmp **Caută <coloană>** care restrânge lista (nu bifează nimic singur).
- În antetul secțiunii: **Reset** (șterge selecția din coloana aceea) și contorul `bifate/total`.
- Numărătorile sunt pe toate ofertele, nu doar pe cele rămase după celelalte filtre.
- **Creată de la / până la** filtrează după data creării.

### 4.3 Indicatori (KPI)

Șase carduri, recalculate după filtre și căutare: **Oferte** (din total), **Deschise** (% și număr), **Acceptate** (și
conversia), **Salariați mobili**, **Onorariu an 1**, **Economie client / an**.

### 4.4 Grupare și subtotaluri

- **Grupare** → „+ nivel…” adaugă un nivel (Etapă, Județ, Șablon, Luna creării, Link, Companie…); poți pune mai multe
  niveluri (ex. Etapă › Județ). Chip-urile numerotate arată ordinea; × scoate nivelul.
- Fiecare rând de grup arată valoarea, numărul de oferte și **subtotalurile** (deschideri, salariați, onorariu, licență,
  economie; media zilelor până la deschidere, marcată ⌀). Rândul **Total** rămâne jos.
- Clic pe un rând de grup îl restrânge / extinde; **Restrânge tot / Extinde tot** le face pe toate.
- **Numai grupările** ascunde rândurile detaliate și lasă doar grupurile cu subtotaluri.

### 4.5 Tabelul

- **Sortare**: clic pe antet — crescător, încă un clic descrescător, al treilea revine la ordinea implicită (cea mai nouă
  ofertă prima). Valorile goale stau primele la sortarea crescătoare.
- **Fixare**: pictograma de pioneză din antet (apare la trecerea mouse-ului) ține coloana la stânga când derulezi orizontal;
  maximum 3 coloane.
- **Ordine**: trage un antet peste altul.
- **Lățime**: trage marginea dreaptă a antetului.
- **Selecție**: bifa din antet selectează rândurile afișate; bifele de pe rânduri selectează individual. Bara de deasupra
  tabelului arată câte sunt selectate și „Deselectează”.
- Subsol: **În baza de date: N · Afișate: M · Selectate: S**.

### 4.6 Export

- Rândurile exportate: **cele selectate**, dacă există o selecție, altfel **toate rândurile filtrate**, în ordinea și cu
  coloanele afișate. Numele fișierului spune ce conține: `raport_ofertare_selectie_2026-10-12.xlsx` sau `…_lista_…`.
- **Export Excel** (.xlsx): antet înghețat, filtru automat pe coloane, numerele rămân numere (se pot însuma în Excel).
- **Export CSV**: UTF-8, separator `;`, cu rândul Total; se deschide direct în Excel.
- **Export PDF**: se deschide o fereastră de tipărire (A4 landscape) cu indicatorii, grupările cu subtotaluri și totalul;
  alegi **Save as PDF** din dialogul de tipărire. Dacă browserul blochează fereastra, permite pop-up-urile pentru site.

## 5. Catalog produse și servicii

Catalogul ține articolele pe care le oferi: **Serviciu**, **Licență**, **Serviciu digital**, **Produs**, **Pachet servicii**,
**Pachet produse**. Fiecare articol are denumire, unitate de măsură (buc, oră, lună, salariat, salariat/lună, pachet…) și
perioada de valabilitate („până la” gol = nelimitat).

- **Articol nou în catalog** adaugă un articol; lista de dedesubt îl editează pe loc, cu **Salvează**, **Copiază** (un articol
  nou cu aceleași date — util când începe o nouă perioadă de valabilitate) și **Șterge** (definitiv).
- Starea față de azi: **valid**, **din viitor** sau **expirat**; sumarul numără articolele pe stări. Căutarea și filtrele pe
  tip și valabilitate lucrează fără reîncărcarea paginii.
- **Pachetele** se compun din articole existente, cu cantități: un pachet de servicii acceptă servicii, servicii digitale și
  licențe; un pachet de produse acceptă produse și licențe. Compoziția se deschide sub rândul pachetului.
- Datele se pot scrie `AAAA-LL-ZZ` sau `ZZ.LL.AAAA`; „până la” nu poate fi înaintea lui „de la”.

## 6. Șabloane email

Lista din stânga arată șabloanele existente și de câte ori a fost folosit fiecare; „+ Șablon nou” creează unul gol.

- **Nume intern** (apare doar în cockpit) și **Subiect email**.
- **Textul emailului**, scris simplu:
  - rând gol = paragraf nou; rânduri consecutive = listă compactă;
  - `✓ ` la începutul rândului = bifă colorată; `**text**` = bold;
  - variabile: `{{salut}}` („Bună ziua, Nume,” sau „Bună ziua,”), `{{nume}}`, `{{companie}}`, `{{email}}`,
    `{{salariati}}`, `{{economie_an}}` (economia anuală calculată), `{{expira}}` (data expirării linkurilor);
  - `{{materiale}}` pe un rând separat inserează butoanele cu linkurile personale, în ordinea materialelor șablonului.
- **Materialele șablonului**: bifezi materialele active și le dai ordinea (câmpul „ordine”); sunt cele bifate automat în
  „Ofertă nouă”.
- **Previzualizare** (dreapta): emailul randat cu date de exemplu, după ultima salvare.
- **Copiază** creează o copie a șablonului; **Șterge** îl elimină — ofertele deja trimise nu sunt afectate (își păstrează
  subiectul și linkurile).

## 7. Materiale

- **Încarcă material**: titlu (textul butonului din email), tip (**Ofertă** sau **Material suport**) și fișierul
  (PDF, HTML, DOCX, XLSX, PPTX, PNG, JPG, MP4; limita implicită 25 MB).
- Lista arată pentru fiecare material: extensia, numele original, mărimea, data actualizării, în câte șabloane apare,
  de câte ori a fost trimis și deschis.
- **Înlocuiește fișierul** păstrează linkurile deja trimise: clienții deschid noua versiune.
- **Activ** debifat: materialul nu mai poate fi deschis de clienți și dispare din șabloane și din „Ofertă nouă”;
  bifat din nou, revine.
- Fișierele stau într-un folder privat de pe server, fără adresă publică; ajung la client doar prin linkul personal.

## 8. Utilizatori

- **Parola mea**: parola actuală, parola nouă (minimum 10 caractere) și repetarea ei.
- **Utilizator nou**: nume de utilizator (litere mici, cifre, `. _ @ -`), nume afișat și parolă. Lăsată goală, parola se
  generează automat și se afișează **o singură dată** în mesajul de confirmare — trimite-o colegului pe un canal sigur.
- **Lista**: poți redenumi, dezactiva/activa (un cont dezactivat nu se mai poate autentifica și este deconectat imediat),
  seta o parolă nouă (câmpul „Parolă nouă (opțional)”) și șterge. Propriul cont (marcat „eu”) nu poate fi dezactivat
  sau șters.
- Toți utilizatorii au aceleași drepturi în cockpit.

## 9. Ajutor

Secțiunea **Ajutor** din cockpit repetă pe scurt acest manual: fluxul de lucru, cum ajung materialele suport în email pas cu
pas, câte o parte pentru fiecare secțiune și problemele frecvente. Valorile afișate acolo (limita de încărcare, valabilitatea
codului de acces, numărul de coduri și de încercări, durata sesiunii) sunt cele din configurația curentă a serverului.

## 10. Ce vede clientul

1. Primește emailul din șablon, cu câte un buton pentru fiecare material. Fiecare buton este un link personal de forma
   `https://ofertare.aiall.ro/<cod-lung>`.
2. La prima deschidere, pagina îi cere un **cod de 6 cifre**, trimis automat pe adresa lui (dacă oferta are „Cod de acces
   pe email”). Codul este valabil 10 minute, are 5 încercări, iar un link nu trimite mai mult de 5 coduri pe oră.
3. Codul deschide **toate** materialele din același email, timp de 12 ore, în același browser. După aceea i se cere un cod
   nou.
4. Fiecare deschidere se înregistrează pe card (contorul materialului, jurnalul) și mută oferta în „Deschisă” la prima
   deschidere; administratorul primește o notificare pe email la prima deschidere (dacă este configurată).
5. După expirarea linkurilor sau după revocare, pagina afișează „Link indisponibil”. Un link redirecționat altcuiva ajunge
   tot la pagina de cod, iar codul pleacă doar la destinatarul inițial.

## 11. Întrebări frecvente

**Emailul nu a plecat („Trimiterea a eșuat”).** Oferta și linkurile există; copiază linkurile din mesajul de confirmare
și trimite-le manual. Verifică cu administratorul căsuța expeditoare din `config.php` (`mail_from`).

**Clientul nu primește codul.** Codul pleacă pe adresa din ofertă; verifică folderul Spam al clientului și adresa de pe card.
Poți crea o ofertă nouă fără „Cod de acces pe email” dacă situația o cere.

**Am greșit materialele sau destinatarul.** Revocă linkurile ofertei greșite (Detalii → „Revocă toate linkurile ofertei”),
mută-o în „Pierdută” sau las-o cu o notă, și creează oferta corectă.

**Vreau să schimb oferta (PDF) deja trimisă.** Materiale → „Înlocuiește fișierul”: toate linkurile trimise deschid noua
versiune, fără email nou.

**Nu mai pot intra (parolă uitată).** Un coleg îți setează o parolă nouă din Utilizatori. Dacă nimeni nu mai poate intra,
administratorul serverului folosește calea de recuperare din `README.md` (contul din `config.php`).

**Tabelul din Raportare arată altfel decât ieri.** Vederea (filtre, coloane, sortare, grupare) se memorează în browserul
tău; „Resetează vederea” o aduce la cea implicită.
