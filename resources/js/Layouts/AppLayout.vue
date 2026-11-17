<script setup>
import { computed, ref, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { formatUsd, formatUsdShort } from '@/lib/money';
import BuyCredits from '@/Components/BuyCredits.vue';

const page = usePage();
const user = computed(() => page.props.auth?.user);
const credits = computed(() => page.props.credits);
const flash = computed(() => page.props.flash ?? {});
const buying = ref(false);

// Flash messages disappear on their own; errors stay until dismissed.
const toast = ref(null);
let timer = null;
watch(
    flash,
    (value) => {
        if (!value.success && !value.error) return;
        toast.value = value.error ? { type: 'error', text: value.error } : { type: 'success', text: value.success };
        clearTimeout(timer);
        if (!value.error) timer = setTimeout(() => (toast.value = null), 4000);
    },
    { immediate: true },
);

const links = computed(() => [
    { href: '/generate', label: 'Generate', active: page.url.startsWith('/generate') },
    { href: '/history', label: 'History', active: page.url.startsWith('/history') },
    ...(user.value?.is_admin ? [{ href: '/admin/report', label: 'Report', active: page.url.startsWith('/admin') }] : []),
]);
</script>

<template>
    <div class="min-h-screen">
        <header class="sticky top-0 z-20 border-b border-stone-200 bg-white/90 backdrop-blur">
            <div class="mx-auto flex h-14 max-w-6xl items-center gap-6 px-4 sm:px-6">
                <Link href="/generate" class="flex items-center gap-2 font-semibold tracking-tight">
                    <span class="grid size-7 place-items-center rounded-md bg-amber-600 text-sm text-white">F</span>
                    <span class="hidden sm:inline">Forge Studio</span>
                </Link>

                <nav class="flex items-center gap-1 text-sm">
                    <Link
                        v-for="link in links"
                        :key="link.href"
                        :href="link.href"
                        class="rounded-md px-3 py-1.5 font-medium transition"
                        :class="link.active ? 'bg-stone-100 text-stone-900' : 'text-stone-500 hover:text-stone-900'"
                        :aria-current="link.active ? 'page' : undefined"
                    >
                        {{ link.label }}
                    </Link>
                </nav>

                <div class="ml-auto flex items-center gap-3 text-sm">
                    <div v-if="credits" class="relative">
                        <button
                            type="button"
                            class="flex items-center gap-2 rounded-full border border-stone-200 bg-white py-1 pr-1 pl-3 hover:border-stone-300"
                            :aria-expanded="buying"
                            @click="buying = !buying"
                        >
                            <span class="text-stone-500">Credits</span>
                            <span class="font-semibold tabular-nums">{{ formatUsdShort(credits.available_nano) }}</span>
                            <span class="rounded-full bg-amber-600 px-2 py-0.5 text-xs font-semibold text-white">Buy</span>
                        </button>

                        <div
                            v-if="buying"
                            class="absolute right-0 mt-2 w-72 rounded-xl border border-stone-200 bg-white p-4 shadow-lg"
                        >
                            <dl class="space-y-1 text-sm">
                                <div class="flex justify-between">
                                    <dt class="text-stone-500">Balance</dt>
                                    <dd class="tabular-nums">{{ formatUsd(credits.balance_nano) }}</dd>
                                </div>
                                <div v-if="credits.reserved_nano > 0" class="flex justify-between">
                                    <dt class="text-stone-500">Held for running images</dt>
                                    <dd class="tabular-nums">−{{ formatUsd(credits.reserved_nano) }}</dd>
                                </div>
                                <div class="flex justify-between border-t border-stone-100 pt-1 font-semibold">
                                    <dt>Available</dt>
                                    <dd class="tabular-nums">{{ formatUsd(credits.available_nano) }}</dd>
                                </div>
                            </dl>
                            <p v-if="credits.reserved_nano > 0" class="mt-2 text-xs text-stone-500">
                                The hold is the most a running image can cost. Whatever it doesn't use is released when
                                it finishes.
                            </p>
                            <BuyCredits class="mt-3" @bought="buying = false" />
                        </div>
                    </div>

                    <span class="hidden text-stone-500 md:inline">{{ user?.name }}</span>
                    <button type="button" class="text-stone-500 hover:text-stone-900" @click="router.post('/logout')">
                        Log out
                    </button>
                </div>
            </div>
        </header>

        <main class="mx-auto max-w-6xl px-4 py-8 sm:px-6">
            <slot />
        </main>

        <div
            v-if="toast"
            role="status"
            class="fixed right-4 bottom-4 z-30 flex max-w-sm items-start gap-3 rounded-lg px-4 py-3 text-sm text-white shadow-lg"
            :class="toast.type === 'error' ? 'bg-red-700' : 'bg-stone-900'"
        >
            <span>{{ toast.text }}</span>
            <button type="button" class="opacity-70 hover:opacity-100" aria-label="Dismiss" @click="toast = null">✕</button>
        </div>
    </div>
</template>
