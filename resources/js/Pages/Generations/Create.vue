<script setup>
import { computed } from 'vue';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { estimate } from '@/lib/pricing';
import { formatUsd, formatUsdApprox } from '@/lib/money';
import BuyCredits from '@/Components/BuyCredits.vue';

const props = defineProps({
    pricing: Object, // null when Pixelforge's prices could not be loaded
    markupBps: Number,
    sizes: Array,
    qualities: Array,
});

const MAX_PROMPT = 4000;
const MAX_REFERENCES = 4;

const sizeLabels = { '1024x1024': 'Square', '1536x1024': 'Landscape', '1024x1536': 'Portrait' };
const qualityHints = { low: 'Fast draft', medium: 'Balanced', high: 'Most detail' };

const form = useForm({ prompt: '', size: '1024x1024', quality: 'medium', reference_images: [] });
const credits = computed(() => usePage().props.credits);

// Blank reference rows are ignored rather than treated as an error.
const references = computed(() => form.reference_images.map((url) => url.trim()).filter(Boolean));
const prompt = computed(() => form.prompt.trim());
const promptLength = computed(() => [...prompt.value].length);

const cost = computed(() =>
    props.pricing
        ? estimate(props.pricing, props.markupBps, {
              prompt: prompt.value,
              size: form.size,
              quality: form.quality,
              referenceImages: references.value.length,
          })
        : null,
);

const cannotAfford = computed(() => cost.value && credits.value.available_nano < cost.value.max);
const canSubmit = computed(
    () => cost.value && promptLength.value > 0 && promptLength.value <= MAX_PROMPT && !cannotAfford.value && !form.processing,
);

function submit() {
    form.transform((data) => ({ ...data, prompt: prompt.value, reference_images: references.value })).post('/generations');
}
</script>

