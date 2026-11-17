<?php

namespace App\Http\Controllers;

use App\Models\WebhookEvent;
use App\Services\Pixelforge\GenerationUpdater;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class PixelforgeWebhookController extends Controller
{
    public function __invoke(Request $request, GenerationUpdater $updater): Response
    {
        // Verify against the raw body, before any JSON decoding.
        $payload = $request->getContent();

        abort_unless($this->signatureIsValid($request, $payload), 400, 'Invalid Pixelforge signature.');

        $event = json_decode($payload, true);

        abort_unless(is_array($event) && is_string($event['id'] ?? null), 400, 'Malformed event.');

        if (($event['type'] ?? null) === 'ping') {
            return response()->noContent();
        }

        $object = $event['data']['object'] ?? [];

        DB::transaction(function () use ($event, $object, $updater) {
            // The unique event_id is the deduplication: a second delivery inserts nothing and stops here.
            // Recording the event and acting on it share one transaction, so if applying fails,
            // nothing is recorded and Pixelforge's retry gets a clean second chance.
            $firstDelivery = WebhookEvent::insertOrIgnore([
                'event_id' => $event['id'],
                'type' => (string) ($event['type'] ?? ''),
                'provider_generation_id' => $object['id'] ?? null,
                'payload' => json_encode($event),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($firstDelivery) {
                $updater->apply($object);
            }
        });

        return response()->noContent();
    }

    /** Header format: t=<unix timestamp>,v1=<hex HMAC-SHA256 of "<timestamp>.<raw body>">. */
    private function signatureIsValid(Request $request, string $payload): bool
    {
        $secret = (string) config('pixelforge.webhook_secret');
        $parts = [];

        foreach (explode(',', (string) $request->header('Pixelforge-Signature')) as $part) {
            [$key, $value] = array_pad(explode('=', $part, 2), 2, '');
            $parts[trim($key)] = trim($value);
        }

        $timestamp = (int) ($parts['t'] ?? 0);

        if ($secret === '' || $timestamp === 0) {
            return false;
        }

        $expected = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);

        // Constant-time comparison, and old timestamps are refused to stop replays.
        return hash_equals($expected, $parts['v1'] ?? '')
            && abs(time() - $timestamp) <= config('pixelforge.webhook_tolerance');
    }
}
