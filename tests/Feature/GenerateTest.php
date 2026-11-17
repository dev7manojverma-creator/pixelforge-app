<?php

namespace Tests\Feature;

use App\Jobs\SubmitGeneration;
use App\Jobs\SyncGeneration;
use App\Models\Generation;
use App\Models\User;
use App\Services\Pixelforge\Pricing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use Tests\Support\PixelforgeFixtures;
use Tests\TestCase;

class GenerateTest extends TestCase
{
    use PixelforgeFixtures, RefreshDatabase;

    private array $valid = ['prompt' => 'A lighthouse at dawn', 'size' => '1024x1024', 'quality' => 'medium', 'reference_images' => []];

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Http::fake(['*/v1/pricing' => Http::response($this->pricingDocument())]);
    }

    public function test_guests_are_sent_to_the_login_page(): void
    {
        $this->get('/generate')->assertRedirect('/login');
        $this->post('/generations', $this->valid)->assertRedirect('/login');
        $this->get('/history')->assertRedirect('/login');
    }

    public function test_submitting_saves_the_estimate_and_queues_the_request(): void
    {
        $user = User::factory()->create(['balance_nano' => 1_000_000_000]);

        $this->actingAs($user)->post('/generations', $this->valid)->assertRedirect('/history');

        $generation = $user->generations()->sole();
        $estimate = Pricing::estimate($this->pricingDocument(), $this->valid['prompt'], '1024x1024', 'medium', 0);

        $this->assertSame(Generation::PENDING, $generation->status);
        $this->assertSame($estimate->expectedChargeNano, $generation->estimated_charge_nano);
        $this->assertSame($estimate->maxChargeNano, $generation->max_charge_nano);
        $this->assertNull($generation->charge_nano);
        Queue::assertPushed(SubmitGeneration::class, fn ($job) => $job->generationId === $generation->id);
    }

    public function test_generating_is_blocked_when_the_user_cannot_afford_the_highest_possible_cost(): void
    {
        $max = Pricing::estimate($this->pricingDocument(), $this->valid['prompt'], '1024x1024', 'medium', 0)->maxChargeNano;
        $user = User::factory()->create(['balance_nano' => $max - 1]);

        $this->actingAs($user)->post('/generations', $this->valid)->assertSessionHasErrors('credits');

        $this->assertSame(0, Generation::count());
        Queue::assertNothingPushed();

        $user->forceFill(['balance_nano' => $max])->save();
        $this->actingAs($user)->post('/generations', $this->valid)->assertSessionHasNoErrors();
    }

    public function test_credits_held_by_running_generations_cannot_be_spent_twice(): void
    {
        $max = Pricing::estimate($this->pricingDocument(), $this->valid['prompt'], '1024x1024', 'medium', 0)->maxChargeNano;
        $user = User::factory()->create(['balance_nano' => $max + 1]);

        $this->actingAs($user)->post('/generations', $this->valid)->assertSessionHasNoErrors();
        $this->actingAs($user)->post('/generations', $this->valid)->assertSessionHasErrors('credits');

        $this->assertSame(1, Generation::count());
    }

    public function test_invalid_requests_are_refused_before_anything_is_created(): void
    {
        $user = User::factory()->create(['balance_nano' => 1_000_000_000]);
        $this->actingAs($user);

        $this->post('/generations', [...$this->valid, 'prompt' => ''])->assertSessionHasErrors('prompt');
        $this->post('/generations', [...$this->valid, 'prompt' => str_repeat('a', 4001)])->assertSessionHasErrors('prompt');
        $this->post('/generations', [...$this->valid, 'size' => '512x512'])->assertSessionHasErrors('size');
        $this->post('/generations', [...$this->valid, 'quality' => 'ultra'])->assertSessionHasErrors('quality');
        $this->post('/generations', [...$this->valid, 'reference_images' => ['ftp://example.com/a.png']])->assertSessionHasErrors('reference_images.0');
        $this->post('/generations', [...$this->valid, 'reference_images' => array_fill(0, 5, 'https://example.com/a.png')])->assertSessionHasErrors('reference_images');

        $this->assertSame(0, Generation::count());
    }

    public function test_nothing_is_submitted_when_prices_cannot_be_loaded(): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['*' => Http::response([], 503)]);
        $user = User::factory()->create(['balance_nano' => 1_000_000_000]);

        $this->actingAs($user)->post('/generations', $this->valid)->assertSessionHasErrors('prompt');

        $this->assertSame(0, Generation::count());
    }

    public function test_history_shows_only_the_users_own_generations_with_their_costs(): void
    {
        $user = User::factory()->create();
        Generation::factory()->for($user)->accepted('gen_1', Generation::SUCCEEDED)->create(['charge_nano' => 381_887_500]);
        Generation::factory()->for($user)->create(); // pending
        Generation::factory()->accepted('gen_other')->create(); // someone else's

        $this->actingAs($user)->get('/history')->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Generations/Index')
            ->has('generations.data', 2)
            ->where('generations.data.0.status', 'queued') // pending is shown as queued
            ->where('generations.data.1.status', 'succeeded')
            ->where('generations.data.1.charge_nano', 381_887_500));
    }

    public function test_history_polls_pixelforge_for_generations_that_have_gone_quiet(): void
    {
        $user = User::factory()->create();
        $quiet = Generation::factory()->for($user)->accepted('gen_quiet', Generation::PROCESSING)->create(['updated_at' => now()->subMinute()]);
        Generation::factory()->for($user)->accepted('gen_fresh', Generation::PROCESSING)->create();

        $this->actingAs($user)->get('/history');
        $this->actingAs($user)->get('/history'); // a second view within 30 seconds does not poll again

        Queue::assertPushed(SyncGeneration::class, 1);
        Queue::assertPushed(SyncGeneration::class, fn ($job) => $job->generationId === $quiet->id);
    }

    public function test_a_sync_applies_what_pixelforge_reports(): void
    {
        $user = User::factory()->create(['balance_nano' => 1_000_000_000]);
        $generation = Generation::factory()->for($user)->accepted('gen_lost', Generation::PROCESSING)->create();
        Http::fake(['*/v1/generations/gen_lost' => Http::response($this->providerObject($generation, 'succeeded'))]);

        app()->call([new SyncGeneration($generation->id), 'handle']);

        $this->assertSame(Generation::SUCCEEDED, $generation->fresh()->status);
        $this->assertSame(1_000_000_000 - 381_887_500, $user->fresh()->balance_nano);
    }
}
