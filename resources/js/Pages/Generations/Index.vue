<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted } from 'vue';

const props = defineProps({
    generations: Object,
});

const LABELS = {
    submitting: 'Submitting',
    queued: 'Queued',
    processing: 'Processing',
    succeeded: 'Succeeded',
    failed: 'Failed',
};

const STYLES = {
    submitting: 'bg-gray-100 text-gray-700',
    queued: 'bg-gray-100 text-gray-700',
    processing: 'bg-blue-100 text-blue-800',
    succeeded: 'bg-green-100 text-green-800',
    failed: 'bg-red-100 text-red-800',
};

const rows = computed(() => props.generations.data);
const hasActive = computed(() => rows.value.some((g) => g.status !== 'succeeded' && g.status !== 'failed'));

// Poll while anything is still in flight. Once every row is final, polling stops.
let timer = null;
function poll() {
    if (hasActive.value) {
        router.reload({ only: ['generations', 'auth'], preserveScroll: true });
    }
}

onMounted(() => {
    timer = setInterval(poll, 3000);
});
onBeforeUnmount(() => clearInterval(timer));

function formatDate(iso) {
    return new Date(iso).toLocaleString();
}
</script>

<template>
    <Head title="Generations" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold leading-tight text-gray-800">Generations</h2>
                <Link
                    :href="route('generations.create')"
                    class="rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white"
                >
                    New generation
                </Link>
            </div>
        </template>

        <div class="py-10">
            <div class="mx-auto max-w-5xl px-4">
                <p v-if="rows.length === 0" class="rounded-lg bg-white p-6 text-gray-600 shadow-sm">
                    No generations yet.
                </p>

                <ul class="grid gap-4 sm:grid-cols-2">
                    <li v-for="g in rows" :key="g.id" class="overflow-hidden rounded-lg bg-white shadow-sm">
                        <div class="flex aspect-square items-center justify-center bg-gray-100">
                            <img
                                v-if="g.status === 'succeeded' && g.image_url"
                                :src="g.image_url"
                                :alt="g.prompt"
                                class="h-full w-full object-cover"
                            />
                            <span v-else-if="g.status === 'failed'" class="px-4 text-center text-sm text-red-700">
                                {{ g.error_message || 'Generation failed. You were not charged.' }}
                            </span>
                            <span v-else class="text-sm text-gray-500">{{ LABELS[g.status] }}…</span>
                        </div>

                        <div class="space-y-2 p-4">
                            <p class="line-clamp-2 text-sm text-gray-900">{{ g.prompt }}</p>
                            <div class="flex items-center justify-between text-xs">
                                <span class="rounded-full px-2 py-0.5" :class="STYLES[g.status]">
                                    {{ LABELS[g.status] }}
                                </span>
                                <span class="text-gray-500">{{ g.size }} · {{ g.quality }}</span>
                            </div>
                            <dl class="grid grid-cols-2 gap-1 text-xs text-gray-600">
                                <dt>Estimated (max)</dt>
                                <dd class="text-right">{{ g.estimated }}</dd>
                                <dt>Charged</dt>
                                <dd class="text-right">
                                    {{ g.charged ?? (g.status === 'failed' ? '$0.000000' : '—') }}
                                </dd>
                            </dl>
                            <p class="text-xs text-gray-400">{{ formatDate(g.created_at) }}</p>
                        </div>
                    </li>
                </ul>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
