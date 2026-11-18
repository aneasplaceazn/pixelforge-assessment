<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { money } from '@/lib/money';
import { dashboard } from '@/routes';
import { index, reconcile } from '@/routes/reports';
type Totals = {
    total: number;
    succeeded: number;
    failed: number;
    charges: string;
    provider_cost: string;
    profit: string;
};
type Comparison = {
    state: string;
    checked_at: string;
    message: string | null;
    pending_count: number;
    legacy_pending_count: number;
    legacy_scope_count: number;
    inventory_state: string;
    unscoped_provider_count: number;
    missing_local: string[];
    missing_provider: string[];
    unsettled: string[];
    mismatched: string[];
    days: {
        date: string;
        local_cost: string;
        provider_cost: string;
        local_count: number;
        provider_count: number;
        state: string;
    }[];
};
const props = defineProps<{
    start: string;
    end: string;
    report: {
        totals: Totals;
        days: (Totals & { day: string })[];
        users: {
            data: (Totals & { identity: string; name: string | null })[];
            current_page: number;
            last_page: number;
        };
        pending: number;
    };
    reconciliation: Comparison | null;
}>();
defineOptions({
    layout: { breadcrumbs: [{ title: 'Financial reports', href: index() }] },
});
const filter = useForm({ start: props.start, end: props.end });
const comparison = useForm({ start: props.start, end: props.end });
function compare() {
    comparison.start = props.start;
    comparison.end = props.end;
    comparison.post(reconcile.url(), { preserveScroll: true });
}
</script>

