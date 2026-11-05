<?php

use App\Domain\Identity\Enums\Role;
use App\Domain\System\Services\Settings;

test('defaults apply until a setting is saved', function () {
    expect(app(Settings::class)->get('receipt.language'))->toBe('en')
        ->and(app(Settings::class)->get('shop.name_en'))->toBe('Kodithuwakku Hardware')
        ->and(app(Settings::class)->get('shop.name_si'))->toBe('')
        ->and(app(Settings::class)->get('tax.registered'))->toBeTrue()
        ->and(app(Settings::class)->get('tax.default_rate'))->toBe(18)
        ->and(app(Settings::class)->get('pos.max_discount_percent_cashier'))->toBe(10)
        ->and(app(Settings::class)->get('pos.max_discount_percent_manager'))->toBe(25);
});

test('the owner saves the shop profile in English only', function () {
    $this->actingAs(userWithRole(Role::SuperAdmin))
        ->get(route('admin.settings.edit', 'shop'))
        ->assertOk()
        ->assertDontSee('Shop name (Sinhala)');

    $this->actingAs(userWithRole(Role::SuperAdmin))
        ->put(route('admin.settings.update', 'shop'), [
            'name_en' => 'Kodithuwakku Hardware',
            'address_en' => 'Main Street, Town',
            'phone' => '011 234 5678',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('admin.settings.edit', 'shop'));

    expect(app(Settings::class)->get('shop.address_en'))->toBe('Main Street, Town')
        ->and(app(Settings::class)->get('shop.phone'))->toBe('011 234 5678');

    $this->actingAs(userWithRole(Role::SuperAdmin))
        ->put(route('admin.settings.update', 'receipt'), ['language' => 'si'])
        ->assertSessionHasErrors('language');
});

test('with the Sinhala fields switched on, the Sinhala shop name is saved too', function () {
    config(['pos.sinhala_fields' => true]);

    $this->actingAs(userWithRole(Role::SuperAdmin))
        ->put(route('admin.settings.update', 'shop'), [
            'name_en' => 'Kodithuwakku Hardware',
            'name_si' => 'කොඩිතුවක්කු හාඩ්වෙයාර්',
            'address_en' => 'Main Street, Town',
            'address_si' => 'ප්‍රධාන වීදිය, නගරය',
            'phone' => '011 234 5678',
        ])
        ->assertRedirect(route('admin.settings.edit', 'shop'));

    expect(app(Settings::class)->get('shop.name_si'))->toBe('කොඩිතුවක්කු හාඩ්වෙයාර්');
});

test('checkbox and number settings are stored with the right types', function () {
    $this->actingAs(userWithRole(Role::SuperAdmin))
        ->put(route('admin.settings.update', 'tax'), ['registered' => '1', 'vat_number' => '123', 'default_rate' => '18'])
        ->assertSessionHasNoErrors();

    expect(app(Settings::class)->get('tax.registered'))->toBeTrue()
        ->and(app(Settings::class)->get('tax.default_rate'))->toBe(18);
});

test('invalid settings are rejected', function () {
    $this->actingAs(userWithRole(Role::SuperAdmin))
        ->put(route('admin.settings.update', 'receipt'), ['language' => 'fr'])
        ->assertSessionHasErrors('language');
});

test('unknown settings groups return 404', function () {
    $this->actingAs(userWithRole(Role::SuperAdmin))
        ->get(route('admin.settings.edit', 'nope'))
        ->assertNotFound();
});

test('only the Owner can change settings', function () {
    $this->actingAs(userWithRole(Role::Manager))
        ->get(route('admin.settings.edit', 'shop'))
        ->assertForbidden();
});
