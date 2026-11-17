<?php

namespace App\Http\Controllers;

use App\Services\Credits;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CreditController extends Controller
{
    /** Whole-dollar packs a user can "buy". No payment is taken. */
    public const PACKS = [5, 10, 25];

    public function store(Request $request, Credits $credits): RedirectResponse
    {
        $data = $request->validate(['amount' => ['required', 'integer', Rule::in(self::PACKS)]]);

        $credits->purchase($request->user(), (int) $data['amount'] * Money::NANO_PER_DOLLAR);

        return back()->with('success', "\${$data['amount']}.00 of credits added.");
    }
}
