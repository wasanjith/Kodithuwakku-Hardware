<?php

namespace Database\Seeders;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\PriceList;
use App\Domain\Catalog\Models\Tax;
use App\Domain\Catalog\Models\Unit;
use Illuminate\Database\Seeder;

/**
 * Catalogue master data the shop needs before the first product: units,
 * price lists, VAT 18 % (the shop is VAT-registered) and the top-level hardware
 * categories with their short code ranges. Codes are 5 digits because the shop
 * has 10,000+ products; a category without a range gets codes from 100000.
 */
class CatalogSeeder extends Seeder
{
    public function run(): void
    {
        $units = [
            // name, symbol, allows decimal
            ['piece', 'pc', false],
            ['pair', 'pair', false],
            ['set', 'set', false],
            ['box', 'box', false],
            ['packet', 'pkt', false],
            ['dozen', 'doz', false],
            ['bag', 'bag', false],
            ['bottle', 'btl', false],
            ['tube', 'tube', false],
            ['tin', 'tin', false],
            ['roll', 'roll', false],
            ['coil', 'coil', false],
            ['sheet', 'sheet', false],
            ['length', 'len', false],
            ['bundle', 'bdl', false],
            ['kg', 'kg', true],
            ['g', 'g', false],
            ['metre', 'm', true],
            ['feet', 'ft', true],
            ['inch', 'in', true],
            ['sq ft', 'sq ft', true],
            ['cube', 'cu', true],
            ['litre', 'L', true],
            ['ml', 'ml', false],
            ['gallon', 'gal', true],
        ];

        foreach ($units as [$name, $symbol, $allowsDecimal]) {
            Unit::updateOrCreate(['name' => $name], ['symbol' => $symbol, 'allows_decimal' => $allowsDecimal]);
        }

        foreach (['Retail' => true, 'Wholesale' => false, 'Contractor' => false] as $name => $isDefault) {
            PriceList::updateOrCreate(['name' => $name], ['is_default' => $isDefault]);
        }

        Tax::firstOrCreate(['name' => 'VAT'], ['rate' => 18, 'is_active' => true]);

        $categories = [
            // name, code range, children
            ['Building Materials', [10000, 14999], ['Cement', 'Sand & Metal', 'Bricks & Blocks', 'Steel & Iron']],
            ['Roofing', [15000, 17999], ['Roofing Sheets', 'Gutters & Accessories']],
            ['Timber & Boards', [18000, 19999], []],
            ['Plumbing', [20000, 24999], ['PVC Pipes', 'Pipe Fittings', 'Taps & Valves', 'Water Tanks']],
            ['Sanitaryware & Tiles', [25000, 29999], ['Sanitaryware', 'Tiles', 'Tile Adhesive & Grout']],
            ['Electrical', [30000, 34999], ['Wires & Cables', 'Switches & Sockets', 'Lighting', 'Breakers & DB Boxes', 'Conduits']],
            ['Paints', [35000, 39999], ['Emulsion', 'Enamel', 'Primers & Undercoats', 'Wood Finishes', 'Brushes & Rollers', 'Thinners & Solvents']],
            ['Hand Tools', [40000, 44999], []],
            ['Power Tools', [45000, 47999], ['Drills', 'Grinders', 'Bits & Accessories']],
            ['Pumps & Motors', [48000, 49999], []],
            ['Fasteners', [50000, 54999], ['Nails', 'Screws', 'Bolts & Nuts', 'Wall Plugs & Anchors']],
            ['Door & Window Hardware', [55000, 59999], ['Locks', 'Hinges', 'Handles', 'Tower Bolts']],
            ['Adhesives & Chemicals', [60000, 64999], ['Glue', 'Sealants & Silicone', 'Waterproofing']],
            ['Garden & Agriculture', [65000, 69999], ['Hoses', 'Sprayers', 'Garden Tools']],
            ['Safety & Workwear', [70000, 72999], []],
            ['Other', [90000, 99999], []],
        ];

        foreach ($categories as $order => [$name, [$from, $to], $children]) {
            $parent = Category::updateOrCreate(
                ['name' => $name, 'parent_id' => null],
                ['code_from' => $from, 'code_to' => $to, 'sort_order' => $order],
            );

            foreach ($children as $childOrder => $child) {
                Category::updateOrCreate(['name' => $child, 'parent_id' => $parent->id], ['sort_order' => $childOrder]);
            }
        }
    }
}
