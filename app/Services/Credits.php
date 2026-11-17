<?php

namespace App\Services;

use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** The user's credit balance. Credits are US dollars, stored in nanodollars. */
class Credits
{
    /** Credits held back for generations that are still running (their highest possible charge). */
    public function reservedNano(User $user): int
    {
        return (int) $user->generations()->inFlight()->sum('max_charge_nano');
    }

    /** What the user can still spend: balance minus what running generations might cost. */
    public function availableNano(User $user): int
    {
        return $user->balance_nano - $this->reservedNano($user);
    }

    /** A pretend purchase: no payment is taken, the credits are simply added. */
    public function purchase(User $user, int $amountNano): void
    {
        DB::transaction(function () use ($user, $amountNano) {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $locked->balance_nano += $amountNano;
            $locked->save();

            CreditTransaction::create([
                'user_id' => $locked->id,
                'type' => CreditTransaction::PURCHASE,
                'amount_nano' => $amountNano,
                'balance_after_nano' => $locked->balance_nano,
            ]);
        });
    }

    /**
     * Charge a succeeded generation. Must run inside the transaction that
     * marks it succeeded; the unique index on generation_id makes a second
     * charge for the same generation impossible.
     */
    public function chargeFor(Generation $generation, int $chargeNano): void
    {
        $user = User::whereKey($generation->user_id)->lockForUpdate()->firstOrFail();
        $user->balance_nano -= $chargeNano;
        $user->save();

        CreditTransaction::create([
            'user_id' => $user->id,
            'type' => CreditTransaction::CHARGE,
            'amount_nano' => -$chargeNano,
            'balance_after_nano' => $user->balance_nano,
            'generation_id' => $generation->id,
        ]);
    }
}
