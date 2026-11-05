<?php

namespace Database\Seeders;

use App\Domain\Catalog\Models\Product;
use App\Domain\Finance\Services\FinancePosting;
use App\Domain\Purchasing\Models\Supplier;
use Illuminate\Database\Seeder;

/**
 * Demo hardware suppliers, for local development (never run in production). Each one is
 * linked to the products of the brands it supplies (supplier_products), so a new purchase
 * order suggests only that supplier's products. Run after DevelopmentCatalogSeeder.
 */
class DevelopmentSupplierSeeder extends Seeder
{
    public function run(FinancePosting $finance): void
    {
        $suppliers = [
            // name => details, brands supplied
            'Lanka Building Supplies' => [[
                'contact_person' => 'Mr. Perera',
                'phone' => '0112345601',
                'address' => 'No. 12, Main Street, Colombo 11',
                'opening_balance' => '150000.00',
            ], ['Tokyo Super', 'Sanstha', 'Melwa', 'Lanka Roofing']],
            'Pipe & Fittings Distributors' => [[
                'contact_person' => 'Mr. Silva',
                'phone' => '0112345602',
                'address' => 'No. 45, Kandy Road, Kadawatha',
                'opening_balance' => '120000.00',
            ], ['S-lon', 'Arpico', 'Rocell', 'Lanka Tiles']],
            'Electrical Wholesale' => [[], ['Kelani', 'Orange', 'ABB']],
            'Tools & Paints Agency' => [[], ['Dulux', 'Multilac', 'Harris', 'Stanley', 'Bosch', 'Makita', 'Pedrollo']],
        ];

        foreach ($suppliers as $name => [$details, $brands]) {
            $supplier = Supplier::updateOrCreate(['name' => $name], [
                'payment_terms_days' => 30,
                'opening_balance' => '0.00',
                'is_active' => true,
                ...$details,
            ]);

            if ((float) $supplier->opening_balance > 0) {
                $finance->supplierOpening($supplier);
            }

            $productIds = Product::query()->whereHas('brand', fn ($query) => $query->whereIn('name', $brands))->pluck('id');
            $costs = Product::query()->whereKey($productIds)->pluck('reference_cost', 'id');

            $supplier->products()->syncWithoutDetaching(
                $productIds->mapWithKeys(fn (int $id) => [$id => ['last_cost' => $costs[$id]]])->all(),
            );
        }
    }
}
