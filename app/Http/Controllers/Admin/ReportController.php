<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Generation;
use App\Models\User;
use App\Services\Pixelforge\PixelforgeClient;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Operator report: what users were charged, what Pixelforge charged us, and
 * the profit, per UTC day and per user. Days follow Pixelforge's rule (a
 * generation is billed on the UTC day it completed) so our provider cost can
 * be checked line by line against GET /v1/usage.
 */
class ReportController extends Controller
{
    public function __invoke(Request $request, PixelforgeClient $client): Response
    {
        $days = min(92, max(1, $request->integer('days', 30)));
        $end = Carbon::now('UTC')->startOfDay();
        $start = $end->copy()->subDays($days - 1);

        $rows = Generation::query()
            ->where('status', Generation::SUCCEEDED)
            ->where('completed_at', '>=', $start)
            ->selectRaw('DATE(completed_at) as day, user_id, COUNT(*) as generations, SUM(charge_nano) as charged_nano, SUM(provider_cost_nano) as cost_nano')
            ->groupBy('day', 'user_id')
            ->orderByDesc('day')
            ->get();

        $users = User::whereIn('id', $rows->pluck('user_id')->unique())->get(['id', 'name', 'email'])->keyBy('id');
        $pixelforge = $this->pixelforgeUsage($client, $start, $end);

        $byDay = $rows->groupBy('day')->map(function ($dayRows, $day) use ($users, $pixelforge) {
            $cost = (int) $dayRows->sum('cost_nano');
            $reported = $pixelforge['days'][$day] ?? null;

            return [
                'date' => $day,
                'generations' => (int) $dayRows->sum('generations'),
                'charged_nano' => (int) $dayRows->sum('charged_nano'),
                'cost_nano' => $cost,
                'profit_nano' => (int) $dayRows->sum('charged_nano') - $cost,
                'pixelforge_cost_nano' => $reported,
                'matches_pixelforge' => $reported === null ? null : $reported === $cost,
                'users' => $dayRows->map(fn ($row) => [
                    'name' => $users[$row->user_id]->name ?? 'Deleted user',
                    'email' => $users[$row->user_id]->email ?? '',
                    'generations' => (int) $row->generations,
                    'charged_nano' => (int) $row->charged_nano,
                    'cost_nano' => (int) $row->cost_nano,
                    'profit_nano' => (int) $row->charged_nano - (int) $row->cost_nano,
                ])->sortByDesc('charged_nano')->values(),
            ];
        });

        // A day Pixelforge billed that we have no record of is a discrepancy too: show it.
        foreach ($pixelforge['days'] as $day => $reported) {
            if ($reported > 0 && ! $byDay->has($day)) {
                $byDay[$day] = [
                    'date' => $day, 'generations' => 0, 'charged_nano' => 0, 'cost_nano' => 0, 'profit_nano' => 0,
                    'pixelforge_cost_nano' => $reported, 'matches_pixelforge' => false, 'users' => [],
                ];
            }
        }

        $byDay = $byDay->sortByDesc('date')->values();

        $cost = (int) $rows->sum('cost_nano');

        return Inertia::render('Admin/Report', [
            'days' => $days,
            'startDate' => $start->toDateString(),
            'endDate' => $end->toDateString(),
            'byDay' => $byDay,
            'totals' => [
                'generations' => (int) $rows->sum('generations'),
                'charged_nano' => (int) $rows->sum('charged_nano'),
                'cost_nano' => $cost,
                'profit_nano' => (int) $rows->sum('charged_nano') - $cost,
                'pixelforge_cost_nano' => $pixelforge['total'],
                'matches_pixelforge' => $pixelforge['total'] === null ? null : $pixelforge['total'] === $cost,
            ],
            'pixelforgeError' => $pixelforge['error'],
        ]);
    }

    /** @return array{days: array<string, int>, total: ?int, error: ?string} */
    private function pixelforgeUsage(PixelforgeClient $client, Carbon $start, Carbon $end): array
    {
        try {
            $response = $client->usage($start->toDateString(), $end->toDateString());

            if ($response->successful()) {
                return [
                    'days' => collect($response->json('data'))
                        ->mapWithKeys(fn ($day) => [$day['date'] => Money::nanoFromDecimal($day['cost_usd'])])
                        ->all(),
                    'total' => Money::nanoFromDecimal($response->json('total.cost_usd')),
                    'error' => null,
                ];
            }

            $error = $response->json('error.message') ?? "HTTP {$response->status()}";
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }

        return ['days' => [], 'total' => null, 'error' => "Pixelforge's usage report could not be loaded ({$error})."];
    }
}
