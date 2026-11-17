<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';

const emit = defineEmits(['bought']);
const packs = [5, 10, 25];
const busy = ref(null);

function buy(amount) {
    busy.value = amount;
    router.post(
        '/credits',
        { amount },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => emit('bought'),
            onFinish: () => (busy.value = null),
        },
    );
}
</script>

<template>
    <div>
        <div class="grid grid-cols-3 gap-2">
            <button
                v-for="amount in packs"
                :key="amount"
                type="button"
                class="btn-secondary"
                :disabled="busy !== null"
                @click="buy(amount)"
            >
                {{ busy === amount ? 'Adding…' : `+ $${amount}` }}
            </button>
        </div>
        <p class="mt-2 text-xs text-stone-500">Demo purchase: credits are added instantly and no payment is taken.</p>
    </div>
</template>
