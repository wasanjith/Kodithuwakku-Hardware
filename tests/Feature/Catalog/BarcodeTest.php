<?php

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Unit;
use App\Domain\Catalog\Services\ProductSearchService;
use App\Domain\Identity\Enums\Role;
use Database\Seeders\CatalogSeeder;

beforeEach(function () {
    $this->seed(CatalogSeeder::class);
    $this->owner = userWithRole(Role::SuperAdmin);
    $this->manager = userWithRole(Role::Manager);
    $this->cashier = userWithRole(Role::Cashier);
    $this->pipe = createProduct(['short_code' => '20001', 'name' => 'PVC Pipe 1/2" 20ft', 'category' => 'PVC Pipes', 'base_unit' => 'length', 'barcode' => '4792081001234'],
        prices: ['Retail' => ['length' => '750.00']]);
    $this->hammer = createProduct(['short_code' => '40001', 'name' => 'Claw Hammer 16oz', 'category' => 'Hand Tools', 'base_unit' => 'piece'],
        prices: ['Retail' => ['piece' => '1950.00']]);
});

function hammerPayload(array $overrides = []): array
{
    return [
        'short_code' => '40002',
        'name' => 'Measuring Tape 5m',
        'category_id' => Category::firstWhere('name', 'Hand Tools')->id,
        'base_unit_id' => Unit::firstWhere('name', 'piece')->id,
        'is_active' => '1',
        'reorder_level' => '0',
        'reorder_qty' => '0',
        ...$overrides,
    ];
}

test('a barcode is saved on the product form and shown on the product page', function () {
    $this->actingAs($this->owner)
        ->post(route('catalog.products.store'), hammerPayload(['barcode' => '  8901234567890 ']))
        ->assertSessionHasNoErrors();

    $tape = Product::firstWhere('short_code', '40002');
    expect($tape->barcode)->toBe('8901234567890');

    $this->actingAs($this->owner)->get(route('catalog.products.show', $tape))->assertOk()->assertSee('8901234567890');
    $this->actingAs($this->owner)->get(route('catalog.products.edit', $tape))->assertOk()->assertSee('data-barcode-field', false);
});

test('one barcode per product: a barcode already used is refused with that product\'s name', function () {
    $this->actingAs($this->owner)
        ->post(route('catalog.products.store'), hammerPayload(['barcode' => '4792081001234']))
        ->assertSessionHasErrors(['barcode' => 'Barcode 4792081001234 already belongs to 20001 · PVC Pipe 1/2" 20ft.']);

    // Saving the product that has it keeps it.
    $this->actingAs($this->owner)
        ->put(route('catalog.products.update', $this->pipe), [...hammerPayload(), 'short_code' => '20001', 'name' => $this->pipe->name, 'barcode' => '4792081001234'])
        ->assertSessionHasNoErrors();
});

test('a barcode may not be another product\'s short code, and a short code may not be another product\'s barcode', function () {
    $this->actingAs($this->owner)
        ->post(route('catalog.products.store'), hammerPayload(['barcode' => '40001']))
        ->assertSessionHasErrors(['barcode' => '40001 is the short code of Claw Hammer 16oz. Use a different barcode.']);

    $this->actingAs($this->owner)
        ->post(route('catalog.products.store'), hammerPayload(['short_code' => '4792081001234']))
        ->assertSessionHasErrors(['short_code' => '4792081001234 is the barcode of PVC Pipe 1/2" 20ft. Use a different short code.']);

    $this->actingAs($this->owner)
        ->post(route('catalog.products.store'), hammerPayload(['barcode' => 'AB 12']))
        ->assertSessionHasErrors('barcode');
});

test('the search finds a scanned barcode before anything else, with a quantity typed before it', function () {
    $this->actingAs($this->cashier)
        ->getJson(route('api.pos.search', ['q' => '4792081001234']))
        ->assertOk()
        ->assertJsonPath('engine', ProductSearchService::ENGINE_BARCODE)
        ->assertJsonCount(1, 'items')
        ->assertJsonPath('items.0.short_code', '20001')
        ->assertJsonPath('items.0.barcode', '4792081001234')
        ->assertJsonPath('items.0.price', '750.00');

    $this->actingAs($this->cashier)
        ->getJson(route('api.pos.search', ['q' => '12*4792081001234']))
        ->assertJsonPath('engine', ProductSearchService::ENGINE_BARCODE)
        ->assertJsonPath('qty', '12')
        ->assertJsonPath('items.0.id', $this->pipe->id);

    // Short codes still work, and an unknown barcode finds nothing.
    $this->actingAs($this->cashier)->getJson(route('api.pos.search', ['q' => '40001']))->assertJsonPath('engine', ProductSearchService::ENGINE_CODE);
    $this->actingAs($this->cashier)->getJson(route('api.pos.search', ['q' => '4790000000000']))->assertJsonCount(0, 'items');
});

