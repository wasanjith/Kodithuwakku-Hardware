<?php

namespace App\Domain\Sales\Actions;

use App\Domain\CashDrawer\Models\DrawerSession;
use App\Domain\Identity\Models\Terminal;
use App\Domain\Sales\Models\PrintJob;
use App\Domain\Sales\Models\Sale;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * F9 at a counter that takes payment: the invoice is issued and settled into this counter's
 * own drawer in one transaction. If the payment is refused (no reference, over the credit
 * limit) nothing has happened: no invoice number used, no stock reserved, and the bill
 * stays on the screen. A repeated call with the same key returns the first result.
 */
class TakeCounterPaymentAction
{
    public function __construct(
        private readonly IssueCounterInvoiceAction $issue,
        private readonly SettleInvoiceAction $settle,
    ) {}

    /**
     * @param  array<string, mixed>  $cart
     * @return array{sale: Sale, print_job: PrintJob|null, credit_bill: PrintJob|null, open_drawer: bool, created: bool}
     */
    public function handle(Terminal $terminal, User $user, DrawerSession $session, array $cart, ?string $reference, string $idempotencyKey): array
    {
        try {
            return DB::transaction(function () use ($terminal, $user, $session, $cart, $reference, $idempotencyKey): array {
                $issued = $this->issue->handle($terminal, $user, $cart, $idempotencyKey, announce: false);
                $settled = $this->settle->handle($issued['sale'], $user, $terminal, $session, ['reference' => $reference], $idempotencyKey);

                return [
                    'sale' => $settled['sale'],
                    'print_job' => $issued['print_job'],
                    'credit_bill' => $settled['credit_bill'],
                    'open_drawer' => $settled['open_drawer'],
                    'created' => $settled['created'],
                ];
            });
        } catch (UniqueConstraintViolationException $exception) {
            // Another request with the same key won the race (and has committed by now).
            $sale = Sale::query()->where('idempotency_key', $idempotencyKey)->first();

            if ($sale === null) {
                throw $exception;
            }

            return ['sale' => $sale, 'print_job' => null, 'credit_bill' => null, 'open_drawer' => false, 'created' => false];
        }
    }
}
