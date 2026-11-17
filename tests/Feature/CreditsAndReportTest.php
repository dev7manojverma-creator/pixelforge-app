<?php

namespace Tests\Feature;

use App\Models\Generation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class CreditsAndReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_buying_credits_adds_them_to_the_balance(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/credits', ['amount' => 10])->assertSessionHasNoErrors();

        $this->assertSame(10_000_000_000, $user->fresh()->balance_nano);
        $this->assertDatabaseHas('credit_transactions', ['user_id' => $user->id, 'type' => 'purchase', 'amount_nano' => 10_000_000_000, 'balance_after_nano' => 10_000_000_000]);
    }

    public function test_only_the_offered_packs_can_be_bought(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/credits', ['amount' => 1_000_000])->assertSessionHasErrors('amount');
        $this->actingAs($user)->post('/credits', ['amount' => -5])->assertSessionHasErrors('amount');

        $this->assertSame(0, $user->fresh()->balance_nano);
    }

    public function test_the_report_is_for_operators_only(): void
    {
        config(['pixelforge.admin_emails' => ['ops@example.com']]);

        $this->actingAs(User::factory()->create())->get('/admin/report')->assertForbidden();
        Http::fake(['*' => Http::response([], 500)]);
        $this->actingAs(User::factory()->create(['email' => 'ops@example.com']))->get('/admin/report')->assertOk();
    }

    public function test_the_report_totals_spend_cost_and_profit_per_day_and_checks_them_against_pixelforge(): void
    {
        config(['pixelforge.admin_emails' => ['ops@example.com']]);
        $this->travelTo('2026-11-05 12:00:00');

        $admin = User::factory()->create(['email' => 'ops@example.com']);
        $alice = User::factory()->create();
        $succeeded = fn (User $user, string $id, string $completedAt, int $cost) => Generation::factory()->for($user)
            ->accepted($id, Generation::SUCCEEDED)
            ->create(['completed_at' => $completedAt, 'provider_cost_nano' => $cost, 'charge_nano' => intdiv($cost * 5, 4)]);

        $succeeded($alice, 'gen_1', '2026-11-04 23:59:59', 100_000_000);
        $succeeded($alice, 'gen_2', '2026-11-05 00:00:00', 200_000_000);
        $succeeded($admin, 'gen_3', '2026-11-05 08:00:00', 40_000_000);
        // Failed generations are free and must not appear.
        Generation::factory()->for($alice)->accepted('gen_4', Generation::FAILED)->create(['completed_at' => '2026-11-05 09:00:00', 'provider_cost_nano' => 0, 'charge_nano' => 0]);

        Http::fake(['*/v1/usage*' => Http::response([
            'data' => [
                ['date' => '2026-11-04', 'cost_usd' => '0.100000000'],
                ['date' => '2026-11-05', 'cost_usd' => '0.250000000'], // we recorded 0.24: a discrepancy
            ],
            'total' => ['cost_usd' => '0.350000000'],
        ])]);

        $this->actingAs($admin)->get('/admin/report?days=7')->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Admin/Report')
            ->where('totals.generations', 3)
            ->where('totals.cost_nano', 340_000_000)
            ->where('totals.charged_nano', 425_000_000)
            ->where('totals.profit_nano', 85_000_000)
            ->where('totals.matches_pixelforge', false)
            ->where('byDay.0.date', '2026-11-05')
            ->where('byDay.0.cost_nano', 240_000_000)
            ->where('byDay.0.matches_pixelforge', false)
            ->has('byDay.0.users', 2)
            ->where('byDay.1.date', '2026-11-04')
            ->where('byDay.1.cost_nano', 100_000_000)
            ->where('byDay.1.matches_pixelforge', true)
            ->where('byDay.1.users.0.profit_nano', 25_000_000));
    }
}
