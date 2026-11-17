<?php

namespace App\Http\Controllers;

use App\Exceptions\PricingUnavailable;
use App\Jobs\SubmitGeneration;
use App\Jobs\SyncGeneration;
use App\Models\Generation;
use App\Models\User;
use App\Services\Credits;
use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pixelforge\Pricing;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class GenerationController extends Controller
{
    /** The generate form. The pricing document is passed down so the cost updates as the user types. */
    public function create(PixelforgeClient $client): Response
    {
        try {
            $pricing = $client->pricing();
        } catch (PricingUnavailable) {
            $pricing = null;
        }

        return Inertia::render('Generations/Create', [
            'pricing' => $pricing,
            'markupBps' => config('pixelforge.markup_bps'),
            'sizes' => Generation::SIZES,
            'qualities' => Generation::QUALITIES,
        ]);
    }

    public function store(Request $request, PixelforgeClient $client, Credits $credits): RedirectResponse
    {
        $data = $request->validate([
            'prompt' => ['required', 'string', 'max:4000'],
            'size' => ['required', Rule::in(Generation::SIZES)],
            'quality' => ['required', Rule::in(Generation::QUALITIES)],
            'reference_images' => ['array', 'max:4'],
            'reference_images.*' => ['required', 'string', 'url:http,https', 'max:2000'],
        ], [
            'reference_images.*.url' => 'Each reference image must be an http(s) URL.',
        ]);

        $references = array_values($data['reference_images'] ?? []);

        try {
            $pricing = $client->pricing();
        } catch (PricingUnavailable) {
            throw ValidationException::withMessages([
                'prompt' => 'We could not load current prices, so nothing was submitted. Please try again in a moment.',
            ]);
        }

        // Always priced on the server; the number in the browser is only a preview of this.
        $estimate = Pricing::estimate($pricing, $data['prompt'], $data['size'], $data['quality'], count($references));

        $generation = DB::transaction(function () use ($request, $credits, $data, $references, $pricing, $estimate) {
            // Lock the user so two simultaneous submits cannot both spend the same credits.
            $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();

            if ($credits->availableNano($user) < $estimate->maxChargeNano) {
                throw ValidationException::withMessages([
                    'credits' => 'You do not have enough credits for this image. Buy credits or wait for running generations to finish.',
                ]);
            }

            return $user->generations()->create([
                'idempotency_key' => (string) Str::uuid(),
                'status' => Generation::PENDING,
                'prompt' => $data['prompt'],
                'size' => $data['size'],
                'quality' => $data['quality'],
                'reference_images' => $references,
                'rates' => $pricing['rates'],
                'estimated_charge_nano' => $estimate->expectedChargeNano,
                'max_charge_nano' => $estimate->maxChargeNano,
            ]);
        });

        SubmitGeneration::dispatch($generation->id);

        return redirect()->route('generations.index')->with('success', 'Your image is on its way.');
    }

    /** History with live status: the page re-requests this while anything is still running. */
    public function index(Request $request): Response
    {
        $generations = $request->user()->generations()->latest('id')->paginate(12);

        $this->syncQuietGenerations($generations->getCollection());

        return Inertia::render('Generations/Index', [
            'generations' => $generations->through(fn (Generation $generation) => $this->present($generation)),
        ]);
    }

    /**
     * Safety net for lost webhooks: while the user is watching, a running
     * generation that has not been heard about for 30 seconds is polled, at
     * most once every 30 seconds.
     */
    private function syncQuietGenerations($generations): void
    {
        $generations
            ->filter(fn (Generation $g) => ! $g->isFinal() && $g->provider_id
                && collect([$g->updated_at, $g->synced_at])->filter()->max()->lt(now()->subSeconds(30)))
            ->each(function (Generation $g) {
                $g->timestamps = false;
                $g->forceFill(['synced_at' => now()])->save();
                SyncGeneration::dispatch($g->id);
            });
    }

    private function present(Generation $generation): array
    {
        $imageExpired = $generation->image_url_expires_at?->isPast() ?? false;

        return [
            'id' => $generation->id,
            // "pending" (not yet accepted by Pixelforge) is an internal detail; to the user it is queued.
            'status' => $generation->status === Generation::PENDING ? Generation::QUEUED : $generation->status,
            'prompt' => $generation->prompt,
            'size' => $generation->size,
            'quality' => $generation->quality,
            'reference_images' => $generation->reference_images,
            'estimated_charge_nano' => $generation->estimated_charge_nano,
            'max_charge_nano' => $generation->max_charge_nano,
            'charge_nano' => $generation->charge_nano,
            'image_url' => $imageExpired ? null : $generation->image_url,
            'image_expired' => $imageExpired,
            'error_message' => $generation->error_message,
            'created_at' => $generation->created_at->toIso8601String(),
            'completed_at' => $generation->completed_at?->toIso8601String(),
        ];
    }
}
