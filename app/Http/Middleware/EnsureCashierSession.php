<?php

namespace App\Http\Middleware;

use App\Domain\CashDrawer\Models\DrawerSession;
use App\Domain\Identity\Support\CurrentTerminal;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Taking money: only on a registered terminal with its own cash drawer (the main machine,
 * or a counter that takes payment), only for the holder of its open drawer session. At
 * the main machine the holder must also hold cashier authority (pos.settle, possibly
 * through a delegation).
 *
 *  - no drawer open        → "Open drawer" page (or 403 for someone who may not open it)
 *  - drawer held by others → 403
 *  - authority revoked or expired (main machine) → "count the drawer" page
 *
 * The open session is available as $request->attributes->get('drawerSession').
 */
class EnsureCashierSession
{
    public function __construct(private CurrentTerminal $currentTerminal) {}

    public function handle(Request $request, Closure $next): Response
    {
        $terminal = $this->currentTerminal->get();
        $user = $request->user();

        if ($terminal === null) {
            return $request->expectsJson()
                ? response()->json(['message' => 'This device is not a registered terminal.'], 403)
                : redirect()->route('terminal.unregistered');
        }

        if (! $terminal->hasOwnDrawer()) {
            abort(403, $terminal->displayName().' does not take payment. Print the bill and settle it at the main cashier.');
        }

        $session = DrawerSession::query()->where('terminal_id', $terminal->id)->open()->with('holder')->first();

        if ($session === null) {
            if (! $terminal->canRunDrawer($user)) {
                abort(403, 'You do not hold cashier authority.');
            }

            return $this->redirectTo($request, route('pos.drawer.open'), 'Open the cash drawer first.');
        }

        if ($session->holder_user_id !== $user->id) {
            abort(403, "The drawer is held by {$session->holder->name}.");
        }

        if ($terminal->isMainCashier() && ! $user->can('pos.settle')) {
            return $this->redirectTo($request, route('pos.authority-ended'), 'Cashier authority revoked or expired. Count the drawer.');
        }

        $request->attributes->set('drawerSession', $session);

        return $next($request);
    }

    private function redirectTo(Request $request, string $url, string $message): Response
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $message, 'redirect' => $url], 409);
        }

        return redirect()->to($url);
    }
}
