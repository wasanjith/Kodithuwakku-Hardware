# Kodithuwakku Hardware POS: Task Log

> Running record of project progress. Updated after every request.
> Plan: [HARDWARE_PLAN.md](HARDWARE_PLAN.md) · Baseline (Citizens) docs: [citizens-reference/](citizens-reference/)

---

## Current status

| Item | Status |
|---|---|
| **Current phase** | H1 hardware catalogue base done (except logo + shop details). Next: H2 barcodes |
| **Last updated** | 2026-11-05 |
| **Tests** | 538 Pest tests: all 538 pass, 0 skipped, 0 fail (2026-11-05, parallel, ~4 min) |
| **Open issue** | Waiting for the owner's **shop details** (address, phone, VAT number, invoice footer), and their own logo file if they have one (a purple logo made for now is in `public/images/logo.svg` / `logo-icon.svg`). Site: start Herd; `kodithuwakkuhardware.test` needs a hosts entry if Herd does not add it. Own Docker services: Redis **6380**, Meilisearch **7701** (`docker compose -f docker-compose.dev.yml up -d`). |
| **Next** | Owner: upload logo + shop details. Dev: H2 barcodes, then H3 per-counter payment. |

### Phase progress

| Phase | Name | Status |
|---|---|---|
| H0 | Copy + own identity | ✅ Done |
| H1 | Hardware catalogue base, English only, roles | 🟨 Done except logo + shop details |
| H2 | Barcodes (POS scan, link unknown, GRN/stocktake/returns) | ⬜ |
| H3 | Per-counter payment + counter drawers | ⬜ |
| H4 | Prices (contractor, special, bulk change), VAT invoices | ⬜ |
| H5 | Quotation maker | ⬜ |
| H6 | Serial numbers + warranty | ⬜ |
| H7 | Delivery notes, paint tinting | ⬜ |
| H8 | Phone photos of supplier invoices, 15,000-product performance check | ⬜ |
| H9 | Go-live | ⬜ |

---

## Decisions made

