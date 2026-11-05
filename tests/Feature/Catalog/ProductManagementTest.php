<?php

use App\Domain\Catalog\Import\ProductsExport;
use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\PriceList;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductPrice;
use App\Domain\Catalog\Models\Unit;
use App\Domain\Identity\Enums\Role;
use Database\Seeders\CatalogSeeder;

beforeEach(function () {
    $this->seed(CatalogSeeder::class);
    $this->owner = userWithRole(Role::SuperAdmin);
    $this->kg = Unit::firstWhere('name', 'kg');
    $this->bag = Unit::firstWhere('name', 'bag');
    $this->retail = PriceList::firstWhere('name', 'Retail');
    $this->fertilizers = Category::firstWhere('name', 'Building Materials');
});

function productPayload(array $overrides = []): array
{
    $test = test();

    return [
        'short_code' => '1001',
        'name' => 'Urea 50kg',
        'name_si' => 'යූරියා',
        'aliases' => 'yuriya, u50',
        'category_id' => $test->fertilizers->id,
        'base_unit_id' => $test->kg->id,
        'is_active' => '1',
        'reorder_level' => '100',
        'reorder_qty' => '500',
        'reference_cost' => '160',
        'min_selling_margin_pct' => '5',
        'units' => [
            ['unit_id' => $test->kg->id, 'factor' => '1', 'is_default_sale' => '0', 'is_default_purchase' => '0'],
            ['unit_id' => $test->bag->id, 'factor' => '50', 'is_default_sale' => '1', 'is_default_purchase' => '1'],
        ],
        'prices' => [
            ['unit_id' => $test->kg->id, 'price_list_id' => $test->retail->id, 'price' => '190'],
            ['unit_id' => $test->bag->id, 'price_list_id' => $test->retail->id, 'price' => '9000'],
        ],
        'attribute_rows' => [['key' => 'NPK', 'value' => '46-0-0']],
        ...$overrides,
    ];
}

test('the owner creates a product with units, prices and attributes', function () {
    $this->actingAs($this->owner)
        ->post(route('catalog.products.store'), productPayload())
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $product = Product::with('units')->firstWhere('short_code', '1001');

    expect($product->name_si)->toBe('යූරියා')
        ->and($product->attributes)->toBe(['NPK' => '46-0-0'])
        ->and($product->units)->toHaveCount(2)
        ->and($product->defaultSaleUnit()->unit_id)->toBe($this->bag->id)
        ->and($product->units->firstWhere('unit_id', $this->bag->id)->factor)->toBe('50.000')
        ->and($product->reference_cost)->toBe('160.0000')
        ->and(ProductPrice::where('product_id', $product->id)->count())->toBe(2)
        ->and($product->created_by)->toBe($this->owner->id);
});

test('the base unit is always kept with factor 1', function () {
    $this->actingAs($this->owner)->post(route('catalog.products.store'), productPayload([
        'units' => [['unit_id' => $this->bag->id, 'factor' => '50', 'is_default_sale' => '1']],
    ]))->assertSessionHasNoErrors();

    $units = Product::firstWhere('short_code', '1001')->units()->pluck('factor', 'unit_id');

    expect($units[$this->kg->id])->toBe('1.000')
        ->and($units[$this->bag->id])->toBe('50.000');
});

test('a price change adds a history row and an unchanged price does not', function () {
    $product = createUrea();
    $countBefore = ProductPrice::where('product_id', $product->id)->count();

    $payload = productPayload([
        'prices' => [
            ['unit_id' => $this->kg->id, 'price_list_id' => $this->retail->id, 'price' => '190.00'],  // unchanged
            ['unit_id' => $this->bag->id, 'price_list_id' => $this->retail->id, 'price' => '9200'],   // changed
        ],
    ]);

    $this->actingAs($this->owner)->put(route('catalog.products.update', $product), $payload)->assertSessionHasNoErrors();

    $bagPrices = ProductPrice::where(['product_id' => $product->id, 'unit_id' => $this->bag->id, 'price_list_id' => $this->retail->id])
        ->orderBy('id')->pluck('price')->all();

    expect(ProductPrice::where('product_id', $product->id)->count())->toBe($countBefore + 1)
        ->and($bagPrices)->toBe(['9000.00', '9200.00']);

    $this->actingAs($this->owner)->get(route('catalog.products.show', $product))
        ->assertOk()
        ->assertSeeInOrder(['Price history', '9,200.00', '9,000.00']);
});

test('a price below cost plus the minimum margin is refused', function () {
    // Cost 160/kg + 5% = 168/kg, so a bag (50 kg) must be at least 8,400.
    $this->actingAs($this->owner)->post(route('catalog.products.store'), productPayload([
        'prices' => [['unit_id' => $this->bag->id, 'price_list_id' => $this->retail->id, 'price' => '8000']],
    ]))->assertSessionHasErrors('prices.0.price');

    expect(Product::where('short_code', '1001')->exists())->toBeFalse();
});

test('short codes are unique across products and variants', function () {
    createProduct(['short_code' => '5001', 'category' => 'PVC Pipes'], variants: [
        ['short_code' => '5002', 'name' => '26"', 'is_active' => true],
    ]);

    $this->actingAs($this->owner)
        ->post(route('catalog.products.store'), productPayload(['short_code' => '5002']))
        ->assertSessionHasErrors('short_code');

    $this->actingAs($this->owner)
        ->post(route('catalog.products.store'), productPayload([
            'variants' => [['short_code' => '5001', 'name' => 'Clash']],
        ]))
        ->assertSessionHasErrors('variants.0.short_code');
});

