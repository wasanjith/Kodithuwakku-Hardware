<?php

namespace Database\Seeders;

use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\PriceList;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductPrice;
use App\Domain\Catalog\Models\ProductUnit;
use App\Domain\Catalog\Models\SearchSynonym;
use App\Domain\Catalog\Models\Unit;
use Illuminate\Database\Seeder;

/**
 * Demo hardware products for local development (never run in production; the real list is
 * typed in from the shop's books before go-live).
 *   - each size is its own product ("PVC Pipe 1/2" 20ft", "PVC Pipe 3/4" 20ft");
 *   - codes come from the category range (Plumbing 20000-24999 …);
 *   - a sealed roll / box opens into a loose product sold by the metre / kg (Open packs);
 *   - products sold in metres, feet, kg or cubes are "sold loose" (decimal quantities);
 *   - prices include VAT; a product without prices shows in the no-price filter.
 */
class DevelopmentCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $units = Unit::all()->keyBy('name');
        $lists = PriceList::pluck('id', 'name');
        $categories = Category::pluck('id', 'name');
        $brand = fn (string $name) => Brand::firstOrCreate(['name' => $name])->id;

        // code, name, aliases, category, brand, unit, cost, [retail, wholesale, contractor], reorder level, opens into [code, qty]
        $products = [
            ['10001', 'Cement 50kg bag', 'cement, siment', 'Cement', 'Tokyo Super', 'bag', 2050, [2350, 2300, 2280], 50, null],
            ['10002', 'Cement 50kg bag (Sanstha)', 'cement, siment', 'Cement', 'Sanstha', 'bag', 1990, [2290, 2250, 2230], 50, null],
            ['10010', 'River Sand (cube)', 'sand, weli', 'Sand & Metal', 'Local', 'cube', 18000, [23000, null, 22000], 0, null],
            ['10011', 'Metal 3/4" (cube)', 'metal, gal, chips', 'Sand & Metal', 'Local', 'cube', 9000, [12500, null, 12000], 0, null],
            ['10020', 'Cement Block 4"', 'block, bloku', 'Bricks & Blocks', 'Local', 'piece', 62, [80, 75, 72], 500, null],
            ['10030', 'Steel Rod 10mm (length)', 'tor steel, kambi, rod', 'Steel & Iron', 'Melwa', 'length', 1650, [1900, 1850, 1820], 50, null],
            ['10031', 'Binding Wire', 'binding wire, bandana kambi', 'Steel & Iron', 'Melwa', 'kg', 420, [520, 500, 490], 25, null],
            ['15001', 'Roofing Sheet Asbestos 8ft', 'roof sheet, asbestos', 'Roofing Sheets', 'Lanka Roofing', 'sheet', 2900, [3400, 3300, 3250], 20, null],
            ['15002', 'Roofing Sheet Colour 10ft 0.47mm', 'zinc sheet, colour sheet', 'Roofing Sheets', 'Lanka Roofing', 'sheet', 5200, [6100, 5950, 5900], 10, null],
            ['20001', 'PVC Pipe 1/2" 20ft (Type 1000)', 'pipe, pvc, half inch', 'PVC Pipes', 'S-lon', 'length', 610, [750, 720, 700], 30, null],
            ['20002', 'PVC Pipe 3/4" 20ft (Type 1000)', 'pipe, pvc, three quarter', 'PVC Pipes', 'S-lon', 'length', 820, [1000, 960, 940], 30, null],
            ['20003', 'PVC Pipe 4" 20ft (Type 600)', 'pipe, pvc, four inch', 'PVC Pipes', 'S-lon', 'length', 5400, [6500, 6300, 6200], 10, null],
            ['20010', 'PVC Elbow 1/2"', 'elbow, bend', 'Pipe Fittings', 'S-lon', 'piece', 32, [45, 42, 40], 100, null],
            ['20011', 'PVC Tee 1/2"', 'tee, t joint', 'Pipe Fittings', 'S-lon', 'piece', 40, [55, 52, 50], 100, null],
            ['20012', 'PVC Solvent Cement 100ml', 'gum, pipe glue, solvent', 'Pipe Fittings', 'S-lon', 'tin', 260, [340, 320, 310], 24, null],
            ['20020', 'Bib Tap 1/2" Brass', 'tap, kothalaya', 'Taps & Valves', 'Rocell', 'piece', 1350, [1750, 1700, 1650], 10, null],
            ['20030', 'Water Tank 1000L', 'tank, water tank', 'Water Tanks', 'Arpico', 'piece', 26500, [31500, 31000, 30500], 2, null],
            ['25001', 'Floor Tile 60x60 Glossy (box)', 'tile, ulu', 'Tiles', 'Lanka Tiles', 'box', 3700, [4500, 4400, 4300], 20, null],
            ['25010', 'Squatting Pan', 'pan, toilet', 'Sanitaryware', 'Rocell', 'piece', 5200, [6500, 6300, 6200], 3, null],
            ['30001', 'Wire 2.5mm 90m roll', 'wire, cable, electric wire', 'Wires & Cables', 'Kelani', 'roll', 14800, [17500, 17000, 16800], 5, ['30002', 90]],
            ['30002', 'Wire 2.5mm (per m)', 'wire, cable, electric wire', 'Wires & Cables', 'Kelani', 'metre', 165, [210, null, null], 0, null],
            ['30003', 'Wire 1.5mm 90m roll', 'wire, cable', 'Wires & Cables', 'Kelani', 'roll', 9800, [11800, 11500, 11300], 5, ['30004', 90]],
            ['30004', 'Wire 1.5mm (per m)', 'wire, cable', 'Wires & Cables', 'Kelani', 'metre', 110, [140, null, null], 0, null],
            ['30010', 'Switch 1 Gang', 'switch', 'Switches & Sockets', 'Orange', 'piece', 210, [290, 275, 265], 50, null],
            ['30011', 'Socket 13A', 'plug base, socket', 'Switches & Sockets', 'Orange', 'piece', 480, [650, 620, 600], 30, null],
            ['30020', 'LED Bulb 9W', 'bulb, led', 'Lighting', 'Orange', 'piece', 330, [450, 420, 410], 50, null],
            ['30030', 'MCB 32A Single Pole', 'breaker, mcb, trip switch', 'Breakers & DB Boxes', 'ABB', 'piece', 1100, [1450, 1400, 1380], 10, null],
            ['35001', 'Emulsion Paint White 4L', 'paint, emulsion, white', 'Emulsion', 'Dulux', 'tin', 6900, [8200, 8000, 7900], 6, null],
            ['35002', 'Emulsion Paint Base 4L (tint)', 'paint, base, tinting', 'Emulsion', 'Dulux', 'tin', 7200, [8600, 8400, 8300], 6, null],
            ['35010', 'Enamel Paint Black 1L', 'enamel, paint, black', 'Enamel', 'Multilac', 'tin', 1900, [2350, 2300, 2250], 6, null],
            ['35020', 'Paint Brush 2"', 'brush', 'Brushes & Rollers', 'Harris', 'piece', 210, [290, 270, 260], 24, null],
            ['35021', 'Paint Roller 9"', 'roller', 'Brushes & Rollers', 'Harris', 'piece', 650, [880, 850, 820], 10, null],
            ['35030', 'Thinner 1L', 'thinner, turpentine', 'Thinners & Solvents', 'Multilac', 'bottle', 780, [980, 950, 930], 12, null],
            ['40001', 'Claw Hammer 16oz', 'hammer, miti', 'Hand Tools', 'Stanley', 'piece', 1450, [1950, 1900, 1850], 5, null],
            ['40002', 'Measuring Tape 5m', 'tape, measure', 'Hand Tools', 'Stanley', 'piece', 950, [1300, 1250, 1200], 10, null],
            ['40003', 'Mammoty', 'udalla, hoe', 'Hand Tools', 'Lion', 'piece', 1800, [2400, 2300, 2250], 5, null],
            ['45001', 'Impact Drill 13mm 650W', 'drill, power drill', 'Drills', 'Bosch', 'piece', 15500, [19500, 19000, 18800], 2, null],
            ['45002', 'Angle Grinder 4" 850W', 'grinder', 'Grinders', 'Makita', 'piece', 14200, [17800, 17500, 17200], 2, null],
            ['45010', 'Masonry Drill Bit 6mm', 'bit, drill bit', 'Bits & Accessories', 'Bosch', 'piece', 180, [260, 240, 230], 20, null],
            ['48001', 'Water Pump 1HP', 'pump, motor', 'Pumps & Motors', 'Pedrollo', 'piece', 26000, [32500, 32000, 31500], 1, null],
            ['50001', 'Wire Nails 2" (box 5kg)', 'nails, ana', 'Nails', 'Local', 'box', 1900, [2400, 2350, 2300], 10, ['50002', 5]],
            ['50002', 'Wire Nails 2" (loose)', 'nails, ana', 'Nails', 'Local', 'kg', 380, [520, null, null], 0, null],
            ['50010', 'Wood Screw 1" (box 100)', 'screw, iskuruppu', 'Screws', 'Local', 'box', 280, [380, 360, 350], 20, null],
            ['50020', 'Wall Plug 6mm (pack 100)', 'rawl plug, wall plug', 'Wall Plugs & Anchors', 'Local', 'packet', 160, [240, 220, 210], 20, null],
            ['55001', 'Door Lock Mortice 3 Lever', 'lock, door lock', 'Locks', 'Yale', 'piece', 2600, [3400, 3300, 3200], 5, null],
            ['55002', 'Butt Hinge 3" (pair)', 'hinge', 'Hinges', 'Local', 'pair', 190, [280, 260, 250], 30, null],
            ['60001', 'Silicone Sealant Clear', 'silicone, sealant', 'Sealants & Silicone', 'Dow', 'tube', 780, [1050, 1000, 980], 12, null],
            ['60002', 'Waterproofing 1L', 'water proof, waterproof', 'Waterproofing', 'Sika', 'bottle', 1900, [2450, 2400, 2350], 6, null],
            ['65001', 'Garden Hose 1/2" (per ft)', 'hose, hose pipe', 'Hoses', 'Local', 'feet', 38, [55, null, null], 0, null],
            ['70001', 'Safety Gloves', 'gloves', 'Safety & Workwear', 'Local', 'pair', 160, [250, 230, 220], 20, null],
            // Not priced yet (shows in the no-price filter).
            ['40010', 'Spirit Level 24"', 'level, spirit level', 'Hand Tools', 'Stanley', 'piece', null, [null, null, null], 2, null],
        ];

        $saved = [];

        foreach ($products as [$code, $name, $aliases, $category, $brandName, $unitName, $cost, $prices, $reorder, $opensInto]) {
            $unit = $units[$unitName];

            $product = Product::updateOrCreate(['short_code' => $code], [
                'name' => $name,
                'aliases' => $aliases,
                'category_id' => $categories[$category],
                'brand_id' => $brand($brandName),
                'base_unit_id' => $unit->id,
                'sold_loose' => $unit->allows_decimal,
                'has_variants' => false,
                'reorder_level' => $reorder,
                'reorder_qty' => $reorder * 2,
                'reference_cost' => $cost,
                'min_selling_margin_pct' => 5,
                'is_active' => true,
            ]);

            ProductUnit::updateOrCreate(['product_id' => $product->id, 'unit_id' => $unit->id], [
                'factor' => 1,
                'is_default_sale' => true,
                'is_default_purchase' => true,
            ]);

            foreach (array_combine(['Retail', 'Wholesale', 'Contractor'], $prices) as $list => $price) {
                if ($price !== null) {
                    ProductPrice::firstOrCreate(
                        ['product_id' => $product->id, 'unit_id' => $unit->id, 'price_list_id' => $lists[$list], 'min_qty' => 0],
                        ['price' => $price, 'effective_from' => now()->subDay()],
                    );
                }
            }

            $saved[$code] = [$product, $opensInto];
        }

        foreach ($saved as [$product, $opensInto]) {
            if ($opensInto !== null) {
                $product->update(['opens_into_product_id' => $saved[$opensInto[0]][0]->id, 'opens_into_qty' => $opensInto[1]]);
            }
        }

        $synonyms = [
            'pipe' => ['pvc'],
            'wire' => ['cable', 'kambi'],
            'nails' => ['ana'],
            'tap' => ['kothalaya'],
            'cement' => ['siment'],
        ];

        foreach ($synonyms as $term => $words) {
            SearchSynonym::updateOrCreate(['term' => $term], ['synonyms' => $words]);
        }

        // Index again now that units exist.
        $all = Product::query()->with(['category.parent.parent', 'brand', 'variants'])->get();
        $all->first()?->searchableUsing()->update($all);
    }
}
