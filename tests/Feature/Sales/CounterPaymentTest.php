<?php

use App\Domain\CashDrawer\Enums\CashMovementType;
use App\Domain\CashDrawer\Enums\DrawerCloseReason;
use App\Domain\CashDrawer\Models\DrawerSession;
use App\Domain\CashDrawer\Services\DrawerCalculator;
use App\Domain\Customers\Models\CustomerLedgerEntry;
use App\Domain\Finance\Models\JournalEntry;
use App\Domain\Finance\Services\DrawerCash;
use App\Domain\Finance\Services\FinancePosting;
use App\Domain\Finance\Services\JournalService;
use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\Printer;
use App\Domain\Identity\Models\Terminal;
use App\Domain\Identity\Services\CashierAuthority;
use App\Domain\Inventory\Models\StockLevel;
use App\Domain\Sales\Actions\IssueCounterInvoiceAction;
use App\Domain\Sales\Actions\VoidInvoiceAction;
use App\Domain\Sales\Enums\PaymentMethod;
use App\Domain\Sales\Enums\SaleStatus;
use App\Domain\Sales\Models\Payment;
use App\Domain\Sales\Models\Sale;
use App\Domain\Sales\Services\LiveBillingSnapshot;
use App\Domain\Sales\Services\LiveCartStore;
use App\Models\User;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->pos = posSetup();
    $this->urea = ureaInStock();
    $this->pos['counter']->update(['takes_payment' => true]);
    $this->cashier = $this->pos['staff'];
    $this->token = $this->pos['counterToken'];
});

function bagCart($urea, array $extra = []): array
{
    return cartPayload([['product_id' => $urea->id, 'unit_id' => unitId('bag'), 'qty' => '1']], ['tendered' => '10000', ...$extra]);
}

function payAtCounter(string $token, User $user, array $cart, ?string $key = null, array $extra = [])
{
    return atTerminal($token, $user)->postJson(route('api.pos.invoices.pay'), [...$cart, 'idempotency_key' => $key ?? Str::random(32), ...$extra]);
}

function terminalForm(Terminal $terminal, array $overrides = []): array
{
    return ['name' => $terminal->name, 'code' => $terminal->code, 'type' => $terminal->type->value, 'counter_no' => $terminal->counter_no, 'is_active' => '1', ...$overrides];
}

// ------------------------------------------------------------------ the setting

test('the owner switches payment on for a counter from the main machine', function () {
    $this->pos['counter']->update(['takes_payment' => false]);

    atTerminal($this->pos['mainToken'], $this->pos['owner'])
        ->get(route('admin.terminals.edit', $this->pos['counter']))
        ->assertOk()
        ->assertSee('This counter has a cash drawer and takes payment');

    atTerminal($this->pos['mainToken'], $this->pos['owner'])
        ->put(route('admin.terminals.update', $this->pos['counter']), terminalForm($this->pos['counter'], ['takes_payment' => '1']))
        ->assertRedirect(route('admin.terminals.index'))
        ->assertSessionHasNoErrors();

    expect($this->pos['counter']->fresh()->takesPayment())->toBeTrue();
});

test('the setting cannot change away from the main machine, and other edits there leave it alone', function () {
    $this->pos['counter']->update(['takes_payment' => false]);

    atTerminal($this->token, $this->pos['owner'])
        ->get(route('admin.terminals.edit', $this->pos['counter']))
        ->assertOk()
        ->assertSee('Change this from the main machine')
        ->assertDontSee('name="takes_payment"', false);

    atTerminal($this->token, $this->pos['owner'])
        ->put(route('admin.terminals.update', $this->pos['counter']), terminalForm($this->pos['counter'], ['takes_payment' => '1']))
        ->assertSessionHasErrors(['takes_payment' => 'Change this from the main machine.']);

    atTerminal($this->token, $this->pos['owner'])
        ->put(route('admin.terminals.update', $this->pos['counter']), terminalForm($this->pos['counter'], ['name' => 'Front counter']))
        ->assertSessionHasNoErrors();

    expect($this->pos['counter']->fresh())
        ->name->toBe('Front counter')
        ->takes_payment->toBeFalse();
});

