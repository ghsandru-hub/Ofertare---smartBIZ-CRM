# Ofertare — smartBIZ CRM: reguli pentru dezvoltare

Aplicație PHP fără dependențe (SQLite, un singur `admin.php` pentru cockpit, `lib.php` comun), deploy din cPanel
prin `.cpanel.yml`. Detalii funcționale în `README.md`; ghidul de utilizare este tab-ul „Ajutor” din cockpit.

## Reguli generice de UI (cerute de utilizator, se aplică la orice ecran nou sau modificat)

1. **Un rând = o linie de controale, la aceeași înălțime.** În listele editabile (Materiale, Utilizatori, Catalog) și în
   formulare, toate controalele unui rând (input, select, file, checkbox, butoane) stau pe aceeași linie, aliniate la
   câmpul principal, cu înălțimea de 36px. Textul secundar (meta, explicații, stări) merge sub câmp, nu deasupra.
   Nu centra vertical celulele cu înălțimi diferite; aliniază la început (`align-items:start`) și egalizează înălțimile.
2. **Butonul care acționează asupra unui câmp este o pictogramă**, lipită în dreapta câmpului, de aceeași înălțime
   (ex. încărcarea fișierului). Fără butoane late cu text pentru acțiuni locale. Întotdeauna `title` + `aria-label`.
3. **Comutatoarele de vedere folosesc pictograme**, nu etichete text (ex. Kanban / Tabel), cu tooltip și `aria-pressed`;
   pictogramele sunt SVG inline, `currentColor`, cu acul/detaliile desenate ca linii trasate, nu ca forme de sub-pixel.
   Clase CSS proprii pentru fiecare grup de pictograme, ca să nu intre în coliziune cu stilurile altor pagini.
4. **Antetul și meniul sunt fixate** (`position:sticky`). Utilizatorul și „Ieșire” stau într-un pill translucid în antet,
   ca în celelalte aplicații smartBIZ Copilot (avatar cu inițiale, nume, buton de ieșire). Footerul are doar textul din dreapta.
5. **Conținutul secțiunii alese stă într-un container distinct**, legat vizual de tab-ul activ. Același principiu în interiorul
   unei pagini cu mai multe secțiuni (ex. Ajutor): câte un container per secțiune, vizibil doar când este ales din bară;
   alegerea intră în hash-ul URL-ului și se reține în browser.
6. **Pipeline**: panoul „Ofertă nouă” stă în stânga paginii, pâlnia în dreapta; kanban-ul are și o vedere tabel cu aceleași acțiuni.
7. **Înainte de orice trimitere de email există previzualizare** cu datele reale; rezultatul trimiterii arată transportul și
   eroarea exactă a serverului, niciodată un „trimis” fără confirmare.
8. **Stil**: paleta smartBIZ Copilot (navy `#1F4788`, teal `#2D8B8E`, amber), IBM Plex Sans / Mono, aceleași componente ca în
   ContaCheck (`ghsandru-hub/ContaCheckCopilot`); temă deschisă și închisă prin variabilele din `:root`.

## Proces

- Fiecare modificare: `php -l`, test pe `php -S` local cu SQLite și verificare în Chromium (Playwright) înainte de push.
- Commit + push pe branch-ul de lucru, apoi PR către `main`; deploy-ul îl face utilizatorul din cPanel (Update from Remote → Deploy HEAD Commit).
- Un PR deja îmbinat nu se reutilizează: branch-ul se repornește din `main` și se deschide PR nou.
