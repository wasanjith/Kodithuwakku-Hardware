<?php

namespace App\Http\Requests\Admin;

use App\Domain\CashDrawer\Models\DrawerSession;
use App\Domain\Identity\Enums\TerminalType;
use App\Domain\Identity\Models\Terminal;
use App\Domain\Identity\Support\CurrentTerminal;
use App\Domain\Sales\Enums\SaleStatus;
use App\Domain\Sales\Models\Sale;
use App\Domain\Sales\Services\LiveCartStore;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveTerminalRequest extends FormRequest
{
    public function authorize(): bool
    {
        $terminal = $this->route('terminal');

        return $terminal instanceof Terminal
            ? $this->user()->can('update', $terminal)
            : $this->user()->can('create', Terminal::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $terminal = $this->route('terminal');

        return [
            'name' => ['required', 'string', 'max:60'],
            'code' => ['required', 'string', 'max:10', 'alpha_dash:ascii', Rule::unique('terminals', 'code')->ignore($terminal?->id)],
            'type' => ['required', Rule::enum(TerminalType::class)],
            'counter_no' => [
                Rule::requiredIf($this->input('type') === TerminalType::Counter->value),
                'nullable', 'integer', 'between:1,9',
                Rule::unique('terminals', 'counter_no')->ignore($terminal?->id),
            ],
            'takes_payment' => ['boolean'],
            'receipt_language' => ['nullable', 'in:si,en,si+en'],
            'is_active' => ['boolean'],
        ];
    }

    /**
     * Who takes payment changes from the main machine only, and only while the counter has
     * no open bill and no open drawer.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $terminal = $this->route('terminal');
            $current = $terminal instanceof Terminal && $terminal->takes_payment;

            if ((bool) $this->input('takes_payment') === $current) {
                return;
            }

            if (! app(CurrentTerminal::class)->get()?->isMainCashier()) {
                $validator->errors()->add('takes_payment', 'Change this from the main machine.');

                return;
            }

            if (! $terminal instanceof Terminal) {
                return;
            }

            if (DrawerSession::query()->where('terminal_id', $terminal->id)->open()->exists()) {
                $validator->errors()->add('takes_payment', 'This counter has an open drawer. Count and close it first.');
            }

            $waiting = Sale::query()->where('invoiced_terminal_id', $terminal->id)->where('status', SaleStatus::Invoiced)->orderBy('invoice_no')->pluck('invoice_no');

            if ($waiting->isNotEmpty()) {
                $validator->errors()->add('takes_payment', 'This counter has open bills. Settle or void them first: '.$waiting->implode(', ').'.');
            }

            if ((app(LiveCartStore::class)->get($terminal->id)['lines'] ?? []) !== []) {
                $validator->errors()->add('takes_payment', 'This counter has a bill in progress. Print, hold or clear it first.');
            }
        }];
    }

    protected function prepareForValidation(): void
    {
        $terminal = $this->route('terminal');
        $isCounter = $this->input('type') === TerminalType::Counter->value;
        // The form leaves the tick out away from the main machine: the setting stays as it is.
        $current = $terminal instanceof Terminal && $terminal->takes_payment;

        $this->merge([
            'code' => mb_strtoupper((string) $this->input('code')),
            'is_active' => $this->boolean('is_active'),
            'counter_no' => $isCounter ? $this->input('counter_no') : null,
            'takes_payment' => $isCounter && ($this->has('takes_payment') ? $this->boolean('takes_payment') : $current),
            'receipt_language' => $this->input('receipt_language') ?: null,
        ]);
    }
}