test('the setting cannot change while the counter has an open drawer, an open bill or a bill in progress', function () {
    $change = fn () => atTerminal($this->pos['mainToken'], $this->pos['owner'])
        ->put(route('admin.terminals.update', $this->pos['counter']), terminalForm($this->pos['counter'], ['takes_payment' => '0']));

    $session = openDrawer($this->pos['counter'], $this->cashier);
    $change()->assertSessionHasErrors('takes_payment');

    $session->forceFill(['is_open' => null, 'closed_at' => now()])->save();
    $sale = counterInvoice($this->pos, [['product_id' => $this->urea->id, 'unit_id' => unitId('bag'), 'qty' => '1']], ['tendered' => '9000']);
    $change()->assertSessionHasErrors('takes_payment');

    app(VoidInvoiceAction::class)->handle($sale, $this->pos['owner'], 'Test');
    app(LiveCartStore::class)->put($this->pos['counter']->id, ['lines' => [['key' => 'x']]]);
    $change()->assertSessionHasErrors('takes_payment');

    app(LiveCartStore::class)->forget($this->pos['counter']->id);
    $change()->assertSessionHasNoErrors();

    expect($this->pos['counter']->fresh()->takes_payment)->toBeFalse();
});

test('a terminal changed to the main cashier type stops being a counter that takes payment', function () {
    $second = Terminal::factory()->counter(2)->takingPayment()->create();

    expect($second->takesPayment())->toBeTrue()->and($second->hasOwnDrawer())->toBeTrue();

    atTerminal($this->pos['mainToken'], $this->pos['owner'])
        ->put(route('admin.terminals.update', $second), terminalForm($second, ['type' => 'main_cashier', 'counter_no' => null]))
        ->assertSessionHasNoErrors();

    expect($second->fresh()->takes_payment)->toBeFalse();
});

// ------------------------------------------------------------------ the drawer of a counter

test('a cashier opens the drawer of a counter that takes payment; a plain counter has none', function () {
    atTerminal($this->token, $this->cashier)->get(route('pos.drawer.open'))->assertOk()->assertSee('Open the cash drawer');

    atTerminal($this->token, $this->cashier)
        ->post(route('pos.drawer.store'), ['denominations' => ['1000' => 5]])
        ->assertRedirect(route('pos.counter'));

    $session = DrawerSession::sole();
    expect($session->terminal_id)->toBe($this->pos['counter']->id)
        ->and($session->holder_user_id)->toBe($this->cashier->id)
        ->and($session->opening_float)->toBe('5000.00');

    expect(app(JournalService::class)->hasPosted($session, 'drawer.opened'))->toBeTrue();

    $this->pos['counter']->update(['takes_payment' => false]);

    atTerminal($this->token, $this->cashier)->get(route('pos.drawer.show'))->assertForbidden();
});

test('a cashier cannot use the main machine drawer or the main machine cashier screen', function () {
    atTerminal($this->pos['mainToken'], $this->cashier)->post(route('pos.drawer.store'), ['denominations' => ['1000' => 5]])->assertForbidden();
    expect(DrawerSession::count())->toBe(0);

    openDrawer($this->pos['counter'], $this->cashier);

    atTerminal($this->token, $this->cashier)->get(route('pos.cashier'))->assertForbidden();
});

test('the counter drawer stays out of the main drawer: authority, finance and live billing totals', function () {
    openDrawer($this->pos['counter'], $this->cashier);

    expect(app(CashierAuthority::class)->openSession())->toBeNull()
        ->and(app(DrawerCash::class)->openSession())->toBeNull()
        ->and(app(CashierAuthority::class)->holder()->id)->toBe($this->pos['owner']->id);

    $main = openDrawer($this->pos['main'], $this->pos['owner']);

    expect(app(CashierAuthority::class)->openSession()->id)->toBe($main->id)
        ->and(app(DrawerCash::class)->openSession()->id)->toBe($main->id);
});

test('handover to a Manager stays at the main machine, also for the owner holding a counter drawer', function () {
    openDrawer($this->pos['counter'], $this->pos['owner']);

    atTerminal($this->token, $this->pos['owner'])
        ->get(route('pos.drawer.show'))
        ->assertOk()
        ->assertSee('Expected in drawer')
        ->assertDontSee('Hand over')
        ->assertSee('Count and close');

    atTerminal($this->token, $this->pos['owner'])->get(route('pos.handover.create'))->assertForbidden();
});

