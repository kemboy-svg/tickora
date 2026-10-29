<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    CalendarPlus,
    ChartColumn,
    ClipboardCheck,
    Gauge,
    Globe,
    LayoutGrid,
    Store,
    TicketPercent,
    Ticket,
} from '@lucide/vue';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import NavFooter from '@/components/NavFooter.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import WorkspaceSwitcher from '@/components/organizer/WorkspaceSwitcher.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import type { NavItem } from '@/types';

const page = usePage();
const nav = computed(() => page.props.nav);

const mainNavItems = computed<NavItem[]>(() => [
    { title: 'My tickets', href: '/dashboard', icon: LayoutGrid },
    { title: 'Explore events', href: '/events', icon: Ticket },
]);

const workspaceRole = computed(() => page.props.workspace?.current.role);

const organizerNavItems = computed<NavItem[]>(() =>
    nav.value?.organizer
        ? [
              ...(workspaceRole.value && workspaceRole.value !== 'staff'
                  ? [
                        {
                            title: 'Overview',
                            href: '/organizer/dashboard',
                            icon: ChartColumn,
                        },
                    ]
                  : []),
              { title: 'My events', href: '/organizer/events', icon: Ticket },
              {
                  title: 'Promo codes',
                  href: '/organizer/promo-codes',
                  icon: TicketPercent,
              },
              {
                  title: 'Create event',
                  href: '/organizer/events/create',
                  icon: CalendarPlus,
              },
          ]
        : [
              {
                  title: 'Become an organiser',
                  href: '/organizer/onboarding',
                  icon: Store,
              },
          ],
);

const adminNavItems = computed<NavItem[]>(() => [
    ...(nav.value?.platform
        ? [{ title: 'Overview', href: '/admin', icon: Gauge }]
        : []),
    ...(nav.value?.moderation
        ? [
              {
                  title: 'Event review',
                  href: '/admin/events',
                  icon: ClipboardCheck,
              },
          ]
        : []),
    ...(nav.value?.verification
        ? [{ title: 'Organisers', href: '/admin/organizers', icon: Store }]
        : []),
]);

const footerNavItems: NavItem[] = [
    { title: 'Home page', href: '/', icon: Globe },
];
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link href="/dashboard">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" label="Tickora" />
            <WorkspaceSwitcher />
            <NavMain :items="organizerNavItems" label="Organiser" />
            <NavMain
                v-if="adminNavItems.length"
                :items="adminNavItems"
                label="Platform"
            />
        </SidebarContent>

        <SidebarFooter>
            <NavFooter :items="footerNavItems" />
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
