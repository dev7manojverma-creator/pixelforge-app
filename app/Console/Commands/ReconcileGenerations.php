<?php

namespace App\Console\Commands;

use App\Jobs\SyncGeneration;
use App\Models\Generation;
use Illuminate\Console\Command;

class ReconcileGenerations extends Command
{
    protected $signature = 'pixelforge:reconcile';

    protected $description = 'Poll Pixelforge for generations whose webhooks may have been lost';

    public function handle(): int
    {
        $stale = Generation::whereIn('status', [Generation::QUEUED, Generation::PROCESSING])
            ->whereNotNull('provider_id')
            ->where('updated_at', '<', now()->subSeconds(45))
            ->limit(20)
            ->get();

        foreach ($stale as $generation) {
            $generation->forceFill(['synced_at' => now()])->save();
            SyncGeneration::dispatch($generation->id);
        }

        $this->info("Queued {$stale->count()} generation(s) for a sync.");

        return self::SUCCESS;
    }
}
