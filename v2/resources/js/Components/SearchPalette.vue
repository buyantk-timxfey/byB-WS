<script setup lang="ts">
import { ref, onMounted, onUnmounted, nextTick, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import Icon from '@/Components/Icon.vue';

type Item = { icon: string; t: string; s: string; url: string };
type Group = { title: string; items: Item[] };

const open = ref(false);
const query = ref('');
const groups = ref<Group[]>([]);
const loading = ref(false);
const inputEl = ref<HTMLInputElement | null>(null);
let timer: ReturnType<typeof setTimeout> | null = null;

async function run() {
    const q = query.value.trim();
    if (q.length < 2) { groups.value = []; loading.value = false; return; }
    loading.value = true;
    try {
        const res = await fetch('/search?q=' + encodeURIComponent(q), { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        const data = await res.json();
        groups.value = data.groups ?? [];
    } catch { groups.value = []; }
    loading.value = false;
}
watch(query, () => {
    if (timer) clearTimeout(timer);
    timer = setTimeout(run, 220);
});

async function show() {
    open.value = true;
    await nextTick();
    inputEl.value?.focus();
}
function hide() { open.value = false; query.value = ''; groups.value = []; }
function go(url: string) { hide(); router.visit(url); }

function onKey(e: KeyboardEvent) {
    if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); open.value ? hide() : show(); }
    if (e.key === 'Escape') hide();
}
onMounted(() => document.addEventListener('keydown', onKey));
onUnmounted(() => document.removeEventListener('keydown', onKey));
defineExpose({ show });
</script>

<template>
    <div v-if="open" class="search-ov" @click.self="hide">
        <div class="search-box glass-strong">
            <div class="search-input">
                <Icon name="search" :size="20" class="text-ink-3" />
                <input ref="inputEl" v-model="query" placeholder="Поиск по контрагентам, товарам, документам…" autocomplete="off" />
                <span class="kbd">esc</span>
            </div>
            <div class="search-res">
                <div v-if="loading" class="search-empty">Поиск…</div>
                <div v-else-if="query.trim().length < 2" class="search-empty">Введите минимум 2 символа</div>
                <div v-else-if="!groups.length" class="search-empty">Ничего не найдено</div>
                <template v-for="g in groups" :key="g.title">
                    <div class="search-grp">{{ g.title }}</div>
                    <button v-for="(it, i) in g.items" :key="i" class="search-item" @click="go(it.url)">
                        <span class="si-ic"><Icon :name="it.icon" :size="16" /></span>
                        <div>
                            <div class="si-t">{{ it.t }}</div>
                            <div v-if="it.s" class="si-s">{{ it.s }}</div>
                        </div>
                    </button>
                </template>
            </div>
        </div>
    </div>
</template>

<style scoped>
.search-item { width: 100%; text-align: left; background: transparent; border: 0; cursor: pointer; }
.search-empty { padding: 24px; text-align: center; color: var(--ink-3); font-size: 14px; }
</style>
