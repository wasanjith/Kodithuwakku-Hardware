<?php

namespace Database\Seeders;

use App\Domain\Catalog\Models\PriceList;
use App\Domain\Customers\Actions\SaveCustomerAction;
use App\Domain\Customers\Models\Customer;
use App\Domain\Identity\Enums\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Dummy contractors and regular customers for local development (until the owner sends the
 * real credit list). Safe to run again: customers whose phone already exists are skipped.
 */
class DevelopmentCustomerSeeder extends Seeder
{
    public function run(SaveCustomerAction $save): void
    {
        $owner = User::role(Role::SuperAdmin->value)->first();

        if ($owner === null) {
            return;
        }

        $wholesale = PriceList::query()->where('name', 'Wholesale')->value('id');
        $contractor = PriceList::query()->where('name', 'Contractor')->value('id');

        $customers = [
            ['name' => 'Sunil Perera (Contractor)', 'phone' => '0771234567', 'nic' => '781234567V', 'area' => 'Kurunegala', 'credit_limit' => 300000, 'credit_days' => 60, 'opening_balance' => 45000, 'price_list_id' => $contractor],
            ['name' => 'Kamal Builders', 'phone' => '0712345678', 'nic' => null, 'area' => 'Kuliyapitiya', 'credit_limit' => 500000, 'credit_days' => 45, 'opening_balance' => 12500, 'price_list_id' => $contractor],
            ['name' => 'Nimal Rathnayake', 'phone' => '0763456789', 'nic' => '196912345678', 'area' => 'Wariyapola', 'credit_limit' => 50000, 'credit_days' => 30, 'opening_balance' => 0, 'price_list_id' => null],
            ['name' => 'Chandrika Kumari', 'phone' => '0784567890', 'nic' => '905556667V', 'area' => 'Kurunegala', 'credit_limit' => 30000, 'credit_days' => 30, 'opening_balance' => 28000, 'price_list_id' => null],
            ['name' => 'Wijesinghe Hardware', 'phone' => '0372264411', 'nic' => null, 'area' => 'Mawathagama', 'credit_limit' => 250000, 'credit_days' => 45, 'opening_balance' => 86500, 'price_list_id' => $wholesale],
            ['name' => 'Priyantha Senarath', 'phone' => '0705678901', 'nic' => '821234987V', 'area' => 'Kurunegala', 'credit_limit' => 0, 'credit_days' => 30, 'opening_balance' => 0, 'price_list_id' => null],
            ['name' => 'Ranjith Electricals (Contractor)', 'phone' => '0756789012', 'nic' => '197512309876', 'area' => 'Kuliyapitiya', 'credit_limit' => 150000, 'credit_days' => 60, 'opening_balance' => 5000, 'price_list_id' => $contractor],
            ['name' => 'Somapala Herath', 'phone' => '0727890123', 'nic' => '601239874V', 'area' => 'Wariyapola', 'credit_limit' => 40000, 'credit_days' => 30, 'opening_balance' => 0, 'price_list_id' => null, 'is_active' => false],
        ];

        foreach ($customers as $data) {
            if (Customer::withTrashed()->where('phone', $data['phone'])->exists()) {
                continue;
            }

            $save->handle([
                'address' => null,
                'notes' => 'Dummy customer for testing.',
                'is_active' => true,
                ...$data,
            ], $owner);
        }
    }
}