test('the count is blind for a counter cashier until the drawer is closed', function () {
    $session = openDrawer($this->pos['counter'], $this->cashier);

    atTerminal($this->token, $this->cashier)
        ->get(route('pos.drawer.show'))
        ->assertOk()
        ->assertDontSee('Expected in drawer')
        ->assertDontSee('X report')
        ->assertSee('Count and close');

    atTerminal($this->token, $this->cashier)->get(route('pos.drawer.report', $session))->assertForbidden();

    atTerminal($this->token, $this->pos['manager'])->get(route('pos.drawer.report', $session))->assertOk();

    atTerminal($this->token, $this->cashier)
        ->post(route('pos.drawer.close.store'), ['denominations' => ['1000' => 5]])
        ->assertRedirect(route('pos.drawer.report', ['drawerSession' => $session->id, 'print' => 1]));

    atTerminal($this->token, $this->cashier)->get(route('pos.drawer.report', $session))->assertOk()->assertSee('Counter 1');
});

test('a counter cashier records pay ins and safe drops but not pay outs', function () {
    $session = openDrawer($this->pos['counter'], $this->cashier);
    $movement = fn (User $user, string $type, string $amount) => atTerminal($this->token, $user)
        ->post(route('pos.drawer.movements.store'), ['type' => $type, 'amount' => $amount, 'reason' => 'Test']);

    $movement($this->cashier, 'pay_in', '1000')->assertRedirect(route('pos.drawer.show'))->assertSessionHasNoErrors();
    $movement($this->cashier, 'safe_drop', '2000')->assertSessionHasNoErrors();
    $movement($this->cashier, 'pay_out', '100')->assertSessionHasErrors('type');

    // The message must not name the expected amount (blind count).
    $movement($this->cashier, 'safe_drop', '999999')->assertSessionHasErrors(['amount' => 'The drawer does not hold that much cash.']);

    expect($session->cashMovements()->pluck('type')->map->value->all())->toBe([CashMovementType::PayIn->value, CashMovementType::SafeDrop->value])
        ->and((string) app(DrawerCalculator::class)->expectedCash($session))->toBe('4000.00');
});

// ------------------------------------------------------------------ F9 at a counter that takes payment

test('F9 issues and settles in one step, into the drawer of this counter', function () {
    $session = openDrawer($this->pos['counter'], $this->cashier);

    payAtCounter($this->token, $this->cashier, bagCart($this->urea))
        ->assertCreated()
        ->assertJsonPath('created', true)
        ->assertJsonPath('open_drawer', true)
        ->assertJsonPath('sale.status', SaleStatus::Settled->value)
        ->assertJsonPath('sale.change_due', '1000.00')
        ->assertJsonPath('credit_bill_url', null);

    $sale = Sale::sole();
    expect($sale->status)->toBe(SaleStatus::Settled)
        ->and($sale->drawer_session_id)->toBe($session->id)
        ->and($sale->invoiced_terminal_id)->toBe($this->pos['counter']->id)
        ->and($sale->settled_terminal_id)->toBe($this->pos['counter']->id)
        ->and($sale->settled_by)->toBe($this->cashier->id)
        ->and($sale->cost_total)->not->toBe('0.00');

    $payment = Payment::sole();
    expect($payment->drawer_session_id)->toBe($session->id)
        ->and($payment->method)->toBe(PaymentMethod::Cash)
        ->and($payment->amount)->toBe('9000.00');

    expect((string) app(DrawerCalculator::class)->expectedCash($session))->toBe('14000.00')
        ->and(StockLevel::where('product_id', $this->urea->id)->sum('qty_reserved'))->toEqual(0)
        ->and(app(JournalService::class)->hasPosted($sale, FinancePosting::SALE_SETTLED))->toBeTrue();

    expectStockMatchesLedger();
});