<template>
    <Head title="Generate" />

    <div class="mb-6">
        <h1 class="text-2xl font-semibold tracking-tight">Generate an image</h1>
        <p class="mt-1 text-sm text-stone-500">Describe what you want. You see the cost before anything is charged.</p>
    </div>

    <div
        v-if="!pricing"
        role="alert"
        class="mb-6 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800"
    >
        We can't load current prices right now, so generating is paused. Please reload in a moment.
    </div>

    <form class="grid gap-6 lg:grid-cols-[1fr_22rem]" @submit.prevent="submit">
        <div class="space-y-6 rounded-xl border border-stone-200 bg-white p-6 shadow-xs">
            <div>
                <div class="mb-1 flex items-baseline justify-between">
                    <label for="prompt" class="text-sm font-medium">Prompt</label>
                    <span class="text-xs tabular-nums" :class="promptLength > MAX_PROMPT ? 'text-red-700' : 'text-stone-400'">
                        {{ promptLength.toLocaleString() }} / {{ MAX_PROMPT.toLocaleString() }}
                    </span>
                </div>
                <textarea
                    id="prompt"
                    v-model="form.prompt"
                    rows="5"
                    class="field resize-y"
                    placeholder="A lighthouse on a cliff at dawn, oil painting"
                    autofocus
                />
                <p v-if="form.errors.prompt" class="mt-1 text-sm text-red-700">{{ form.errors.prompt }}</p>
            </div>

            <fieldset>
                <legend class="mb-2 text-sm font-medium">Size</legend>
                <div class="grid grid-cols-3 gap-2">
                    <label
                        v-for="size in sizes"
                        :key="size"
                        class="cursor-pointer rounded-lg border px-3 py-2 text-center text-sm transition has-focus-visible:ring-2 has-focus-visible:ring-amber-600"
                        :class="form.size === size ? 'border-stone-900 bg-stone-900 text-white' : 'border-stone-300 bg-white hover:bg-stone-50'"
                    >
                        <input v-model="form.size" type="radio" name="size" :value="size" class="sr-only" />
                        <span class="block font-medium">{{ sizeLabels[size] ?? size }}</span>
                        <span class="block text-xs opacity-70">{{ size.replace('x', ' × ') }}</span>
                    </label>
                </div>
                <p v-if="form.errors.size" class="mt-1 text-sm text-red-700">{{ form.errors.size }}</p>
            </fieldset>

            <fieldset>
                <legend class="mb-2 text-sm font-medium">Quality</legend>
                <div class="grid grid-cols-3 gap-2">
                    <label
                        v-for="quality in qualities"
                        :key="quality"
                        class="cursor-pointer rounded-lg border px-3 py-2 text-center text-sm transition has-focus-visible:ring-2 has-focus-visible:ring-amber-600"
                        :class="form.quality === quality ? 'border-stone-900 bg-stone-900 text-white' : 'border-stone-300 bg-white hover:bg-stone-50'"
                    >
                        <input v-model="form.quality" type="radio" name="quality" :value="quality" class="sr-only" />
                        <span class="block font-medium capitalize">{{ quality }}</span>
                        <span class="block text-xs opacity-70">{{ qualityHints[quality] }}</span>
                    </label>
                </div>
                <p v-if="form.errors.quality" class="mt-1 text-sm text-red-700">{{ form.errors.quality }}</p>
            </fieldset>

            <div>
                <div class="mb-2 flex items-baseline justify-between">
                    <span class="text-sm font-medium">Reference images <span class="font-normal text-stone-400">(optional)</span></span>
                    <span class="text-xs text-stone-400">{{ form.reference_images.length }} / {{ MAX_REFERENCES }}</span>
                </div>
                <div class="space-y-2">
                    <div v-for="(_, index) in form.reference_images" :key="index">
                        <div class="flex gap-2">
                            <input
                                v-model="form.reference_images[index]"
                                type="url"
                                class="field"
                                placeholder="https://example.com/style.png"
                                :aria-label="`Reference image URL ${index + 1}`"
                            />
                            <button
                                type="button"
                                class="btn-secondary"
                                :aria-label="`Remove reference image ${index + 1}`"
                                @click="form.reference_images.splice(index, 1)"
                            >
                                ✕
                            </button>
                        </div>
                        <p v-if="form.errors[`reference_images.${index}`]" class="mt-1 text-sm text-red-700">
                            {{ form.errors[`reference_images.${index}`] }}
                        </p>
                    </div>
                </div>
                <button
                    v-if="form.reference_images.length < MAX_REFERENCES"
                    type="button"
                    class="mt-2 text-sm font-medium text-amber-700 hover:underline"
                    @click="form.reference_images.push('')"
                >
                    + Add a reference image URL
                </button>
                <p class="mt-1 text-xs text-stone-500">Image links that guide the style. Each one adds a little to the cost.</p>
                <p v-if="form.errors.reference_images" class="mt-1 text-sm text-red-700">{{ form.errors.reference_images }}</p>
            </div>
        </div>

        <aside class="h-fit space-y-4 rounded-xl border border-stone-200 bg-white p-6 shadow-xs lg:sticky lg:top-20">
            <h2 class="text-sm font-medium text-stone-500">Estimated cost</h2>

            <template v-if="cost">
                <div aria-live="polite">
                    <p class="text-3xl font-semibold tracking-tight tabular-nums">≈ {{ formatUsdApprox(cost.expected) }}</p>
                    <p class="mt-1 text-sm text-stone-600">
                        Between {{ formatUsdApprox(cost.min) }} and
                        <span class="font-medium text-stone-900">{{ formatUsdApprox(cost.max) }} at most</span>
                    </p>
                </div>

                <p class="text-xs leading-relaxed text-stone-500">
                    The exact price depends on how much the model "thinks" before drawing, which is only known once the
                    image is done. You pay what it actually used, never more than the maximum. Failed images are free.
                </p>

                <details class="text-xs text-stone-600">
                    <summary class="cursor-pointer font-medium text-stone-700">How this is calculated</summary>
                    <dl class="mt-2 space-y-1 tabular-nums">
                        <div class="flex justify-between">
                            <dt>Prompt text</dt>
                            <dd>{{ cost.tokens.textMin }}–{{ cost.tokens.textMax }} tokens</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt>Reference images</dt>
                            <dd>{{ cost.tokens.image.toLocaleString() }} tokens</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt>Image ({{ form.quality }}, {{ form.size }})</dt>
                            <dd>{{ cost.tokens.output.toLocaleString() }} tokens</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt>Model reasoning</dt>
                            <dd>{{ cost.tokens.reasoning.min.toLocaleString() }}–{{ cost.tokens.reasoning.max.toLocaleString() }} tokens</dd>
                        </div>
                    </dl>
                    <p class="mt-2">Priced at the provider's per-token rates plus a {{ markupBps / 100 }}% service fee.</p>
                </details>

                <div class="border-t border-stone-100 pt-4 text-sm">
                    <div class="flex justify-between">
                        <span class="text-stone-500">Available credits</span>
                        <span class="font-medium tabular-nums">{{ formatUsd(credits.available_nano) }}</span>
                    </div>
                </div>

                <div v-if="cannotAfford || form.errors.credits" role="alert" class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
                    <p class="font-medium">Not enough credits</p>
                    <p class="mt-1 text-xs">
                        This image can cost up to {{ formatUsdApprox(cost.max) }}, and we hold that amount until it
                        finishes. Add credits to continue.
                    </p>
                    <BuyCredits class="mt-3" />
                </div>
            </template>
            <p v-else class="text-sm text-stone-500">Prices are unavailable right now.</p>

            <button type="submit" class="btn-primary w-full" :disabled="!canSubmit">
                {{ form.processing ? 'Submitting…' : 'Generate image' }}
            </button>
            <p v-if="cost && !promptLength" class="text-center text-xs text-stone-400">Write a prompt to continue.</p>
        </aside>
    </form>
</template>
