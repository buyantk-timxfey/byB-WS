<script setup lang="ts">
import { ref, computed } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import AppShell from '@/Layouts/AppShell.vue';
import Icon from '@/Components/Icon.vue';
import StatusPill from '@/Components/StatusPill.vue';
import AppModal from '@/Components/AppModal.vue';
import { money, num } from '@/lib/format';

const props = defineProps<{
    counterparties: any[]; nomenclature: any[]; carriers: any[];
    accounts: any[]; articles: any[]; groups: any[];
}>();

const dir = ref<'counterparties' | 'nomenclature' | 'carriers' | 'accounts' | 'articles'>('counterparties');
const q = ref('');
const tabs = [
    { id: 'counterparties', label: 'Контрагенты' }, { id: 'nomenclature', label: 'Номенклатура' },
    { id: 'carriers', label: 'Перевозчики' }, { id: 'accounts', label: 'Счета' }, { id: 'articles', label: 'Статьи затрат' },
] as const;

const list = computed<any[]>(() => {
    const src = (props as any)[dir.value] as any[];
    if (!q.value) return src;
    const s = q.value.toLowerCase();
    return src.filter((x) => JSON.stringify(x).toLowerCase().includes(s));
});

const debtText = (n: number) => n > 0 ? '+ ' + money(n) + ' (нам)' : n < 0 ? '− ' + money(-n) + ' (мы)' : '—';
const cpVariant = (t: string) => t === 'Поставщик' ? 'info' : t === 'Покупатель' ? 'ok' : 'neutral';
const createLabel = computed(() => ({ counterparties: 'контрагента', nomenclature: 'товар', carriers: 'перевозчика', accounts: 'счёт', articles: 'статью' }[dir.value]));

// ── Форма (поля переключаются по типу) ──
const open = ref(false);
const editingId = ref<number | null>(null);
const form = useForm<Record<string, any>>({
    type: 'Оба', name: '', inn: '', contact: '', comment: '',
    group_id: null, unit: 'шт', article: '',
    site: '', note: '', account_type: 'Банк', bank: '', last4: '', opening_balance: 0,
});

function create() {
    editingId.value = null;
    form.reset();
    open.value = true;
}
function edit(x: any) {
    editingId.value = x.id;
    form.reset();
    Object.assign(form, x);
    if (dir.value === 'accounts') form.account_type = x.type;       // не конфликтовать с counterparty.type
    open.value = true;
}
function payload() {
    if (dir.value === 'counterparties') return { type: form.type, name: form.name, inn: form.inn, contact: form.contact, comment: form.comment };
    if (dir.value === 'nomenclature') return { name: form.name, group_id: form.group_id, unit: form.unit, article: form.article, comment: form.comment };
    if (dir.value === 'carriers') return { name: form.name, site: form.site, note: form.note };
    if (dir.value === 'accounts') return { name: form.name, type: form.account_type, bank: form.bank, last4: form.last4, opening_balance: form.opening_balance };
    return { name: form.name };
}
function submit() {
    form.transform(payload);
    const opts = { onSuccess: () => { open.value = false; } };
    if (editingId.value) form.put(`/references/${dir.value}/${editingId.value}`, opts);
    else form.post(`/references/${dir.value}`, opts);
}
function destroy() {
    if (editingId.value && confirm('Удалить запись?')) {
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
                    <thead><tr><th>Статья</th><th>Тип</th></tr></thead>
                    <tbody>
                        <tr v-for="x in list" :key="x.id" @click="!x.is_system && edit(x)">
                            <td>{{ x.name }}</td>
                            <td><StatusPill :text="x.is_system ? 'Системная' : 'Пользовательская'" :variant="x.is_system ? 'info' : 'neutral'" /></td>
                        </tr>
                        <tr v-if="!list.length"><td colspan="2"><div class="j-empty">Пусто</div></td></tr>
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
                    <div class="fld"><label>Группа</label><select v-model="form.group_id"><option :value="null">—</option><option v-for="g in groups" :key="g.id" :value="g.id">{{ g.name }}</option></select></div>
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
                <div class="fld-row">
                    <div class="fld"><label>Название</label><input v-model="form.name" placeholder="Сбер · 7781" /></div>
                    <div class="fld"><label>Тип</label><select v-model="form.account_type"><option>Банк</option><option>Касса</option></select></div>
                </div>
                <div class="fld-row">
                    <div class="fld"><label>Банк</label><input v-model="form.bank" /></div>
                    <div class="fld"><label>Последние 4</label><input v-model="form.last4" /></div>
                </div>
                <div class="fld"><label>Начальный остаток</label><input v-model.number="form.opening_balance" type="number" /></div>
            </template>
            <template v-else>
                <div class="fld"><label>Название статьи</label><input v-model="form.name" /></div>
            </template>

            <template #footer>
                <button class="btn-primary pressable" style="flex:1;justify-content:center" :disabled="form.processing" @click="submit">Сохранить</button>
                <button v-if="editingId" class="btn-ghost pressable" @click="destroy">Удалить</button>
            </template>
        </AppModal>
    </AppShell>
</template>
