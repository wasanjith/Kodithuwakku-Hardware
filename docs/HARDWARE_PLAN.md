# Kodithuwakku Hardware POS: Architecture Plan

> Started 2026-11-05 as a copy of the CITIZENS Agro POS (Laravel 13, MySQL 8, Redis, Meilisearch, Reverb, Blade + Alpine).
> The Citizens design documents are kept in [citizens-reference/](citizens-reference/) as the baseline. This file covers only **what changes** for the hardware shop.
> Progress: [TASK_LOG.md](TASK_LOG.md)

---

## 1. Owner's answers (2026-11-05)

| Topic | Answer |
|---|---|
| Project | Separate copy in `C:\projects\KodithuwakkuHardware`, separate owner. Goes live **after** Citizens. |
| Barcodes | **One unique barcode per product.** Scan the item, then type the quantity (e.g. 12). Products without a barcode are found with the normal search. **No label printing.** About **40 %** of products have a maker's barcode. No shared barcodes and no box-vs-piece barcodes. |
| Scanning | At the counter, plus goods receipts and stocktake. **Phone camera** wanted (see open question 1). |
| Sales flow | **Both** flows, chosen by the owner with a toggle: (a) counter bills → main cashier settles (Citizens flow), (b) the counter takes the payment itself. |
| Roles | Owner, Manager, Cashier. |
| Live Billing | Yes. |
| Language | **English only** (invoices and screens). |
| VAT | Shop is **VAT-registered**. |
| Units | All kinds: piece, box/pack, length (m/ft), weight (kg), area (sq ft), volume (L). |
| Cut lengths | Yes (cut from a roll, sell the rest later). |
| Sizes / colours | Like Citizens: **each size is its own product**. |
| Serial numbers / warranty | Yes. |
| Paint tinting | Yes. |
| Heavy goods + delivery | Yes. |
| Price levels | Several (retail, wholesale, contractor, customer special prices). Prices change often, so **bulk price change** is needed. |
| Discounts | Yes, up to **25 %**. |
| Credit customers | Yes (contractors). |
| Quotations | A full **quotation maker** is wanted. |
| Returns / exchanges | Yes. |
| Modules | Keep all Citizens modules (purchasing, finance, banks, cheques, HR/payroll, reports). |
| Setup | Same as Citizens (on-site server, thermal printers, Tailscale). |

### 1.1 Answers to the open questions (2026-11-05)

| # | Question | Answer → what we build |
|---|---|---|
| 1 | Counter scanning | **USB barcode reader at every counter.** The **phone camera** is for **photographing supplier invoices** and filing them in the supplier's folder (not for barcodes). See § 3.2. |
| 2 | Where the products are now | **In written books.** No old POS and no Excel. The list is typed in before go-live (§ 3.13). Barcodes are added while receiving goods and selling (§ 3.1). |
| 3 | Opening balances | **Yes**, supplier and credit-customer balances. The Citizens go-live import (Admin → Go-live: template → upload → preview → import) already does this. |
| 4 | Terminals | **Main server machine + 4 counters** (5 terminals). Payment settings are made from the main machine. |
| 5 | Who settles | **Both:** the main machine settles bills sent by counters, **and** a counter with its **own cash drawer** settles its own bills. So the choice is **per counter** ("this counter has a cash drawer and takes payment"), not one shop-wide toggle. See § 3.3. |
| 6 | Discount limits | Default taken: **Cashier 10 %, Manager 25 %**; more than that needs the **Owner's** approval (existing override). Can be changed in Settings → POS rules. |
| 7 | VAT | "Yes" taken as: **shelf prices include VAT**, some items are **VAT-exempt** (VAT set per product), contractors get tax invoices with their VAT number. The shop's VAT number comes with the shop details (12). |
| 8 | Price lists | **Retail, Wholesale, Contractor** (seeded). |
| 9 | Paint tinting | **Tinting charge: yes. Colour saved per customer: yes.** |
| 10 | Delivery | Default taken: **own vehicle**, transport charge **typed per delivery** (a default amount in Settings), **pay now, collect or deliver later** supported (goods kept reserved). |
| 11 | Serial numbers | **Yes.** Default taken: a per-product "track serial numbers" tick (power tools, pumps/motors, electricals with warranty); the claim register handles repair by the shop **or** by the supplier. |
| 12 | Shop details | Owner will **upload** logo, address, phone, VAT number and invoice footer. |

