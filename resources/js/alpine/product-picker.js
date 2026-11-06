import { api, ApiError } from '../pos/client';
import { scanMissing, scanOk } from '../scanner';

/**
 * Product search box for document line editors (purchase orders, GRNs, adjustments …).
 * Uses the POS search endpoint and dispatches `product-picked` with the chosen item, so
 * the surrounding form adds a line:
 *
 *   <div x-data="poForm(...)" @product-picked="addLine($event.detail)">
 *       <x-ui.product-picker />
 *   </div>
 *
 * Items: { id, variant_id, short_code, barcode, name, name_si, units: [{ id, symbol, factor, ... }], stock, base_unit, ... }
 *
 * With `supplier: true` (purchase orders) it lists only the chosen supplier's products while
 * "only this supplier" is ticked; the form announces the supplier with
 * window.dispatchEvent(new CustomEvent('picker-supplier', { detail: { id, name } })).
 *
 * Barcode scanner: a scan anywhere in the same form (the picker box, a line's quantity …)
 * picks the product with that barcode or short code. An unknown barcode can be linked to a
 * product right here by the Owner or a Manager (`canLink`), so it is known from then on.
 */
export default function productPicker({ url, supplier = false, supplierId = null, supplierName = '', canLink = false, linkUrl = null }) {
    return {
        url,
        supplier,
        supplierId,
        supplierName,
        canLink,
        linkUrl,
        onlySupplier: true,
        query: '',
        items: [],
        itemsFor: null,
        open: false,
        highlighted: 0,
        controller: null,
        pending: null,
        // { code, query, results, linking, error, replace }
        unknown: null,

        get filtersBySupplier() {
            return this.supplier && this.onlySupplier && Boolean(this.supplierId);
        },

        setSupplier({ id, name }) {
            this.supplierId = id || null;
            this.supplierName = name || '';
            this.items = [];
            this.open = false;
        },

        searchParams(query, limit) {
            const params = new URLSearchParams({ limit, q: query });
            if (this.filtersBySupplier) params.set('supplier_id', this.supplierId);
            return params;
        },

        search() {
            const query = this.query.trim();

            // With a supplier chosen, an empty box lists all of that supplier's products.
            if (query.length < 1 && !this.filtersBySupplier) {
                this.items = [];
                this.itemsFor = null;
                this.open = false;
                return Promise.resolve(null);
            }

            if (this.pending?.query === query) return this.pending.promise;

            this.controller?.abort();
            const controller = new AbortController();
            this.controller = controller;

            const promise = (async () => {
                try {
                    const response = await fetch(`${this.url}?${this.searchParams(query, query.length < 1 ? 50 : 10)}`, {
                        headers: { Accept: 'application/json' },
                        signal: controller.signal,
                    });

                    if (!response.ok) return null;

                    const data = await response.json();
                    if (this.query.trim() !== query) return null;
                    this.items = data.items;
                    this.itemsFor = query;
                    this.highlighted = 0;
                    this.open = true;
                    return data;
                } catch (error) {
                    if (error.name !== 'AbortError') this.items = [];
                    return null;
                } finally {
                    if (this.pending?.promise === promise) this.pending = null;
                }
            })();

            this.pending = { query, promise };
            return promise;
        },

        move(step) {
            if (this.items.length === 0) {
                return;
            }
            this.highlighted = (this.highlighted + step + this.items.length) % this.items.length;
        },

        /** Enter: wait for the results of what is typed now, then take the highlighted one. */
        async enter() {
            // A barcode typed by hand: exact lookup only, so a near match is never picked.
            if (/^\d{8,14}$/.test(this.query.trim())) {
                await this.scan(this.query.trim());
                return;
            }
            if (this.query.trim() !== '' && this.itemsFor !== this.query.trim()) {
                await this.search();
            }
            if (this.items[this.highlighted]) {
                this.pick();
            }
        },

        pick(item = this.items[this.highlighted]) {
            if (!item) {
                return;
            }
            this.$dispatch('product-picked', item);
            this.query = '';
            this.items = [];
            this.itemsFor = null;
            this.open = false;
            // Back to the search box, unless the form moved the cursor on (the GRN goes to the line's quantity).
            this.$nextTick(() => {
                const active = document.activeElement;
                if (!active || active === document.body || active === this.$refs.input) this.$refs.input?.focus();
            });
        },

        // ------------------------------------------------------------------ barcode scanner

        onScan(detail) {
            if (this.unknown) return;

            const target = detail.target;
            const own = target === this.$refs.input;
            const form = this.$root.closest('form') ?? this.$root.parentElement;
            const inOwnForm = target instanceof Element && form?.contains(target);
            const nowhere = !target || target === document.body;

            if (!own && !inOwnForm && !nowhere) return;

            detail.handled = true;
            detail.keepText = own;
            this.scan(own ? this.query.trim() : detail.code);
        },

        async scan(code) {
            this.controller?.abort();
            this.pending = null;
            this.query = code;

            try {
                // A scanned item is found even when the order's supplier is not linked to it yet.
                const response = await fetch(`${this.url}?${new URLSearchParams({ limit: 10, q: code, exact: 1 })}`, { headers: { Accept: 'application/json' } });
                const data = response.ok ? await response.json() : { items: [] };
                const items = data.items ?? [];

                if (items.length === 1 && ['barcode', 'code'].includes(data.engine)) {
                    scanOk();
                    this.pick(items[0]);
                } else if (items.length === 0) {
                    this.openUnknown(code);
                } else {
                    this.items = items;
                    this.itemsFor = code;
                    this.highlighted = 0;
                    this.open = true;
                    this.$refs.input?.focus();
                }
            } catch {
                this.items = [];
            }
        },

        openUnknown(code) {
            scanMissing();
            this.query = '';
            this.items = [];
            this.open = false;
            this.unknown = { code, query: '', results: [], linking: false, error: null, replace: null };
            if (this.canLink) this.$nextTick(() => document.getElementById('picker-unknown-search')?.focus());
        },

        closeUnknown() {
            this.unknown = null;
            this.$nextTick(() => this.$refs.input?.focus());
        },

        async searchUnknown() {
            const query = this.unknown?.query.trim() ?? '';
            if (!this.unknown) return;
            if (query === '') {
                this.unknown.results = [];
                return;
            }
            try {
                const data = await api(`${this.url}?${new URLSearchParams({ limit: 8, q: query })}`);
                if (this.unknown && this.unknown.query.trim() === query) this.unknown.results = data.items ?? [];
            } catch (error) {
                if (this.unknown) this.unknown.error = error.message;
            }
        },

        async linkUnknown(item, replace = false) {
            const unknown = this.unknown;
            if (!unknown || unknown.linking) return;
            unknown.linking = true;
            unknown.error = null;

            try {
                await api(this.linkUrl, { method: 'POST', body: { barcode: unknown.code, product_id: item.id, replace } });
                this.unknown = null;
                scanOk();
                this.pick({ ...item, barcode: unknown.code });
            } catch (error) {
                unknown.linking = false;
                if (error instanceof ApiError && error.errors?.replace) {
                    unknown.replace = { item, message: error.errors.replace[0] };
                } else {
                    unknown.error = error instanceof ApiError ? error.first : error.message;
                }
            }
        },

        emptyText() {
            if (!this.filtersBySupplier) {
                return 'No products found.';
            }
            return this.query.trim() === ''
                ? `No products linked to ${this.supplierName || 'this supplier'} yet. Untick "Only this supplier's products" to search all.`
                : `No ${this.supplierName || 'supplier'} product matches. Untick "Only this supplier's products" to search all.`;
        },

        stockText(item) {
            if (item.stock === null || item.stock === undefined) {
                return '';
            }
            return `${Number(item.stock).toLocaleString('en-LK', { maximumFractionDigits: 3 })} ${item.base_unit ?? ''} in stock`;
        },
    };
}
