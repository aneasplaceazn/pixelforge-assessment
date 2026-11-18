<script setup lang="ts">
import { Head, Link, useForm, usePoll } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { money } from '@/lib/money';
import { dashboard } from '@/routes';
import { index, store } from '@/routes/credits';

defineProps<{
    credits: { available: string; reserved: string; outstanding: string };
    entries: {
        data: {
            id: number;
            kind: string;
            amount: string;
            available_delta: string;
            reserved_delta: string;
            outstanding_delta: string;
            created_at: string;
        }[];
        current_page: number;
        last_page: number;
    };
}>();
defineOptions({
    layout: { breadcrumbs: [{ title: 'Credits', href: index() }] },
});
const form = useForm({ amount: '5', purchase_key: crypto.randomUUID() });
usePoll(5000, { only: ['credits', 'entries'] });
function purchase() {
    form.post(store.url(), {
        preserveScroll: true,
        onSuccess: () => {
            form.purchase_key = crypto.randomUUID();
        },
    });
}
const labels: Record<string, string> = {
    purchase: 'Simulated purchase',
    reserve: 'Generation reservation',
    settle: 'Final generation charge',
    release: 'Unused reservation released',
    debt_payment: 'Outstanding charge paid',
};
</script>

<template>
    <div class="mx-auto w-full max-w-5xl space-y-6 p-4 md:p-8">
        <Head title="Credits" />
        <header class="flex flex-wrap items-center justify-between gap-4">
            <h1 class="text-3xl font-semibold">Your credits</h1>
            <Link :href="dashboard()" class="underline"
                >Back to image studio</Link
            >
        </header>
        <p class="text-muted-foreground">
            USD credits are simulated. No real payment is collected.
        </p>
        <dl class="grid gap-4 sm:grid-cols-3" aria-live="polite">
            <div class="rounded-xl border p-4">
                <dt>Available credits</dt>
                <dd
                    data-credit-available
                    class="mt-2 text-xl font-semibold break-all"
                >
                    {{ money(credits.available) }}
                </dd>
            </div>
            <div class="rounded-xl border p-4">
                <dt>Reserved for pending images</dt>
                <dd class="mt-2 text-xl font-semibold break-all">
                    {{ money(credits.reserved) }}
                </dd>
            </div>
            <div class="rounded-xl border p-4">
                <dt>Outstanding charges</dt>
                <dd class="mt-2 text-xl font-semibold break-all">
                    {{ money(credits.outstanding) }}
                </dd>
            </div>
        </dl>
        <p class="text-sm text-muted-foreground">
            Reservations hold the maximum estimate until the result is known.
            Failed images release the full reservation. If provider rates
            increase before acceptance, the exact final charge may exceed the
            estimate; purchases and released credits pay any outstanding amount
            first.
        </p>
        <form
            class="space-y-3 rounded-xl border p-5"
            @submit.prevent="purchase"
        >
            <h2 class="text-lg font-semibold">Buy credits</h2>
            <Label for="credit-amount">Simulated purchase amount</Label>
            <select
                id="credit-amount"
                v-model="form.amount"
                :disabled="form.processing"
                :aria-invalid="!!form.errors.amount"
                aria-describedby="amount-error"
                class="block rounded-md border bg-background p-2"
            >
                <option value="5">$5</option>
                <option value="10">$10</option>
                <option value="20">$20</option>
            </select>
            <InputError id="amount-error" :message="form.errors.amount" />
            <InputError
                id="purchase-error"
                :message="form.errors.purchase_key"
                role="alert"
            />
            <Button
                type="submit"
                :disabled="form.processing"
                aria-describedby="purchase-error"
                >{{
                    form.processing
                        ? 'Adding credits…'
                        : 'Add simulated credits'
                }}</Button
            >
            <p v-if="form.recentlySuccessful" role="status">
                Credits added. No real payment was made.
            </p>
        </form>
        <section aria-labelledby="transactions-title" class="space-y-3">
            <h2 id="transactions-title" class="text-lg font-semibold">
                Credit transaction history
            </h2>
            <p v-if="!entries.data.length">No transactions yet.</p>
            <ol class="divide-y rounded-xl border">
                <li
                    v-for="entry in entries.data"
                    :key="entry.id"
                    class="space-y-1 p-4"
                >
                    <div class="flex flex-wrap justify-between gap-2">
                        <span class="font-medium">{{
                            labels[entry.kind] ?? entry.kind
                        }}</span
                        ><span class="break-all">{{
                            money(entry.amount)
                        }}</span>
                    </div>
                    <time class="text-xs text-muted-foreground">{{
                        entry.created_at
                    }}</time>
                    <p class="text-xs break-all text-muted-foreground">
                        Available change: {{ money(entry.available_delta) }} ·
                        Reserved change: {{ money(entry.reserved_delta) }} ·
                        Outstanding change: {{ money(entry.outstanding_delta) }}
                    </p>
                </li>
            </ol>
            <nav
                v-if="entries.last_page > 1"
                aria-label="Credit history pages"
                class="flex justify-between gap-4"
            >
                <Link
                    v-if="entries.current_page > 1"
                    :href="index({ query: { page: entries.current_page - 1 } })"
                    >Previous</Link
                ><span v-else />
                <span
                    >Page {{ entries.current_page }} of
                    {{ entries.last_page }}</span
                >
                <Link
                    v-if="entries.current_page < entries.last_page"
                    :href="index({ query: { page: entries.current_page + 1 } })"
                    >Next</Link
                >
            </nav>
        </section>
    </div>
</template>
