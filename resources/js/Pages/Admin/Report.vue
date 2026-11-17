<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { formatUsd } from '@/lib/money';

defineProps({
    days: Number,
    startDate: String,
    endDate: String,
    byDay: Array,
    totals: Object,
    pixelforgeError: String,
});

const ranges = [7, 30, 90];
</script>

<template>
    <Head title="Report" />

    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">Spend, cost and profit</h1>
            <p class="mt-1 text-sm text-stone-500">
                {{ startDate }} to {{ endDate }} (UTC). A generation counts on the day it completed, the same rule
                Pixelforge bills by.
            </p>
        </div>
        <div class="flex gap-1 rounded-lg border border-stone-200 bg-white p-1 text-sm">
            <Link
                v-for="range in ranges"
                :key="range"
                :href="`/admin/report?days=${range}`"
                class="rounded-md px-3 py-1 font-medium"
                :class="days === range ? 'bg-stone-900 text-white' : 'text-stone-600 hover:bg-stone-100'"
            >
                {{ range }} days
            </Link>
        </div>
    </div>

    <div v-if="pixelforgeError" role="alert" class="mb-6 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
        {{ pixelforgeError }} Our own figures below are unaffected; only the comparison is missing.
    </div>

    <dl class="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div class="rounded-xl border border-stone-200 bg-white p-4 shadow-xs">
            <dt class="text-sm text-stone-500">Charged to users</dt>
            <dd class="mt-1 text-xl font-semibold tabular-nums">{{ formatUsd(totals.charged_nano) }}</dd>
            <dd class="text-xs text-stone-500">{{ totals.generations }} successful generations</dd>
        </div>
        <div class="rounded-xl border border-stone-200 bg-white p-4 shadow-xs">
            <dt class="text-sm text-stone-500">Provider cost (our records)</dt>
            <dd class="mt-1 text-xl font-semibold tabular-nums">{{ formatUsd(totals.cost_nano) }}</dd>
        </div>
        <div class="rounded-xl border border-stone-200 bg-white p-4 shadow-xs">
            <dt class="text-sm text-stone-500">Provider cost (Pixelforge)</dt>
            <dd class="mt-1 text-xl font-semibold tabular-nums">
                {{ totals.pixelforge_cost_nano === null ? '—' : formatUsd(totals.pixelforge_cost_nano) }}
            </dd>
            <dd v-if="totals.matches_pixelforge !== null" class="text-xs font-medium" :class="totals.matches_pixelforge ? 'text-emerald-700' : 'text-red-700'">
                {{ totals.matches_pixelforge ? '✓ Matches our records exactly' : '✗ Differs from our records' }}
            </dd>
        </div>
        <div class="rounded-xl border border-stone-200 bg-white p-4 shadow-xs">
            <dt class="text-sm text-stone-500">Profit</dt>
            <dd class="mt-1 text-xl font-semibold tabular-nums text-emerald-700">{{ formatUsd(totals.profit_nano) }}</dd>
        </div>
    </dl>

    <div v-if="!byDay.length" class="rounded-xl border border-dashed border-stone-300 bg-white px-6 py-12 text-center text-sm text-stone-500">
        No successful generations in this period.
    </div>

    <div v-else class="overflow-x-auto rounded-xl border border-stone-200 bg-white shadow-xs">
        <table class="w-full text-sm">
            <thead class="border-b border-stone-200 bg-stone-50 text-left text-xs text-stone-500">
                <tr>
                    <th scope="col" class="px-4 py-2 font-medium">Day / user</th>
                    <th scope="col" class="px-4 py-2 text-right font-medium">Generations</th>
                    <th scope="col" class="px-4 py-2 text-right font-medium">Charged</th>
                    <th scope="col" class="px-4 py-2 text-right font-medium">Provider cost</th>
                    <th scope="col" class="px-4 py-2 text-right font-medium">Pixelforge says</th>
                    <th scope="col" class="px-4 py-2 text-right font-medium">Profit</th>
                </tr>
            </thead>
            <tbody v-for="day in byDay" :key="day.date" class="border-b border-stone-200 last:border-0">
                <tr class="bg-stone-50/60 font-semibold">
                    <th scope="row" class="px-4 py-2 text-left">{{ day.date }}</th>
                    <td class="px-4 py-2 text-right tabular-nums">{{ day.generations }}</td>
                    <td class="px-4 py-2 text-right tabular-nums">{{ formatUsd(day.charged_nano) }}</td>
                    <td class="px-4 py-2 text-right tabular-nums">{{ formatUsd(day.cost_nano) }}</td>
                    <td class="px-4 py-2 text-right tabular-nums">
                        <template v-if="day.pixelforge_cost_nano !== null">
                            {{ formatUsd(day.pixelforge_cost_nano) }}
                            <span :class="day.matches_pixelforge ? 'text-emerald-700' : 'text-red-700'" :title="day.matches_pixelforge ? 'Matches' : 'Differs'">
                                {{ day.matches_pixelforge ? '✓' : '✗' }}
                            </span>
                        </template>
                        <template v-else>—</template>
                    </td>
                    <td class="px-4 py-2 text-right tabular-nums">{{ formatUsd(day.profit_nano) }}</td>
                </tr>
                <tr v-for="user in day.users" :key="user.email" class="text-stone-600">
                    <td class="py-1.5 pr-4 pl-8">
                        {{ user.name }} <span class="text-stone-400">{{ user.email }}</span>
                    </td>
                    <td class="px-4 py-1.5 text-right tabular-nums">{{ user.generations }}</td>
                    <td class="px-4 py-1.5 text-right tabular-nums">{{ formatUsd(user.charged_nano) }}</td>
                    <td class="px-4 py-1.5 text-right tabular-nums">{{ formatUsd(user.cost_nano) }}</td>
                    <td class="px-4 py-1.5"></td>
                    <td class="px-4 py-1.5 text-right tabular-nums">{{ formatUsd(user.profit_nano) }}</td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
