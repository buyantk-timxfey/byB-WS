<script setup lang="ts">
import { ref, onMounted, onUnmounted } from 'vue';

const open = ref(false);

// Демо (Фаза 1 — авто-генерация из регистров: долги, ETA, выписка).
const items = [
    { dot: 'var(--expense)', t: 'Долг просрочен · ООО Электро', s: '5 дней · 184 000 ₽' },
    { dot: 'var(--warn)', t: 'ETA истёк · Автоматы ABB', s: 'поставка просрочена' },
    { dot: '#0a84ff', t: 'Новая выписка', s: '5 строк не разнесено' },
    { dot: 'var(--ink-3)', t: 'Напоминание', s: 'Перезвонить в ООО Лайт по КП' },
];

function toggle() { open.value = !open.value; }
function hide() { open.value = false; }
function onKey(e: KeyboardEvent) { if (e.key === 'Escape') hide(); }

onMounted(() => document.addEventListener('keydown', onKey));
onUnmounted(() => document.removeEventListener('keydown', onKey));

defineExpose({ toggle });
</script>

<template>
    <div v-if="open" class="notif-ov" @click.self="hide">
        <div class="notif-panel glass-strong">
            <div class="notif-head">
                <span class="nt">Уведомления</span>
                <a @click="hide">Прочитать все</a>
            </div>
            <div v-for="(n, i) in items" :key="i" class="notif-item">
                <span class="nd" :style="`background:${n.dot}`"></span>
                <div>
                    <div class="nti">{{ n.t }}</div>
                    <div class="nsi">{{ n.s }}</div>
                </div>
            </div>
        </div>
    </div>
</template>
