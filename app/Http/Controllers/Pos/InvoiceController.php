<?php

namespace App\Http\Controllers\Pos;

use App\Domain\Identity\Support\CurrentTerminal;
use App\Domain\Sales\Actions\IssueCounterInvoiceAction;
use App\Domain\Sales\Actions\ReprintInvoiceAction;
use App\Domain\Sales\Actions\TakeCounterPaymentAction;
use App\Domain\Sales\Enums\PrintDocumentType;
use App\Domain\Sales\Models\PrintJob;
use App\Domain\Sales\Models\Sale;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\CartRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Printing invoices at a counter (F9), today's invoices of the counter, reprints (F10).
 */
class InvoiceController extends Controller
{
    public function __construct(private readonly CurrentTerminal $currentTerminal) {}

    /**
     * POST /api/pos/invoices {cart…, idempotency_key}
     */
    public function store(CartRequest $request, IssueCounterInvoiceAction $issue): JsonResponse
    {
        $key = (string) $request->validate(['idempotency_key' => ['required', 'string', 'min:16', 'max:64']])['idempotency_key'];

        $result = $issue->handle($this->currentTerminal->get(), $request->user(), $request->cart(), $key);
        $sale = $result['sale'];
        $job = $result['print_job'] ?? $this->unprintedJob(PrintDocumentType::Invoice, $sale);

        return response()->json([
            'sale' => $sale->liveSummary(),
            'created' => $result['created'],
            'print_url' => $job !== null ? route('pos.sales.invoice', ['sale' => $sale, 'job' => $job->id]) : null,
            'print_job_id' => $job?->id,
        ], $result['created'] ? 201 : 200);
    }

    /**
     * POST /api/pos/invoices/pay {cart…, reference?, idempotency_key}: F9 at a counter that
     * takes payment. The bill is printed and settled into this counter's drawer at once.
     */
    public function pay(CartRequest $request, TakeCounterPaymentAction $take): JsonResponse
    {
        $validated = $request->validate([
            'idempotency_key' => ['required', 'string', 'min:16', 'max:64'],
            'reference' => ['nullable', 'string', 'max:100'],
        ]);

        $result = $take->handle(
            $this->currentTerminal->get(),
            $request->user(),
            $request->attributes->get('drawerSession'),
            $request->cart(),
            $validated['reference'] ?? null,
            $validated['idempotency_key'],
        );
        $sale = $result['sale'];
        $job = $result['print_job'] ?? $this->unprintedJob(PrintDocumentType::Invoice, $sale);
        $creditBill = $result['credit_bill'] ?? $this->unprintedJob(PrintDocumentType::CreditBill, $sale);

        return response()->json([
            'sale' => $sale->liveSummary(),
            'created' => $result['created'],
            'print_url' => $job !== null ? route('pos.sales.invoice', ['sale' => $sale, 'job' => $job->id]) : null,
            'print_job_id' => $job?->id,
            'open_drawer' => $result['open_drawer'] && $result['created'],
            'credit_bill_url' => $creditBill !== null ? route('pos.sales.credit-bill', ['sale' => $sale, 'job' => $creditBill->id]) : null,
        ], $result['created'] ? 201 : 200);
    }

    /**
     * A retried request prints the original job if the first answer never arrived.
     */
    private function unprintedJob(PrintDocumentType $type, Sale $sale): ?PrintJob
    {
        return PrintJob::query()
            ->where('document_type', $type)
            ->where('document_id', $sale->id)
            ->where('is_copy', false)
            ->whereNull('printed_at')
            ->first();
    }

    /**
     * GET /api/pos/invoices/today: the "Last invoices" drawer of this counter.
     */
    public function today(Request $request): JsonResponse
    {
        $terminal = $this->currentTerminal->get();

        $sales = Sale::query()
            ->with(['invoicedBy', 'invoicedTerminal', 'settledBy'])
            ->withCount('items')
            ->where('invoiced_terminal_id', $terminal->id)
            ->whereNotNull('invoice_no')
            ->where('invoiced_at', '>=', today())
            ->latest('invoiced_at')
            ->latest('id')
            ->limit(100)
            ->get();

        return response()->json([
            'invoices' => $sales->map(fn (Sale $sale) => [...$sale->liveSummary(), 'lines' => $sale->items_count, 'print_count' => $sale->print_count])->all(),
        ]);
    }

    /**
     * POST /api/pos/sales/{sale}/reprint
     */
    public function reprint(Request $request, Sale $sale, ReprintInvoiceAction $reprint): JsonResponse
    {
        $this->authorize('reprint', $sale);

        $job = $reprint->handle($sale, $request->user(), $this->currentTerminal->get());

        return response()->json([
            'sale' => $sale->refresh()->liveSummary(),
            'print_url' => route('pos.sales.invoice', ['sale' => $sale, 'job' => $job->id]),
            'print_job_id' => $job->id,
        ]);
    }
}
