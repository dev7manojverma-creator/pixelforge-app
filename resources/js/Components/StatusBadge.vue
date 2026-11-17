<script setup>
defineProps({ status: { type: String, required: true } });

const styles = {
    queued: { label: 'Queued', class: 'bg-stone-100 text-stone-700', spin: true },
    processing: { label: 'Generating', class: 'bg-amber-100 text-amber-800', spin: true },
    succeeded: { label: 'Done', class: 'bg-emerald-100 text-emerald-800', spin: false },
    failed: { label: 'Failed', class: 'bg-red-100 text-red-800', spin: false },
};
</script>

<template>
    <span
        class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-semibold"
        :class="styles[status].class"
    >
        <svg v-if="styles[status].spin" class="size-3 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="3" class="opacity-25" />
            <path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round" />
        </svg>
        {{ styles[status].label }}
    </span>
</template>
