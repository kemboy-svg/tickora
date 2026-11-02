<script setup lang="ts">
import { Form, Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { logout } from '@/routes';
import { send } from '@/routes/verification';

defineOptions({
    layout: {
        title: 'Confirm your email',
        description:
            'One last step: confirm your email address to buy tickets and get them by email.',
    },
});

defineProps<{
    status?: string;
}>();

const email = computed(() => usePage().props.auth?.user?.email ?? null);
</script>

<template>
    <Head title="Confirm your email" />

    <p class="mb-4 text-center text-sm text-muted-foreground">
        We sent a confirmation link to
        <strong class="text-foreground">{{ email ?? 'your email' }}</strong
        >. Open it on this device to finish. It can take a minute to arrive;
        check your spam or promotions folder too.
    </p>

    <div
        v-if="status === 'verification-link-sent'"
        class="mb-4 text-center text-sm font-medium text-green-600"
    >
        We sent a new link to {{ email ?? 'your email address' }}.
    </div>

    <Form
        v-bind="send.form()"
        class="space-y-6 text-center"
        v-slot="{ processing }"
    >
        <Button :disabled="processing" variant="secondary">
            <Spinner v-if="processing" />
            Resend verification email
        </Button>

        <p class="text-sm text-muted-foreground">
            Wrong address?
            <Link
                href="/settings/profile"
                class="font-semibold text-foreground underline underline-offset-4"
                >Change your email</Link
            >
            and we'll send the link there.
        </p>

        <TextLink :href="logout()" as="button" class="mx-auto block text-sm">
            Log out
        </TextLink>
    </Form>
</template>
