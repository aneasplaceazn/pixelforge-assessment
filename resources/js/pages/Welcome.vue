<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { dashboard, login, register } from '@/routes';
</script>

<template>
    <div class="min-h-screen bg-background text-foreground">
        <Head title="Turn your ideas into images" />
        <nav
            class="mx-auto flex max-w-6xl items-center justify-between p-6"
            aria-label="Main navigation"
        >
            <span class="text-lg font-semibold tracking-tight"
                >✦ PixelForge</span
            >
            <Link
                v-if="$page.props.auth.user"
                :href="dashboard()"
                class="rounded-full border px-5 py-2 text-sm"
                >Open studio</Link
            >
            <Link
                v-else
                :href="login()"
                class="rounded-full border px-5 py-2 text-sm"
                >Log in</Link
            >
        </nav>
        <main
            class="mx-auto grid max-w-6xl gap-12 px-6 py-20 md:grid-cols-2 md:items-center md:py-32"
        >
            <div class="space-y-7">
                <p
                    class="text-xs font-semibold tracking-widest text-violet-600 uppercase dark:text-violet-400"
                >
                    Your imagination, made visible
                </p>
                <h1
                    class="text-5xl leading-tight font-semibold tracking-tight md:text-6xl"
                >
                    Small prompts.<br />New possibilities.
                </h1>
                <p
                    class="max-w-md text-lg leading-relaxed text-muted-foreground"
                >
                    An image studio with transparent pricing. Describe an idea,
                    review the estimate, and follow your creation from start to
                    finish.
                </p>
                <Link
                    :href="$page.props.auth.user ? dashboard() : register()"
                    class="inline-flex rounded-full bg-foreground px-6 py-3 text-sm font-medium text-background"
                    >Open your studio →</Link
                >
                <p class="text-xs text-muted-foreground">
                    Simulated image generation. No real payments. Failed
                    generations are always free.
                </p>
            </div>
            <div
                aria-hidden="true"
                class="relative flex aspect-square items-center justify-center overflow-hidden rounded-[3rem] bg-violet-100 dark:bg-violet-950"
            >
                <div
                    class="absolute top-12 right-12 size-40 rounded-full bg-amber-200 blur-2xl dark:bg-amber-800/60"
                />
                <div
                    class="absolute bottom-8 left-8 size-64 rounded-full bg-violet-400 blur-3xl dark:bg-violet-600"
                />
                <span class="relative text-[12rem] leading-none text-white"
                    >✦</span
                >
            </div>
        </main>
    </div>
</template>
