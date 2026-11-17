<script setup>
defineProps({ title: String, subtitle: String });
</script>

<template>
    <div class="grid min-h-screen place-items-center px-4 py-12">
        <div class="w-full max-w-sm">
            <div class="mb-8 text-center">
                <span class="mx-auto grid size-10 place-items-center rounded-lg bg-amber-600 text-lg font-semibold text-white">F</span>
                <h1 class="mt-4 text-xl font-semibold tracking-tight">{{ title }}</h1>
                <p class="mt-1 text-sm text-stone-500">{{ subtitle }}</p>
            </div>
            <div class="rounded-xl border border-stone-200 bg-white p-6 shadow-xs">
                <slot />
            </div>
            <p class="mt-6 text-center text-sm text-stone-500"><slot name="footer" /></p>
        </div>
    </div>
</template>