test('F9 sent twice with the same key gives one sale and prints the unprinted original', function () {
    openDrawer($this->pos['counter'], $this->cashier);
    $cart = bagCart($this->urea);
    $key = Str::random(32);

    $first = payAtCounter($this->token, $this->cashier, $cart, $key)->assertCreated();
    $second = payAtCounter($this->token, $this->cashier, $cart, $key)->assertOk()->assertJsonPath('created', false)->assertJsonPath('open_drawer', false);

    expect($second->json('sale.id'))->toBe($first->json('sale.id'))
        ->and($second->json('print_job_id'))->toBe($first->json('print_job_id'))
        ->and(Sale::count())->toBe(1)
        ->and(Payment::count())->toBe(1);
});

test('without an open drawer nothing is issued; someone else holding it refuses too', function () {
    payAtCounter($this->token, $this->cashier, bagCart($this->urea))
        ->assertStatus(409)
        ->assertJsonPath('redirect', route('pos.drawer.open'));

    openDrawer($this->pos['counter'], $this->pos['manager']);

    payAtCounter($this->token, $this->cashier, bagCart($this->urea))->assertForbidden();

    expect(Sale::count())->toBe(0)
        ->and(StockLevel::where('product_id', $this->urea->id)->sum('qty_reserved'))->toEqual(0);
});

test('a plain counter cannot take payment; the main machine does not use this route', function () {
    $this->pos['counter']->update(['takes_payment' => false]);

    payAtCounter($this->token, $this->cashier, bagCart($this->urea))->assertForbidden();

    openDrawer($this->pos['main'], $this->pos['owner']);
    payAtCounter($this->pos['mainToken'], $this->pos['owner'], bagCart($this->urea))->assertForbidden();

    expect(Sale::count())->toBe(0);
});

test('a refused payment leaves nothing: no sale, no number used, no stock reserved', function () {
    openDrawer($this->pos['counter'], $this->cashier);
    $card = bagCart($this->urea, ['payment_method' => 'card', 'tendered' => null]);

    payAtCounter($this->token, $this->cashier, $card)->assertUnprocessable()->assertJsonValidationErrors('reference');

    expect(Sale::count())->toBe(0)
        ->and(StockLevel::where('product_id', $this->urea->id)->sum('qty_reserved'))->toEqual(0);

    payAtCounter($this->token, $this->cashier, $card, null, ['reference' => 'SLIP-77'])->assertCreated()->assertJsonPath('open_drawer', false);

    $sale = Sale::sole();
    expect($sale->payments()->sole()->reference)->toBe('SLIP-77')
        // The refused try did not take a number: this is the first invoice.
        ->and($sale->invoice_no)->toEndWith('000001');
});

test('credit at the counter: within the limit it goes on the account with a credit bill; over it is refused', function () {
    openDrawer($this->pos['counter'], $this->cashier);
    $customer = creditCustomer('10000');
    $credit = fn () => bagCart($this->urea, ['payment_method' => 'credit', 'tendered' => null, 'customer_id' => $customer->id]);

    $response = payAtCounter($this->token, $this->cashier, $credit())->assertCreated();

    expect($response->json('credit_bill_url'))->toContain('/credit-bill')
        ->and(CustomerLedgerEntry::where('customer_id', $customer->id)->sum('debit'))->toEqual(9000);

    atTerminal($this->token, $this->cashier)->get($response->json('credit_bill_url'))->assertOk();

    payAtCounter($this->token, $this->cashier, $credit())->assertUnprocessable()->assertJsonValidationErrors('credit_limit');

    expect(Sale::count())->toBe(1);
});

test('the drawer holder sends a bill to the main cashier, who settles it into the main drawer', function () {
    $counterDrawer = openDrawer($this->pos['counter'], $this->cashier);
    $mainDrawer = openDrawer($this->pos['main'], $this->pos['owner']);

    $id = atTerminal($this->token, $this->cashier)
        ->postJson(route('api.pos.invoices.store'), [...bagCart($this->urea), 'idempotency_key' => Str::random(32)])
        ->assertCreated()
        ->assertJsonPath('sale.status', SaleStatus::Invoiced->value)
        ->json('sale.id');

    atTerminal($this->pos['mainToken'], $this->pos['owner'])
        ->postJson(route('api.pos.sales.settle', $id), ['idempotency_key' => Str::random(32)])
        ->assertOk();

    expect(Sale::find($id)->drawer_session_id)->toBe($mainDrawer->id)
        ->and((string) app(DrawerCalculator::class)->expectedCash($counterDrawer))->toBe('5000.00')
        ->and((string) app(DrawerCalculator::class)->expectedCash($mainDrawer))->toBe('14000.00');
});

