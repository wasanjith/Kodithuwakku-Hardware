import { scanMissing, scanOk } from '../scanner';

/**
 * Barcode scanning on a list that already holds the products (stocktake count sheet, the
 * lines of an invoice being returned): a scan finds the row with that barcode or short code
 * and puts the cursor in its quantity box.
 *
 *   <div x-data="scanRows({ notFound: 'Barcode :code is not on this count sheet.' })" @barcode-scan.window="onScan($event.detail)">
 *       <li data-scan-row data-barcode="4792…" data-code="20001"> … <input data-scan-qty> </li>
 *       <p x-show="scanMessage" x-text="scanMessage"></p>
 *   </div>
 *
 * Before focusing it dispatches `scan-row` (detail: { row }) so the page can clear a filter
 * that hides the row.
 */
export default function scanRows({ notFound = 'Barcode :code is not on this list.' } = {}) {
    return {
        scanMessage: '',

        onScan(detail) {
            const target = detail.target;
            const inside = target instanceof Element && this.$root.contains(target);

            if (!inside && target && target !== document.body) return;

            detail.handled = true;
            const code = detail.code.trim();
            const wanted = code.toLowerCase();
            const row = [...this.$root.querySelectorAll('[data-scan-row]')].find(
                (element) => (element.dataset.barcode ?? '').toLowerCase() === wanted || (element.dataset.code ?? '').toLowerCase() === wanted,
            );

            if (!row) {
                scanMissing();
                this.scanMessage = notFound.replace(':code', code);
                return;
            }

            scanOk();
            this.scanMessage = '';
            this.$dispatch('scan-row', { row });
            this.$nextTick(() => {
                row.scrollIntoView({ block: 'center' });
                const input = row.querySelector('[data-scan-qty]');
                input?.focus();
                input?.select();
            });
        },
    };
}