---

## 2. What is reused unchanged

Purchasing (PO, GRN, supplier returns, supplier invoice photos), stock ledger (`StockService`, `stock_movements`, batches, moving-average cost), stocktake, customers + credit + payments, returns, finance (journal, banks, cheques, expenses), cash drawer sessions + handover, HR / attendance / payroll, reports + dashboard, backups, terminals + PIN sign-in, printing pipeline, roles/permissions engine (`config/pos.php`).

**Open packs** (already built for urea bags) covers cut lengths: a sealed *"Wire 2.5 mm 90 m roll"* product opens into a loose *"Wire 2.5 mm (per m)"* product. Same for nail boxes → loose nails by kg.

---

## 3. What changes

### 3.1 Barcodes (main new feature)

- `products.barcode` (nullable, **unique**, up to 64 chars, indexed). One barcode per product, as the owner said. EAN-13 / UPC-A / EAN-8 / Code 128 are all just text.
- **POS:** the search box stays as it is. On Enter, the order is **exact barcode → exact short code → search**. A USB scanner (keyboard mode, sends Enter) and typing both work. Fast keystrokes (< 30 ms apart) are taken as a scan, so a scan never opens the results list.
- **After a scan** the item is added with qty 1 and the cursor jumps to the **quantity box**: type `12`, Enter, and the cursor goes back to the scan box. Scanning the same item again adds +1. `12*` before a scan also works (as in Citizens).
- **Unknown barcode:** a beep, then "Barcode 4792… not found". Owner/Manager can **link it to a product** right there (search → pick → saved). Cashiers see a message only. So barcodes are learned while selling and nobody has to type 4,000 barcodes before go-live.
- Barcode on: product form, product import (new `barcode` column), product list search, GRN lines, stocktake count sheet, returns.
- Duplicate barcode = refused with the name of the product that already has it.
- Sounds: short beep when found, low buzz when not found (Web Audio, no files).

### 3.2 Phone camera: supplier invoice photos (owner's answer 1)

- Counters use **USB barcode readers** (keyboard mode). No phone barcode scanning is planned.
- New phone page **"Snap supplier invoice"**: pick the supplier (search), optional invoice number / date / amount, take one or more photos → saved in that **supplier's documents folder** (Citizens already has supplier documents and GRN invoice photos), and can be linked to a GRN later.
- Uses the phone's own camera app through a file input (`accept="image/*" capture="environment"`). This works on plain **HTTP** inside the shop network, so **no HTTPS certificate is needed** for it (only a live camera stream inside the browser needs HTTPS).
- Photos are shrunk on the phone before upload (about 1600 px, JPEG) so they upload fast over Wi-Fi.

### 3.3 Two sales flows, chosen per counter (owner's answers 4 and 5)

- 5 terminals: **MAIN** (main server machine, main cashier) + **C1–C4** counters (seeded in H1).
- Per counter, on Admin → Terminals (set from the main machine): **"This counter has a cash drawer and takes payment"**. The printer record already has a `has_cash_drawer` flag.
  - **Off** (Citizens flow): the counter prints the bill and the **main machine settles** it.
  - **On**: F9 opens the payment box at the counter (cash / card / transfer / cheque / credit); the counter has its **own drawer session** (opening float, count, Z report) and opens its own drawer.
- Either way the main machine can still settle any open bill (e.g. a customer who walks to the main cashier). Live Billing works in both.
- The setting can only change when that counter has no open bill and no open drawer.

### 3.4 Roles

`super_admin` (shown as **Owner**), `manager`, `cashier` (was Sales Staff). Done in H1. The person at the main machine settles with `pos.settle` (the Owner, or a Manager/Cashier through a handover, as in Citizens). A cashier on a drawer counter gets settle rights for **their own counter's bills only** (H3).

### 3.5 Units and quantities

- New units: feet (ft), inch (in), square feet (sq ft), box, dozen, length, gallon, coil, sheet, bundle, cube (cu), tube. All length/weight/area/volume units allow decimals.
- Each size is its own product (owner). Multi-unit (box of 100 screws sold by box or by piece) stays available per product.

### 3.6 Prices

