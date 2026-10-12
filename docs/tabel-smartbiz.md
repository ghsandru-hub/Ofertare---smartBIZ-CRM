# Tabelul smartBIZ în Raportare — ce s-a preluat din `ConfigurableDataTableScreen`

Sursa de referință: `Time-Mobility-Tracker/src/shared/components/ConfigurableDataTableScreen.tsx` (React, ~18.000 de linii),
împreună cu `DataTableFacetSection.tsx`, `dataTableColumnBehavior.ts`, `dataTableExcelExport.ts`, `dataTablePdfPagination.ts`
și logica de filtre a host-ului Mobility Tracker (`services/mtReportFilters.ts`).

Implementarea din Ofertare este în `ofertare.aiall.ro/admin.php` (`view_report()` + `report_js()`), JavaScript simplu, fără
biblioteci, peste datele ofertelor din SQLite. Tabelul nu este un port al componentei (aceea este cuplată la modelul de
date Model/Mobility), ci o reimplementare a comportamentului vizibil pentru utilizator, adaptată la un raport cu câteva
sute de rânduri.

Legendă: **Preluat** = același comportament; **Parțial** = preluat cu simplificări; **Nepreluat** = nu există în Ofertare (cu motivul).

## 1. Filtrare

| Funcționalitate în smartBIZ | Stare | În Ofertare |
| --- | --- | --- |
| Căutare globală „Caută în tabel”: substring, `toLocaleLowerCase("ro")`, în textul formatat al tuturor coloanelor (și al celor ascunse) | **Preluat** | identic; câmpul cu lupă din bara de sus |
| Buton icon-only „Afișează filtrele / Ascunde filtrele” lângă titlu, panou lateral stânga, pornit închis | **Preluat** | `#rFiltBtn`; starea panoului se memorează |
| Antetul panoului „Filtre” cu badge `afișate/total` | **Preluat** | badge `M/N` din rândurile afișate / total |
| Facet per coloană text: titlu, chevron, Reset (doar cu selecție), contor `n/total` | **Preluat** | o secțiune pentru fiecare coloană marcată `f` |
| Facet doar pentru coloanele de tip text (nu date, nu numere) | **Preluat** | Companie, Județ, CAEN, Plătitor TVA, Inactiv ANAF, Persoană, Șablon, Etapă, Etapă maximă, Luna creării, Link |
| Căutare în facet, afișată doar peste 8 valori (`mtReportFilterSearchMinOptions`), placeholder „Caută <coloană>” | **Preluat** | identic (pragul 8); reîmprospătează doar lista, focusul rămâne în câmp |
| Opțiuni: bifă, punct colorat, valoare, număr de rânduri; sortare `ro`, numerică | **Preluat** | punctul colorat pentru Etapă și Link, gol la restul |
| Semantică: OR între valorile aceleiași coloane, AND între coloane | **Preluat** | `filtered()` |
| Valorile goale devin bucket-ul „Fără valoare”, selectabil | **Preluat** | `EMPTY = 'Fără valoare'` |
| Numărătorile se calculează pe toate rândurile, nu pe cele filtrate | **Preluat** | identic cu `buildMtReportFilterOptions` |
| O valoare bifată care nu mai apare rămâne în listă cu 0, ca să poată fi debifată | **Preluat** | identic |
| „Resetează filtrele (n)”, dezactivat la 0; Reset pe fiecare secțiune | **Preluat** | perioada „Creată” contează ca un filtru |
| Text „Nu există valori.” la listă goală | **Preluat** | identic |
| Fără „selectează tot” în facet, fără chip-uri de filtre active | **Preluat** | la fel: feedback prin contor, Reset și badge |
| Filtre de interval pentru date / operatori per coloană (=, ≠, conține, >, <, între) | **Parțial** | smartBIZ nu are operatori per coloană în tabel (doar în KPI, coloane virtuale și agregări); Ofertare păstrează perioada „Creată de la / până la” existentă |
| „Area filter cards” (Surse / Resurse / Evidențe / Rezultate) | **Nepreluat** | specific aplicației Model |
| Filtre KPI (valori multiple pe un indicator, confirmare la ștergere) | **Nepreluat** | indicatorii Ofertare sunt ficși (vezi 3) |

## 2. Sortare

