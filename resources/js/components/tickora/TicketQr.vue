<script setup lang="ts">
import QRCode from 'qrcode';
import { ref, watchEffect } from 'vue';

/**
 * A ticket's QR code, drawn in the browser from its payload so the secret
 * never travels as an image URL. Always dark-on-white, whatever the theme,
 * because scanners need the contrast.
 */
const props = withDefaults(
    defineProps<{ payload: string; size?: number; label: string }>(),
    { size: 220 },
);

const svg = ref('');

watchEffect(async () => {
    svg.value = await QRCode.toString(props.payload, {
        type: 'svg',
        errorCorrectionLevel: 'M',
        margin: 2,
        color: { dark: '#140F2E', light: '#FFFFFF' },
    });
});
</script>

<template>
    <div
        role="img"
        :aria-label="label"
        class="overflow-hidden rounded-xl bg-white p-2 [&>svg]:block [&>svg]:size-full"
        :style="{ width: `${size}px`, height: `${size}px` }"
        v-html="svg"
    />
</template>
