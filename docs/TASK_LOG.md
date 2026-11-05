# Kodithuwakku Hardware POS: Task Log

> Running record of project progress. Updated after every request.
> Plan: [HARDWARE_PLAN.md](HARDWARE_PLAN.md) · Baseline (Citizens) docs: [citizens-reference/](citizens-reference/)

---

## Current status

| Item | Status |
|---|---|
| **Current phase** | H0: Project set up (copy of CITIZENS Agro POS). Next: H1 catalogue base, then H2 barcodes |
| **Last updated** | 2026-11-05 |
| **Tests** | 537 Pest tests: 528 pass, 9 skipped (need services that were off), 0 fail (2026-11-05, parallel, ~3.5 min) |
| **Open issue** | Site not reachable yet: Herd must be running, and `kodithuwakkuhardware.test` needs a hosts entry (Herd adds it; editing the hosts file needs admin). Docker Desktop (Redis + Meilisearch) was off. |
| **Next** | Owner: answer the open questions in [HARDWARE_PLAN.md § 5](HARDWARE_PLAN.md#5-open-questions). Dev: H1 + H2 (barcodes). |

### Phase progress

| Phase | Name | Status |
|---|---|---|
| H0 | Copy + own identity | ✅ Done |
| H1 | Hardware catalogue base, English only, roles | ⬜ |
| H2 | Barcodes (POS scan, link unknown, GRN/stocktake/returns) | ⬜ |
| H3 | Two sales flows toggle + counter drawers | ⬜ |
| H4 | Prices (contractor, special, bulk change), VAT invoices | ⬜ |
| H5 | Quotation maker | ⬜ |
| H6 | Serial numbers + warranty | ⬜ |
| H7 | Delivery notes, paint tinting | ⬜ |
| H8 | Phone camera scanning, 15,000-product performance check | ⬜ |
| H9 | Go-live | ⬜ |

---

## Decisions made

| Date | Decision |
|---|---|
| 2026-11-05 | Separate copy of the Citizens code in `C:\projects\KodithuwakkuHardware`, separate owner, goes live after Citizens. |
| 2026-11-05 | **No git** in this project (owner's instruction, same as Citizens): no init, commit or push. |
| 2026-11-05 | Own identity on the dev machine: DB `kodithuwakkuDB` / `kodithuwakkuDB_testing`, cache/redis/scout prefixes `kodithuwakku…`, session cookie `kodithuwakku_session`, terminal cookie `kodithuwakku_terminal`, Reverb port **8081** (Citizens uses 8080), backup name `kodithuwakku-pos`. Redis and Meilisearch containers are shared with Citizens (prefixes keep the data apart). |
| 2026-11-05 | One unique barcode per product; scan then type the quantity; no label printing; unbarcoded goods use search. |
| 2026-11-05 | Both sales flows, owner chooses with a setting. Roles Owner / Manager / Cashier. English only. VAT-registered. Each size is its own product. Cut lengths via Open packs (roll → per metre). |

---

## Log

Newest first.

### 2026-11-05: Project created from CITIZENS Agro POS
- **Copied** `C:\projects\Citizens` → `C:\projects\KodithuwakkuHardware` (robocopy, 18,837 files) **without** `.git`, `.env`, Media (Citizens logos), client deliverables, the Citizens inventory sheet, logs, sessions, caches, uploaded supplier invoices, imports and `public/build`. Empty storage folders re-created.
- **Own identity:** new `.env` (new APP_KEY and Reverb keys; DB password and Meilisearch key taken from the Citizens `.env`), `.env.example`, `phpunit.xml` and `tests/Pest.php` → `kodithuwakkuDB_testing`, `config/pos.php` (header, shop name *Kodithuwakku Hardware*, terminal cookie), `config/backup.php`, `config/filesystems.php`, layouts (logo alt text, POS top bar name), `docker-compose.dev.yml`, `package-lock.json` name. Internal JS names `citizens:printed` → `pos:printed`, `CitizensPrinting` → `PosPrinting`.
- **Databases** `kodithuwakkuDB` and `kodithuwakkuDB_testing` created (utf8mb4); `migrate --seed` ran clean (with Scout/cache off because Docker was not running).
- **Herd:** junction `…\.config\herd\config\valet\Sites\KodithuwakkuHardware` → project (no admin needed). Site URL `http://kodithuwakkuhardware.test` once Herd is running and the hosts entry exists.
- Docs: Citizens docs moved to `docs/citizens-reference/`; new [HARDWARE_PLAN.md](HARDWARE_PLAN.md) (owner's answers, reuse vs change, barcode design, phases H0–H9, 12 open questions); this log.
- **Tests:** first full run 526 pass, 2 fail, 9 skipped. Both failures were tests expecting the old Sinhala shop name "සිටිසන්ස් ඇග්‍රෝ" (`SettingsTest`, `PrintingTestPageTest`); updated to the new defaults, both files pass (10 tests). Pint clean.
- Still Citizens-flavoured (changed in H1): demo seeders (seeds, fertilizer, bicycle parts), categories and code ranges, Sinhala receipt default, logo image `public/images/logo.png`.