<template>
    <div class="mx-auto w-full max-w-6xl space-y-6 p-4 md:p-8">
        <Head title="Financial reports" />
        <header class="flex flex-wrap items-center justify-between gap-4">
            <h1 class="text-3xl font-semibold">Financial reports</h1>
            <Link :href="dashboard()" class="underline">Image studio</Link>
        </header>
        <p class="text-sm text-muted-foreground">
            Operators only. All amounts are exact USD. Successful charges and
            provider costs use the provider's UTC completion day, including late
            settlements. Pending requests are counted on their creation day and
            carry no financial charge yet. Profit is billed charges minus costs,
            not cash receipts or simulated credit purchases.
        </p>
        <form
            class="flex flex-wrap items-end gap-4"
            @submit.prevent="filter.get(index.url())"
        >
            <div class="space-y-2">
                <Label for="start">Start date (UTC)</Label
                ><Input
                    id="start"
                    v-model="filter.start"
                    type="date"
                    required
                    :aria-invalid="!!filter.errors.start"
                    aria-describedby="start-error"
                /><InputError id="start-error" :message="filter.errors.start" />
            </div>
            <div class="space-y-2">
                <Label for="end">End date (UTC)</Label
                ><Input
                    id="end"
                    v-model="filter.end"
                    type="date"
                    required
                    :aria-invalid="!!filter.errors.end"
                    aria-describedby="end-error"
                /><InputError id="end-error" :message="filter.errors.end" />
            </div>
            <Button :disabled="filter.processing">Apply dates</Button>
        </form>
        <p>Showing {{ start }} to {{ end }} (UTC)</p>
        <dl class="grid gap-4 sm:grid-cols-3">
            <div class="rounded-xl border p-4">
                <dt>Total user charges</dt>
                <dd class="mt-2 text-xl font-semibold break-all">
                    {{ money(report.totals.charges) }}
                </dd>
            </div>
            <div class="rounded-xl border p-4">
                <dt>Provider costs</dt>
                <dd class="mt-2 text-xl font-semibold break-all">
                    {{ money(report.totals.provider_cost) }}
                </dd>
            </div>
            <div class="rounded-xl border p-4">
                <dt>Gross profit</dt>
                <dd class="mt-2 text-xl font-semibold break-all">
                    {{ money(report.totals.profit) }}
                </dd>
            </div>
        </dl>
        <p>
            {{ report.totals.total }} total generations ·
            {{ report.totals.succeeded }} succeeded ·
            {{ report.totals.failed }} failed · {{ report.pending }} unresolved
            requests created through the end date
        </p>
        <section aria-labelledby="daily-title" class="space-y-3">
            <h2 id="daily-title" class="text-lg font-semibold">By UTC day</h2>
            <div
                class="overflow-x-auto rounded-xl border"
                tabindex="0"
                role="region"
                aria-label="Scrollable financial table"
            >
                <table class="w-full text-left text-sm">
                    <caption class="sr-only">
                        Daily settled financial totals
                    </caption>
                    <thead>
                        <tr>
                            <th class="p-3">Date</th>
                            <th class="p-3">Charges</th>
                            <th class="p-3">Provider costs</th>
                            <th class="p-3">Profit</th>
                            <th class="p-3">Succeeded / failed / total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="day in report.days"
                            :key="day.day"
                            class="border-t"
                        >
                            <td class="p-3 whitespace-nowrap">{{ day.day }}</td>
                            <td class="p-3">{{ money(day.charges) }}</td>
                            <td class="p-3">{{ money(day.provider_cost) }}</td>
                            <td class="p-3">{{ money(day.profit) }}</td>
                            <td class="p-3">
                                {{ day.succeeded }} / {{ day.failed }} /
                                {{ day.total }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
        <section aria-labelledby="users-title" class="space-y-3">
            <h2 id="users-title" class="text-lg font-semibold">By user</h2>
            <div
                class="overflow-x-auto rounded-xl border"
                tabindex="0"
                role="region"
                aria-label="Scrollable financial table"
            >
                <table class="w-full text-left text-sm">
                    <caption class="sr-only">
                        User financial totals
                    </caption>
                    <thead>
                        <tr>
                            <th class="p-3">User</th>
                            <th class="p-3">Charges</th>
                            <th class="p-3">Provider costs</th>
                            <th class="p-3">Profit</th>
                            <th class="p-3">Succeeded / failed / total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="user in report.users.data"
                            :key="user.identity"
                            class="border-t"
                        >
                            <td class="p-3">
                                {{ user.name ?? 'Deleted account'
                                }}<span
                                    class="block text-xs break-all text-muted-foreground"
                                    >{{ user.identity }}</span
                                >
                            </td>
                            <td class="p-3">{{ money(user.charges) }}</td>
                            <td class="p-3">{{ money(user.provider_cost) }}</td>
                            <td class="p-3">{{ money(user.profit) }}</td>
                            <td class="p-3">
                                {{ user.succeeded }} / {{ user.failed }} /
                                {{ user.total }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <nav
                v-if="report.users.last_page > 1"
                aria-label="User report pages"
                class="flex justify-between gap-4"
            >
                <Link
                    v-if="report.users.current_page > 1"
                    :href="
                        index({
                            query: {
                                start,
                                end,
                                users_page: report.users.current_page - 1,
                            },
                        })
                    "
                    >Previous</Link
                ><span v-else /><span
                    >Page {{ report.users.current_page }} of
                    {{ report.users.last_page }}</span
                ><Link
                    v-if="report.users.current_page < report.users.last_page"
                    :href="
                        index({
                            query: {
                                start,
                                end,
                                users_page: report.users.current_page + 1,
                            },
                        })
                    "
                    >Next</Link
                >
            </nav>
        </section>
        <section
            aria-labelledby="reconciliation-title"
            class="space-y-3 rounded-xl border p-4"
        >
            <h2 id="reconciliation-title" class="text-lg font-semibold">
                Provider reconciliation
            </h2>
            <p class="text-sm text-muted-foreground">
                Checks the currently configured API key's usage against scoped
                local records. Generation listings span the provider account;
                records without a verified scope remain unverified. This
                read-only check never edits financial records.
            </p>
            <Button :disabled="comparison.processing" @click="compare">{{
                comparison.processing ? 'Comparing…' : 'Compare with PixelForge'
            }}</Button>
            <InputError
                :message="comparison.errors.start ?? comparison.errors.end"
                role="alert"
            />
            <p v-if="!reconciliation">Not verified for this date range.</p>
            <div v-else class="space-y-3" aria-live="polite">
                <p>
                    Comparison: <strong>{{ reconciliation.state }}</strong> ·
                    Generation inventory:
                    <strong>{{ reconciliation.inventory_state }}</strong>
                </p>
                <p class="text-xs text-muted-foreground">
                    Snapshot at {{ reconciliation.checked_at }}. New settlements
                    may have occurred since this check; refresh before relying
                    on it.
                </p>
                <p v-if="reconciliation.message" role="status">
                    {{ reconciliation.message }}
                </p>
                <p>
                    Pending: {{ reconciliation.pending_count }} ({{
                        reconciliation.legacy_pending_count
                    }}
                    with unknown scope) · Unverified legacy records:
                    {{ reconciliation.legacy_scope_count }} · Provider records
                    without scope:
                    {{ reconciliation.unscoped_provider_count }}
                </p>
                <div
                    class="overflow-x-auto"
                    tabindex="0"
                    role="region"
                    aria-label="Scrollable reconciliation table"
                >
                    <table class="w-full text-left text-sm">
                        <caption class="sr-only">
                            Provider daily comparison
                        </caption>
                        <thead>
                            <tr>
                                <th class="p-2">UTC date</th>
                                <th class="p-2">Local cost</th>
                                <th class="p-2">Provider cost</th>
                                <th class="p-2">Local / provider count</th>
                                <th class="p-2">Result</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="day in reconciliation.days"
                                :key="day.date"
                            >
                                <td class="p-2 whitespace-nowrap">
                                    {{ day.date }}
                                </td>
                                <td class="p-2">{{ money(day.local_cost) }}</td>
                                <td class="p-2">
                                    {{ money(day.provider_cost) }}
                                </td>
                                <td class="p-2">
                                    {{ day.local_count }} /
                                    {{ day.provider_count }}
                                </td>
                                <td class="p-2">{{ day.state }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p v-if="reconciliation.missing_local.length" class="break-all">
                    Provider-only records, missing locally:
                    {{ reconciliation.missing_local.join(', ') }}
                </p>
                <p
                    v-if="reconciliation.missing_provider.length"
                    class="break-all"
                >
                    Local records absent from provider listing:
                    {{ reconciliation.missing_provider.join(', ') }}
                </p>
                <p v-if="reconciliation.unsettled.length" class="break-all">
                    Pending or unsettled:
                    {{ reconciliation.unsettled.join(', ') }}
                </p>
                <p v-if="reconciliation.mismatched.length" class="break-all">
                    Generation cost or status mismatch:
                    {{ reconciliation.mismatched.join(', ') }}
                </p>
            </div>
        </section>
    </div>
</template>
