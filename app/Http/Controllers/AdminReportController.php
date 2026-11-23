<?php

namespace App\Http\Controllers;

use App\Models\Charge;
use App\Services\PixelforgeClient;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Operator report: what users were charged, what Pixelforge charged us, and profit,
 * per UTC day and per user. Days use the completion time, like Pixelforge's /v1/usage,
 * so provider costs reconcile day for day.
 */
class AdminReportController extends Controller
{
    public function __invoke(Request $request, PixelforgeClient $client): Response
    {
        $data = $request->validate([
            'start' => ['nullable', 'date_format:Y-m-d'],
            'end' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start'],
        ]);

        $end = Carbon::parse($data['end'] ?? now('UTC')->toDateString(), 'UTC')->startOfDay();
        $start = Carbon::parse($data['start'] ?? $end->copy()->subDays(29)->toDateString(), 'UTC')->startOfDay();

        // Pixelforge caps /v1/usage at 92 days; keep the whole report inside that window.
        if ($start->diffInDays($end) > 91) {
            $start = $end->copy()->subDays(91);
        }

        $charges = Charge::query()
            ->with('user:id,name,email')
            ->where('charged_at', '>=', $start->copy()->utc())
            ->where('charged_at', '<', $end->copy()->addDay()->utc())
            ->get(['id', 'user_id', 'amount_nano', 'provider_cost_nano', 'charged_at']);

        $byDay = [];
        $byUser = [];
        $byDayUser = [];

        foreach ($charges as $charge) {
            $day = $charge->charged_at->copy()->utc()->toDateString();
            $this->add($byDay, $day, $charge);
            $this->add($byUser, $charge->user_id, $charge);
            $this->add($byDayUser, $day.'|'.$charge->user_id, $charge);
        }

        $providerByDay = $this->providerUsage($client, $start, $end);

        $days = [];
        for ($d = $start->copy(); $d <= $end; $d->addDay()) {
            $key = $d->toDateString();
            $row = $byDay[$key] ?? ['generations' => 0, 'spend' => 0, 'cost' => 0];
            $reported = $providerByDay[$key] ?? null;
            $days[] = [
                'date' => $key,
                'generations' => $row['generations'],
                'spend' => Money::toUsdString($row['spend']),
                'cost' => Money::toUsdString($row['cost']),
                'profit' => Money::toUsdString($row['spend'] - $row['cost']),
                'pixelforge_cost' => $reported === null ? null : Money::toUsdString($reported),
                'matches' => $reported === null ? null : $reported === $row['cost'],
            ];
        }

        $users = $charges->pluck('user')->unique('id')->keyBy('id');
        $userRows = [];
        foreach ($byUser as $userId => $row) {
            $userRows[] = [
                'user' => $users[$userId]->email,
                'generations' => $row['generations'],
                'spend' => Money::toUsdString($row['spend']),
                'cost' => Money::toUsdString($row['cost']),
                'profit' => Money::toUsdString($row['spend'] - $row['cost']),
            ];
        }

        $dayUserRows = [];
        ksort($byDayUser);
        foreach ($byDayUser as $key => $row) {
            [$day, $userId] = explode('|', $key);
            $dayUserRows[] = [
                'date' => $day,
                'user' => $users[(int) $userId]->email,
                'generations' => $row['generations'],
                'spend' => Money::toUsdString($row['spend']),
                'cost' => Money::toUsdString($row['cost']),
                'profit' => Money::toUsdString($row['spend'] - $row['cost']),
            ];
        }

        $totalSpend = array_sum(array_column($byDay, 'spend'));
        $totalCost = array_sum(array_column($byDay, 'cost'));

        return Inertia::render('Admin/Report', [
            'start' => $start->toDateString(),
            'end' => $end->toDateString(),
            'days' => array_reverse($days),
            'users' => $userRows,
            'dayUsers' => array_reverse($dayUserRows),
            'totals' => [
                'generations' => array_sum(array_column($byDay, 'generations')),
                'spend' => Money::toUsdString($totalSpend),
                'cost' => Money::toUsdString($totalCost),
                'profit' => Money::toUsdString($totalSpend - $totalCost),
            ],
            'usageAvailable' => $providerByDay !== null,
        ]);
    }

    /**
     * @param  array<string|int,array{generations:int,spend:int,cost:int}>  $buckets
     */
    private function add(array &$buckets, string|int $key, Charge $charge): void
    {
        $buckets[$key] ??= ['generations' => 0, 'spend' => 0, 'cost' => 0];
        $buckets[$key]['generations']++;
        $buckets[$key]['spend'] += $charge->amount_nano;
        $buckets[$key]['cost'] += $charge->provider_cost_nano;
    }

    /**
     * Provider cost per UTC day as reported by Pixelforge for our API key, in nanodollars.
     *
     * @return array<string,int>|null null when the usage endpoint could not be reached
     */
    private function providerUsage(PixelforgeClient $client, Carbon $start, Carbon $end): ?array
    {
        try {
            $response = $client->usage($start->toDateString(), $end->toDateString());
        } catch (\Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $byDay = [];
        foreach ($response->json('data', []) as $row) {
            $byDay[$row['date']] = Money::fromUsdString($row['cost_usd']);
        }

        return $byDay;
    }
}
