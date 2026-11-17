<?php

namespace App\Services\Pixelforge;

use App\Exceptions\PricingUnavailable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/** Thin HTTP client for the Pixelforge API. It never throws on HTTP errors; callers branch on the response. */
class PixelforgeClient
{
    private const PRICING_FRESH = 'pixelforge.pricing.fresh';

    private const PRICING_LAST_KNOWN = 'pixelforge.pricing.last-known';

    public function createGeneration(array $payload, string $idempotencyKey): Response
    {
        return $this->request()
            ->withHeaders(['Idempotency-Key' => $idempotencyKey])
            ->post('/generations', $payload);
    }

    public function getGeneration(string $providerId): Response
    {
        return $this->request()->get("/generations/{$providerId}");
    }

    /** What Pixelforge billed this API key per UTC day. */
    public function usage(string $startDate, string $endDate): Response
    {
        return $this->request()->get('/usage', ['start_date' => $startDate, 'end_date' => $endDate]);
    }

    /**
     * The current pricing document. Rates can change, so it is fetched rather
     * than hardcoded: cached for five minutes, with the last good copy kept as
     * a fallback for when Pixelforge is unreachable.
     */
    public function pricing(): array
    {
        if ($fresh = Cache::get(self::PRICING_FRESH)) {
            return $fresh;
        }

        try {
            $response = Http::baseUrl(config('pixelforge.base_url'))->acceptJson()->timeout(5)->get('/pricing');

            if ($response->successful() && is_array($response->json('rates')) && is_array($response->json('tokens'))) {
                Cache::put(self::PRICING_FRESH, $response->json(), now()->addMinutes(5));
                Cache::forever(self::PRICING_LAST_KNOWN, $response->json());

                return $response->json();
            }
        } catch (Throwable $e) {
            report($e);
        }

        return Cache::get(self::PRICING_LAST_KNOWN)
            ?? throw new PricingUnavailable('Pixelforge pricing could not be loaded.');
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl(config('pixelforge.base_url'))
            ->withToken((string) config('pixelforge.api_key'))
            ->acceptJson()
            ->asJson()
            ->connectTimeout(5)
            ->timeout(15);
    }
}
