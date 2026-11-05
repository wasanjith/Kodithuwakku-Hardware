<?php

namespace App\Domain\Catalog\Import;

use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;

/**
 * Downloadable .xlsx template with the headings and example rows: PVC pipes (one product
 * per size), wire sold by the metre, the sealed 90 m roll that is opened into it, and a tool.
 */
class ProductImportTemplate implements FromArray, ShouldAutoSize, WithHeadings
{
    use Exportable;

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return ProductImportColumns::headings();
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    public function array(): array
    {
        $rows = [
            ['name' => 'PVC Pipe Type 1000', 'aliases' => 'pipe, pvc', 'category' => 'Plumbing > PVC Pipes', 'brand' => 'S-lon', 'base_unit' => 'length', 'pack_size' => '1/2" 20ft', 'cost' => 610, 'wholesale_price' => 720, 'retail_price' => 750, 'reorder_level' => 30],
            ['pack_size' => '3/4" 20ft', 'cost' => 820, 'wholesale_price' => 960, 'retail_price' => 1000, 'reorder_level' => 30],
            ['pack_size' => '1" 20ft', 'cost' => 1150, 'wholesale_price' => 1330, 'retail_price' => 1400, 'reorder_level' => 20],
            ['name' => 'Wire 2.5mm (per m)', 'aliases' => 'wire, cable', 'category' => 'Electrical > Wires & Cables', 'brand' => 'Kelani', 'base_unit' => 'metre', 'cost' => 165, 'loose_price_kg' => 210, 'reorder_level' => 0],
            ['name' => 'Wire 2.5mm', 'aliases' => 'wire roll', 'category' => 'Electrical > Wires & Cables', 'brand' => 'Kelani', 'base_unit' => 'roll', 'pack_size' => '90m roll', 'cost' => 14800, 'wholesale_price' => 17000, 'retail_price' => 17500, 'reorder_level' => 5, 'opens_into' => 'Wire 2.5mm (per m)', 'opens_into_qty' => 90],
            ['name' => 'Claw Hammer 16oz', 'aliases' => 'hammer', 'category' => 'Hand Tools', 'brand' => 'Stanley', 'base_unit' => 'piece', 'opening_stock' => 12, 'cost' => 1450, 'wholesale_price' => 1900, 'retail_price' => 1950, 'reorder_level' => 5],
        ];

        return array_map(fn (array $row) => array_map(fn (string $key) => $row[$key] ?? '', ProductImportColumns::keys()), $rows);
    }
}
