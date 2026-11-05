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

### 3.2 Phone camera scanning

- Phone pages for **stocktake count** and **goods receipt** with a camera scanner (browser `BarcodeDetector`, with the ZXing JS library as a fallback for iPhones/old Android).
- **Browsers only allow the camera on HTTPS.** The shop server needs a local HTTPS certificate (Tailscale HTTPS certificate, or a local CA such as mkcert installed on the phones). This goes into the deployment guide.
- Counters: see open question 1 (I recommend a USB scanner at each counter, because a phone is too slow for billing).

### 3.3 Two sales flows (owner's toggle)

- Settings → POS → **"Who takes the payment"**: *Main cashier settles* (Citizens) or *Counter takes payment*.
- In "Counter takes payment", F9 opens the payment box at the counter (cash / card / transfer / cheque / credit), and each counter has its **own drawer session** (opening float, count, Z report). Live Billing works in both modes.
- The setting can only change when no counter has an open bill or drawer.

### 3.4 Roles

`super_admin` (Owner), `manager`, `cashier` (rename of Sales Staff, see open question 5). The permission matrix test is updated with them.

### 3.5 Units and quantities

- New units: feet (ft), inch (in), square feet (sq ft), box, dozen, length, gallon, coil, sheet, bundle, cube (cu), tube. All length/weight/area/volume units allow decimals.
- Each size is its own product (owner). Multi-unit (box of 100 screws sold by box or by piece) stays available per product.

### 3.6 Prices

- Price lists: **Retail** (default), **Wholesale**, **Contractor** + **customer special price** (per customer per product).
- **Bulk price change:** filter by category / brand / supplier → raise or lower by % or amount, round to Rs. 1 / 5 / 10, preview → apply. Every change saved in a price history (who, when, old → new).
- Discount limits: see open question 6 (25 %).

### 3.7 VAT (shop is VAT-registered)

- VAT 18 % active by default. Tax invoice layout: shop VAT number, invoice number, date, customer name + VAT number (when given), VAT amount, total incl. VAT.
- VAT summary report for the monthly return. See open question 7.

### 3.8 Serial numbers and warranty

- Product flag **"Track serial numbers"** + warranty months.
- GRN: scan or type each unit's serial. Sale: pick or scan the serial being sold (stock must have it). Invoice prints serial + warranty end date.
- **Warranty lookup** by serial, invoice or phone. **Warranty claim register**: received from customer → sent to supplier → returned / replaced / refunded.

### 3.9 Paint tinting

- A tinted paint = **base paint product** + colour code/name (+ optional tinting charge line). The colour code prints on the invoice and is saved on the customer, so a repeat order can be mixed again. See open question 9.

### 3.10 Delivery of heavy goods

- On an invoice: "Deliver" tick, address, delivery date, transport charge line.
- **Delivery note** (printed, signed by the customer) and a **pending deliveries** list. Goods paid now and collected later stay reserved. See open question 10.

### 3.11 Quotation maker (back office)

- Citizens only makes a quotation from the counter cart (F7). New back-office **Quotations → New**: customer / contractor, project or site name, lines (search or scan), price list, line and bill discounts, VAT, validity days, terms and notes, **A4 PDF with logo**.
- Revisions (Q-0012 rev 2), status (draft, sent, accepted, expired, billed), **convert to invoice** at the counter, copy a quote.

### 3.12 English only

- Receipt language **English** by default. Sinhala name fields hidden on forms (columns kept, empty). Thermal invoices can print as plain text (faster) instead of an image; the Chrome image path stays as a fallback.

### 3.13 10,000+ products

- Import in chunks on the queue with a progress bar and an error file, re-runnable. Meilisearch indexes in the background. Product list pages are paginated server-side with filters (already). Checked with a seeded 15,000-product test catalogue before go-live (search < 50 ms, POS add < 150 ms).

---

## 4. Build phases

| Phase | Work |
|---|---|
| **H0** | Copy project, own identity (DB, prefixes, ports, cookies), tests green. *(done 2026-11-05)* |
| **H1** | Hardware catalogue base: categories + code ranges, new units, English-only, roles (cashier), hardware demo data, logo + shop details. |
| **H2** | **Barcodes**: column, product form/import/list, POS scan flow (scan → qty box), unknown-barcode linking, GRN/stocktake/returns scanning, sounds. |
| **H3** | Two sales flows toggle + counter drawers. |
| **H4** | Prices: contractor list, customer special prices, bulk price change + history, discount limits. VAT invoices + VAT report. |
| **H5** | Quotation maker. |
| **H6** | Serial numbers + warranty + claims. |
| **H7** | Delivery notes + pending deliveries. Paint tinting. |
| **H8** | Phone camera scanning (HTTPS on the shop server), 15,000-product performance check. |
| **H9** | Go-live: real product import, opening stock, balances, hardware install, training, parallel run. |

---

## 5. Open questions

1. **Counter scanning:** the answer to "which scanner" was *phone cam*. Should the **counters** also use a phone camera, or a **USB scanner at each counter + phone camera for stocktake / goods receipts**? I recommend the USB scanner at the counters (about Rs. 8,000–15,000 each; one press, under half a second). A phone needs to be unlocked and pointed every time.
2. **Product list:** where are the 10,000 products now? (Old POS software and its name / Excel / paper.) Does it have barcodes, cost and selling prices, stock?
3. **Opening balances:** are there supplier and credit-customer balances to bring in? Roughly how many?
4. **Sales-flow toggle:** one setting for the whole shop, or chosen **per counter** (e.g. Counter 1 takes payments, Counters 2–3 send to the cashier)? How many counters?
5. **Cashier role:** is "Cashier" the person at each counter who bills? In the "main cashier settles" flow, who settles: the Owner, the Manager, or a main cashier?
6. **Discount 25 %:** is that the cashier's limit? What is the Manager's limit, and does anything above need the Owner's OK?
7. **VAT:** do shelf prices **include** VAT? Shop VAT number? Any VAT-exempt items? Do contractors ask for tax invoices with their VAT number?
8. **Price lists:** are Retail, Wholesale and Contractor the right names? Any others?
9. **Paint tinting:** is there a tinting charge? Do you sell by base + colour code (from a colour card), and should the colour mix be saved per customer?
10. **Delivery:** own vehicle? Fixed charge, per km, or by weight? Do customers pay now and collect later?
11. **Serial numbers:** which items (power tools, motors, pumps, electricals)? Does the shop or the supplier handle the warranty repair?
12. **Shop details:** logo file, shop address, phone, VAT number, invoice footer text.
