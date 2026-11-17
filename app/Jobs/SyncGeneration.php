<?php

namespace App\Jobs;

use App\Models\Generation;
use App\Services\Pixelforge\GenerationUpdater;
use App\Services\Pixelforge\PixelforgeClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Polling fallback: webhook delivery is not guaranteed, so a generation that
 * has been quiet for a while is fetched from Pixelforge and applied through
 * the same path as a webhook.
 */
class SyncGeneration implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(public int $generationId) {}

    public function handle(PixelforgeClient $client, GenerationUpdater $updater): void
    {
        $generation = Generation::find($this->generationId);

        if (! $generation || $generation->isFinal() || ! $generation->provider_id) {
            return;
        }

        $response = $client->getGeneration($generation->provider_id);

        if ($response->successful()) {
            $updater->apply($response->json());
        }
    }
}
