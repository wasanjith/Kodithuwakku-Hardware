{{--
    Product search for line editors. Dispatches `product-picked` with the search item.
    <div x-data="…" @product-picked="addLine($event.detail)"><x-ui.product-picker /></div>
    :supplier="true" (purchase orders): only the chosen supplier's products, see product-picker.js.
    A barcode scanned anywhere in the same form picks the product; an unknown barcode can be
    linked to a product by the Owner or a Manager.
--}}
@props(['label' => 'Add product', 'placeholder' => 'Scan, or search by code, name, alias or brand (F2)', 'supplier' => false, 'supplierId' => null, 'supplierName' => ''])

<div
    x-data="productPicker({
        url: @js(route('api.pos.search')),
        supplier: @js((bool) $supplier),
        supplierId: @js($supplierId),
        supplierName: @js($supplierName),
        canLink: @js((bool) auth()->user()?->can('linkBarcode', \App\Domain\Catalog\Models\Product::class)),
        linkUrl: @js(route('api.barcodes.link')),
    })"
    @click.outside="open = false"
    @picker-supplier.window="setSupplier($event.detail)"
    @barcode-scan.window="onScan($event.detail)"
    {{ $attributes->merge(['class' => 'relative']) }}
>
    <label for="product-picker" class="block text-sm font-medium text-gray-700">{{ $label }}</label>
    <input
        type="search"
        id="product-picker"
        x-ref="input"
        x-model="query"
        x-hotkey.f2="$el.focus(); $el.select()"
        @input.debounce.150ms="search()"
        @focus="items.length ? (open = true) : (filtersBySupplier && search())"
        @keydown.arrow-down.prevent="move(1)"
        @keydown.arrow-up.prevent="move(-1)"
        @keydown.enter.prevent="enter()"
        @keydown.escape="open = false"
        placeholder="{{ $placeholder }}"
        autocomplete="off"
        class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500"
    >
    <ul x-show="open" x-cloak class="absolute z-30 mt-1 max-h-80 w-full overflow-auto rounded-md bg-white py-1 text-sm shadow-lg ring-1 ring-black/5">
        <template x-for="(item, index) in items" :key="item.key">
            <li
                @click="pick(item)"
                @mouseenter="highlighted = index"
                :class="index === highlighted ? 'bg-brand-50' : ''"
                class="flex cursor-pointer items-start justify-between gap-3 px-3 py-2"
            >
                <span class="min-w-0">
                    <span class="font-mono text-xs font-semibold text-brand-700" x-text="item.short_code"></span>
                    <span class="font-medium text-gray-900" x-text="item.name"></span>
                    <span x-show="item.brand" class="rounded bg-gray-100 px-1.5 py-0.5 text-xs text-gray-700" x-text="item.brand"></span>
                    <span class="block truncate font-sinhala text-xs text-gray-500" x-text="item.name_si"></span>
                </span>
                <span class="whitespace-nowrap text-xs tabular text-gray-600" x-text="stockText(item)"></span>
            </li>
        </template>
        <li x-show="items.length === 0" class="px-3 py-2 text-gray-500" x-text="emptyText()"></li>
    </ul>

    {{-- Unknown barcode --}}
    <template x-if="unknown">
        <div class="mt-2 rounded-md border border-red-200 bg-red-50 p-3 text-sm" role="alert">
            <div class="flex items-start justify-between gap-3">
                <p class="text-red-800">Barcode <span class="font-mono font-semibold" x-text="unknown.code"></span> is not on any product.</p>
                <button type="button" @click="closeUnknown()" class="text-lg leading-none text-gray-500" aria-label="Close">&times;</button>
            </div>
            <template x-if="canLink">
                <div class="mt-2">
                    <label for="picker-unknown-search" class="text-gray-700">Which product is it? The barcode is saved on it.</label>
                    <input id="picker-unknown-search" x-model="unknown.query" @input.debounce.200ms="searchUnknown()" @keydown.enter.prevent="unknown.results.length === 1 && linkUnknown(unknown.results[0])" @keydown.escape.prevent="closeUnknown()" type="search" autocomplete="off" placeholder="Name or short code" class="mt-1 block w-full rounded-md border-gray-300 text-sm">
                    <p x-show="unknown.error" x-text="unknown.error" class="mt-1 text-red-700"></p>
                    <template x-if="unknown.replace">
                        <div class="mt-2 flex flex-wrap items-center gap-2 text-amber-900">
                            <p class="flex-1" x-text="unknown.replace.message"></p>
                            <x-ui.button size="sm" @click="linkUnknown(unknown.replace.item, true)">Replace</x-ui.button>
                            <x-ui.button size="sm" variant="secondary" @click="unknown.replace = null">Cancel</x-ui.button>
                        </div>
                    </template>
                    <ul class="mt-2 divide-y divide-red-100 rounded-md bg-white">
                        <template x-for="item in unknown.results" :key="item.key">
                            <li class="flex items-center justify-between gap-3 px-3 py-2">
                                <span class="min-w-0">
                                    <span class="font-mono text-xs font-semibold text-brand-700" x-text="item.short_code"></span>
                                    <span class="font-medium" x-text="item.name"></span>
                                    <span x-show="item.barcode" class="block text-xs text-gray-500" x-text="'Has barcode ' + item.barcode"></span>
                                </span>
                                <x-ui.button size="sm" x-bind:disabled="unknown.linking" @click="linkUnknown(item)">This one</x-ui.button>
                            </li>
                        </template>
                    </ul>
                </div>
            </template>
            <p x-show="! canLink" class="mt-1 text-gray-700">Search for the item by name, and ask the manager to link the barcode to it.</p>
        </div>
    </template>

    @if ($supplier)
        <label x-show="supplierId" x-cloak class="mt-2 flex items-center gap-2 text-sm text-gray-700">
            <input type="checkbox" x-model="onlySupplier" @change="items = []; open = false; $refs.input.focus()" class="rounded border-gray-300 text-brand-600 focus:ring-brand-500">
            <span>Only <span class="font-medium" x-text="supplierName || 'this supplier'"></span>'s products</span>
        </label>
        <p x-show="! supplierId" class="mt-2 text-xs text-gray-500">Choose the supplier to see only the products they supply.</p>
    @endif
</div>
