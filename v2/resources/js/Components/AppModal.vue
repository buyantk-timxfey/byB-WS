<script setup lang="ts">
import { onMounted, onUnmounted } from 'vue';

defineProps<{ open: boolean; title?: string; subtitle?: string; wide?: boolean }>();
const emit = defineEmits(['close']);

const onKey = (e: KeyboardEvent) => { if (e.key === 'Escape') emit('close'); };
onMounted(() => document.addEventListener('keydown', onKey));
onUnmounted(() => document.removeEventListener('keydown', onKey));
</script>

<template>
    <Teleport to="body">
        <div v-if="open" class="modal-ov">
            <div class="modal-box glass-strong" :class="{ 'modal-box--wide': wide }">
                <div class="modal-grip"></div>
                <div class="drawer-head">
                    <div>
                        <div class="dt">{{ title }}</div>
                        <div v-if="subtitle" class="ds">{{ subtitle }}</div>
                    </div>
                    <button class="drawer-x" @click="emit('close')">×</button>
                </div>
                <div class="drawer-body"><slot /></div>
                <div v-if="$slots.footer" class="drawer-foot"><slot name="footer" /></div>
            </div>
        </div>
    </Teleport>
</template>
