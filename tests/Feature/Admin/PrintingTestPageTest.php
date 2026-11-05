<?php

use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\Terminal;
use Database\Seeders\TerminalSeeder;

test('the sample thermal invoice renders Sinhala labels, product names, paid and balance', function () {
    $this->actingAs(userWithRole(Role::SuperAdmin))
        ->get(route('admin.printing-test.thermal', ['lang' => 'si']))
        ->assertOk()
        ->assertSee('සිමෙන්ති 50kg')
        ->assertSee('ගෙවිය යුතු මුදල')
        ->assertSee('ගෙවූ මුදල (මුදල්)')
        ->assertSee('ඉතිරිය')
        ->assertSee('40,525.00')
        ->assertSee('41,000.00')
        ->assertSee('475.00');
});

test('the sample invoice can be printed in English or both languages', function () {
    $this->actingAs(userWithRole(Role::SuperAdmin));

    $this->get(route('admin.printing-test.thermal', ['lang' => 'en']))
        ->assertOk()
        ->assertSee('Cement 50kg bag')
        ->assertDontSee('සිමෙන්ති 50kg');

    $this->get(route('admin.printing-test.thermal', ['lang' => 'si+en']))
        ->assertOk()
        ->assertSee('ඉතිරිය / Balance');
});

test('auto-printing records the test time on the terminal printer', function () {
    $this->seed(TerminalSeeder::class);
    [$terminal, $token] = registeredTerminal(Terminal::where('code', 'C2')->firstOrFail());

    $this->actingAs(userWithRole(Role::SuperAdmin))
        ->withCookie(deviceCookie(), $token)
        ->get(route('admin.printing-test.thermal', ['autoprint' => 1]))
        ->assertOk()
        ->assertSee('window.print()', false);

    expect($terminal->printer->fresh()->last_test_at)->not->toBeNull();
});

test('the printing test page is for the Owner only', function () {
    $this->actingAs(userWithRole(Role::Cashier))
        ->get(route('admin.printing-test'))
        ->assertForbidden();
});