- Price lists: **Retail** (default), **Wholesale**, **Contractor** + **customer special price** (per customer per product).
- **Bulk price change:** filter by category / brand / supplier → raise or lower by % or amount, round to Rs. 1 / 5 / 10, preview → apply. Every change saved in a price history (who, when, old → new).
- Discount limits (H1): Cashier 10 %, Manager 25 %; above that the Owner approves.

### 3.7 VAT (shop is VAT-registered)

- VAT 18 % active by default. Tax invoice layout: shop VAT number, invoice number, date, customer name + VAT number (when given), VAT amount, total incl. VAT.
- Shelf prices **include VAT**; VAT-exempt products have no tax. VAT is set per product (bulk-set by category).
- VAT summary report for the monthly return.

### 3.8 Serial numbers and warranty

- Product flag **"Track serial numbers"** + warranty months.
- GRN: scan or type each unit's serial. Sale: pick or scan the serial being sold (stock must have it). Invoice prints serial + warranty end date.
- **Warranty lookup** by serial, invoice or phone. **Warranty claim register**: received from customer → sent to supplier → returned / replaced / refunded.

### 3.9 Paint tinting

- A tinted paint = **base paint product** + colour code/name + **tinting charge** line. The colour code prints on the invoice and is **saved on the customer**, so a repeat order can be mixed again.

### 3.10 Delivery of heavy goods

- On an invoice: "Deliver" tick, address, delivery date, transport charge line.
- **Delivery note** (printed, signed by the customer) and a **pending deliveries** list. Own vehicle; the transport charge is typed per delivery (default amount in Settings). Goods paid now and collected or delivered later stay reserved.

### 3.11 Quotation maker (back office)

- Citizens only makes a quotation from the counter cart (F7). New back-office **Quotations → New**: customer / contractor, project or site name, lines (search or scan), price list, line and bill discounts, VAT, validity days, terms and notes, **A4 PDF with logo**.
- Revisions (Q-0012 rev 2), status (draft, sent, accepted, expired, billed), **convert to invoice** at the counter, copy a quote.

### 3.12 English only

- Receipt language **English** by default. Sinhala name fields hidden on forms (columns kept, empty). Thermal invoices can print as plain text (faster) instead of an image; the Chrome image path stays as a fallback.

### 3.13 10,000+ products, typed in from the books

- The list is in **written books**, so it has to be typed in. Two ways, both already in the Citizens code: the **Excel template** (Catalogue → Import; several people can type at once, one sheet per category) and the product form. Start with the fast sellers; the rest can be added when goods arrive (GRN quick-add) or are sold.
- Short codes are **5 digits** from the category range (Building Materials 10000–14999, Plumbing 20000–24999 …); barcodes are scanned in on the GRN or at the first sale.
- Import in chunks on the queue with a progress bar and an error file, re-runnable. Meilisearch indexes in the background. Product list pages are paginated server-side with filters (already). Checked with a seeded 15,000-product test catalogue before go-live (search < 50 ms, POS add < 150 ms).

---

## 4. Build phases

| Phase | Work |
|---|---|
| **H0** | Copy project, own identity (DB, prefixes, ports, cookies), tests green. *(done 2026-11-05)* |
| **H1** | Hardware catalogue base: categories + code ranges, new units, English-only, roles (cashier), hardware demo data, logo + shop details. *(done 2026-11-05 except the logo and shop details, waiting for the owner's upload)* |
| **H2** | **Barcodes**: column, product form/import/list, POS scan flow (scan → qty box), unknown-barcode linking, GRN/stocktake/returns scanning, sounds. |
| **H3** | Per-counter payment (drawer counters settle their own bills) + counter drawer sessions. |
| **H4** | Prices: contractor list, customer special prices, bulk price change + history, discount limits. VAT invoices + VAT report. |
| **H5** | Quotation maker. |
| **H6** | Serial numbers + warranty + claims. |
| **H7** | Delivery notes + pending deliveries. Paint tinting. |
| **H8** | Phone page to photograph supplier invoices into the supplier's folder, 15,000-product performance check. |
| **H9** | Go-live: real product import, opening stock, balances, hardware install, training, parallel run. |

---

## 5. Open questions

All 12 were answered on 2026-11-05 (see § 1.1). Still waiting for the **logo file and shop details** (address, phone, VAT number, invoice footer).
