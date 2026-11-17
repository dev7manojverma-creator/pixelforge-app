<script setup>
import { computed, onBeforeUnmount, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { formatUsd, formatUsdApprox } from '@/lib/money';
import StatusBadge from '@/Components/StatusBadge.vue';

const props = defineProps({ generations: Object });

const running = computed(() => props.generations.data.some((g) => g.status === 'queued' || g.status === 'processing'));

// Live status: while anything is still running, refresh the list (and the
// credit balance) every two seconds. Polling stops as soon as everything is final.
let timer = null;
watch(
    running,
    (isRunning) => {
        clearInterval(timer);
        if (isRunning) {
            timer = setInterval(() => router.reload({ only: ['generations', 'credits'] }), 2000);
        }
    },
    { immediate: true },
);
onBeforeUnmount(() => clearInterval(timer));

const dateFormat = new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' });
const when = (iso) => dateFormat.format(new Date(iso));

const waiting = {
    queued: 'In the queue, starting shortly…',
    processing: 'Drawing your image… this usually takes under 30 seconds.',
};

// Pixelforge's image links stop working after 24 hours; show that plainly instead of a broken image.
function imageFailed(event) {
    event.target.closest('[data-image]').dataset.broken = 'true';
}
</script>

<template>
    <Head title="History" />

    <div class="mb-6 flex items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">History</h1>
            <p class="mt-1 text-sm text-stone-500">
                Every image you've generated and what it cost.
                <span v-if="running" class="text-amber-700">Updating live…</span>
            </p>
        </div>
        <Link href="/generate" class="btn-primary">New image</Link>
    </div>

    <div v-if="!generations.data.length" class="rounded-xl border border-dashed border-stone-300 bg-white px-6 py-16 text-center">
        <p class="font-medium">No images yet</p>
        <p class="mt-1 text-sm text-stone-500">Your generations and their costs will show up here.</p>
        <Link href="/generate" class="btn-primary mt-4">Generate your first image</Link>
    </div>

    <ul v-else class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <li
            v-for="generation in generations.data"
            :key="generation.id"
            class="flex flex-col overflow-hidden rounded-xl border border-stone-200 bg-white shadow-xs"
        >
            <div class="grid aspect-square place-items-center bg-stone-100 p-3">
                <div v-if="generation.status === 'succeeded' && generation.image_url" data-image class="group size-full">
                    <a :href="generation.image_url" target="_blank" rel="noopener" class="block size-full group-data-broken:hidden">
                        <img :src="generation.image_url" :alt="generation.prompt" class="size-full rounded-md object-contain" @error="imageFailed" />
                    </a>
                    <p class="hidden h-full place-content-center text-center text-sm text-stone-500 group-data-broken:grid">
                        This image is no longer available.<br />Image links expire after 24 hours.
                    </p>
                </div>
                <p v-else-if="generation.status === 'succeeded'" class="text-center text-sm text-stone-500">
                    This image is no longer available.<br />Image links expire after 24 hours.
                </p>
                <div v-else-if="generation.status === 'failed'" class="px-4 text-center">
                    <p class="text-sm font-medium text-red-800">Couldn't generate this image</p>
                    <p class="mt-1 text-sm text-stone-600">{{ generation.error_message }}</p>
                </div>
                <div v-else class="px-4 text-center">
                    <div class="mx-auto size-8 animate-spin rounded-full border-2 border-stone-300 border-t-amber-600" aria-hidden="true" />
                    <p class="mt-3 text-sm text-stone-600">{{ waiting[generation.status] }}</p>
                </div>
            </div>

            <div class="flex flex-1 flex-col gap-3 p-4">
                <div class="flex items-center justify-between gap-2">
                    <StatusBadge :status="generation.status" />
                    <time class="text-xs text-stone-400" :datetime="generation.created_at">{{ when(generation.created_at) }}</time>
                </div>

                <p class="line-clamp-3 text-sm text-stone-800" :title="generation.prompt">{{ generation.prompt }}</p>
                <p class="text-xs text-stone-500">
                    {{ generation.size.replace('x', ' × ') }} · <span class="capitalize">{{ generation.quality }}</span> quality
                    <template v-if="generation.reference_images.length">
                        · {{ generation.reference_images.length }} reference{{ generation.reference_images.length > 1 ? 's' : '' }}
                    </template>
                </p>

                <dl class="mt-auto grid grid-cols-2 gap-2 border-t border-stone-100 pt-3 text-sm">
                    <div>
                        <dt class="text-xs text-stone-500">Estimated</dt>
                        <dd class="tabular-nums text-stone-600">≈ {{ formatUsdApprox(generation.estimated_charge_nano) }}</dd>
                    </div>
                    <div class="text-right">
                        <dt class="text-xs text-stone-500">{{ generation.status === 'failed' ? 'Charged' : 'Actual cost' }}</dt>
                        <dd v-if="generation.status === 'succeeded'" class="font-semibold tabular-nums">
                            {{ formatUsd(generation.charge_nano) }}
                        </dd>
                        <dd v-else-if="generation.status === 'failed'" class="font-semibold text-stone-600">$0.00 · not charged</dd>
                        <dd v-else class="text-stone-400">Known when done</dd>
                    </div>
                </dl>
            </div>
        </li>
    </ul>

    <nav v-if="generations.last_page > 1" class="mt-6 flex items-center justify-between text-sm" aria-label="Pagination">
        <component
            :is="generations.prev_page_url ? Link : 'span'"
            :href="generations.prev_page_url"
            class="btn-secondary"
            :class="{ 'pointer-events-none opacity-50': !generations.prev_page_url }"
        >
            ← Newer
        </component>
        <span class="text-stone-500">Page {{ generations.current_page }} of {{ generations.last_page }}</span>
        <component
            :is="generations.next_page_url ? Link : 'span'"
            :href="generations.next_page_url"
            class="btn-secondary"
            :class="{ 'pointer-events-none opacity-50': !generations.next_page_url }"
        >
            Older →
        </component>
    </nav>
</template>
