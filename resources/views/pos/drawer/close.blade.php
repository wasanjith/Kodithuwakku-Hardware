@extends('layouts.pos')

@php
    $atMain = $terminal->isMainCashier();
@endphp

@section('title', $atMain ? 'Close the day' : 'Count and close the drawer')

@section('content')
    <div class="mx-auto max-w-3xl p-4">
        <x-ui.page-header :title="$atMain ? 'Close the day' : 'Count and close the drawer'" :description="$terminal->displayName().' · drawer of '.$session->holder->name.' since '.$session->opened_at->format('H:i').'. Count the cash; the report shows the expected amount and the variance.'" />

        @if ($waiting->isNotEmpty())
            @if ($atMain)
                <x-ui.alert type="error" class="mb-4" title="Invoices are still waiting for settlement">
                    <p>Settle or void them on the cashier screen before closing the day:</p>
                    <ul class="mt-2 list-inside list-disc">
                        @foreach ($waiting as $sale)
                            <li>{{ $sale->invoice_no }} · {{ $sale->invoicedTerminal->displayName() }} · Rs. {{ number_format((float) $sale->total, 2) }}</li>
                        @endforeach
                    </ul>
                    <x-ui.button class="mt-3" size="sm" :href="route('pos.cashier')">Go to the cashier screen</x-ui.button>
                </x-ui.alert>
            @else
                <x-ui.alert type="info" class="mb-4" title="Bills from this counter are still open">
                    <p>Their money is not in this drawer. The main cashier settles or voids them:</p>
                    <ul class="mt-2 list-inside list-disc">
                        @foreach ($waiting as $sale)
                            <li>{{ $sale->invoice_no }} · Rs. {{ number_format((float) $sale->total, 2) }}</li>
                        @endforeach
                    </ul>
                </x-ui.alert>
            @endif
        @endif

        <form method="POST" action="{{ route('pos.drawer.close.store') }}" class="space-y-4">
            @csrf
            <x-pos.denomination-count :denominations="$denominations" title="Cash in the drawer" />
            <x-ui.input name="note" label="Note (optional)" maxlength="255" />
            @unless ($atMain)
                <x-ui.checkbox name="shift_change" label="Shift change: another cashier takes over today" hint="Prints a handover slip instead of the Z report. The next cashier's day report includes this count." />
            @endunless

            <div class="flex justify-end gap-2">
                <x-ui.button variant="secondary" :href="route('pos.drawer.show')">Cancel</x-ui.button>
                <x-ui.button type="submit" size="lg" :disabled="$atMain && $waiting->isNotEmpty()">{{ $atMain ? 'Close day and print Z report' : 'Close the drawer and print' }}</x-ui.button>
            </div>
        </form>
    </div>
@endsection
