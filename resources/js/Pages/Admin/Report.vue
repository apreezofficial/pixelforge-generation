<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    start: String,
    end: String,
    days: Array,
    users: Array,
    dayUsers: Array,
    totals: Object,
    usageAvailable: Boolean,
});

const range = ref({ start: props.start, end: props.end });

function apply() {
    router.get(route('admin.report'), range.value, { preserveState: true });
}

// Amounts arrive as exact nine-decimal strings; show them as-is so they can be
// compared with Pixelforge's /v1/usage figures digit for digit.
const usd = (value) => `$${value}`;
</script>

<template>
    <Head title="Operator report" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Operator report (UTC days)</h2>
        </template>

        <div class="py-10">
            <div class="mx-auto max-w-6xl space-y-8 px-4">
                <form class="flex flex-wrap items-end gap-3" @submit.prevent="apply">
                    <label class="text-sm text-gray-700">
                        From
                        <input v-model="range.start" type="date" class="mt-1 block rounded-md border-gray-300" />
                    </label>
                    <label class="text-sm text-gray-700">
                        To
                        <input v-model="range.end" type="date" class="mt-1 block rounded-md border-gray-300" />
                    </label>
                    <button class="rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white">Apply</button>
                </form>

                <div class="grid gap-4 sm:grid-cols-4">
                    <div class="rounded-lg bg-white p-4 shadow-sm">
                        <p class="text-xs text-gray-500">Generations</p>
                        <p class="text-xl font-semibold">{{ totals.generations }}</p>
                    </div>
                    <div class="rounded-lg bg-white p-4 shadow-sm">
                        <p class="text-xs text-gray-500">Credit spend (charged)</p>
                        <p class="text-xl font-semibold">{{ usd(totals.spend) }}</p>
                    </div>
                    <div class="rounded-lg bg-white p-4 shadow-sm">
                        <p class="text-xs text-gray-500">Provider cost</p>
                        <p class="text-xl font-semibold">{{ usd(totals.cost) }}</p>
                    </div>
                    <div class="rounded-lg bg-white p-4 shadow-sm">
                        <p class="text-xs text-gray-500">Profit</p>
                        <p class="text-xl font-semibold">{{ usd(totals.profit) }}</p>
                    </div>
                </div>

                <section class="overflow-x-auto rounded-lg bg-white shadow-sm">
                    <h3 class="p-4 font-medium text-gray-900">By day</h3>
                    <p v-if="!usageAvailable" class="px-4 pb-2 text-sm text-amber-700">
                        Could not reach Pixelforge's usage report, so provider costs are not cross-checked.
                    </p>
                    <table class="min-w-full text-left text-sm">
                        <thead class="bg-gray-50 text-xs uppercase text-gray-500">
                            <tr>
                                <th class="px-4 py-2">Day</th>
                                <th class="px-4 py-2">Gens</th>
                                <th class="px-4 py-2">Spend</th>
                                <th class="px-4 py-2">Provider cost</th>
                                <th class="px-4 py-2">Profit</th>
                                <th class="px-4 py-2">Pixelforge usage</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="d in days" :key="d.date" class="border-t">
                                <td class="px-4 py-2">{{ d.date }}</td>
                                <td class="px-4 py-2">{{ d.generations }}</td>
                                <td class="px-4 py-2 font-mono">{{ d.spend }}</td>
                                <td class="px-4 py-2 font-mono">{{ d.cost }}</td>
                                <td class="px-4 py-2 font-mono">{{ d.profit }}</td>
                                <td class="px-4 py-2 font-mono">
                                    <span v-if="d.pixelforge_cost === null" class="text-gray-400">n/a</span>
                                    <span v-else :class="d.matches ? 'text-green-700' : 'font-semibold text-red-700'">
                                        {{ d.pixelforge_cost }} {{ d.matches ? '✓' : 'MISMATCH' }}
                                    </span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </section>

                <section class="overflow-x-auto rounded-lg bg-white shadow-sm">
                    <h3 class="p-4 font-medium text-gray-900">By user</h3>
                    <table class="min-w-full text-left text-sm">
                        <thead class="bg-gray-50 text-xs uppercase text-gray-500">
                            <tr>
                                <th class="px-4 py-2">User</th>
                                <th class="px-4 py-2">Gens</th>
                                <th class="px-4 py-2">Spend</th>
                                <th class="px-4 py-2">Provider cost</th>
                                <th class="px-4 py-2">Profit</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="u in users" :key="u.user" class="border-t">
                                <td class="px-4 py-2">{{ u.user }}</td>
                                <td class="px-4 py-2">{{ u.generations }}</td>
                                <td class="px-4 py-2 font-mono">{{ u.spend }}</td>
                                <td class="px-4 py-2 font-mono">{{ u.cost }}</td>
                                <td class="px-4 py-2 font-mono">{{ u.profit }}</td>
                            </tr>
                        </tbody>
                    </table>
                </section>

                <section class="overflow-x-auto rounded-lg bg-white shadow-sm">
                    <h3 class="p-4 font-medium text-gray-900">By day and user</h3>
                    <table class="min-w-full text-left text-sm">
                        <thead class="bg-gray-50 text-xs uppercase text-gray-500">
                            <tr>
                                <th class="px-4 py-2">Day</th>
                                <th class="px-4 py-2">User</th>
                                <th class="px-4 py-2">Gens</th>
                                <th class="px-4 py-2">Spend</th>
                                <th class="px-4 py-2">Provider cost</th>
                                <th class="px-4 py-2">Profit</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="r in dayUsers" :key="r.date + r.user" class="border-t">
                                <td class="px-4 py-2">{{ r.date }}</td>
                                <td class="px-4 py-2">{{ r.user }}</td>
                                <td class="px-4 py-2">{{ r.generations }}</td>
                                <td class="px-4 py-2 font-mono">{{ r.spend }}</td>
                                <td class="px-4 py-2 font-mono">{{ r.cost }}</td>
                                <td class="px-4 py-2 font-mono">{{ r.profit }}</td>
                            </tr>
                        </tbody>
                    </table>
                </section>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
