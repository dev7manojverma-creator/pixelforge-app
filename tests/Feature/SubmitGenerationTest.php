<?php

namespace Tests\Feature;

use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\Support\PixelforgeFixtures;
use Tests\TestCase;

class SubmitGenerationTest extends TestCase
{
    use PixelforgeFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['pixelforge.api_key' => 'pf_test_key']);
    }

    /** Run the job as a queue worker would, capturing whether it asked to be retried. */
    private function run_(Generation $generation, ?int &$releasedFor = null): void
    {
        $queueJob = Mockery::mock(Job::class);
        $queueJob->shouldReceive('attempts')->andReturn(1);
        $queueJob->shouldReceive('release')->andReturnUsing(function ($delay) use (&$releasedFor) {
            $releasedFor = $delay;
        });

        $job = new SubmitGeneration($generation->id);
        $job->setJob($queueJob);

        app()->call([$job, 'handle']);
    }

    public function test_it_submits_the_request_with_the_idempotency_key_and_stores_the_provider_id(): void
    {
        $generation = Generation::factory()->create();
        Http::fake(['*/v1/generations' => Http::response($this->providerObject($generation, 'queued', ['id' => 'gen_abc']), 202)]);

        $this->run_($generation);

        Http::assertSent(fn (Request $request) => $request->hasHeader('Idempotency-Key', $generation->idempotency_key)
            && $request->hasHeader('Authorization', 'Bearer pf_test_key')
            && $request['prompt'] === $generation->prompt
            && $request['metadata'] === ['generation_id' => (string) $generation->id]
            && array_keys($request->data()) === ['prompt', 'size', 'quality', 'reference_images', 'metadata']);

        $generation->refresh();
        $this->assertSame('gen_abc', $generation->provider_id);
        $this->assertSame(Generation::QUEUED, $generation->status);
    }

    public function test_temporary_refusals_are_retried_after_the_delay_pixelforge_asks_for(): void
    {
        $generation = Generation::factory()->create();

        foreach ([[503, 'overloaded', 2], [429, 'concurrency_limit_exceeded', 5], [429, 'rate_limit_exceeded', 41]] as [$status, $code, $retryAfter]) {
            Http::swap(new \Illuminate\Http\Client\Factory);
            Http::fake(['*' => Http::response(['error' => ['type' => 'x', 'code' => $code, 'message' => 'x']], $status, ['Retry-After' => $retryAfter])]);

            $releasedFor = null;
            $this->run_($generation, $releasedFor);

            $this->assertGreaterThanOrEqual($retryAfter, $releasedFor, $code);
            $this->assertSame(Generation::PENDING, $generation->fresh()->status, $code);
        }
    }

    public function test_a_network_error_is_retried_with_the_same_idempotency_key(): void
    {
        $generation = Generation::factory()->create();
        Http::fake(fn () => throw new ConnectionException('timed out'));

        $releasedFor = null;
        $this->run_($generation, $releasedFor);

        $this->assertNotNull($releasedFor);
        $this->assertSame(Generation::PENDING, $generation->fresh()->status);
    }

    public function test_a_blocked_prompt_fails_the_generation_without_any_charge(): void
    {
        $generation = Generation::factory()->create();
        Http::fake(['*' => Http::response(['error' => ['type' => 'invalid_request_error', 'code' => 'content_policy_violation', 'message' => 'Rejected.']], 400)]);

        $releasedFor = null;
        $this->run_($generation, $releasedFor);

        $generation->refresh();
        $this->assertNull($releasedFor);
        $this->assertSame(Generation::FAILED, $generation->status);
        $this->assertSame('content_policy_violation', $generation->error_code);
        $this->assertSame(0, $generation->charge_nano);
        $this->assertDatabaseCount('credit_transactions', 0);
    }

    public function test_giving_up_marks_the_generation_failed_and_free(): void
    {
        $generation = Generation::factory()->create();

        (new SubmitGeneration($generation->id))->failed(null);

        $generation->refresh();
        $this->assertSame(Generation::FAILED, $generation->status);
        $this->assertSame(Generation::ERROR_NOT_SUBMITTED, $generation->error_code);
        $this->assertSame(0, $generation->charge_nano);
    }

    public function test_if_pixelforge_did_accept_a_request_we_gave_up_on_its_result_still_counts(): void
    {
        $generation = Generation::factory()->for(\App\Models\User::factory()->create(['balance_nano' => 1_000_000_000]))->create();
        (new SubmitGeneration($generation->id))->failed(null);

        $this->deliverWebhook('evt_1', 'generation.succeeded', $this->providerObject($generation, 'succeeded'))->assertNoContent();

        $generation->refresh();
        $this->assertSame(Generation::SUCCEEDED, $generation->status);
        $this->assertSame(381_887_500, $generation->charge_nano);
        $this->assertNull($generation->error_code);
    }

    public function test_it_does_nothing_once_the_generation_has_left_pending(): void
    {
        $generation = Generation::factory()->accepted()->create();
        Http::fake();

        $this->run_($generation);

        Http::assertNothingSent();
    }
}
