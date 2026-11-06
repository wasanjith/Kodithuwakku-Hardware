<?php

namespace App\Domain\Identity\Models;

use App\Domain\Identity\Enums\TerminalType;
use App\Domain\Identity\Policies\TerminalPolicy;
use App\Models\User;
use Database\Factories\TerminalFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A shop PC: the main cashier or one of the counters.
 *
 * @property int $id
 * @property string $name
 * @property string $code
 * @property TerminalType $type
 * @property int|null $counter_no
 * @property bool $takes_payment
 * @property string|null $device_token_hash
 * @property Carbon|null $registered_at
 * @property int|null $registered_by
 * @property string|null $receipt_language
 * @property bool $is_active
 * @property Carbon|null $last_seen_at
 */
#[Fillable(['name', 'code', 'type', 'counter_no', 'takes_payment', 'receipt_language', 'is_active'])]
#[Hidden(['device_token_hash'])]
#[UseFactory(TerminalFactory::class)]
#[UsePolicy(TerminalPolicy::class)]
class Terminal extends Model
{
    /** @use HasFactory<TerminalFactory> */
    use HasFactory, LogsActivity;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => TerminalType::class,
            'counter_no' => 'integer',
            'takes_payment' => 'boolean',
            'is_active' => 'boolean',
            'registered_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'code', 'type', 'counter_no', 'takes_payment', 'receipt_language', 'is_active', 'registered_at'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /**
     * @return HasOne<Printer, $this>
     */
    public function printer(): HasOne
    {
        return $this->hasOne(Printer::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function registeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by');
    }

    /**
     * @param  Builder<Terminal>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function isMainCashier(): bool
    {
        return $this->type === TerminalType::MainCashier;
    }

    public function isCounter(): bool
    {
        return $this->type === TerminalType::Counter;
    }

    /**
     * A counter with its own cash drawer: it takes the payment for its own bills.
     */
    public function takesPayment(): bool
    {
        return $this->isCounter() && $this->takes_payment;
    }

    /**
     * Terminals with a cash drawer of their own: the main machine and the counters that take payment.
     */
    public function hasOwnDrawer(): bool
    {
        return $this->isMainCashier() || $this->takesPayment();
    }

    /**
     * May this user open, count and close the drawer here? At the main machine that takes
     * cashier authority; at a counter that takes payment, whoever may sell at that counter.
     */
    public function canRunDrawer(User $user): bool
    {
        if ($this->isMainCashier()) {
            return $user->can('drawer.manage');
        }

        return $this->takesPayment() && $user->can('pos.sell');
    }

    public function isRegistered(): bool
    {
        return $this->device_token_hash !== null;
    }

    public function displayName(): string
    {
        return $this->isCounter() ? "Counter {$this->counter_no}" : $this->name;
    }
}
