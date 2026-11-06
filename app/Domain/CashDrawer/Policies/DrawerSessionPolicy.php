<?php

namespace App\Domain\CashDrawer\Policies;

use App\Domain\CashDrawer\Models\DrawerSession;
use App\Models\User;

class DrawerSessionPolicy
{
    /**
     * Drawer and handover history.
     */
    public function viewAny(User $user): bool
    {
        return $user->canAny(['drawer.handover', 'drawer.manage', 'reports.sales']);
    }

    /**
     * The holder sees their own report. A counter cashier's count is blind, so the figures
     * of a drawer still open (X report) stay closed to them until they have counted.
     */
    public function view(User $user, DrawerSession $session): bool
    {
        if ($this->viewAny($user)) {
            return true;
        }

        return $session->holder_user_id === $user->id && (! $session->isOpen() || $user->can('pos.settle'));
    }
}
