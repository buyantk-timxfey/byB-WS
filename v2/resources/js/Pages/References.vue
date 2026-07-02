<script setup lang="ts">
import { ref, computed } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import AppShell from '@/Layouts/AppShell.vue';
import Icon from '@/Components/Icon.vue';
import StatusPill from '@/Components/StatusPill.vue';
import AppModal from '@/Components/AppModal.vue';
import SearchSelect from '@/Components/SearchSelect.vue';
import { money, num } from '@/lib/format';
import { confirmDlg } from '@/lib/confirm';

const props = defineProps<{
    counterparties: any[]; nomenclature: any[]; carriers: any[];
    accounts: any[]; articles: any[]; groups: any[];
}>();

const dir = ref<'counterparties' | 'nomenclature' | 'carriers' | 'accounts' | 'articles'>('counterparties');
const q = ref('');
const tabs = [
    { id: 'counterparties', label: 'Контрагенты' }, { id: 'nomenclature', label: 'Номенклатура' },
    { id: 'carriers', label: 'Транспортные компании' }, { id: 'accounts', label: 'Счета' }, { id: 'articles', label: 'Статьи' },
] as const;

const list = computed<any[]>(() => {
    const src = (props as any)[dir.value] as any[];
    if (!q.value) return src;
    const s = q.value.toLowerCase();
    return src.filter((x) => JSON.stringify(x).toLowerCase().includes(s));
});

const debtText = (n: number) => n > 0 ? '+ ' + money(n) + ' (нам)' : n < 0 ? '− ' + money(-n) + ' (мы)' : '—';
const cpVariant = (t: string) => t === 'Поставщик' ? 'info' : t === 'Покупатель' ? 'ok' : 'neutral';
const createLabel = computed(() => ({ counterparties: 'контрагента', nomenclature: 'товар', carriers: 'транспортную компанию', accounts: 'счёт', articles: 'статью' }[dir.value]));

async function cleanupGoods() {
    if (!await confirmDlg('Удалить товары, которых нет ни в поставках, ни в продажах, ни на складе?')) return;
    router.post('/references/nomenclature/cleanup', {}, { preserveScroll: true });
}

// ── Форма (поля переключаются по типу) ──
const open = ref(false);
const editingId = ref<number | null>(null);
const accountColors = ['#0a84ff', '#ef3124', '#34c759', '#ff9f0a', '#af52de', '#5e5ce6', '#ff375f', '#64748b'];
const blankForm = {
    type: 'Поставщик', name: '', inn: '', contact: '', comment: '',
    group_id: null, unit: 'шт', article: '',
    site: '', note: '', last4: '', color: accountColors[0], kind: 'expense',
};
const form = useForm<Record<string, any>>({ ...blankForm });

function create() {
    editingId.value = null;
    form.reset();
    open.value = true;
}
function edit(x: any) {
    editingId.value = x.id;
    form.reset();
    Object.assign(form, x);
    if (dir.value === 'accounts' && !x.color) form.color = accountColors[0];
    open.value = true;
}
function payload() {
    if (dir.value === 'counterparties') return { type: form.type, name: form.name, inn: form.inn, contact: form.contact, comment: form.comment };
    if (dir.value === 'nomenclature') return { name: form.name, group_id: form.group_id, unit: form.unit, article: form.article, comment: form.comment };
    if (dir.value === 'carriers') return { name: form.name, site: form.site, note: form.note };
    if (dir.value === 'accounts') return { name: form.name, last4: form.last4, color: form.color };
    return { name: form.name, kind: form.kind || 'expense' };
}
function submit() {
    form.transform(payload);
    const wasCreate = !editingId.value;
    const opts = {
        onSuccess: () => {
            open.value = false;
            // Inertia после успешной отправки сам делает отправленные значения новым
            // "дефолтом" для reset() — из-за этого создание следующей записи открывало
            // форму, уже заполненную предыдущей. Возвращаем дефолты к пустым явно.
            if (wasCreate) {
                form.defaults({ ...blankForm });
                form.reset();
            }
        },
    };
    if (editingId.value) form.put(`/references/${dir.value}/${editingId.value}`, opts);
    else form.post(`/references/${dir.value}`, opts);
}
async function destroy() {
    if (editingId.value && await confirmDlg('Удалить запись?')) {
        router.delete(`/references/${dir.value}/${editingId.value}`, { onSuccess: () => { open.value = false; } });
    }
}
</script>

