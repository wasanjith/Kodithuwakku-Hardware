<?php

namespace App\Domain\Sales\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends a real-time event without letting a stopped Reverb server break the sale.
 * Screens fall back to polling (Live Billing snapshot, counter inbox) when it is down.
 *
 * Inside a transaction the event waits for the commit, so a sale that is rolled back
 * never shows on a screen.
 */
final class LiveBroadcast
{
    public static function send(object $event): void
    {
        DB::afterCommit(function () use ($event): void {
            try {
                broadcast($event);
            } catch (Throwable $exception) {
                Log::warning('Real-time broadcast failed; screens will catch up by polling.', [
                    'event' => $event::class,
                    'error' => $exception->getMessage(),
                ]);
            }
        });
    }
}
