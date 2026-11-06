<?php

namespace App\Http\Controllers\Pos;

use App\Domain\CashDrawer\Actions\CloseDrawerAction;
use App\Domain\CashDrawer\Actions\OpenDrawerAction;
use App\Domain\CashDrawer\Actions\RecordCashMovementAction;
use App\Domain\CashDrawer\Enums\CashMovementType;
use App\Domain\CashDrawer\Enums\DrawerCloseReason;
use App\Domain\CashDrawer\Models\DrawerSession;
use App\Domain\CashDrawer\Services\DrawerCalculator;
use App\Domain\Identity\Support\CurrentTerminal;
use App\Domain\Sales\Enums\PrintDocumentType;
use App\Domain\Sales\Enums\SaleStatus;
use App\Domain\Sales\Models\PrintJob;
use App\Domain\Sales\Models\Sale;
use App\Domain\Sales\Support\InvoicePresenter;
use App\Domain\System\Services\Settings;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\DrawerCountRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Spatie\LaravelPdf\Facades\Pdf;
use Spatie\LaravelPdf\PdfBuilder;

/**
 * The cash drawer of this terminal (the main cashier, or a counter that takes payment):
 * open with a float, pay in / out / safe drop, end-of-day count with the Z report.
 *
 * A counter cashier's count is blind: they do not see what the drawer should hold until
 * they have counted it (the Z report shows it then).
 */
class DrawerController extends Controller
{
    public function __construct(
        private readonly CurrentTerminal $currentTerminal,
        private readonly DrawerCalculator $calculator,
    ) {}

    /**
     * GET /pos/drawer: the open session (summary, movements) or a link to open one.
     */
    public function show(Request $request): View
    {
        $terminal = $this->currentTerminal->get();
        $session = DrawerSession::query()->where('terminal_id', $terminal->id)->open()->with(['holder', 'cashMovements.user'])->first();
        $user = $request->user();
        $canRun = $terminal->canRunDrawer($user);

        abort_unless($session === null || $session->holder_user_id === $user->id || $canRun || $user->canAny(['drawer.handover', 'drawer.manage']), 403);

        $showFigures = $terminal->isMainCashier() || $user->canAny(['drawer.handover', 'drawer.manage', 'reports.sales']);

        return view('pos.drawer.show', [
            'terminal' => $terminal,
            'session' => $session,
            'summary' => $session !== null ? $this->calculator->summary($session) : null,
            'isHolder' => $session?->holder_user_id === $user->id,
            'canRun' => $canRun,
            'showFigures' => $showFigures,
            'movementTypes' => $this->movementTypes($request),
            'recent' => DrawerSession::query()
                ->where('terminal_id', $terminal->id)
                ->whereNotNull('closed_at')
                ->when(! $showFigures, fn ($query) => $query->where('holder_user_id', $user->id))
                ->with('holder')
                ->latest('closed_at')
                ->limit(5)
                ->get(),
        ]);
    }

    public function create(Request $request, OpenDrawerAction $open): View|RedirectResponse
    {
        $this->authorizeDrawer($request);
        $terminal = $this->currentTerminal->get();

        if (DrawerSession::query()->where('terminal_id', $terminal->id)->open()->exists()) {
            return redirect()->route('pos.drawer.show');
        }

        $previous = $open->waitingHandover($terminal);

        return view('pos.drawer.open', [
            'terminal' => $terminal,
            'previous' => $previous?->load('holder'),
            'denominations' => DrawerCalculator::DENOMINATIONS,
            'count' => $previous->denominations ?? [],
        ]);
    }

    public function store(DrawerCountRequest $request, OpenDrawerAction $open): RedirectResponse
    {
        $this->authorizeDrawer($request);

        $terminal = $this->currentTerminal->get();
        $session = $open->handle($terminal, $request->user(), $request->denominations());

        return redirect()->route($terminal->isMainCashier() ? 'pos.cashier' : 'pos.counter')->with('success', 'Drawer opened with Rs. '.number_format((float) $session->opening_float, 2).'.');
    }