<template>
    <Head title="Справочники" />
    <AppShell>
        <div class="toolbar">
            <h1>Справочники</h1>
            <div class="tb-search">
                <Icon name="search" :size="16" class="text-ink-3" />
                <input v-model="q" placeholder="Поиск…" />
            </div>
            <button v-if="dir === 'nomenclature'" class="btn-ghost pressable" @click="cleanupGoods"><Icon name="alert" :size="16" /> Очистить неиспользуемые</button>
            <button class="btn-primary pressable" @click="create"><Icon name="plus" :size="17" /> Добавить {{ createLabel }}</button>
        </div>

        <div class="seg ref-tabs">
            <button v-for="t in tabs" :key="t.id" :class="{ on: dir === t.id }" @click="dir = t.id; q = ''">{{ t.label }}</button>
        </div>

        <div class="jcard glass">
            <div class="jscroll">
                <table v-if="dir === 'counterparties'" class="jtable">
                    <thead><tr><th>Тип</th><th>Наименование</th><th>ИНН</th><th>Контакт</th><th class="num">Сальдо</th></tr></thead>
                    <tbody>
                        <tr v-for="x in list" :key="x.id" @click="edit(x)">
                            <td><StatusPill :text="x.type" :variant="cpVariant(x.type)" /></td>
                            <td>{{ x.name }}</td><td class="text-ink-2 tnum">{{ x.inn }}</td><td class="text-ink-2">{{ x.contact }}</td>
                            <td class="num" :style="x.debt > 0 ? { color: 'var(--income)' } : (x.debt < 0 ? { color: 'var(--expense)' } : {})">{{ debtText(x.debt) }}</td>
                        </tr>
                        <tr v-if="!list.length"><td colspan="5"><div class="j-empty">Пусто</div></td></tr>
                    </tbody>
                </table>

                <table v-else-if="dir === 'nomenclature'" class="jtable">
                    <thead><tr><th>Наименование</th><th>Группа</th><th>Ед.</th><th>Артикул</th><th class="num">Остаток</th></tr></thead>
                    <tbody>
                        <tr v-for="x in list" :key="x.id" @click="edit(x)">
                            <td>{{ x.name }}</td><td class="text-ink-2">{{ x.group ?? '—' }}</td><td class="text-ink-2">{{ x.unit }}</td>
                            <td class="text-ink-2 tnum">{{ x.article }}</td>
                            <td class="num" :style="x.qty < 0 ? { color: 'var(--expense)' } : {}">{{ num(x.qty) }}</td>
                        </tr>
                        <tr v-if="!list.length"><td colspan="5"><div class="j-empty">Пусто</div></td></tr>
                    </tbody>
                </table>

                <table v-else-if="dir === 'carriers'" class="jtable">
                    <thead><tr><th>Название</th><th>Сайт</th><th>Комментарий</th></tr></thead>
                    <tbody>
                        <tr v-for="x in list" :key="x.id" @click="edit(x)">
                            <td>{{ x.name }}</td>
                            <td><a v-if="x.site" class="ref-link" :href="'https://' + x.site" target="_blank" @click.stop>{{ x.site }}</a><span v-else>—</span></td>
                            <td class="text-ink-2">{{ x.note || '—' }}</td>
                        </tr>
                        <tr v-if="!list.length"><td colspan="3"><div class="j-empty">Пусто</div></td></tr>
                    </tbody>
                </table>

                <table v-else-if="dir === 'accounts'" class="jtable">
                    <thead><tr><th>Название</th><th>Тип</th><th class="num">Баланс</th></tr></thead>
                    <tbody>
                        <tr v-for="x in list" :key="x.id" @click="edit(x)">
                            <td>{{ x.name }}</td><td><StatusPill :text="x.type" :variant="x.type === 'Банк' ? 'info' : 'neutral'" /></td>
                            <td class="num">{{ money(x.balance) }}</td>
                        </tr>
                        <tr v-if="!list.length"><td colspan="3"><div class="j-empty">Пусто</div></td></tr>
                    </tbody>
                </table>

                <table v-else class="jtable">
                    <thead><tr><th>Статья</th><th>Вид</th><th>Тип</th></tr></thead>
                    <tbody>
                        <tr v-for="x in list" :key="x.id" @click="!x.is_system && edit(x)">
                            <td>{{ x.name }}</td>
                            <td><StatusPill :text="x.kind === 'income' ? 'Доход' : 'Расход'" :variant="x.kind === 'income' ? 'ok' : 'neutral'" /></td>
                            <td><StatusPill :text="x.is_system ? 'Системная' : 'Пользовательская'" :variant="x.is_system ? 'info' : 'neutral'" /></td>
                        </tr>
                        <tr v-if="!list.length"><td colspan="3"><div class="j-empty">Пусто</div></td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <AppModal :open="open" :title="(editingId ? 'Изменить ' : 'Новый ') + createLabel" @close="open = false">
            <template v-if="dir === 'counterparties'">
                <div class="fld-row">
                    <div class="fld"><label>Тип</label><select v-model="form.type"><option>Поставщик</option><option>Покупатель</option><option>Оба</option></select></div>
                    <div class="fld"><label>ИНН</label><input v-model="form.inn" /></div>
                </div>
                <div class="fld"><label>Наименование</label><input v-model="form.name" /></div>
                <div class="fld"><label>Контакт</label><input v-model="form.contact" placeholder="телефон / email" /></div>
                <div class="fld"><label>Комментарий</label><input v-model="form.comment" /></div>
            </template>
            <template v-else-if="dir === 'nomenclature'">
                <div class="fld"><label>Наименование</label><input v-model="form.name" /></div>
                <div class="fld-row">
                    <div class="fld"><label>Группа</label><SearchSelect v-model="form.group_id" :options="groups" placeholder="—" /></div>
                    <div class="fld"><label>Ед. изм.</label><input v-model="form.unit" /></div>
                </div>
                <div class="fld"><label>Артикул</label><input v-model="form.article" /></div>
            </template>
            <template v-else-if="dir === 'carriers'">
                <div class="fld"><label>Название</label><input v-model="form.name" /></div>
                <div class="fld"><label>Сайт (для трекинга)</label><input v-model="form.site" placeholder="cdek.ru" /></div>
                <div class="fld"><label>Комментарий</label><input v-model="form.note" /></div>
            </template>
            <template v-else-if="dir === 'accounts'">
                <div class="fld"><label>Название</label><input v-model="form.name" placeholder="Сбер · 7781" /></div>
                <div class="fld-row">
                    <div class="fld"><label>Последние 4</label><input v-model="form.last4" placeholder="7781" /></div>
                    <div class="fld"><label>Цвет карточки</label>
                        <div class="acc-colors">
                            <button v-for="c in accountColors" :key="c" type="button" class="acc-color" :class="{ on: form.color === c }" :style="{ background: c }" @click="form.color = c"></button>
                        </div>
                    </div>
                </div>
            </template>
            <template v-else>
                <div class="fld"><label>Название статьи</label><input v-model="form.name" /></div>
                <div class="fld"><label>Вид</label>
                    <select v-model="form.kind"><option value="expense">Расход</option><option value="income">Доход (кэшбэк, проценты и т.п.)</option></select>
                </div>
            </template>

            <template #footer>
                <button class="btn-primary pressable" style="flex:1;justify-content:center" :disabled="form.processing" @click="submit">Сохранить</button>
                <button v-if="editingId" class="btn-ghost pressable" @click="destroy">Удалить</button>
            </template>
        </AppModal>
    </AppShell>
</template>

<style scoped>
.acc-colors { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; height: 42px; }
.acc-color { width: 26px; height: 26px; border-radius: 50%; border: 2px solid transparent; cursor: pointer; padding: 0; outline: none; transition: transform .1s; }
.acc-color:hover { transform: scale(1.12); }
.acc-color.on { border-color: var(--ink); box-shadow: 0 0 0 2px var(--glass-bg, #fff); }
</style>
