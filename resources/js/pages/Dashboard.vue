<script setup lang="ts">
import { Head, Link, router, useForm, useHttp, usePoll } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { money } from '@/lib/money';
import { index as creditsPage } from '@/routes/credits';
import { index as reportsPage } from '@/routes/reports';
import { dashboard } from '@/routes';
import { estimate, retry, store } from '@/routes/generations';

type Generation = {
    id: string;
    prompt: string;
    size: string;
    quality: string;
    reference_images: string[];
    status: 'queued' | 'processing' | 'succeeded' | 'failed';
    estimated_charge: string;
    user_charge: string | null;
    image_url: string | null;
    image_url_expires_at: string | null;
    error_message: string | null;
    can_retry: boolean;
    created_at: string;
};
type Settings = {
    prompt: string;
    size: string;
    quality: string;
    reference_images: string[];
};
type Quote = {
    can_afford: boolean;
    user_charge: string;
    provider_cost: string;
    quote: string;
    expires_at: number;
};
const props = defineProps<{
    credits: { available: string; reserved: string; outstanding: string };
    can_report: boolean;
    generations: {
        data: Generation[];
        current_page: number;
        last_page: number;
        total: number;
    };
}>();
defineOptions({
    layout: { breadcrumbs: [{ title: 'Image studio', href: dashboard() }] },
});
const form = useForm('generation-draft', {
    prompt: '',
    size: '1024x1024',
    quality: 'medium',
    reference_images: [] as string[],
    quote: '',
});
const referenceText = ref(form.reference_images.join('\n'));
const quote = ref<Quote | null>(null);
const message = ref('');
const quoting = useHttp<Settings, Quote>({
    prompt: '',
    size: '',
    quality: '',
    reference_images: [],
});
const busy = computed(() => quoting.processing || form.processing);
const promptLength = computed(() => Array.from(form.prompt).length);
const retrying = ref<string | null>(null);
const imageErrors = ref<Record<string, boolean>>({});
const imageAttempts = ref<Record<string, number>>({});
const errors = computed(() => ({ ...quoting.errors, ...form.errors }));
const referenceErrors = computed(() =>
    Object.entries(errors.value)
        .filter(([key]) => key.startsWith('reference_images'))
        .map(([, error]) => error),
);
function imageExpired(generation: Generation): boolean {
    return (
        generation.image_url_expires_at !== null &&
        Date.parse(generation.image_url_expires_at) <= Date.now()
    );
}
function retryImage(generation: Generation) {
    imageAttempts.value[generation.id] =
        (imageAttempts.value[generation.id] ?? 0) + 1;
    imageErrors.value[generation.id] = false;
}
usePoll(3000, { only: ['generations', 'credits'] });
watch(
    [() => form.prompt, () => form.size, () => form.quality, referenceText],
    () => {
        quote.value = null;
        form.quote = '';
        form.reference_images = referenceText.value
            .split('\n')
            .map((url) => url.trim())
            .filter(Boolean);
        message.value = '';
        form.clearErrors();
        quoting.clearErrors();
    },
);
async function getEstimate() {
    message.value = '';
    quote.value = null;
    quoting.transform(() => ({
        prompt: form.prompt,
        size: form.size,
        quality: form.quality,
        reference_images: form.reference_images,
    }));
    try {
        const result = await quoting.post(estimate.url());
        if (!result) {
            throw new Error('Estimate unavailable');
        }
        quote.value = result;
        form.quote = result.quote;
    } catch {
        message.value = quoting.hasErrors
            ? 'Please check the highlighted settings.'
            : 'Pricing is unavailable right now. Please try again shortly.';
    }
}
function generate() {
    form.post(store.url(), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            referenceText.value = '';
            quote.value = null;
        },
    });
}
function checkAgain(generation: Generation) {
    retrying.value = generation.id;
    router.post(
        retry.url(generation.id),
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                retrying.value = null;
            },
        },
    );
}
function useAgain(generation: Generation) {
    form.prompt = generation.prompt;
    form.size = generation.size;
    form.quality = generation.quality;
    referenceText.value = generation.reference_images.join('\n');
    quote.value = null;
    form.quote = '';
    document.getElementById('prompt')?.focus();
}
</script>

