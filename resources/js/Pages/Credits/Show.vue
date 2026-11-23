<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, router, usePage } from '@inertiajs/vue3';

defineProps({ packs: Array });

const page = usePage();

function buy(amount) {
    router.post(route('credits.store'), { amount }, { preserveScroll: true });
}
</script>

<template>
    <Head title="Credits" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Credits</h2>
        </template>

        <div class="py-10">
            <div class="mx-auto max-w-2xl space-y-6 px-4">
                <div class="rounded-lg bg-white p-6 shadow-sm">
                    <p class="text-sm text-gray-500">Available balance</p>
                    <p class="mt-1 text-4xl font-semibold text-gray-900">{{ page.props.auth.balance }}</p>
                    <p class="mt-2 text-xs text-gray-500">
                        Credits in flight are held at the maximum estimate until a generation finishes, then
                        you are charged the exact amount and the rest is released.
                    </p>
                </div>

                <p v-if="page.props.flash.status" class="rounded-md bg-green-50 p-3 text-sm text-green-800">
                    {{ page.props.flash.status }}
                </p>

                <div class="rounded-lg bg-white p-6 shadow-sm">
                    <h3 class="font-medium text-gray-900">Buy credits</h3>
                    <p class="mt-1 text-xs text-gray-500">This is a demo: no real payment is taken.</p>
                    <div class="mt-4 flex gap-3">
                        <button
                            v-for="amount in packs"
                            :key="amount"
                            class="rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white"
                            @click="buy(amount)"
                        >
                            Add ${{ amount }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
