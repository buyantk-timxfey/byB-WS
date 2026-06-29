<script setup lang="ts">
import { ref, onMounted, onUnmounted } from 'vue';
import { router } from '@inertiajs/vue3';

type Note = { dot: string; t: string; s: string; url?: string };
const props = defineProps<{ items?: Note[] }>();

const open = ref(false);
function toggle() { open.value = !open.value; }
function hide() { open.value = false; }
function go(url?: string) { hide(); if (url) router.visit(url); }
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
                <a @click="hide">Закрыть</a>
            </div>
            <button v-for="(n, i) in (items ?? [])" :key="i" class="notif-item" @click="go(n.url)">
                <span class="nd" :style="`background:${n.dot}`"></span>
                <div>
                    <div class="nti">{{ n.t }}</div>
                    <div class="nsi">{{ n.s }}</div>
                </div>
            </button>
            <div v-if="!(items && items.length)" class="notif-empty">Нет новых уведомлений</div>
        </div>
    </div>
</template>

<style scoped>
.notif-item { width: 100%; text-align: left; background: transparent; border: 0; cursor: pointer; }
.notif-empty { padding: 22px; text-align: center; color: var(--ink-3); font-size: 14px; }
</style>