test('a scan asks for exact matches only: an unknown number never brings near matches', function () {
    // "Hammer" would match the name search; as a scan it is only a code or barcode.
    $this->actingAs($this->cashier)->getJson(route('api.pos.search', ['q' => 'Hammer']))->assertJsonPath('items.0.id', $this->hammer->id);
    $this->actingAs($this->cashier)->getJson(route('api.pos.search', ['q' => 'Hammer', 'exact' => 1]))->assertJsonCount(0, 'items');

    $this->actingAs($this->cashier)->getJson(route('api.pos.search', ['q' => '4792081001234', 'exact' => 1]))
        ->assertJsonPath('engine', ProductSearchService::ENGINE_BARCODE)
        ->assertJsonCount(1, 'items');
    $this->actingAs($this->cashier)->getJson(route('api.pos.search', ['q' => '40001', 'exact' => 1]))->assertJsonPath('items.0.id', $this->hammer->id);
});

test('an inactive product is not found by its barcode at the counter', function () {
    $this->pipe->update(['is_active' => false]);

    $this->actingAs($this->cashier)->getJson(route('api.pos.search', ['q' => '4792081001234']))->assertJsonCount(0, 'items');
});

test('a manager links an unknown barcode to a product; the next scan finds it', function () {
    $this->actingAs($this->manager)
        ->postJson(route('api.barcodes.link'), ['barcode' => '6001234567890', 'product_id' => $this->hammer->id])
        ->assertOk()
        ->assertJsonPath('product.barcode', '6001234567890')
        ->assertJsonPath('message', 'Barcode 6001234567890 saved on Claw Hammer 16oz.');

    $this->actingAs($this->cashier)
        ->getJson(route('api.pos.search', ['q' => '6001234567890']))
        ->assertJsonPath('engine', ProductSearchService::ENGINE_BARCODE)
        ->assertJsonPath('items.0.id', $this->hammer->id);

    // Recorded in the product's history.
    expect($this->hammer->activitiesAsSubject()->latest('id')->first()->attribute_changes['attributes']['barcode'] ?? null)->toBe('6001234567890');
});

test('a cashier cannot link barcodes', function () {
    $this->actingAs($this->cashier)
        ->postJson(route('api.barcodes.link'), ['barcode' => '6001234567890', 'product_id' => $this->hammer->id])
        ->assertForbidden();

    expect($this->hammer->fresh()->barcode)->toBeNull();
});

test('linking asks before replacing a product\'s barcode and refuses one that belongs to another product', function () {
    $this->actingAs($this->manager)
        ->postJson(route('api.barcodes.link'), ['barcode' => '6001234567890', 'product_id' => $this->pipe->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['replace' => 'PVC Pipe 1/2" 20ft already has barcode 4792081001234. Replace it with 6001234567890?']);

    $this->actingAs($this->manager)
        ->postJson(route('api.barcodes.link'), ['barcode' => '6001234567890', 'product_id' => $this->pipe->id, 'replace' => true])
        ->assertOk();

    expect($this->pipe->fresh()->barcode)->toBe('6001234567890');

    $this->actingAs($this->manager)
        ->postJson(route('api.barcodes.link'), ['barcode' => '6001234567890', 'product_id' => $this->hammer->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['barcode' => 'Barcode 6001234567890 already belongs to 20001 · PVC Pipe 1/2" 20ft.']);
});

test('a deleted product gives its barcode up', function () {
    $this->actingAs($this->owner)->delete(route('catalog.products.destroy', $this->pipe))->assertRedirect();

    $this->actingAs($this->manager)
        ->postJson(route('api.barcodes.link'), ['barcode' => '4792081001234', 'product_id' => $this->hammer->id])
        ->assertOk();
});

test('the product list finds a barcode and lists products without one', function () {
    $this->actingAs($this->owner)
        ->get(route('catalog.products.index', ['search' => '4792081001234']))
        ->assertOk()
        ->assertSee('PVC Pipe 1/2&quot; 20ft', false)
        ->assertDontSee('Claw Hammer');

    $this->actingAs($this->owner)
        ->get(route('catalog.products.index', ['filter' => ['no_barcode' => '1']]))
        ->assertOk()
        ->assertSee('Claw Hammer')
        ->assertDontSee('PVC Pipe 1/2&quot; 20ft', false);
});

test('the counter screen knows who may link barcodes', function () {
    $pos = posSetup();

    $config = atTerminal($pos['counterToken'], $pos['staff'])->get(route('pos.counter'))->assertOk()->viewData('config');
    expect($config['can_link_barcode'])->toBeFalse()
        ->and($config['urls']['barcode_link'])->toBe(route('api.barcodes.link'));

    $config = atTerminal($pos['counterToken'], $pos['manager'])->get(route('pos.counter'))->assertOk()->viewData('config');
    expect($config['can_link_barcode'])->toBeTrue();
});
