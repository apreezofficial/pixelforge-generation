<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    sizes: Array,
    qualities: Array,
    maxReferenceImages: Number,
});

const form = useForm({
    prompt: '',
    size: '1024x1024',
    quality: 'medium',
    reference_images: [],
    referenceText: '',
});

const estimate = ref(null);
const estimateError = ref(null);
let timer = null;

// Reference URLs are entered one per line; split and trim before validating.
const referenceList = computed(() =>
    form.referenceText
        .split('\n')
        .map((line) => line.trim())
        .filter(Boolean),
);

function csrfToken() {
    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
}

async function fetchEstimate() {
    if (!form.prompt.trim()) {
        estimate.value = null;
        return;
    }
    try {
        const response = await fetch('/generations/estimate', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': csrfToken(),
            },
            body: JSON.stringify({
                prompt: form.prompt,
                size: form.size,
                quality: form.quality,
                reference_images: referenceList.value,
            }),
        });
        if (!response.ok) {
            estimateError.value = 'Check the prompt and reference URLs.';
            estimate.value = null;
            return;
        }
        estimate.value = await response.json();
        estimateError.value = null;
    } catch {
        estimateError.value = 'Could not calculate the price. Try again.';
    }
}

watch(
    () => [form.prompt, form.size, form.quality, form.referenceText],
    () => {
        clearTimeout(timer);
        timer = setTimeout(fetchEstimate, 300);
    },
);

function submit() {
    form.reference_images = referenceList.value;
    form.transform((data) => ({
        prompt: data.prompt,
        size: data.size,
        quality: data.quality,
        reference_images: data.reference_images,
    })).post(route('generations.store'));
}
</script>

<template>
    <Head title="New generation" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                New generation
            </h2>
        </template>

        <div class="py-10">
            <div class="mx-auto grid max-w-5xl gap-6 px-4 md:grid-cols-3">
                <form
                    class="space-y-5 rounded-lg bg-white p-6 shadow-sm md:col-span-2"
                    @submit.prevent="submit"
                >
                    <div>
                        <label class="block text-sm font-medium text-gray-700" for="prompt">Prompt</label>
                        <textarea
                            id="prompt"
                            v-model="form.prompt"
                            rows="4"
                            maxlength="4000"
                            class="mt-1 w-full rounded-md border-gray-300"
                            placeholder="A lighthouse at dusk, watercolour style"
                        />
                        <p v-if="form.errors.prompt" class="mt-1 text-sm text-red-600">{{ form.errors.prompt }}</p>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label class="block text-sm font-medium text-gray-700" for="size">Size</label>
                            <select id="size" v-model="form.size" class="mt-1 w-full rounded-md border-gray-300">
                                <option v-for="size in sizes" :key="size" :value="size">{{ size }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700" for="quality">Quality</label>
                            <select id="quality" v-model="form.quality" class="mt-1 w-full rounded-md border-gray-300">
                                <option v-for="quality in qualities" :key="quality" :value="quality">
                                    {{ quality }}
                                </option>
                            </select>
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700" for="refs">
                            Reference image URLs (optional, up to {{ maxReferenceImages }}, one per line)
                        </label>
                        <textarea
                            id="refs"
                            v-model="form.referenceText"
                            rows="3"
                            class="mt-1 w-full rounded-md border-gray-300"
                        />
                        <p v-if="form.errors.reference_images" class="mt-1 text-sm text-red-600">
                            {{ form.errors.reference_images }}
                        </p>
                        <p v-if="referenceList.length > maxReferenceImages" class="mt-1 text-sm text-red-600">
                            Use at most {{ maxReferenceImages }} reference images.
                        </p>
                    </div>

                    <button
                        type="submit"
                        class="rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white disabled:opacity-50"
                        :disabled="form.processing || !form.prompt.trim() || referenceList.length > maxReferenceImages"
                    >
                        Generate
                    </button>
                </form>

                <aside class="rounded-lg bg-white p-6 shadow-sm">
                    <h3 class="text-sm font-medium text-gray-500">Estimated cost</h3>
                    <p v-if="estimate" class="mt-2 text-3xl font-semibold text-gray-900">
                        up to {{ estimate.charge_display }}
                    </p>
                    <p v-else class="mt-2 text-sm text-gray-500">Enter a prompt to see the price.</p>
                    <p v-if="estimateError" class="mt-2 text-sm text-red-600">{{ estimateError }}</p>
                    <p class="mt-4 text-xs text-gray-500">
                        The final charge is based on the tokens actually used, so it can be lower than this
                        upper estimate. Failed generations cost nothing. Includes a 25% markup on provider cost.
                    </p>
                    <dl v-if="estimate" class="mt-4 space-y-1 text-xs text-gray-600">
                        <div class="flex justify-between"><dt>Text tokens (max)</dt><dd>{{ estimate.breakdown.text_tokens }}</dd></div>
                        <div class="flex justify-between"><dt>Image tokens</dt><dd>{{ estimate.breakdown.image_tokens }}</dd></div>
                        <div class="flex justify-between"><dt>Output tokens</dt><dd>{{ estimate.breakdown.output_tokens }}</dd></div>
                        <div class="flex justify-between"><dt>Reasoning tokens (max)</dt><dd>{{ estimate.breakdown.reasoning_tokens }}</dd></div>
                    </dl>
                </aside>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
