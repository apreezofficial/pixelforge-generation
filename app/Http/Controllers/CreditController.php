<?php

namespace App\Http\Controllers;

use App\Services\Balance;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CreditController extends Controller
{
    /** Fixed packs in whole dollars. This is a fake purchase: nothing is charged. */
    public const PACKS = [5, 10, 25];

    public function show(): Response
    {
        return Inertia::render('Credits/Show', ['packs' => self::PACKS]);
    }

    public function store(Request $request, Balance $balance): RedirectResponse
    {
        $data = $request->validate(['amount' => ['required', 'integer', 'in:'.implode(',', self::PACKS)]]);

        $balance->purchase($request->user(), $data['amount'] * Money::NANO_PER_USD);

        return redirect()->route('credits.show')->with('status', "Added \${$data['amount']} in credits.");
    }
}