<template>
    <div class="mx-auto w-full max-w-7xl space-y-8 p-4 md:p-8">
        <Head title="Image studio" />
        <header class="flex flex-wrap items-end justify-between gap-4">
            <div class="space-y-2">
                <p
                    class="text-xs font-semibold tracking-widest text-muted-foreground uppercase"
                >
                    PixelForge / Image studio
                </p>
                <h1 class="text-3xl font-semibold tracking-tight">
                    A thought. An image.
                </h1>
                <p class="text-muted-foreground">
                    Describe what you see. Review the price. Make it yours.
                </p>
            </div>
            <span
                class="rounded-full border px-3 py-1 text-xs text-muted-foreground"
                >Simulated images · No real payments</span
            >
        </header>
        <section
            aria-label="Credit balance"
            class="flex flex-wrap items-center gap-4 rounded-xl border p-4 text-sm"
        >
            <span
                >Available:
                <strong>{{ money(credits.available) }}</strong></span
            >
            <span>Reserved: {{ money(credits.reserved) }}</span>
            <Link :href="creditsPage()" class="font-medium underline"
                >Buy credits</Link
            >
            <Link
                v-if="can_report"
                :href="reportsPage()"
                class="font-medium underline"
                >Financial reports</Link
            >
        </section>
        <div
            class="grid items-start gap-8 lg:grid-cols-[minmax(280px,360px)_1fr]"
        >
            <section
                aria-labelledby="create-title"
                class="rounded-2xl border bg-card p-5 shadow-sm"
            >
                <h2 id="create-title" class="mb-5 text-lg font-semibold">
                    Create an image
                </h2>
                <form
                    class="space-y-5"
                    @submit.prevent="quote ? generate() : getEstimate()"
                >
                    <fieldset
                        :disabled="busy"
                        class="space-y-5 disabled:opacity-60"
                    >
                        <div class="space-y-2">
                            <Label for="prompt">Your prompt</Label>
                            <textarea
                                id="prompt"
                                v-model="form.prompt"
                                required
                                rows="5"
                                placeholder="A quiet lighthouse at dawn, soft light and muted colors…"
                                class="w-full resize-y rounded-lg border bg-background p-3 text-sm focus-visible:ring-2 focus-visible:ring-ring"
                                :aria-invalid="!!errors.prompt"
                                :aria-describedby="
                                    errors.prompt
                                        ? 'prompt-help prompt-error'
                                        : 'prompt-help'
                                "
                            />
                            <p
                                id="prompt-help"
                                class="text-xs text-muted-foreground"
                            >
                                {{ promptLength }} / 4,000 characters. Be as
                                specific as you like.
                            </p>
                            <InputError
                                id="prompt-error"
                                :message="
                                    form.errors.prompt || quoting.errors.prompt
                                "
                            />
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div class="space-y-2">
                                <Label for="size">Size</Label>
                                <select
                                    id="size"
                                    v-model="form.size"
                                    :aria-invalid="!!errors.size"
                                    :aria-describedby="
                                        errors.size ? 'size-error' : undefined
                                    "
                                    class="h-10 w-full rounded-lg border bg-background px-2 text-sm focus-visible:ring-2 focus-visible:ring-ring"
                                >
                                    <option value="1024x1024">Square</option>
                                    <option value="1536x1024">Landscape</option>
                                    <option value="1024x1536">Portrait</option>
                                </select>
                                <p class="text-xs text-muted-foreground">
                                    {{ form.size.replace('x', ' × ') }} pixels
                                </p>
                                <InputError
                                    id="size-error"
                                    :message="
                                        form.errors.size || quoting.errors.size
                                    "
                                />
                            </div>
                            <div class="space-y-2">
                                <Label for="quality">Quality</Label>
                                <select
                                    id="quality"
                                    v-model="form.quality"
                                    :aria-invalid="!!errors.quality"
                                    :aria-describedby="
                                        errors.quality
                                            ? 'quality-error'
                                            : undefined
                                    "
                                    class="h-10 w-full rounded-lg border bg-background px-2 text-sm focus-visible:ring-2 focus-visible:ring-ring"
                                >
                                    <option value="low">Low</option>
                                    <option value="medium">Medium</option>
                                    <option value="high">High</option>
                                </select>
                                <InputError
                                    id="quality-error"
                                    :message="
                                        form.errors.quality ||
                                        quoting.errors.quality
                                    "
                                />
                            </div>
                        </div>
                        <div class="space-y-2">
                            <Label for="references"
                                >Reference image URLs
                                <span class="font-normal text-muted-foreground"
                                    >(optional)</span
                                ></Label
                            >
                            <textarea
                                id="references"
                                v-model="referenceText"
                                rows="3"
                                class="w-full rounded-lg border bg-background p-3 text-sm focus-visible:ring-2 focus-visible:ring-ring"
                                placeholder="https://example.com/image.png"
                                :aria-invalid="referenceErrors.length > 0"
                                :aria-describedby="
                                    referenceErrors.length
                                        ? 'reference-help reference-errors'
                                        : 'reference-help'
                                "
                            />
                            <p
                                id="reference-help"
                                class="text-xs text-muted-foreground"
                            >
                                One http(s) URL per line, up to four. Each
                                reference adds to the price.
                            </p>
                            <div id="reference-errors">
                                <InputError
                                    v-for="(error, index) in referenceErrors"
                                    :key="index"
                                    :message="error"
                                />
                            </div>
                        </div>
                    </fieldset>
                    <div aria-live="polite" class="space-y-3">
                        <div
                            v-if="quote"
                            class="space-y-2 rounded-xl bg-violet-50 p-4 dark:bg-violet-950/40"
                        >
                            <p
                                class="text-xs font-medium text-violet-800 dark:text-violet-200"
                            >
                                Maximum estimated price · USD
                            </p>
                            <p
                                class="text-2xl font-semibold tracking-tight break-all"
                            >
                                {{ money(quote.user_charge) }}
                            </p>
                            <p
                                class="text-xs leading-relaxed text-muted-foreground"
                            >
                                Includes a 25% markup and maximum token usage at
                                current rates. Final cost uses actual usage and
                                the rates when the provider accepts your
                                request. Failed generations are free.
                            </p>
                            <button
                                type="button"
                                class="text-xs underline underline-offset-4"
                                :disabled="busy"
                                @click="getEstimate"
                            >
                                Refresh estimate
                            </button>
                        </div>
                        <p v-else class="text-sm text-muted-foreground">
                            Review your maximum estimated price before
                            submitting.
                        </p>
                        <p
                            v-if="message"
                            role="alert"
                            class="text-sm text-destructive"
                        >
                            {{ message }}
                        </p>
                        <InputError
                            id="quote-error"
                            role="alert"
                            :message="form.errors.quote"
                        />
                    </div>
                    <p
                        v-if="quote && !quote.can_afford"
                        id="credit-error"
                        role="status"
                        class="text-sm text-destructive"
                    >
                        Insufficient available credits. Buy simulated credits,
                        then refresh this estimate.
                    </p>
                    <Button
                        type="submit"
                        class="w-full"
                        :aria-describedby="
                            form.errors.quote ? 'quote-error' : undefined
                        "
                        :disabled="
                            busy ||
                            !form.prompt.trim() ||
                            promptLength > 4000 ||
                            (quote !== null && !quote.can_afford)
                        "
                    >
                        {{
                            form.processing
                                ? 'Submitting…'
                                : quoting.processing
                                  ? 'Calculating…'
                                  : quote
                                    ? 'Generate image'
                                    : 'Get price estimate'
                        }}
                    </Button>
                </form>
            </section>
            <section aria-labelledby="history-title" class="min-w-0 space-y-5">
                <div class="flex items-center justify-between gap-3">
                    <h2 id="history-title" class="text-lg font-semibold">
                        Your generations
                        <span
                            class="ml-1 text-sm font-normal text-muted-foreground"
                            >{{ generations.total }}</span
                        >
                    </h2>
                    <span class="text-xs text-muted-foreground"
                        >Updates automatically</span
                    >
                </div>
                <div
                    v-if="!generations.data.length"
                    class="flex min-h-80 flex-col items-center justify-center gap-3 rounded-2xl border border-dashed p-8 text-center"
                >
                    <span aria-hidden="true" class="text-4xl text-violet-500"
                        >✦</span
                    >
                    <h3 class="font-medium">Your next idea belongs here</h3>
                    <p class="max-w-xs text-sm text-muted-foreground">
                        Start with a prompt. Your images, status updates, and
                        exact charges will appear here.
                    </p>
                </div>
                <div
                    class="grid gap-5 sm:grid-cols-2"
                    aria-live="polite"
                    aria-relevant="additions text"
                >
                    <article
                        v-for="generation in generations.data"
                        :key="generation.id"
                        class="overflow-hidden rounded-2xl border bg-card"
                        :data-generation-id="generation.id"
                    >
                        <div
                            class="relative flex aspect-square items-center justify-center bg-muted/50"
                        >
                            <img
                                v-if="
                                    generation.image_url &&
                                    !imageErrors[generation.id] &&
                                    !imageExpired(generation)
                                "
                                :key="imageAttempts[generation.id] ?? 0"
                                :src="generation.image_url"
                                :alt="generation.prompt"
                                loading="lazy"
                                referrerpolicy="no-referrer"
                                class="h-full w-full object-contain"
                                @error="imageErrors[generation.id] = true"
                            />
                            <div v-else class="space-y-2 p-6 text-center">
                                <p
                                    class="text-sm font-medium"
                                    :class="{
                                        'animate-pulse':
                                            generation.status === 'processing',
                                    }"
                                >
                                    {{
                                        generation.status === 'succeeded'
                                            ? imageExpired(generation)
                                                ? 'Image link expired'
                                                : 'Image could not load'
                                            : generation.status === 'failed'
                                              ? 'Generation unsuccessful'
                                              : generation.status ===
                                                  'processing'
                                                ? 'Creating your image…'
                                                : 'Waiting to generate'
                                    }}
                                </p>
                                <p class="text-xs text-muted-foreground">
                                    {{
                                        generation.status === 'succeeded'
                                            ? imageExpired(generation)
                                                ? 'Provider image links expire after 24 hours. Use this prompt again to create a new image.'
                                                : 'The image may be temporarily unavailable. Check your connection and retry.'
                                            : generation.status === 'failed'
                                              ? 'You were not charged.'
                                              : generation.can_retry
                                                ? 'Automatic checks are paused. Resume this saved request below.'
                                                : 'You can leave this page. We’ll keep checking.'
                                    }}
                                </p>
                                <Button
                                    v-if="
                                        generation.status === 'succeeded' &&
                                        generation.image_url &&
                                        !imageExpired(generation)
                                    "
                                    variant="outline"
                                    size="sm"
                                    @click="retryImage(generation)"
                                    >Retry image</Button
                                >
                            </div>
                            <span
                                class="absolute top-3 left-3 rounded-full border bg-background/95 px-2.5 py-1 text-xs font-medium capitalize"
                                >{{ generation.status }}</span
                            >
                        </div>
                        <div class="space-y-3 p-4">
                            <p
                                class="line-clamp-3 text-sm font-medium break-words"
                            >
                                {{ generation.prompt }}
                            </p>
                            <p class="text-xs text-muted-foreground">
                                {{ generation.size }} ·
                                {{ generation.quality }} quality
                            </p>
                            <dl class="space-y-1 text-xs">
                                <div
                                    class="flex flex-wrap justify-between gap-1"
                                >
                                    <dt class="text-muted-foreground">
                                        Max. estimate
                                    </dt>
                                    <dd class="break-all">
                                        {{ money(generation.estimated_charge) }}
                                    </dd>
                                </div>
                                <div
                                    class="flex flex-wrap justify-between gap-1"
                                >
                                    <dt class="text-muted-foreground">
                                        Final charge
                                    </dt>
                                    <dd class="font-semibold break-all">
                                        {{
                                            generation.user_charge === null
                                                ? 'Pending'
                                                : money(generation.user_charge)
                                        }}
                                    </dd>
                                </div>
                            </dl>
                            <p
                                v-if="generation.error_message"
                                class="text-xs leading-relaxed text-destructive"
                            >
                                {{ generation.error_message }}
                            </p>
                            <Button
                                v-if="generation.can_retry"
                                size="sm"
                                variant="outline"
                                :disabled="retrying === generation.id"
                                @click="checkAgain(generation)"
                                >{{
                                    retrying === generation.id
                                        ? 'Checking…'
                                        : 'Check saved request again'
                                }}</Button
                            >
                            <Button
                                v-if="
                                    ['succeeded', 'failed'].includes(
                                        generation.status,
                                    )
                                "
                                size="sm"
                                variant="ghost"
                                @click="useAgain(generation)"
                                >Use prompt again</Button
                            >
                        </div>
                    </article>
                </div>
                <nav
                    v-if="generations.last_page > 1"
                    aria-label="Generation history pages"
                    class="flex items-center justify-between text-sm"
                >
                    <Link
                        v-if="generations.current_page > 1"
                        :href="
                            dashboard({
                                query: { page: generations.current_page - 1 },
                            })
                        "
                        preserve-scroll
                        >Previous</Link
                    ><span v-else />
                    <span
                        >Page {{ generations.current_page }} of
                        {{ generations.last_page }}</span
                    >
                    <Link
                        v-if="generations.current_page < generations.last_page"
                        :href="
                            dashboard({
                                query: { page: generations.current_page + 1 },
                            })
                        "
                        preserve-scroll
                        >Next</Link
                    ><span v-else />
                </nav>
            </section>
        </div>
    </div>
</template>
