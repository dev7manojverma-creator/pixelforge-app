<?php

namespace Tests\Feature;

use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\PixelforgeFixtures;
use Tests\TestCase;

class WebhookTest extends TestCase
{
    use PixelforgeFixtures, RefreshDatabase;

    private const PROVIDER_COST = 305_510_000; // $0.305510

    private const CHARGE = 381_887_500; // × 1.25

    private function generation(string $status = Generation::QUEUED): Generation
    {
        $user = User::factory()->create(['balance_nano' => 5_000_000_000]);

        return Generation::factory()->for($user)->accepted(status: $status)->create();
    }

    public function test_a_succeeded_event_records_the_exact_cost_and_charges_the_user(): void
    {
        $generation = $this->generation();

        $this->deliverWebhook('evt_1', 'generation.succeeded', $this->providerObject($generation, 'succeeded'))->assertNoContent();

        $generation->refresh();
        $this->assertSame(Generation::SUCCEEDED, $generation->status);
        $this->assertSame(self::PROVIDER_COST, $generation->provider_cost_nano);
        $this->assertSame(self::CHARGE, $generation->charge_nano);
        $this->assertSame(3184, $generation->reasoning_tokens);
        $this->assertNotNull($generation->image_url);
        $this->assertSame('2026-11-04 14:03:29', $generation->completed_at->toDateTimeString());
        $this->assertSame(5_000_000_000 - self::CHARGE, $generation->user->fresh()->balance_nano);
        $this->assertDatabaseHas('credit_transactions', ['generation_id' => $generation->id, 'amount_nano' => -self::CHARGE]);
    }

    public function test_the_same_event_delivered_twice_is_recorded_and_charged_once(): void
    {
        $generation = $this->generation();
        $object = $this->providerObject($generation, 'succeeded');

        $this->deliverWebhook('evt_1', 'generation.succeeded', $object)->assertNoContent();
        $this->deliverWebhook('evt_1', 'generation.succeeded', $object)->assertNoContent();

        $this->assertSame(1, WebhookEvent::count());
        $this->assertSame(1, CreditTransaction::count());
        $this->assertSame(5_000_000_000 - self::CHARGE, $generation->user->fresh()->balance_nano);
    }

    public function test_two_different_events_for_the_same_success_still_charge_once(): void
    {
        $generation = $this->generation();
        $object = $this->providerObject($generation, 'succeeded');

        $this->deliverWebhook('evt_1', 'generation.succeeded', $object)->assertNoContent();
        $this->deliverWebhook('evt_2', 'generation.succeeded', $object)->assertNoContent();

        $this->assertSame(1, CreditTransaction::count());
        $this->assertSame(5_000_000_000 - self::CHARGE, $generation->user->fresh()->balance_nano);
    }

    public function test_a_late_processing_event_never_moves_a_finished_generation_back(): void
    {
        $generation = $this->generation();

        $this->deliverWebhook('evt_2', 'generation.succeeded', $this->providerObject($generation, 'succeeded'))->assertNoContent();
        $this->deliverWebhook('evt_1', 'generation.processing', $this->providerObject($generation, 'processing'))->assertNoContent();

        $generation->refresh();
        $this->assertSame(Generation::SUCCEEDED, $generation->status);
        $this->assertSame(self::CHARGE, $generation->charge_nano);
        $this->assertNotNull($generation->image_url);
    }

    public function test_a_failed_generation_costs_nothing(): void
    {
        $generation = $this->generation(Generation::PROCESSING);

        $this->deliverWebhook('evt_1', 'generation.failed', $this->providerObject($generation, 'failed'))->assertNoContent();

        $generation->refresh();
        $this->assertSame(Generation::FAILED, $generation->status);
        $this->assertSame(0, $generation->charge_nano);
        $this->assertSame(0, $generation->provider_cost_nano);
        $this->assertSame('internal_error', $generation->error_code);
        $this->assertSame(0, CreditTransaction::count());
        $this->assertSame(5_000_000_000, $generation->user->fresh()->balance_nano);
    }

    public function test_a_final_state_is_never_overwritten_by_another_final_state(): void
    {
        $generation = $this->generation();

        $this->deliverWebhook('evt_1', 'generation.failed', $this->providerObject($generation, 'failed'))->assertNoContent();
        $this->deliverWebhook('evt_2', 'generation.succeeded', $this->providerObject($generation, 'succeeded'))->assertNoContent();

        $this->assertSame(Generation::FAILED, $generation->fresh()->status);
        $this->assertSame(0, CreditTransaction::count());
    }

    public function test_a_webhook_that_beats_the_create_response_is_matched_by_metadata(): void
    {
        $user = User::factory()->create(['balance_nano' => 5_000_000_000]);
        $generation = Generation::factory()->for($user)->create(); // still pending, no provider id yet

        $object = $this->providerObject($generation, 'processing', ['id' => 'gen_early']);
        $this->deliverWebhook('evt_1', 'generation.processing', $object)->assertNoContent();

        $generation->refresh();
        $this->assertSame('gen_early', $generation->provider_id);
        $this->assertSame(Generation::PROCESSING, $generation->status);
    }

    public function test_a_generation_is_billed_at_the_rates_fixed_when_it_was_created(): void
    {
        $generation = $this->generation();
        $doubled = array_map(fn ($rate) => (string) ($rate * 2), $generation->rates);

        $this->deliverWebhook('evt_1', 'generation.succeeded', $this->providerObject($generation, 'succeeded', ['rates' => $doubled]));

        $this->assertSame(self::PROVIDER_COST * 2, $generation->fresh()->provider_cost_nano);
    }

    public function test_an_invalid_signature_is_rejected_and_nothing_is_recorded(): void
    {
        $generation = $this->generation();
        $object = $this->providerObject($generation, 'succeeded');

        $this->deliverWebhook('evt_1', 'generation.succeeded', $object, secret: 'whsec_wrong')->assertStatus(400);

        $this->assertSame(0, WebhookEvent::count());
        $this->assertSame(Generation::QUEUED, $generation->fresh()->status);
    }

    public function test_an_old_timestamp_is_rejected_as_a_replay(): void
    {
        $generation = $this->generation();
        $object = $this->providerObject($generation, 'succeeded');

        $this->deliverWebhook('evt_1', 'generation.succeeded', $object, timestamp: time() - 301)->assertStatus(400);

        $this->assertSame(Generation::QUEUED, $generation->fresh()->status);
    }

    public function test_a_missing_signature_is_rejected(): void
    {
        config(['pixelforge.webhook_secret' => $this->webhookSecret]);

        $this->postJson('/webhooks/pixelforge', ['id' => 'evt_1', 'type' => 'ping'])->assertStatus(400);
    }

    public function test_ping_events_are_acknowledged(): void
    {
        $this->deliverWebhook('evt_ping', 'ping', [])->assertNoContent();

        $this->assertSame(0, WebhookEvent::count());
    }

    public function test_events_for_unknown_generations_are_acknowledged_without_side_effects(): void
    {
        $generation = Generation::factory()->make(['provider_id' => 'gen_someone_else']);
        $generation->id = 999;

        $this->deliverWebhook('evt_1', 'generation.succeeded', $this->providerObject($generation, 'succeeded'))->assertNoContent();

        $this->assertSame(0, CreditTransaction::count());
    }
}
