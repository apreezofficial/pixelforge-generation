<?php

namespace App\Services;

use App\Models\Charge;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Credit balance, derived from the ledgers so it cannot drift:
 *
 *   available = purchases - charges - holds
 *
 * A hold is the upper-bound estimate for a generation still in flight. Holding the
 * maximum means concurrent generations can never overspend, and the hold disappears
 * on its own when a generation succeeds (replaced by the exact charge) or fails (free).
 */
class Balance
{
    private const IN_FLIGHT = [Generation::SUBMITTING, Generation::QUEUED, Generation::PROCESSING];

    public function available(User $user): int
    {
        $purchased = (int) DB::table('credit_purchases')->where('user_id', $user->id)->sum('amount_nano');
        $charged = (int) Charge::query()->where('user_id', $user->id)->sum('amount_nano');
        $held = (int) Generation::query()
            ->where('user_id', $user->id)
            ->whereIn('status', self::IN_FLIGHT)
            ->sum('estimated_nano');

        return $purchased - $charged - $held;
    }

    public function purchase(User $user, int $amountNano): void
    {
        DB::table('credit_purchases')->insert([
            'user_id' => $user->id,
            'amount_nano' => $amountNano,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