test('a counter settles its own open bills only', function () {
    openDrawer($this->pos['counter'], $this->cashier);
    $own = counterInvoice($this->pos, [['product_id' => $this->urea->id, 'unit_id' => unitId('bag'), 'qty' => '1']], ['tendered' => '9000']);

    [$other] = registeredTerminal(Terminal::factory()->counter(2)->create());
    $theirs = app(IssueCounterInvoiceAction::class)->handle($other, $this->pos['manager'], bagCart($this->urea), Str::random(32))['sale'];

    atTerminal($this->token, $this->cashier)
        ->postJson(route('api.pos.sales.settle', $theirs), ['idempotency_key' => Str::random(32)])
        ->assertForbidden();

    atTerminal($this->token, $this->cashier)
        ->postJson(route('api.pos.sales.settle', $own), ['idempotency_key' => Str::random(32)])
        ->assertOk()
        ->assertJsonPath('open_drawer', true);

    expect($own->fresh()->status)->toBe(SaleStatus::Settled)
        ->and($theirs->fresh()->status)->toBe(SaleStatus::Invoiced);
});

test('a plain counter still prints for the main cashier and cannot settle', function () {
    $this->pos['counter']->update(['takes_payment' => false]);
    $sale = counterInvoice($this->pos, [['product_id' => $this->urea->id, 'unit_id' => unitId('bag'), 'qty' => '1']], ['tendered' => '9000']);

    atTerminal($this->token, $this->cashier)
        ->postJson(route('api.pos.sales.settle', $sale), ['idempotency_key' => Str::random(32)])
        ->assertForbidden();

    openDrawer($this->pos['main'], $this->pos['owner']);

    atTerminal($this->pos['mainToken'], $this->pos['owner'])
        ->postJson(route('api.pos.sales.settle', $sale), ['idempotency_key' => Str::random(32)])
        ->assertOk();
});

test('voids stay at the main machine, and the refund comes out of the main drawer', function () {
    $counterDrawer = openDrawer($this->pos['counter'], $this->cashier);
    $id = payAtCounter($this->token, $this->cashier, bagCart($this->urea))->assertCreated()->json('sale.id');

    atTerminal($this->token, $this->cashier)->postJson(route('pos.sales.void', $id), ['reason' => 'Wrong'])->assertForbidden();

    $mainDrawer = openDrawer($this->pos['main'], $this->pos['owner']);

    atTerminal($this->pos['mainToken'], $this->pos['owner'])
        ->postJson(route('pos.sales.void', $id), ['reason' => 'Wrong'])
        ->assertOk();

    expect(Sale::find($id)->status)->toBe(SaleStatus::Void)
        ->and((string) app(DrawerCalculator::class)->expectedCash($counterDrawer))->toBe('14000.00')
        ->and((string) app(DrawerCalculator::class)->expectedCash($mainDrawer))->toBe('-4000.00');
});

// ------------------------------------------------------------------ closing a counter's drawer

