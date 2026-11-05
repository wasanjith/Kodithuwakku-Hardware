<?php

use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\PriceList;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Unit;
use App\Domain\Identity\Enums\Role;
use App\Domain\Purchasing\Models\Supplier;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\DevelopmentCatalogSeeder;
use Database\Seeders\DevelopmentSupplierSeeder;
use Database\Seeders\DocumentSequenceSeeder;

beforeEach(function () {
    $this->seed([CatalogSeeder::class, DocumentSequenceSeeder::class]);
    $this->manager = userWithRole(Role::Manager);
    $this->staff = userWithRole(Role::Cashier);
    $this->supplier = Supplier::factory()->create(['name' => 'Bathalagoda Agro']);
    $this->okra = createProduct(['name' => 'Okra Haritha 10g packet', 'category' => 'Fasteners', 'base_unit' => 'packet']);
    $this->beans = createProduct(['name' => 'Bonchi Bean 10g packet', 'category' => 'Fasteners', 'base_unit' => 'packet']);
    $this->chili = createProduct(['name' => 'Chili MI-2 10g packet', 'category' => 'Fasteners', 'base_unit' => 'packet']);
    $this->supplier->products()->attach([$this->okra->id, $this->beans->id]);
});

test('with a supplier the product search finds only the products that supplier supplies', function () {
    $this->actingAs($this->staff);

    $this->getJson(route('api.pos.search', ['q' => '10g', 'supplier_id' => $this->supplier->id]))
        ->assertOk()
        ->assertJsonCount(2, 'items')
        ->assertJsonMissing(['name' => 'Chili MI-2 10g packet']);

    // An exact short code of someone else's product is not suggested either.
    $this->getJson(route('api.pos.search', ['q' => $this->chili->short_code, 'supplier_id' => $this->supplier->id]))
        ->assertOk()
        ->assertJsonCount(0, 'items');

    // Without the supplier (direct deliveries, "only this supplier" unticked) everything is found.
    $this->getJson(route('api.pos.search', ['q' => '10g']))->assertOk()->assertJsonCount(3, 'items');
});

test('with a supplier and nothing typed the search lists all of their products A-Z', function () {
    $this->actingAs($this->staff)
        ->getJson(route('api.pos.search', ['q' => '', 'supplier_id' => $this->supplier->id]))
        ->assertOk()
        ->assertJsonPath('items.0.name', 'Bonchi Bean 10g packet')
        ->assertJsonPath('items.1.name', 'Okra Haritha 10g packet')
        ->assertJsonCount(2, 'items');

    $this->getJson(route('api.pos.search', ['q' => '']))->assertOk()->assertJsonCount(0, 'items');
});

test('the purchase order form offers new supplier and new product to the manager only', function () {
    $this->actingAs($this->manager)->get(route('purchasing.purchase-orders.create'))
        ->assertOk()
        ->assertSee('+ New supplier')
        ->assertSee('+ New product')
        ->assertSee('Choose the supplier to see only the products they supply.');

    $this->actingAs($this->staff)->get(route('purchasing.purchase-orders.create'))
        ->assertOk()
        ->assertDontSee('+ New supplier')
        ->assertDontSee('+ New product');
});

test('a new supplier can be added from the purchase order form', function () {
    $this->seed(ChartOfAccountsSeeder::class);

    $this->actingAs($this->manager)
        ->postJson(route('purchasing.suppliers.store'), ['name' => 'Ishara Lak Seeds', 'phone' => '0712345678', 'payment_terms_days' => 30])
        ->assertCreated()
        ->assertJson(['label' => 'Ishara Lak Seeds', 'hint' => '0712345678']);

    expect(Supplier::where('name', 'Ishara Lak Seeds')->exists())->toBeTrue();

    $this->actingAs($this->staff)->postJson(route('purchasing.suppliers.store'), ['name' => 'Someone'])->assertForbidden();
});