    /**
     * POST /pos/drawer/movements (cashier): pay in, pay out, safe drop.
     */
    public function movement(Request $request, RecordCashMovementAction $record): RedirectResponse
    {
        $this->authorizeDrawer($request);

        $validated = $request->validate([
            'type' => ['required', Rule::in(array_keys($this->movementTypes($request)))],
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $movement = $record->handle(
            $request->attributes->get('drawerSession'),
            $request->user(),
            CashMovementType::from($validated['type']),
            (string) $validated['amount'],
            $validated['reason'],
        );

        return redirect()->route('pos.drawer.show')->with('success', "{$movement->type->label()} of Rs. ".number_format((float) $movement->amount, 2).' recorded.');
    }

    /**
     * GET /pos/drawer/close: end-of-day count. The expected amount is shown only after
     * counting (on the Z report), so the count is blind.
     */
    public function closeForm(Request $request): View|RedirectResponse
    {
        $session = $this->ownOpenSession($request);
        $terminal = $this->currentTerminal->get();

        return view('pos.drawer.close', [
            'terminal' => $terminal,
            'session' => $session->load('holder'),
            'denominations' => DrawerCalculator::DENOMINATIONS,
            'waiting' => Sale::query()
                ->with('invoicedTerminal')
                ->where('status', SaleStatus::Invoiced)
                ->when(! $terminal->isMainCashier(), fn ($query) => $query->where('invoiced_terminal_id', $terminal->id))
                ->orderBy('invoice_no')
                ->get(),
        ]);
    }

    public function close(DrawerCountRequest $request, CloseDrawerAction $close): RedirectResponse
    {
        $session = $this->ownOpenSession($request);
        $validated = $request->validate([
            'note' => ['nullable', 'string', 'max:255'],
            'shift_change' => ['nullable', 'boolean'],
        ]);

        // A counter's shift change: the next cashier opens with this count, on the same day's Z report.
        $shiftChange = (bool) ($validated['shift_change'] ?? false) && ! $this->currentTerminal->get()->isMainCashier();
        $reason = $shiftChange ? DrawerCloseReason::Handover : DrawerCloseReason::EndOfDay;

        $closed = $close->handle($session, $request->user(), $request->denominations(), $reason, $validated['note'] ?? null);

        return redirect()->route('pos.drawer.report', ['drawerSession' => $closed, 'print' => 1])
            ->with('success', ($shiftChange ? 'Drawer closed for the next cashier.' : 'Day closed.').' Variance Rs. '.number_format((float) $closed->variance, 2).'.');
    }

    /**
     * GET /pos/drawer/sessions/{session}/report[?print=1]: Z report (end of day), handover
     * slip, or X report of an open drawer, on the 80 mm printer.
     */
    public function report(Request $request, DrawerSession $drawerSession, InvoicePresenter $presenter, Settings $settings): View
    {
        $this->authorize('view', $drawerSession);

        $kind = $this->kind($drawerSession);
        $terminal = $this->currentTerminal->get();
        $job = null;

        if ($request->boolean('print') && $terminal !== null) {
            $job = PrintJob::record($kind === 'handover' ? PrintDocumentType::Handover : PrintDocumentType::ZReport, $drawerSession->id, $terminal->loadMissing('printer'), $request->user()->id);
        }

        return view('print.drawer-report', [
            'kind' => $kind,
            'report' => $this->calculator->zReport($drawerSession, wholeDay: $kind === 'z'),
            'language' => $presenter->language($terminal, $request->query('lang')),
            'shop' => $settings->group('shop'),
            'autoprint' => $job !== null,
            'printJobId' => $job?->id,
            'paperWidth' => $terminal->printer->paper_width_mm ?? 80,
        ]);
    }

    public function reportPdf(Request $request, DrawerSession $drawerSession, InvoicePresenter $presenter, Settings $settings): PdfBuilder
    {
        $this->authorize('view', $drawerSession);

        $kind = $this->kind($drawerSession);

        return Pdf::view('print.drawer-report-a4', [
            'kind' => $kind,
            'report' => $this->calculator->zReport($drawerSession, wholeDay: $kind === 'z'),
            'language' => $presenter->language(null, $request->query('lang')),
            'shop' => $settings->group('shop'),
        ])->format('a4')->name(($kind === 'z' ? 'z-report' : 'drawer').'-'.$drawerSession->opened_at->format('Y-m-d').'-'.$drawerSession->id.'.pdf');
    }

    private function kind(DrawerSession $session): string
    {
        return match (true) {
            $session->isOpen() => 'x',
            $session->close_reason === DrawerCloseReason::Handover => 'handover',
            default => 'z',
        };
    }

    private function authorizeDrawer(Request $request): void
    {
        abort_unless($this->currentTerminal->get()->canRunDrawer($request->user()), 403, 'You do not hold cashier authority.');
    }

    /**
     * What this user may record on this drawer. A pay out is cash the owner takes for
     * themselves, so at a counter it stays with cashier authority.
     *
     * @return array<string, string>
     */
    private function movementTypes(Request $request): array
    {
        $types = CashMovementType::drawerOptions();

        if (! $this->currentTerminal->get()->isMainCashier() && ! $request->user()->can('drawer.manage')) {
            unset($types[CashMovementType::PayOut->value]);
        }

        return $types;
    }

    private function ownOpenSession(Request $request): DrawerSession
    {
        $session = DrawerSession::query()->where('terminal_id', $this->currentTerminal->get()->id)->open()->first();

        abort_if($session === null, 404, 'No drawer is open on this terminal.');
        abort_unless($session->holder_user_id === $request->user()->id, 403, 'Only the drawer holder can close the day.');
        abort_unless($this->currentTerminal->get()->canRunDrawer($request->user()), 403, 'Cashier authority ended: use "Count and hand back" instead.');

        return $session;
    }
}