test('closing the day at a counter gives its own Z report, not held up by other bills', function () {
    $session = openDrawer($this->pos['counter'], $this->cashier);
    $id = payAtCounter($this->token, $this->cashier, bagCart($this->urea))->assertCreated()->json('sale.id');

    // A bill waiting elsewhere, and one of this counter's sent to the main cashier.
    [$other] = registeredTerminal(Terminal::factory()->counter(2)->create());
    $elsewhere = app(IssueCounterInvoiceAction::class)->handle($other, $this->pos['manager'], bagCart($this->urea), Str::random(32))['sale'];
    $waiting = counterInvoice($this->pos, [['product_id' => $this->urea->id, 'unit_id' => unitId('bag'), 'qty' => '1']], ['tendered' => '9000']);

    atTerminal($this->token, $this->cashier)
        ->get(route('pos.drawer.close'))
        ->assertOk()
        ->assertSee($waiting->invoice_no)
        ->assertDontSee($elsewhere->invoice_no);

    atTerminal($this->token, $this->cashier)
        ->post(route('pos.drawer.close.store'), ['denominations' => ['5000' => 2, '1000' => 3, '500' => 1]])
        ->assertRedirect(route('pos.drawer.report', ['drawerSession' => $session->id, 'print' => 1]));

    $session->refresh();
    expect($session->close_reason)->toBe(DrawerCloseReason::EndOfDay)
        ->and($session->expected_cash)->toBe('14000.00')
        ->and($session->counted_cash)->toBe('13500.00')
        ->and($session->variance)->toBe('-500.00');

    $report = app(DrawerCalculator::class)->zReport($session);
    expect($report['terminal'])->toBe('Counter 1')
        ->and($report['sales_count'])->toBe(1)
        ->and($report['per_counter'][0]['terminal'])->toBe('Counter 1');

    expect(Sale::find($id)->status)->toBe(SaleStatus::Settled)
        ->and(app(JournalService::class)->hasPosted($session, 'drawer.closed'))->toBeTrue();
});

test('a counter Z report lists only its own voids; the main report lists the whole shop', function () {
    $session = openDrawer($this->pos['counter'], $this->cashier);

    [$other] = registeredTerminal(Terminal::factory()->counter(2)->create());
    $sale = app(IssueCounterInvoiceAction::class)->handle($other, $this->pos['manager'], bagCart($this->urea), Str::random(32))['sale'];
    app(VoidInvoiceAction::class)->handle($sale, $this->pos['owner'], 'Test');

    $main = openDrawer($this->pos['main'], $this->pos['owner']);

    expect(app(DrawerCalculator::class)->zReport($session)['void_count'])->toBe(0)
        ->and(app(DrawerCalculator::class)->zReport($main)['void_count'])->toBe(1);
});

test('the main machine still cannot close the day while a bill waits, also from a counter that takes payment', function () {
    openDrawer($this->pos['main'], $this->pos['owner']);
    counterInvoice($this->pos, [['product_id' => $this->urea->id, 'unit_id' => unitId('bag'), 'qty' => '1']], ['tendered' => '9000']);

    atTerminal($this->pos['mainToken'], $this->pos['owner'])
        ->post(route('pos.drawer.close.store'), ['denominations' => ['1000' => 5]])
        ->assertSessionHasErrors('drawer');
});

test('shift change: the next cashier continues with the count, on one day report', function () {
    $first = openDrawer($this->pos['counter'], $this->cashier);
    payAtCounter($this->token, $this->cashier, bagCart($this->urea))->assertCreated();

    atTerminal($this->token, $this->cashier)
        ->post(route('pos.drawer.close.store'), ['denominations' => ['5000' => 2, '1000' => 4], 'shift_change' => '1'])
        ->assertRedirect();

    expect($first->fresh()->close_reason)->toBe(DrawerCloseReason::Handover);

    $next = userWithRole(Role::Cashier, ['name' => 'Kamal']);

    atTerminal($this->token, $next)->get(route('pos.drawer.open'))->assertOk()->assertSee('Continuing after');
    atTerminal($this->token, $next)->post(route('pos.drawer.store'), ['denominations' => ['5000' => 2, '1000' => 4]])->assertRedirect(route('pos.counter'));

    $second = DrawerSession::query()->open()->sole();
    expect($second->previous_session_id)->toBe($first->id)
        ->and($second->holder_user_id)->toBe($next->id);

    payAtCounter($this->token, $next, bagCart($this->urea))->assertCreated();

    atTerminal($this->token, $next)
        ->post(route('pos.drawer.close.store'), ['denominations' => ['5000' => 4, '1000' => 3]])
        ->assertRedirect();

    $report = app(DrawerCalculator::class)->zReport($second->fresh());
    expect($report['sessions'])->toHaveCount(2)
        ->and($report['sales_count'])->toBe(2)
        ->and($report['total_variance'])->toBe('0.00');
});