test('a new product of a new brand is created, linked to the supplier and returned as a line', function () {
    $seeds = Category::firstWhere('name', 'Fasteners');
    $packet = Unit::firstWhere('name', 'packet');

    $item = $this->actingAs($this->manager)
        ->postJson(route('purchasing.purchase-orders.quick-product'), [
            'supplier_id' => $this->supplier->id,
            'name' => 'Pumpkin Arjuna 10g packet',
            'name_si' => 'වට්ටක්කා අර්ජුන 10g',
            'category_id' => $seeds->id,
            'new_brand' => 'Land Mark Seeds',
            'base_unit_id' => $packet->id,
            'reference_cost' => '90',
            'retail_price' => '150',
            'reorder_level' => '20',
        ])
        ->assertCreated()
        ->assertJsonPath('name', 'Pumpkin Arjuna 10g packet')
        ->assertJsonPath('brand', 'Land Mark Seeds')
        ->json();

    $product = Product::with(['brand', 'units'])->findOrFail($item['id']);

    expect($product->short_code)->toBe('50000')
        ->and($product->category_id)->toBe($seeds->id)
        ->and($product->reference_cost)->toBe('90.0000')
        ->and($product->reorder_level)->toBe('20.000')
        ->and($product->units->sole()->is_default_purchase)->toBeTrue()
        ->and(Brand::where('name', 'Land Mark Seeds')->count())->toBe(1)
        ->and($this->supplier->products()->whereKey($product->id)->exists())->toBeTrue()
        ->and($product->prices()->where('price_list_id', PriceList::firstWhere('name', 'Retail')->id)->value('price'))->toBe('150.00');

    // Now it shows up in that supplier's suggestions.
    $this->getJson(route('api.pos.search', ['q' => 'pumpkin', 'supplier_id' => $this->supplier->id]))->assertJsonCount(1, 'items');
});

test('a quick product can use an existing brand and needs a name and category', function () {
    $brand = Brand::create(['name' => 'CIC']);

    $this->actingAs($this->manager)
        ->postJson(route('purchasing.purchase-orders.quick-product'), ['base_unit_id' => Unit::firstWhere('name', 'packet')->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name', 'category_id']);

    $item = $this->postJson(route('purchasing.purchase-orders.quick-product'), [
        'name' => 'Big Onion Seeds 50g packet',
        'category_id' => Category::firstWhere('name', 'Fasteners')->id,
        'brand_id' => $brand->id,
        'base_unit_id' => Unit::firstWhere('name', 'packet')->id,
    ])->assertCreated()->json();

    expect(Product::find($item['id'])->brand_id)->toBe($brand->id)
        ->and(Brand::count())->toBe(1);
});

test('sales staff cannot create products from the purchase order form', function () {
    $this->actingAs($this->staff)
        ->postJson(route('purchasing.purchase-orders.quick-product'), [
            'name' => 'Anything',
            'category_id' => Category::firstWhere('name', 'Fasteners')->id,
            'base_unit_id' => Unit::firstWhere('name', 'packet')->id,
        ])
        ->assertForbidden();
});

test('the demo suppliers are linked to the products of their brand', function () {
    Supplier::query()->forceDelete();
    $this->seed([ChartOfAccountsSeeder::class, DevelopmentCatalogSeeder::class, DevelopmentSupplierSeeder::class]);

    $building = Supplier::firstWhere('name', 'Lanka Building Supplies');
    $electrical = Supplier::firstWhere('name', 'Electrical Wholesale');

    expect(Supplier::orderBy('name')->pluck('name')->all())->toBe(['Electrical Wholesale', 'Lanka Building Supplies', 'Pipe & Fittings Distributors', 'Tools & Paints Agency'])
        ->and($building->products()->count())->toBe(6)
        ->and(Supplier::firstWhere('name', 'Pipe & Fittings Distributors')->products()->count())->toBe(10)
        ->and($electrical->products()->pluck('name')->sort()->values()->all())->toBe([
            'LED Bulb 9W', 'MCB 32A Single Pole', 'Socket 13A', 'Switch 1 Gang',
            'Wire 1.5mm (per m)', 'Wire 1.5mm 90m roll', 'Wire 2.5mm (per m)', 'Wire 2.5mm 90m roll',
        ])
        ->and((string) $building->balance())->toBe('150000.00');
});
