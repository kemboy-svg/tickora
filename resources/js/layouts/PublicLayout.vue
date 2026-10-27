<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { Search } from '@lucide/vue';
import { computed, ref } from 'vue';
import TickoraLogo from '@/components/tickora/TickoraLogo.vue';
import { Toaster } from '@/components/ui/sonner';

const page = usePage();
const user = computed(() => page.props.auth.user ?? null);
const query = ref('');

function search(): void {
    router.get('/events', query.value ? { q: query.value } : {});
}
</script>

<template>
    <div class="flex min-h-screen flex-col bg-background text-foreground">
        <a
            href="#main"
            class="sr-only z-50 rounded-md bg-primary px-4 py-2 text-primary-foreground focus:not-sr-only focus:fixed focus:top-3 focus:left-3"
        >
            Skip to content
        </a>

        <header class="border-b bg-card">
            <div
                class="mx-auto flex max-w-7xl flex-wrap items-center gap-x-7 gap-y-3 px-4 py-3 sm:px-6"
            >
                <Link href="/" aria-label="Tickora home">
                    <TickoraLogo />
                </Link>

                <nav
                    aria-label="Primary"
                    class="flex flex-wrap gap-5 text-sm font-semibold"
                >
                    <Link href="/events" class="hover:text-primary"
                        >Explore events</Link
                    >
                    <Link
                        :href="user ? '/organizer' : '/register'"
                        class="hover:text-primary"
                        >For organisers</Link
                    >
                </nav>

                <form
                    role="search"
                    class="flex min-h-11 min-w-48 flex-1 items-center gap-2 rounded-full border bg-muted/60 px-4"
                    @submit.prevent="search"
                >
                    <Search
                        class="size-4 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <label for="site-search" class="sr-only"
                        >Search events</label
                    >
                    <input
                        id="site-search"
                        v-model="query"
                        type="search"
                        placeholder="Search events, artists, venues"
                        class="w-full bg-transparent text-sm outline-none"
                    />
                </form>

                <div class="flex items-center gap-2">
                    <template v-if="user">
                        <Link
                            href="/dashboard"
                            class="inline-flex min-h-11 items-center rounded-lg px-3 text-sm font-semibold hover:bg-accent"
                            >{{ user.name }}</Link
                        >
                    </template>
                    <template v-else>
                        <Link
                            href="/login"
                            class="inline-flex min-h-11 items-center rounded-lg px-3 text-sm font-semibold hover:bg-accent"
                            >Log in</Link
                        >
                        <Link
                            href="/register"
                            class="inline-flex min-h-11 items-center rounded-lg bg-primary px-4 text-sm font-bold text-primary-foreground hover:bg-primary/90"
                            >Create account</Link
                        >
                    </template>
                </div>
            </div>
        </header>

        <main id="main" class="flex-1">
            <slot />
        </main>

        <footer class="mt-16 bg-brand-navy text-slate-300">
            <div
                class="mx-auto grid max-w-7xl gap-8 px-4 py-12 sm:grid-cols-2 sm:px-6 lg:grid-cols-4"
            >
                <div class="flex flex-col gap-3">
                    <TickoraLogo inverted />
                    <p class="text-sm text-slate-400">
                        Every Event. One Ticket.
                    </p>
                </div>
                <nav aria-label="Discover" class="flex flex-col gap-2 text-sm">
                    <span class="font-bold text-white">Discover</span>
                    <Link href="/events" class="hover:text-white"
                        >Explore events</Link
                    >
                    <Link href="/events?when=weekend" class="hover:text-white"
                        >This weekend</Link
                    >
                    <Link href="/events?price=free" class="hover:text-white"
                        >Free events</Link
                    >
                </nav>
                <nav
                    aria-label="Organisers"
                    class="flex flex-col gap-2 text-sm"
                >
                    <span class="font-bold text-white">Organisers</span>
                    <Link href="/organizer" class="hover:text-white"
                        >Sell tickets</Link
                    >
                </nav>
                <div class="flex flex-col gap-2 text-sm">
                    <span class="font-bold text-white">Payments</span>
                    <p class="text-slate-400">
                        Prices are shown in the event's currency. Tickora never
                        stores your card details.
                    </p>
                </div>
            </div>
            <div
                class="mx-auto max-w-7xl border-t border-white/10 px-4 py-5 text-xs text-slate-400 sm:px-6"
            >
                © {{ new Date().getFullYear() }} Tickora
            </div>
        </footer>

        <Toaster />
    </div>
</template>
