# R6 slice 2 — tenantová obrazovka nastavení fakturace

> Stav: hotovo — SSO cíl `/settings/supplier` odemčený
> Datum: 2026-09-03

## Proč vznikla

Managed režim skrývá platformní `/admin/settings` a `/admin/codebooks`, ale
doklad bez úplné adresy a DIČ vystavit nejde a ReviziOR tyhle údaje sám
neposílá (kontrakt nese jen to, co o organizaci ví). Vlastník firmy tak neměl
kde je doplnit; SSO cíl `/settings/supplier` proto do teď vracel
`sso_target_unavailable`.

## Co obrazovka umí

`web/src/pages/SupplierSettings.vue` na routě `settings/supplier`
(`requiresPermission: supplier_settings.manage`, `requiresSupplier`):

- fakturační identita — název, ulice, město, PSČ;
- daňové údaje — IČO, DIČ a režim DPH jako **jeden výběr** (neplátce /
  identifikovaná osoba / plátce), protože `is_vat_payer` a `is_identified`
  se podle § 6g–6l vylučují a dvě zaškrtávátka svádí k neplatné kombinaci;
- kontakt — e-mail, telefon, web;
- výchozí hodnoty dokladu — splatnost, jednotka splatnosti, režim cen.

Chybějící povinné údaje vypíše nahoře **dřív**, než uživatel narazí na
odmítnutý doklad. Země je jen ke čtení: přiřazuje ji provisioning podle
organizace a její změna by rozhodila daňový režim už vystavených dokladů.

Stránka jede na existujícím `GET/PUT /api/settings/supplier`, které už mají
oprávnění `supplier_settings.manage` — žádný nový endpoint, žádná migrace.
Posílá jen pole, která edituje, takže zbytek nastavení firmy zůstává, jak ho
má instalace nebo provisioning.

Navigace: položka **Systém → Nastavení fakturace** se zobrazí každému, kdo má
`supplier_settings.manage` a není platformní admin (ten má vlastní sekci).
Obě locale, manuál § 36.2.5.

## SSO

`ReviziorSsoTargetPolicy` teď `/settings/supplier` propouští. Ceník zůstává
přeložený z kontraktního `/price-list` na `/admin/price-list`.

## Cross-repo smoke (2026-09-03)

| Krok | Výsledek |
|---|---|
| `/faktury/nastaveni` v ReviziORu | `303` na SSO poskytovatele |
| SSO | `303` na `/settings/supplier` (dřív `403 sso_target_unavailable`) |
| SPA | `200`, `GET /api/settings/supplier` vrátil firmu z provisioningu |
| uložení | `PUT` prošlo jako `supplier_owner`: DIČ doplněno, režim DPH `plátce` |

## Ověření

- `pnpm type-check`, `pnpm build`, `pnpm test:pwa` — 66 testů zelených, nový
  test hlídá oprávnění, routu, navigaci i obě locale;
- PHPUnit celá suite a PHPStan level 0 beze změny;
- manuál přegenerován (HTML, 42 kapitol).

## Odkaz na revizi u dokladu

`GET /api/invoices/{id}/revizior-sources` (managed only, doklad musí patřit
aktuální firmě) vrací zdroje z `revizior_invoice_sources` i s hotovou absolutní
URL. Origin se skládá z `deployment.revizior.app_url` — nikdy ze vstupu
requestu, jinak by z odkazu na detailu dokladu vznikl open redirect na doméně
poskytovatele.

Odkazuje se jen `revision_report` (`/revize/zpravy/{uuid}`), protože jen ten má
dnes v ReviziORu obrazovku; ostatní typy se vypíšou jako text bez odkazu —
uhodnout cestu a poslat uživatele na 404 je horší než odkaz nemít.

Detail dokladu ukazuje sekci **Původ v ReviziORu** jen v managed režimu a jen
když seznam není prázdný; chyba načtení sekci tiše skryje, protože je to
doplněk, ne podmínka zobrazení faktury.

Živě ověřeno 2026-09-03: doklad 5 vrátil revizní zprávu RZ-2026-0002 s odkazem
`https://app.revizior.cz/revize/zpravy/<uuid>`.

## R6 je hotová

Zbývá R7 hardening (threat model, penetrační test, key rotation rehearsal,
zátěž, SLO a runbooky) a cutover: odstavení staré interní fakturace v ReviziORu
až po ověřeném provozu.

