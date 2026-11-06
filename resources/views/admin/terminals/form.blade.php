@extends('layouts.app')

@php $isNew = ! $terminal->exists; @endphp

@section('title', $isNew ? 'Add terminal' : 'Edit '.$terminal->name)

@section('content')
    <x-ui.page-header :title="$isNew ? 'Add terminal' : 'Edit terminal'" />

    <form
        method="POST"
        action="{{ $isNew ? route('admin.terminals.store') : route('admin.terminals.update', $terminal) }}"
        x-data="{ type: @js(old('type', $terminal->type?->value)) }"
    >
        @csrf
        @unless ($isNew)
            @method('PUT')
        @endunless

        <x-ui.card>
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.input name="name" label="Name" :value="$terminal->name" hint="e.g. Counter 1, Main Cashier" required />
                <x-ui.input name="code" label="Code" :value="$terminal->code" hint="Short code, e.g. C1, MAIN" required />
                <x-ui.select
                    name="type"
                    label="Type"
                    :options="collect($types)->mapWithKeys(fn ($type) => [$type->value => $type->label()])->all()"
                    :value="$terminal->type"
                    x-model="type"
                    required
                />
                <div x-show="type === 'counter'">
                    <x-ui.input name="counter_no" label="Counter number" type="number" min="1" max="9" :value="$terminal->counter_no" />
                </div>
                <div x-show="type === 'counter'" class="sm:col-span-2">
                    @if ($atMain)
                        <x-ui.checkbox
                            name="takes_payment"
                            label="This counter has a cash drawer and takes payment"
                            :checked="$terminal->takes_payment"
                            hint="On: F9 takes the payment at this counter, which has its own drawer session (opening float, count, Z report). Off: the counter prints the bill and the main machine settles it. Can only change while the counter has no open bill and no open drawer."
                        />
                    @else
                        <p class="text-sm font-medium text-gray-700">Takes payment: {{ $terminal->takes_payment ? 'Yes, it has its own cash drawer' : 'No, the main machine settles its bills' }}</p>
                        <p class="text-xs text-gray-500">Change this from the main machine.</p>
                        <x-ui.field-error name="takes_payment" />
                    @endif
                </div>
                @if (config('pos.sinhala_fields'))
                    <x-ui.select
                        name="receipt_language"
                        label="Invoice language on this terminal"
                        :options="['si' => 'Sinhala', 'en' => 'English', 'si+en' => 'Sinhala + English']"
                        :value="$terminal->receipt_language"
                        placeholder="Use shop setting"
                    />
                @endif
                <x-ui.checkbox name="is_active" label="Active" :checked="$terminal->is_active" class="sm:col-span-2" />
            </div>

            <x-slot:footer>
                <x-ui.button variant="secondary" :href="route('admin.terminals.index')">Cancel</x-ui.button>
                <x-ui.button type="submit">{{ $isNew ? 'Create terminal' : 'Save changes' }}</x-ui.button>
            </x-slot:footer>
        </x-ui.card>
    </form>
@endsection
