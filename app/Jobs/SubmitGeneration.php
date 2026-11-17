<?php

namespace App\Jobs;

use App\Models\Generation;
use App\Services\Pixelforge\GenerationUpdater;
use App\Services\Pixelforge\PixelforgeClient;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Throwable;

/**
 * Hands a locally saved generation to Pixelforge.
 *
 * Temporary refusals (rate limit, concurrency limit, overload, network errors)
 * are retried with a growing delay that respects Retry-After, for a bounded
 * window. Every attempt sends the same Idempotency-Key, so a request that
 * timed out on our side but reached Pixelforge is never created twice.
 */
class SubmitGeneration implements ShouldQueue
{
    use Queueable;

    public int $timeout = 30;

    public function __construct(public int $generationId) {}

    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(config('pixelforge.submit_window_minutes'));
    }

    public function handle(PixelforgeClient $client, GenerationUpdater $updater): void
    {
        $generation = Generation::find($this->generationId);

        if (! $generation || $generation->status !== Generation::PENDING) {
            return; // Already submitted (a webhook may have beaten us) or gone.
        }

        try {
            $response = $client->createGeneration($generation->providerPayload(), $generation->idempotency_key);
        } catch (ConnectionException) {
            $this->release($this->retryDelay(null));

            return;
        }

        if ($response->successful()) {
            $updater->apply($response->json());

            return;
        }

        $code = $response->json('error.code');
        $temporary = $response->serverError()
            || in_array($code, ['rate_limit_exceeded', 'concurrency_limit_exceeded', 'overloaded'], true);

        if ($temporary) {
            $this->release($this->retryDelay($response));

            return;
        }

        $this->reject($generation, $code, $response);
    }

    /** The retry window ran out, or the job crashed for good. */
    public function failed(?Throwable $e): void
    {
        Generation::whereKey($this->generationId)->where('status', Generation::PENDING)->update([
            'status' => Generation::FAILED,
            'error_code' => Generation::ERROR_NOT_SUBMITTED,
            'error_message' => 'The image service was busy for too long, so this generation was cancelled. You were not charged.',
            'charge_nano' => 0,
            'provider_cost_nano' => 0,
            'completed_at' => now(),
        ]);
    }

    /** Pixelforge refused the request for good. Nothing was created or billed. */
    private function reject(Generation $generation, ?string $code, Response $response): void
    {
        $message = match ($code) {
            'content_policy_violation' => 'The prompt was rejected by the content policy.',
            'daily_limit_exceeded' => 'The daily image limit has been reached. Please try again tomorrow.',
            'validation_failed' => 'The image service rejected this request: '.$response->json('error.message'),
            default => 'The image service could not accept this request.',
        };

        if (! in_array($code, ['content_policy_violation', 'daily_limit_exceeded'], true)) {
            // invalid_api_key, validation_failed, idempotency_key_reused…: our bug or misconfiguration.
            logger()->error('Pixelforge rejected a generation', ['generation' => $generation->id, 'status' => $response->status(), 'body' => $response->json()]);
        }

        Generation::whereKey($generation->id)->where('status', Generation::PENDING)->update([
            'status' => Generation::FAILED,
            'error_code' => $code ?? 'rejected',
            'error_message' => $message.' You were not charged.',
            'charge_nano' => 0,
            'provider_cost_nano' => 0,
            'completed_at' => now(),
        ]);
    }

    /** Wait at least as long as Pixelforge asks, backing off further on each attempt (capped at a minute). */
    private function retryDelay(?Response $response): int
    {
        $retryAfter = (int) ($response?->header('Retry-After') ?: 0);
        $backoff = min(60, 2 ** min($this->attempts(), 6));

        return max($retryAfter, $backoff);
    }
}