| Funcționalitate în smartBIZ | Stare | În Ofertare |
| --- | --- | --- |
| Clic pe antet, trei stări: crescător → descrescător → fără sortare | **Preluat** | starea „fără” = ordinea serverului (cea mai nouă ofertă prima) |
| O singură coloană sortată | **Preluat** | — |
| Indicator SVG: săgeată sus/jos activă, săgeată dublă estompată inactiv | **Preluat** | `sortIcon()` |
| Valorile goale primele; numere și date comparate tipizat; text `localeCompare("ro", base, numeric)` | **Preluat** | datele sunt `YYYY-MM-DD`, deci compararea ca text este corectă |
| Sortarea nivelurilor de grupare (asc/desc per nivel) | **Parțial** | grupurile sunt mereu în ordine crescătoare `ro` |
| Tastatură: Enter / Spațiu pe antet | **Preluat** | — |

## 3. Grupare, agregare, KPI

| Funcționalitate în smartBIZ | Stare | În Ofertare |
| --- | --- | --- |
| Grupare pe mai multe coloane, cu ordinea nivelurilor afișată ca chip-uri („Ordine grupare”, Reset) | **Preluat** | selectorul „+ nivel…” adaugă niveluri; chip-urile numerotate le scot |
| Doar coloanele non-numerice sunt grupabile; data are opțiunea derivată „An + lună” | **Preluat** | coloanele `g`; „Luna creării” este derivata datei |
| Rând de grup: caret, valoare, număr de rânduri, celule agregate | **Preluat** | subtotaluri: sumă (Deschideri, Salariați, Onorariu, Licență, Economie) și medie ⌀ (Zile până la deschidere) |
| Restrângere pe clic, „Expand all / Collapse all”, bifa „Numai grupările” (ascunde rândurile detaliate) | **Preluat** | „Restrânge tot / Extinde tot”; starea restrânsă se memorează |
| Grupurile pornesc restrânse (`autoCollapseGroups`) | **Parțial** | pornesc extinse — tabelul are puține rânduri |
| Combinarea a două coloane într-un singur nivel prin tragere | **Nepreluat** | fără echivalent necesar |
| Agregări configurabile per grup (Distinct, Sumă, Medie, Minim, Maxim, Prima/Ultima valoare, Număr zile, Formulă) cu condiții proprii | **Parțial** | agregările sunt fixe pe coloană (sumă / medie), cum erau și înainte |
| Buton Σ pe coloanele numerice („Suma <col>: <val>”) | **Parțial** | sumele apar permanent în rândul Total și în subtotaluri |
| KPI: carduri peste tabel, editor „Afișare KPI” (agregare, culoare, lățime, filtre proprii, reordonare) | **Parțial** | 6 carduri fixe calculate pe rândurile afișate: Oferte, Deschise %, Acceptate (+ conversie), Salariați mobili, Onorariu an 1, Economie client / an |

## 4. Coloane

| Funcționalitate în smartBIZ | Stare | În Ofertare |
| --- | --- | --- |
| „Setări coloane”: afișare / ascundere | **Preluat** | meniul cu pictograma de coloane |
| Reordonare prin tragerea antetelor | **Preluat** | drag & drop HTML5 pe `th`, indicator de inserare |
| Redimensionare din marginea antetului (min/max) | **Preluat** | mâner de 6 px, 60–600 px; lățimile se memorează |
| Fixare (pin) la stânga, maximum 3 | **Preluat** | pictograma din antet; coloanele fixate trec în față și sunt sticky |
| Alias, tip, aliniere, zecimale, separatori, format dată, culoare, mărime, grosime per coloană | **Nepreluat** | coloanele au format fix (număr cu separatori `ro-RO`, 1 zecimală la medii) |
| Coloane virtuale (formule IF/SUM/CONCAT/DATEDIFF…), coloane de referință | **Nepreluat** | fără model de date generic; valorile derivate (onorariu, licență, economie) se calculează pe server |
| Mod compact / densitate | **Preluat** | smartBIZ nu are nici el; „Tabel compact” se referă doar la KPI |

## 5. Rânduri

| Funcționalitate în smartBIZ | Stare | În Ofertare |
| --- | --- | --- |
| Fără paginare; tot setul afișat | **Preluat** | — |
| Virtualizare peste 200 de rânduri grupate | **Nepreluat** | nu e necesară la volumul raportului |
| Bifă „Selectează rândurile vizibile” în antet și bifă pe rând; bara de acțiuni pentru selecție | **Preluat** | bara arată numărul selectat și „Deselectează”; exporturile iau selecția |
| Clic pe rând / rând activ / panou de detalii / CRUD | **Parțial** | compania este link către cardul din Pipeline; fără editare în tabel |
| Texte: „Nu există înregistrări pentru criteriul curent.” | **Preluat** | — |
| Vederi Kanban și Grafic | **Nepreluat** | kanban-ul există deja în Pipeline; pâlnia ține loc de grafic |

