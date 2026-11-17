<?php

namespace App\Services\Pixelforge;

use App\Models\Generation;
use App\Services\Credits;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The one place a generation's state changes.
 *
 * Every source of truth about a generation — the create response, a webhook,
 * a poll — is the same Pixelforge generation object, so they all go through
 * apply(). It is safe to call any number of times, in any order:
 *
 *  - the row is locked, so concurrent deliveries are applied one at a time;
 *  - status only moves forward (queued → processing → succeeded/failed), so a
 *    late "processing" can never undo a final state;
 *  - the charge is written in the same transaction as the move to succeeded,
 *    which can only happen once.
 */
class GenerationUpdater
{
    public function __construct(private Credits $credits) {}

    /**
     * @param  array  $object  a Pixelforge generation object
     * @return bool whether anything changed
     */
    public function apply(array $object): bool
    {
        return DB::transaction(function () use ($object) {
            $generation = $this->findAndLock($object);
            $status = $object['status'] ?? null;

            if (! $generation || ! isset(Generation::RANK[$status])) {
                return false;
            }

            $generation->provider_id ??= $object['id'];

            if ($this->rank($generation) >= Generation::RANK[$status]) {
                // Stale or repeated news: keep what we have (but remember the provider id).
                $generation->save();

                return false;
            }

            $generation->fill([
                'status' => $status,
                'rates' => $object['rates'] ?? $generation->rates,
                'started_at' => $this->time($object['started_at'] ?? null),
                'error_code' => null,
                'error_message' => null,
            ]);

            if ($status === Generation::SUCCEEDED) {
                $this->succeed($generation, $object);
            } elseif ($status === Generation::FAILED) {
                $generation->fill([
                    'completed_at' => $this->time($object['completed_at'] ?? null) ?? now(),
                    'error_code' => $object['error']['code'] ?? 'generation_failed',
                    'error_message' => $object['error']['message'] ?? 'The generation failed.',
                    // Failed generations are never billed, by Pixelforge or by us.
                    'provider_cost_nano' => 0,
                    'charge_nano' => 0,
                ]);
            }

            $generation->save();

            return true;
        });
    }

    private function succeed(Generation $generation, array $object): void
    {
        $usage = $object['usage'];
        // Billed at the rates fixed when Pixelforge created the generation, not today's.
        $providerCost = Pricing::providerCostNano($usage, $generation->rates);
        $charge = Pricing::chargeNano($providerCost);

        $generation->fill([
            'completed_at' => $this->time($object['completed_at'] ?? null) ?? now(),
            'input_text_tokens' => $usage['input_text_tokens'],
            'input_image_tokens' => $usage['input_image_tokens'],
            'output_tokens' => $usage['output_tokens'],
            'reasoning_tokens' => $usage['reasoning_tokens'],
            'provider_cost_nano' => $providerCost,
            'charge_nano' => $charge,
            'image_url' => $object['image_url'] ?? null,
            'image_url_expires_at' => $this->time($object['image_url_expires_at'] ?? null),
        ]);

        $this->credits->chargeFor($generation, $charge);
    }

    private function findAndLock(array $object): ?Generation
    {
        $providerId = $object['id'] ?? null;

        if (! is_string($providerId)) {
            return null;
        }

        $generation = Generation::where('provider_id', $providerId)->lockForUpdate()->first();

        if ($generation) {
            return $generation;
        }

        // A webhook can beat the create response, before we know the provider id.
        // Fall back to the local id we sent as metadata.
        $localId = $object['metadata']['generation_id'] ?? null;

        return is_numeric($localId)
            ? Generation::whereKey((int) $localId)->whereNull('provider_id')->lockForUpdate()->first()
            : null;
    }

    private function rank(Generation $generation): int
    {
        // If we gave up submitting but Pixelforge did accept the request after all,
        // its word wins over our local "failed".
        if ($generation->status === Generation::FAILED && $generation->error_code === Generation::ERROR_NOT_SUBMITTED) {
            return Generation::RANK[Generation::PENDING];
        }

        return Generation::RANK[$generation->status];
    }

    private function time(?string $value): ?Carbon
    {
        return $value ? Carbon::parse($value) : null;
    }
}
