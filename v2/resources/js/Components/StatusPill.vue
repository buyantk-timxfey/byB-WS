<script setup lang="ts">
defineProps<{ text: string; variant?: 'neutral' | 'info' | 'ok' | 'warn' | 'bad' }>();
</script>

<template>
    <span class="pill" :class="'pill--' + (variant || 'neutral')">{{ text }}</span>
</template>