## 6. Export și tipărire

| Funcționalitate în smartBIZ | Stare | În Ofertare |
| --- | --- | --- |
| Meniu „…” Export (icon-only) | **Preluat** | — |
| Rândurile exportate: selecția, dacă există, altfel toate după filtrare, căutare și sortare | **Preluat** | — |
| Export Excel: coloanele afișate cu etichete, numerele ca numere, datele ca text, antet înghețat, autoFilter | **Preluat** | `.xlsx` generat în browser (zip „store” + SpreadsheetML), fără biblioteci |
| Nume de fișier `{prefix}_{selectie\|lista}_{data}.xlsx` | **Preluat** | `raport_ofertare_selectie_2026-10-12.xlsx` |
| Export PDF: previzualizare, apoi „Print / Save as PDF”; KPI pe prima pagină; grupuri cu Subtotal / Total; rândurile nu se rup între pagini | **Preluat** | fereastră de tipărire A4 landscape cu KPI, grupări, subtotaluri, Total, antet repetat; linii punctate subțiri |
| Setări PDF (denumire raport, subiect, imagine antet, culori), salvate în configurație | **Nepreluat** | titlul și linia de context sunt fixe |
| Export CSV | **Preluat** | păstrat din versiunea anterioară (UTF-8 cu BOM, separator `;`), aceleași reguli de selecție și nume |
| Descărcare ZIP cu documentele pe foldere după grupare; email cu rândurile selectate | **Nepreluat** | nu există documente per rând de descărcat |

## 7. Persistență și configurații

| Funcționalitate în smartBIZ | Stare | În Ofertare |
| --- | --- | --- |
| Starea curentă (sortare, grupare, restrânse, ordine, lățimi, fixate, ascunse, panou filtre, căutare, filtre) se păstrează | **Preluat** | în `localStorage` (`ofertare_raport_v3`), nu pe server |
| Selector „Configurație curentă” + configurații denumite, „Salvează configurația” (dialog „Denumire”), „Șterge configurația” | **Preluat** | în `localStorage` (`ofertare_raport_cfg`), per browser |
| Scop Personal / Generic (pentru administratori), salvare pe server per tip de înregistrare | **Nepreluat** | nu există API de configurații; se poate adăuga ulterior |
| „Resetează vederea” la configurația implicită | **Preluat** | — |
| Coloanele salvate care nu mai există se elimină la încărcare | **Preluat** | `cols()` ignoră cheile necunoscute și adaugă coloanele noi |

## 8. Bara de instrumente

| Funcționalitate în smartBIZ | Stare | În Ofertare |
| --- | --- | --- |
| Ordinea: titlu, buton filtre, căutare, configurație + salvare + ștergere, setări coloane, selector vedere, acțiuni, export | **Preluat** | fără selectorul de vedere (nu există Kanban/Grafic aici) |
| Butoane icon-only cu `title` / `aria-label` | **Preluat** | — |
| Meniu „Mai multe acțiuni” când nu încap | **Parțial** | bara se înfășoară pe rândul următor |
| Escape închide meniurile | **Preluat** | și clicul în afara lor |
| Subsol „În baza de date: N · Afișate: M” | **Preluat** | plus „Selectate: S” |
| Light / dark | **Preluat** | smartBIZ nu are logică de temă; Ofertare urmează tema cockpitului |

## 9. Diferențe asumate

- Numărătorile din facete sunt pe toate rândurile (ca în smartBIZ), nu pe rândurile deja filtrate.
- Starea „fără sortare” păstrează ordinea serverului (cea mai nouă ofertă prima), nu ordinea de inserare generică.
- Configurațiile stau în browserul fiecărui utilizator; o configurație nu se vede de pe alt calculator.
- Grupurile pornesc extinse.
- Verificarea s-a făcut cu un test de interacțiune (Playwright, Chrome) peste 12 oferte: 48 de verificări pe filtre, sortare,
  grupare, coloane, selecție, exporturi (Excel validat ca arhivă, CSV, PDF cu `print()`), configurații și persistență.
