<script setup lang="ts">
import { ref, onMounted, onUnmounted, nextTick } from 'vue';
import Icon from '@/Components/Icon.vue';

const open = ref(false);
const query = ref('');
const inputEl = ref<HTMLInputElement | null>(null);

// Демо-результаты (Фаза 1 — реальный поиск по регистрам/документам).
const groups = [
    { title: 'Контрагенты', items: [
        { icon: 'building', t: 'ООО Электро', s: 'Покупатель · долг 184 000 ₽' },
        { icon: 'building', t: 'ИП Сидоров', s: 'Поставщик' },
    ] },
    { title: 'Товары', items: [
        { icon: 'box', t: 'Кабель ВВГ 3×2.5', s: 'остаток 320 м · 88 000 ₽' },
    ] },
    { title: 'Документы', items: [
        { icon: 'doc', t: 'Поступление ПОСТ-2026-0042', s: 'ИП Сидоров · 96 500 ₽' },
        { icon: 'doc', t: 'Реализация РЕАЛ-2026-0118', s: 'ООО Электро · 184 000 ₽' },
    ] },
    { title: 'Действия', items: [
        { icon: 'plus', t: 'Новая продажа', s: '' },
        { icon: 'plus', t: 'Импорт выписки', s: '' },
    ] },
];

async function show() {
    open.value = true;
    await nextTick();
    inputEl.value?.focus();
}
function hide() {
    open.value = false;
    query.value = '';
}

function onKey(e: KeyboardEvent) {
    if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
        e.preventDefault();
        open.value ? hide() : show();
    }
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
                <input ref="inputEl" v-model="query" placeholder="Поиск по системе…" autocomplete="off" />
                <span class="kbd">esc</span>
            </div>
            <div class="search-res">
                <template v-for="g in groups" :key="g.title">
                    <div class="search-grp">{{ g.title }}</div>
                    <div v-for="(it, i) in g.items" :key="i" class="search-item">
                        <span class="si-ic"><Icon :name="it.icon" :size="16" /></span>
                        <div>
                            <div class="si-t">{{ it.t }}</div>
                            <div v-if="it.s" class="si-s">{{ it.s }}</div>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </div>
</template>