test('the owner counts and closes a drawer a cashier left open; another cashier cannot', function () {
    $session = openDrawer($this->pos['counter'], $this->cashier);
    $other = userWithRole(Role::Cashier, ['name' => 'Kamal']);

    atTerminal($this->token, $other)->get(route('pos.drawer.show'))->assertOk()->assertSee('holds this drawer')->assertDontSee('Count and close');
    atTerminal($this->token, $other)->get(route('pos.drawer.close'))->assertForbidden();
    atTerminal($this->token, $other)->post(route('pos.handover.return.store'), ['denominations' => ['1000' => 5]])->assertSessionHasErrors('drawer');

    atTerminal($this->token, $this->pos['owner'])->get(route('pos.handover.return'))->assertOk()->assertSee('next person at this counter');

    atTerminal($this->token, $this->pos['owner'])
        ->post(route('pos.handover.return.store'), ['denominations' => ['1000' => 5], 'new_holder_id' => $this->pos['owner']->id])
        ->assertRedirect(route('pos.drawer.show'));

    expect($session->fresh()->isOpen())->toBeFalse()
        ->and($session->fresh()->close_reason)->toBe(DrawerCloseReason::Handover)
        ->and(DrawerSession::query()->open()->count())->toBe(0);

    atTerminal($this->token, $other)->post(route('pos.drawer.store'), ['denominations' => ['1000' => 5]])->assertRedirect(route('pos.counter'));
    expect(DrawerSession::query()->open()->sole()->previous_session_id)->toBe($session->id);
});

// ------------------------------------------------------------------ screens

test('the counter screen says what this counter does', function () {
    atTerminal($this->token, $this->cashier)
        ->get(route('pos.counter'))
        ->assertOk()
        ->assertViewHas('config', fn (array $config) => $config['takes_payment'] === true
            && $config['drawer'] === ['open' => false, 'mine' => false, 'holder' => null]
            && $config['can_settle'] === false)
        ->assertSee(route('pos.drawer.show'), false)
        ->assertSee('Drawer: ', false)
        ->assertDontSee(route('pos.cashier'), false);

    openDrawer($this->pos['counter'], $this->cashier);

    atTerminal($this->token, $this->cashier)
        ->get(route('pos.counter'))
        ->assertViewHas('config', fn (array $config) => $config['drawer']['mine'] === true && $config['drawer']['holder'] === 'Nimal');

    $this->pos['counter']->update(['takes_payment' => false]);
    DrawerSession::query()->update(['is_open' => null, 'closed_at' => now()]);

    atTerminal($this->token, $this->cashier)
        ->get(route('pos.counter'))
        ->assertViewHas('config', fn (array $config) => $config['takes_payment'] === false)
        ->assertDontSee(route('pos.drawer.show'), false);
});

test('terminals list shows who takes payment, and Live Billing shows the counter drawer', function () {
    atTerminal($this->pos['mainToken'], $this->pos['owner'])->get(route('admin.terminals.index'))->assertOk()->assertSee('Takes payment');

    $column = fn () => collect(app(LiveBillingSnapshot::class)->build()['counters'])->firstWhere('terminal_id', $this->pos['counter']->id);

    expect($column()['takes_payment'])->toBeTrue()->and($column()['drawer'])->toBeNull();

    openDrawer($this->pos['counter'], $this->cashier);
    payAtCounter($this->token, $this->cashier, bagCart($this->urea))->assertCreated();

    expect($column()['drawer'])->toBe(['holder' => 'Nimal', 'expected' => '14000.00'])
        ->and($column()['today']['total'])->toBe('9000.00')
        ->and($column()['invoices'])->toBe([]);
});

test('the counter screen knows whether its own printer has a drawer to open', function () {
    $printer = Printer::where('terminal_id', $this->pos['counter']->id)->sole();
    $printer->update(['has_cash_drawer' => true]);

    atTerminal($this->token, $this->cashier)
        ->get(route('pos.counter'))
        ->assertViewHas('config', fn (array $config) => $config['has_drawer'] === true);
});

test('a journal entry balances for a sale settled at a counter', function () {
    openDrawer($this->pos['counter'], $this->cashier);
    payAtCounter($this->token, $this->cashier, bagCart($this->urea))->assertCreated();

    JournalEntry::query()->with('lines')->get()->each(function (JournalEntry $entry) {
        expect($entry->lines->sum('debit'))->toEqual($entry->lines->sum('credit'));
    });
});