| Date | Decision |
|---|---|
| 2026-11-05 | Separate copy of the Citizens code in `C:\projects\KodithuwakkuHardware`, separate owner, goes live after Citizens. |
| 2026-11-05 | **No git** in this project (owner's instruction, same as Citizens): no init, commit or push. |
| 2026-11-05 | Own identity on the dev machine: DB `kodithuwakkuDB` / `kodithuwakkuDB_testing`, cache/redis/scout prefixes `kodithuwakku…`, session cookie `kodithuwakku_session`, terminal cookie `kodithuwakku_terminal`, Reverb port **8081** (Citizens uses 8080), backup name `kodithuwakku-pos`. |
| 2026-11-05 | **Own Docker containers**, not shared with Citizens (owner's instruction): compose project `kodithuwakku`, `kodithuwakku-redis` on host port **6380**, `kodithuwakku-meilisearch` on **7701** (Citizens keeps 6379 / 7700), own volumes `kodithuwakku_redis-data` / `kodithuwakku_meili-data`, `restart: unless-stopped`. |
| 2026-11-05 | **Brand: purple + amber.** `brand-*` palette = purple (`#faf5ff`…`#3b0764`, main `brand-600 #9333ea`, sidebar `brand-900 #581c87`); accent amber-400 `#fbbf24`. Own logo (hammer + wrench in a purple/amber badge, "KODITHUWAKKU / HARDWARE" word mark) as SVG. |
| 2026-11-05 | One unique barcode per product; scan then type the quantity; no label printing; unbarcoded goods use search. |
| 2026-11-05 | Both sales flows, owner chooses with a setting. Roles Owner / Manager / Cashier. English only. VAT-registered. Each size is its own product. Cut lengths via Open packs (roll → per metre). |
| 2026-11-05 | Owner's answers to the 12 questions (plan § 1.1): **USB barcode readers** at the counters; **phone camera only for supplier invoice photos** into the supplier's folder (file input → no HTTPS needed); product list is in **written books** (typed in); opening balances yes (existing go-live import); **main machine + 4 counters**; settling **per counter**: the main machine settles, and a counter **with its own drawer** settles its own bills; price lists Retail / Wholesale / Contractor; tinting charge + colour saved per customer; serial numbers yes; shop details to be uploaded. |
| 2026-11-05 | Defaults taken where the owner said "default": discounts **Cashier 10 % / Manager 25 %**, above that the Owner approves; delivery by own vehicle, charge typed per delivery, pay now and collect later. "Yes" to VAT taken as: prices include VAT, some items VAT-exempt (per product). |
| 2026-11-05 | **5-digit short codes** with category ranges (10,000+ products do not fit in 4 digits); a category without a range gets codes from 100000. |
| 2026-11-05 | Sinhala stays in the code behind one switch, `pos.sinhala_fields` (env `POS_SINHALA_FIELDS`, off): hides the Sinhala/Tamil name fields, the Sinhala settings and the invoice-language choice. The columns stay. |

---

## Log

Newest first.

### 2026-11-05: Owner's answers recorded; H1 hardware catalogue base
- **Plan:** answers to all 12 questions in [HARDWARE_PLAN.md § 1.1](HARDWARE_PLAN.md#11-answers-to-the-open-questions-2026-11-05); § 3.2 now covers the phone page for supplier invoice photos (instead of phone barcode scanning), § 3.3 covers per-counter payment (instead of one shop-wide toggle), § 3.13 covers typing the list in from the books. § 5 left open: logo + shop details only.
- **Roles:** `sales_staff` → **`cashier`** (enum `Role::Cashier`, config, permissions, factory state `cashier()`, user form default). Role labels **Owner** / Manager / Cashier; "Super Admin" → "Owner" in all screens, messages and comments.
- **Settings defaults:** invoice language **English**, VAT registered **on**, default rate **18 %**, discount limits Cashier 10 % / Manager 25 % (setting key `pos.max_discount_percent_cashier`). With `pos.sinhala_fields` off, the settings page has no Sinhala shop name / address / footer and only English as invoice language; the terminal form hides its language choice; product, category, unit, customer and employee forms hide the Sinhala (and Tamil) name.
- **Prints:** the COPY / CREDIT INVOICE / Credit bill marks were always printed in Sinhala + English; they now follow the invoice language. Test print page in English.
- **Catalogue seeder:** 25 units (added box, dozen, tube, tin, coil, sheet, length, bundle, feet, inch, sq ft, cube, gallon; Sinhala unit names removed); price lists Retail / Wholesale / **Contractor**; VAT 18 % active; 16 hardware categories with 5-digit ranges (Building Materials 10000–14999 … Fasteners 50000–54999 … Other 90000–99999) and their sub-categories. `ShortCodeGenerator` default start 10000 → 100000.
- **Terminals:** MAIN + **C1–C4** (Counter 4 added) and demo user `staff4` (PIN 3334).
- **Demo data:** 51 hardware products (cement, sand, PVC pipes and fittings, wire rolls that open into wire per metre, nail boxes that open into loose nails, paints, tools, pumps …) with Retail / Wholesale / Contractor prices; 4 hardware suppliers linked to their brands; contractor and regular customers; employees without Sinhala names. Product import template examples are hardware items.
- **Dev DB** `kodithuwakkuDB` re-created (`migrate:fresh --seed`; it held only seed data, no sales or GRNs); Meilisearch index `kodithuwakku_products` flushed and re-imported (51 hardware products; search for "wire" checked).
- **Tests:** category names in the tests mapped (Fertilizers → Building Materials, Seeds → Fasteners, Bicycle Parts › Tyres → Plumbing › PVC Pipes, Tools → Hand Tools, Agro-chemicals → Paints) and code-range checks moved to 5 digits; Sinhala print tests now choose Sinhala explicitly; demo-data and settings tests rewritten (new: English-only shop profile, Sinhala switch on). 538 Pest tests: all 538 pass, 0 skipped, 0 fail. Pint clean, all edited files LF.
- **Not done yet in H1:** logo image and shop details (waiting for the owner). The import's loose-price columns still say "per kg" (fine for nails; to be renamed per base unit when the books are typed in).

### 2026-11-05: Own login page, logo and purple theme
- **Logo:** new `public/images/logo.svg` (wide, white/amber text for dark purple backgrounds) and `logo-icon.svg` (round badge). PNG copies rendered from the SVGs (headless Chrome + Pillow) replace the Citizens images: `images/logo.png`, `images/logo-icon.png`, `favicon.ico` (16/32/48), `favicon-32x32.png`, `apple-touch-icon.png`. No Citizens artwork is left in `public/`.
- **Layouts:** `app` sidebar and `pos` top bar use the SVGs. `guest` layout (login, PIN login, 2FA, confirm password) redesigned: purple gradient with soft glows, larger logo, amber "POINT OF SALE" line instead of the Sinhala Citizens name, amber top border on the card, copyright footer.
- **Theme:** `resources/css/app.css` brand palette green → purple; all screens use `brand-*` tokens (no hardcoded greens found), so the whole app is now purple. `npm run build` done.
- Checked: login page screenshots at 1280 px and 390 px (throwaway DB `kodithuwakkuDB_browser` via `php -S`, dropped afterwards with its Redis keys); Auth + PagesRender + PrintingTestPage tests 43/43 pass.
- Note: the "Main cashier" terminal badge is `purple`, which is now close to the brand colour; change it if it is hard to pick out.

### 2026-11-05: Own Docker containers + Vite build
- **Vite manifest missing** (`public/build` was not copied from Citizens): `npm install` + `npm run build` → `public/build/manifest.json` created.
- **Separate Docker services** (owner: Citizens is a separate project): `docker-compose.dev.yml` now has `name: kodithuwakku`, Redis on host port 6380, Meilisearch on 7701, own volumes, `restart: unless-stopped`. `.env` and `.env.example`: `REDIS_PORT=6380`, `MEILISEARCH_HOST=http://127.0.0.1:7701`. Started with `docker compose -f docker-compose.dev.yml up -d`; `optimize:clear`.
- New Meilisearch index filled: `scout:sync-index-settings` + `scout:import` (51 products into `kodithuwakku_products`). Removed the one leftover `kodithuwakku…` key from `citizens-redis`; Citizens containers left running and untouched otherwise.
- Checked: `Redis::ping()` true on 6380, Meilisearch health available on 7701; PagesRenderTest + PasswordLoginTest + ProductSearchTest 46/46 pass.
- Note: sessions moved to the new Redis, so anyone logged in must log in again.

### 2026-11-05: RedisException "connection refused" fixed
- Cause: the shared Docker containers `citizens-redis` and `citizens-meilisearch` had stopped (exit 255, Docker restart), so nothing listened on port 6379. Sessions, cache and queue all use Redis.
- Fix: `docker start citizens-redis citizens-meilisearch`. Checked: `redis-cli ping` → PONG; from Laravel `Redis::ping()` → true and a cache write works. No code changes.

### 2026-11-05: Project created from CITIZENS Agro POS
- **Copied** `C:\projects\Citizens` → `C:\projects\KodithuwakkuHardware` (robocopy, 18,837 files) **without** `.git`, `.env`, Media (Citizens logos), client deliverables, the Citizens inventory sheet, logs, sessions, caches, uploaded supplier invoices, imports and `public/build`. Empty storage folders re-created.
- **Own identity:** new `.env` (new APP_KEY and Reverb keys; DB password and Meilisearch key taken from the Citizens `.env`), `.env.example`, `phpunit.xml` and `tests/Pest.php` → `kodithuwakkuDB_testing`, `config/pos.php` (header, shop name *Kodithuwakku Hardware*, terminal cookie), `config/backup.php`, `config/filesystems.php`, layouts (logo alt text, POS top bar name), `docker-compose.dev.yml`, `package-lock.json` name. Internal JS names `citizens:printed` → `pos:printed`, `CitizensPrinting` → `PosPrinting`.
- **Databases** `kodithuwakkuDB` and `kodithuwakkuDB_testing` created (utf8mb4); `migrate --seed` ran clean (with Scout/cache off because Docker was not running).
- **Herd:** junction `…\.config\herd\config\valet\Sites\KodithuwakkuHardware` → project (no admin needed). Site URL `http://kodithuwakkuhardware.test` once Herd is running and the hosts entry exists.
- Docs: Citizens docs moved to `docs/citizens-reference/`; new [HARDWARE_PLAN.md](HARDWARE_PLAN.md) (owner's answers, reuse vs change, barcode design, phases H0–H9, 12 open questions); this log.
- **Tests:** first full run 526 pass, 2 fail, 9 skipped. Both failures were tests expecting the old Sinhala shop name "සිටිසන්ස් ඇග්‍රෝ" (`SettingsTest`, `PrintingTestPageTest`); updated to the new defaults, both files pass (10 tests). Pint clean.
- Still Citizens-flavoured (changed in H1): demo seeders (seeds, fertilizer, bicycle parts), categories and code ranges, Sinhala receipt default, logo image `public/images/logo.png`.
