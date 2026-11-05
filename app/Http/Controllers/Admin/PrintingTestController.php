<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Identity\Models\Printer;
use App\Domain\Identity\Support\CurrentTerminal;
use App\Domain\System\Services\Settings;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Spatie\LaravelPdf\Facades\Pdf;
use Spatie\LaravelPdf\PdfBuilder;

/**
 * Phase 0.4 printing spike: proves Sinhala invoices print on the real thermal
 * printer model and that the cash drawer opens through QZ Tray.
 */
class PrintingTestController extends Controller
{
    public function index(CurrentTerminal $currentTerminal): View
    {
        $this->authorize('viewAny', Printer::class);

        $terminal = $currentTerminal->get();

        return view('admin.printing-test.index', [
            'terminal' => $terminal,
            'printer' => $terminal?->printer,
        ]);
    }

    /**
     * 80 mm thermal invoice with Sinhala text, printed by the browser as an image.
     */
    public function thermal(Request $request, Settings $settings, CurrentTerminal $currentTerminal): View
    {
        $this->authorize('viewAny', Printer::class);

        $printer = $currentTerminal->get()?->printer;

        if ($request->boolean('autoprint') && $printer !== null) {
            $printer->forceFill(['last_test_at' => now()])->save();
        }

        return view('print.invoice-sample', [
            ...$this->sampleInvoice($request, $settings),
            'autoprint' => $request->boolean('autoprint'),
            'paperWidth' => $printer->paper_width_mm ?? 80,
        ]);
    }

    /**
     * The same invoice as an A4 PDF, rendered by headless Chrome.
     */
    public function pdf(Request $request, Settings $settings): PdfBuilder
    {
        $this->authorize('viewAny', Printer::class);

        return Pdf::view('print.invoice-sample-a4', $this->sampleInvoice($request, $settings))
            ->format('a4')
            ->name('sample-invoice.pdf');
    }

    /**
     * @return array<string, mixed>
     */
    private function sampleInvoice(Request $request, Settings $settings): array
    {
        $language = in_array($request->query('lang'), ['si', 'en', 'si+en'], true)
            ? $request->query('lang')
            : $settings->get('receipt.language', 'en');

        $items = [
            ['name' => 'Cement 50kg bag', 'name_si' => 'සිමෙන්ති 50kg', 'qty' => 10, 'unit' => 'bag', 'unit_si' => 'බෑග්', 'price' => 2350.00],
            ['name' => 'PVC Pipe 1/2" 20ft', 'name_si' => 'PVC බට 1/2"', 'qty' => 6, 'unit' => 'length', 'unit_si' => 'දිග', 'price' => 750.00],
            ['name' => 'Wire 2.5mm (per m)', 'name_si' => 'වයර් 2.5mm', 'qty' => 12.5, 'unit' => 'metre', 'unit_si' => 'මීටර්', 'price' => 210.00],
            ['name' => 'Emulsion Paint White 4L', 'name_si' => 'තීන්ත 4L', 'qty' => 1, 'unit' => 'tin', 'unit_si' => 'ටින්', 'price' => 8200.00],
            ['name' => 'Claw Hammer 16oz', 'name_si' => 'මිටිය', 'qty' => 1, 'unit' => 'piece', 'unit_si' => 'කෑලි', 'price' => 1950.00],
        ];

        foreach ($items as &$item) {
            $item['total'] = round($item['qty'] * $item['price'], 2);
        }
        unset($item);

        $subtotal = array_sum(array_column($items, 'total'));
        $discount = 250.00;
        $total = $subtotal - $discount;
        $tendered = ceil($total / 1000) * 1000;

        return [
            'language' => $language,
            'shop' => $settings->group('shop'),
            'receipt' => $settings->group('receipt'),
            'invoice' => [
                'number' => 'INV-'.now()->format('Y').'-000000',
                'date' => now(),
                'counter' => 2,
                'staff' => $request->user()->name,
                'items' => $items,
                'subtotal' => $subtotal,
                'discount' => $discount,
                'total' => $total,
                'tendered' => $tendered,
                'balance' => $tendered - $total,
                'is_sample' => true,
            ],
        ];
    }
}