test('variants are saved, and removed variants are soft deleted', function () {
    $this->actingAs($this->owner)->post(route('catalog.products.store'), productPayload([
        'short_code' => '5001',
        'variants' => [
            ['short_code' => '5002', 'name' => '26"', 'size' => '26"', 'is_active' => '1'],
            ['short_code' => '5003', 'name' => '28"', 'size' => '28"', 'is_active' => '1'],
        ],
    ]))->assertSessionHasNoErrors();

    $product = Product::firstWhere('short_code', '5001');
    $keep = $product->variants()->firstWhere('short_code', '5002');

    expect($product->has_variants)->toBeTrue()
        ->and($keep->attributes)->toBe(['size' => '26"']);

    $this->actingAs($this->owner)->put(route('catalog.products.update', $product), productPayload([
        'short_code' => '5001',
        'variants' => [['id' => $keep->id, 'short_code' => '5002', 'name' => '26 inch', 'is_active' => '1']],
    ]))->assertSessionHasNoErrors();

    expect($product->variants()->pluck('name')->all())->toBe(['26 inch'])
        ->and($product->variants()->withTrashed()->count())->toBe(2);
});

test('the next short code follows the category range, also for sub-categories', function () {
    $pipes = Category::firstWhere('name', 'PVC Pipes');
    createProduct(['short_code' => '20000', 'category' => 'PVC Pipes']);
    createProduct(['short_code' => '20001', 'category' => 'PVC Pipes']);

    $this->actingAs($this->owner)
        ->getJson(route('catalog.products.next-code', ['category_id' => $pipes->id]))
        ->assertOk()
        ->assertJson(['code' => '20002', 'range' => [20000, 24999]]);

    $this->actingAs($this->owner)
        ->getJson(route('catalog.products.next-code', ['category_id' => $this->fertilizers->id]))
        ->assertJson(['code' => '10000']);
});

test('the next sku follows the category and brand, also counting deleted products', function () {
    $brand = Brand::factory()->create(['name' => 'Bathalagoda Agro']);
    createProduct(['sku' => 'BUI-BAT-001', 'category' => 'Building Materials', 'brand_id' => $brand->id]);
    createProduct(['sku' => 'BUI-BAT-002', 'category' => 'Building Materials', 'brand_id' => $brand->id])->delete();
    createProduct(['sku' => 'BUI-BAT-SPECIAL', 'category' => 'Building Materials']);

    $this->actingAs($this->owner)
        ->getJson(route('catalog.products.next-code', ['category_id' => $this->fertilizers->id, 'brand_id' => $brand->id]))
        ->assertOk()
        ->assertJson(['code' => '10000', 'sku' => 'BUI-BAT-003']);

    $this->actingAs($this->owner)
        ->getJson(route('catalog.products.next-code', ['category_id' => $this->fertilizers->id]))
        ->assertJson(['sku' => 'BUI-001']);

    $this->actingAs($this->owner)
        ->getJson(route('catalog.products.next-code', ['category_id' => Category::firstWhere('name', 'Paints')->id, 'brand_id' => $brand->id]))
        ->assertJson(['sku' => 'PAI-BAT-001']);
});

test('the add product form pre-fills short code and sku when a category is given', function () {
    $this->actingAs($this->owner)
        ->get(route('catalog.products.create', ['category_id' => $this->fertilizers->id]))
        ->assertOk()
        ->assertSee('10000')
        ->assertSee('BUI-001');
});

test('deleted products keep their code reserved', function () {
    $product = createProduct(['short_code' => '10000', 'category' => 'Building Materials']);

    $this->actingAs($this->owner)->delete(route('catalog.products.destroy', $product))->assertRedirect(route('catalog.products.index'));

    expect($product->fresh()->trashed())->toBeTrue();
    $this->actingAs($this->owner)
        ->getJson(route('catalog.products.next-code', ['category_id' => $this->fertilizers->id]))
        ->assertJson(['code' => '10001']);
});

test('sales staff can look at products but not change them or see cost', function () {
    $product = createUrea();
    $staff = userWithRole(Role::Cashier);

    $this->actingAs($staff)->get(route('catalog.products.index'))->assertOk()->assertSee('Urea 50kg');
    $this->actingAs($staff)->get(route('catalog.products.show', $product))
        ->assertOk()
        ->assertSee('9,000.00')
        ->assertDontSee('Cost')
        ->assertDontSee('160.00');

    $this->actingAs($staff)->get(route('catalog.products.create'))->assertForbidden();
    $this->actingAs($staff)->post(route('catalog.products.store'), productPayload(['short_code' => '1999']))->assertForbidden();
    $this->actingAs($staff)->put(route('catalog.products.update', $product), productPayload())->assertForbidden();
    $this->actingAs($staff)->delete(route('catalog.products.destroy', $product))->assertForbidden();
});

test('the product export leaves out cost for sales staff', function () {
    createUrea();

    $this->actingAs(userWithRole(Role::Manager))->get(route('catalog.products.export'))->assertOk()->assertDownload();
    $this->actingAs(userWithRole(Role::Cashier))->get(route('catalog.products.export'))->assertOk()->assertDownload();

    $query = Product::query();
    expect((new ProductsExport($query, withCost: false))->headings())->not->toContain('Cost')
        ->and((new ProductsExport($query, withCost: true))->headings())->toContain('Cost');
});

test('the product list filters by category including sub-categories', function () {
    createProduct(['name' => 'Tyre 26', 'category' => 'PVC Pipes']);
    createProduct(['name' => 'Mammoty', 'category' => 'Hand Tools']);

    $bicycle = Category::firstWhere('name', 'Plumbing');

    $this->actingAs($this->owner)
        ->get(route('catalog.products.index', ['filter' => ['category' => $bicycle->id]]))
        ->assertOk()
        ->assertSee('Tyre 26')
        ->assertDontSee('Mammoty');
});