## Vizuální sjednocení s reviziORem (2026-09-08)

Fakturace měla vlastní identitu (indigo, Inter, logo „M"), takže vedle aplikace
působila jako cizí produkt. Sjednoceno na to, co reviziOR skutečně používá —
ne na to, co má v Tailwind konfiguraci:

| | hodnota | kde se v reviziORu bere |
|---|---|---|
| pozadí stránky | `#F5F0E8` | `<body>` a hlavní plochy |
| text | `#07162B` | `body { color }` |
| primární akce | `#123D75` | modř značky z loga |
| zlatá | `#D9B24B` / `#E2A91A` | tlačítka a značka |
| font | Plus Jakarta Sans | `base.html.twig` |

**Font je hostovaný lokálně** (`web/public/fonts/`), ne z Google Fonts: CSP
fakturace nepouští externí zdroje a font ze třetí strany by navíc prozradil
návštěvu Googlu. Je variabilní, takže jeden soubor na sadu znaků pokrývá
všechny váhy — 56 kB místo 260 kB.

**Logo** je značka reviziORu (`styles/logo.svg`), vytažená z `public/icon.svg`
aplikace bez Inkscape metadat. Z ní jsou odvozené i PWA ikony; maskable varianta
má značku v bezpečné zóně (vnitřních 80 %) na krémovém podkladu — na navy byl
modrý štít skoro neviditelný.

**Barvy e-mailů a PDF se změnily taky.** Výchozí akcent byl fialový
(`#3B2D83`), takže doklady a e-maily odcházely klientům technika v barvách
cizího produktu. Layout ani struktura šablon se nemění.

Layout zůstal, jak byl: fakturace má horní lištu, reviziOR svislé menu.
Přestavba by byla velký zásah s malým přínosem — vjem „jeden produkt" nese
barva, font a značka.

### Tmavý režim se musí přebarvit taky

Paleta žije ve dvou sadách: světlé tokeny a jejich override ve `.dark`. Režim
`auto` sleduje nastavení systému, takže uživatel s tmavým OS vidí **jen** tu
druhou sadu — po přebarvení světlé palety pro něj bylo všechno beze změny
(2026-09-09).

Tmavá varianta teď staví na navy `#07162B` jako pozadí, krémovém textu
`#F5F0E8` a zesvětlené modři značky `#3D7ABF` (s bílým textem ~4,6:1). Stejnou
past mají grafy: `CHART_PALETTE_LIGHT` i `CHART_PALETTE_DARK` v `useTheme.ts`
jsou natvrdo zapsané hodnoty, které se s tokeny nesynchronizují samy.

Fialová zůstává jen tam, kde nese význam (badge „Přeplaceno"), ne jako barva
značky.

### Jen světlý režim

Přepínač System / Light / Dark je pryč a `.dark` se nikdy nenasazuje
(2026-09-09). Důvod je ten samý jako u palety: fakturace a reviziOR mají působit
jako jeden produkt, a hlavní aplikace tmavý režim nemá. Uživatel s tmavým OS
tak viděl v jedné polovině produktu něco jiného než v druhé.

Vypnuto na třech místech, protože každé samo o sobě umí `.dark` nasadit:

- `composables/useTheme.ts` — `isDark` je natvrdo `false` a effect třídu
  odstraňuje i těm, kdo si ji dřív uložili do `localStorage`;
- anti-FOUC script v `web/index.html` — sahal na `prefers-color-scheme` ještě
  před načtením aplikace;
- `ThemeToggle` v `AppLayout` (dva výskyty — desktop a mobil).

Tokeny `.dark` v `styles/main.css` **zůstávají** a jsou v barvách reviziORu:
až tmavý režim dostane hlavní aplikace, zapne se to zpátky bez dalšího ladění.

### Kde jakou barvu použít

Splývání ploch nevyřeší jiná paleta, ale důslednost v tom, co která barva
znamená. Konvence (2026-09-09):

| Plocha | Barva | Proč |
|---|---|---|
| plátno stránky | krémová `#F5F0E8` (`neutral-50`) | totéž pozadí jako reviziOR |
| karta, tabulka, modál | bílá (`surface`) | kontrast proti plátnu dělá hranici karty |
| svislé menu | navy `#07162B` (`primary-900`) | těžká plocha vlevo drží rozvržení |
| horní lišta | krémová jako plátno | bílá lišta splývala s kartami |
| primární akce | modř značky `#123D75` | jediná sytá modrá v UI |
| aktivní položka menu | zlatá `#E2A91A` na tlumeném podkladu | jediné místo, kde zlatá nese význam |
| ohraničení | `neutral-200` `#E3DACB` | teplý tón, ne šedá |

Dvě pravidla, která se snadno poruší:

- **Zlatá je jen pro „tady jsi".** Jakmile se použije i na tlačítka nebo
  odznaky, přestane v menu fungovat jako vodítko.
- **Sekce menu nejsou semafor.** Barevné pilulky (`warning`, `success`,
  `danger`) u nadpisů sekcí braly pozornost jako stavové hlášky; na tmavém
  podkladu stačí tlumený text.

Barvy chromu žijí v `styles/main.css` **mimo `@layer`** — Tailwind řadí
`utilities` až za `components`, takže by je jinak přebily utility v šabloně.
Šablona proto nese jen sémantické třídy (`app-sidebar__item`), ne barvy.

### Obsah: tabulky a primární akce (2026-09-10)

Tabulky měly hlavičku nalepenou na řádcích a primární tlačítko bylo modré
s bílým textem, zatímco reviziOR má vzdušné řádky a jedno těžké tlačítko
(navy plocha, zlatý text). Vedle sebe to působilo jako dva produkty.

- `table thead th` a `tbody td` dostaly svislý prostor jako v reviziORu;
- `button.bg-primary-600` / `a.bg-primary-600` se vykreslí navy + zlatě.

Cílíme na `button`/`a`, ne na každou plochu s tou třídou: odznak se stejnou
třídou má zůstat odznakem. Pravidla stojí — stejně jako chrom — **mimo
`@layer`**, jinak by je přebily utility v šablonách (`px-2 py-2`,
`text-white`). Šablony se tím nemusí přepisovat na 200 místech.

Do lišty přibyl zlatý monogram uživatele, tatáž dvojice jméno + kolečko jako
v reviziORu.

### Tabulky a lišta doladěné podle reviziORu (2026-09-10)

První kolo dalo tabulkám prostor, ale hlavička pořád splývala s prvním řádkem:
reviziOR ji odděluje pruhem (`bg-gray-50` na bílé kartě), fakturace měla
hlavičku bílou jako řádky. Doplněno `table thead` s teplým ekvivalentem
(`neutral-100`), velikost a prostrkání písma hlavičky, odezva řádku na kurzor.

Karty mají 12px rádius jako v reviziORu. Pravidlo cílí na kombinaci
`rounded-lg` **s rámečkem**, aby se nezaoblily i vstupy a tlačítka se stejnou
třídou.

V liště zmizely rámečky sekundárních tlačítek — tři orámovaná tlačítka vedle
sebe z ní dělala ovládací panel, kdežto reviziOR má v liště jen text a ikony.
Odsazení na širokých displejích je `lg:px-6`, stejné jako tam.

### Měřeno, ne odhadem (2026-09-11)

Porovnání snímků obou aplikací po pixelech ukázalo, že plátno, lišta i menu
už mají **shodné** hodnoty (`#F5F0E8`, `#07162B`). Rozdíly zbývaly dva:

| Prvek | reviziOR | fakturace (před) |
|---|---|---|
| karta | `#FDFAF4` teple bílá | `#FFFFFF` čistá bílá |
| hlavička tabulky | o odstín jinak než karta | výrazný pruh `#EFE8DC` |

Čistá bílá na krémovém plátně svítí a čte se jako cizí prvek, proto je
`--color-surface` teple bílá. Hlavička tabulky je zjemněná na `#F4F0E8` —
v reviziORu odděluje, ale nekřičí.

**Lišta nese jméno stránky, ne značku.** Značka sedí nahoře v menu, stejně
jako v reviziORu, takže se v liště neopakuje; jméno se bere z aktivní položky
menu. Na stránkách mimo menu (profil) zůstává název produktu, aby lišta
nebyla prázdná.

Co zůstává jinak: filtry. reviziOR má pilulky nad seznamem, fakturace
rozbalovací seznamy v kartě — to není barva, ale jiný způsob filtrování.
