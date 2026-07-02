<script setup lang="ts">
import AppModal from '@/Components/AppModal.vue';
import { confirmState, answerConfirm } from '@/lib/confirm';
</script>

<template>
    <AppModal :open="confirmState.open" title="Подтверждение" @close="answerConfirm(false)">
        <div style="font-size:14.5px;line-height:1.45">{{ confirmState.message }}</div>
        <template #footer>
            <button type="button" class="btn-ghost pressable" style="flex:1;justify-content:center" @click="answerConfirm(false)">Отмена</button>
            <button type="button" class="btn-primary pressable confirm-danger" style="flex:1;justify-content:center" @click="answerConfirm(true)">{{ confirmState.action }}</button>
        </template>
    </AppModal>
</template>

<style scoped>
.confirm-danger { background: var(--expense); border-color: var(--expense); }
</style>
