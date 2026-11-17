<?php

namespace Database\Factories;

use App\Models\Generation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Generation> */
class GenerationFactory extends Factory
{
    public const RATES = [
        'input_text_tokens' => '5.00',
        'input_image_tokens' => '10.00',
        'output_tokens' => '40.00',
        'reasoning_tokens' => '12.50',
    ];

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'idempotency_key' => (string) Str::uuid(),
            'status' => Generation::PENDING,
            'prompt' => 'A lighthouse on a cliff at dawn, oil painting',
            'size' => '1536x1024',
            'quality' => 'high',
            'reference_images' => ['https://example.com/style.png'],
            'rates' => self::RATES,
            'estimated_charge_nano' => 400_000_000,
            'max_charge_nano' => 500_000_000,
        ];
    }

    public function accepted(string $providerId = 'gen_test', string $status = Generation::QUEUED): static
    {
        return $this->state(['provider_id' => $providerId, 'status' => $status]);
    }
}
