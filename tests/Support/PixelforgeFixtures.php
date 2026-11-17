<?php

namespace Tests\Support;

use App\Models\Generation;
use Database\Factories\GenerationFactory;
use Illuminate\Testing\TestResponse;

trait PixelforgeFixtures
{
    protected string $webhookSecret = 'whsec_test';

    /** The GET /v1/pricing document from the Pixelforge docs. */
    protected function pricingDocument(): array
    {
        return [
            'object' => 'pricing',
            'currency' => 'usd',
            'unit' => 'per_1m_tokens',
            'rates' => GenerationFactory::RATES,
            'tokens' => [
                'characters_per_text_token' => 4,
                'text_token_variance' => 0.15,
                'per_reference_image' => 765,
                'output' => [
                    'low' => ['1024x1024' => 280, '1536x1024' => 420, '1024x1536' => 420],
                    'medium' => ['1024x1024' => 1100, '1536x1024' => 1650, '1024x1536' => 1650],
                    'high' => ['1024x1024' => 4300, '1536x1024' => 6450, '1024x1536' => 6450],
                ],
                'reasoning' => [
                    'low' => ['min' => 0, 'max' => 500],
                    'medium' => ['min' => 200, 'max' => 2000],
                    'high' => ['min' => 1000, 'max' => 8000],
                ],
            ],
        ];
    }

    /** A Pixelforge generation object for the given local generation, as in the docs' succeeded example. */
    protected function providerObject(Generation $generation, string $status, array $overrides = []): array
    {
        $final = in_array($status, ['succeeded', 'failed'], true);

        return array_replace([
            'id' => $generation->provider_id ?? 'gen_test',
            'object' => 'generation',
            'status' => $status,
            'prompt' => $generation->prompt,
            'size' => $generation->size,
            'quality' => $generation->quality,
            'reference_images' => $generation->reference_images,
            'metadata' => ['generation_id' => (string) $generation->id],
            'image_url' => $status === 'succeeded' ? 'https://pixelforge.promptedit.dev/images/gen_test.png?signature=abc' : null,
            'image_url_expires_at' => $status === 'succeeded' ? '2026-11-05T14:03:29Z' : null,
            // 12×5 + 765×10 + 6450×40 + 3184×12.5 = 305,510 → $0.305510 per the published rates.
            'usage' => $status === 'succeeded' ? [
                'input_text_tokens' => 12,
                'input_image_tokens' => 765,
                'output_tokens' => 6450,
                'reasoning_tokens' => 3184,
                'total_tokens' => 10411,
            ] : null,
            'rates' => GenerationFactory::RATES,
            'error' => $status === 'failed' ? ['code' => 'internal_error', 'message' => 'Something went wrong on our side.'] : null,
            'created_at' => '2026-11-04T14:03:11Z',
            'started_at' => $status === 'queued' ? null : '2026-11-04T14:03:13Z',
            'completed_at' => $final ? '2026-11-04T14:03:29Z' : null,
        ], $overrides);
    }

    /** POST a signed webhook the way Pixelforge does. */
    protected function deliverWebhook(string $eventId, string $type, array $object, ?int $timestamp = null, ?string $secret = null): TestResponse
    {
        config(['pixelforge.webhook_secret' => $this->webhookSecret]);

        $body = json_encode([
            'id' => $eventId,
            'object' => 'event',
            'type' => $type,
            'created_at' => '2026-11-04T14:03:29Z',
            'data' => ['object' => $object],
        ]);
        $timestamp ??= time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$body}", $secret ?? $this->webhookSecret);

        return $this->call('POST', '/webhooks/pixelforge', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_PIXELFORGE_SIGNATURE' => "t={$timestamp},v1={$signature}",
        ], content: $body);
    }
}
