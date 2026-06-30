<script setup lang="ts">
import { ref, computed } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import axios from 'axios';
import AppShell from '@/Layouts/AppShell.vue';
import Icon from '@/Components/Icon.vue';
import StatusPill from '@/Components/StatusPill.vue';
import AppModal from '@/Components/AppModal.vue';
import { money, date as fdate } from '@/lib/format';

type Item = { name: string; qty: number | null; price: number | null };
type Row = {
    id: number; number: string; date: string; supplier: string; counterparty_id: number | null;
    name: string | null; status: string; eta: string | null; carrier_id: number | null;
    tracking: string | null; delivery: number; problem: boolean; sum: number; paid: number;
    posted: boolean; items: Item[];
};

const props = defineProps<{
    rows: Row[];
    suppliers: { id: number; name: string }[];
    carriers: { id: number; name: string }[];
    goods: { id: number; name: string; unit: string }[];
}>();

// локальные списки (чтобы добавлять созданные на лету)
const suppliers = ref([...props.suppliers]);
const goods = ref([...props.goods]);

const seg = ref<'all' | 'Ожидает отправки' | 'В пути' | 'Завершено'>('all');
const q = ref('');

const filtered = computed(() => props.rows.filter((s) => {
    if (seg.value !== 'all' && s.status !== seg.value) return false;
    if (q.value && !(`${s.number} ${s.supplier} ${s.name ?? ''}`.toLowerCase().includes(q.value.toLowerCase()))) return false;
    return true;
}));
const totalSum = computed(() => filtered.value.reduce((a, s) => a + s.sum, 0));
const totalDebt = computed(() => filtered.value.reduce((a, s) => a + (s.sum - s.paid), 0));

const statusVariant = (s: string) => s === 'Завершено' ? 'ok' : s === 'В пути' ? 'info' : 'neutral';
const payText = (s: Row) => s.paid >= s.sum && s.sum > 0 ? 'Оплачено' : s.paid > 0 ? 'Частично' : 'Не оплачено';
const payVariant = (s: Row) => s.paid >= s.sum && s.sum > 0 ? 'ok' : s.paid > 0 ? 'warn' : 'bad';

// ── Форма документа ──
const open = ref(false);
const editingId = ref<number | null>(null);
const form = useForm<{
    date: string; counterparty_id: number | null; name: string; status: string; eta: string;
    carrier_id: number | null; tracking: string; delivery: number; problem: boolean; items: Item[];
}>({
    date: '', counterparty_id: null, name: '', status: 'Ожидает отправки', eta: '',
    carrier_id: null, tracking: '', delivery: 0, problem: false, items: [],
});

const formTotal = computed(() =>
    form.items.reduce((a, i) => a + (Number(i.qty) || 0) * (Number(i.price) || 0), 0) + (Number(form.delivery) || 0));

function create() {
    editingId.value = null;
    form.reset();
    form.date = new Date().toISOString().slice(0, 10);
    open.value = true;
}
function openDoc(s: Row) {
    editingId.value = s.id;
    form.date = s.date ?? '';
    form.counterparty_id = s.counterparty_id;
    form.name = s.name ?? '';
    form.status = s.status;
    form.eta = s.eta ?? '';
    form.carrier_id = s.carrier_id;
    form.tracking = s.tracking ?? '';
    form.delivery = s.delivery;
    form.problem = s.problem;
    form.items = s.items.map((i) => ({ name: i.name ?? '', qty: i.qty, price: i.price }));
    open.value = true;
}
function addItem() { form.items.push({ name: '', qty: null, price: null }); }
function removeItem(i: number) { form.items.splice(i, 1); }

// ── Быстрое создание поставщика прямо в модалке ──
const showSup = ref(false);
const supForm = ref({ name: '', type: 'Поставщик' });
const supSaving = ref(false);
async function saveSup() {
    if (!supForm.value.name.trim()) return;
    supSaving.value = true;
    try {
        const { data } = await axios.post('/quick/counterparties', supForm.value);
        suppliers.value.push(data);
        form.counterparty_id = data.id;
        showSup.value = false; supForm.value = { name: '', type: 'Поставщик' };
    } catch { alert('Не удалось создать поставщика'); }
    supSaving.value = false;
}

function submit() {
    const opts = { onSuccess: () => { open.value = false; } };
    if (editingId.value) form.put(`/shipments/${editingId.value}`, opts);
    else form.post('/shipments', opts);
}
function destroy() {
    if (editingId.value && confirm('Удалить поставку?')) {
        router.delete(`/shipments/${editingId.value}`, { onSuccess: () => { open.value = false; } });
    }
}
</script>

<template>
    <Head title="Поставки" />
    <AppShell>
        <div class="toolbar">
            <h1>Поставки</h1>
            <div class="tb-search">
                <Icon name="search" :size="16" class="text-ink-3" />
                <input v-model="q" placeholder="Поиск по поставщику, товару, №…" />
            </div>
            <div class="seg">
                <button :class="{ on: seg === 'all' }" @click="seg = 'all'">Все</button>
                <button :class="{ on: seg === 'Ожидает отправки' }" @click="seg = 'Ожидает отправки'">Ожидает</button>
                <button :class="{ on: seg === 'В пути' }" @click="seg = 'В пути'">В пути</button>
                <button :class="{ on: seg === 'Завершено' }" @click="seg = 'Завершено'">Завершено</button>
            </div>
            <button class="btn-primary pressable" @click="create"><Icon name="plus" :size="17" /> Создать</button>
        </div>

        <div class="jcard glass">
            <div class="jscroll">
                <table class="jtable">
                    <thead>
                        <tr>
                            <th>№</th><th>Дата</th><th>Поставщик</th><th>Название</th>
                            <th class="num">Сумма</th><th>Оплата</th><th>Статус</th><th>ETA</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="s in filtered" :key="s.id" @click="openDoc(s)">
                            <td>{{ s.number }}</td>
                            <td class="text-ink-2">{{ fdate(s.date) }}</td>
                            <td>{{ s.supplier }}</td>
                            <td>
                                {{ s.name }}
                                <StatusPill v-if="s.problem" text="Проблема" variant="bad" class="ml-2" />
                            </td>
                            <td class="num">{{ money(s.sum) }}</td>
                            <td><StatusPill :text="payText(s)" :variant="payVariant(s)" /></td>
                            <td><StatusPill :text="s.status" :variant="statusVariant(s.status)" /></td>
                            <td class="text-ink-2">{{ fdate(s.eta) }}</td>
                        </tr>
                        <tr v-if="!filtered.length"><td colspan="8"><div class="j-empty">Поставок пока нет — создайте первую</div></td></tr>
                    </tbody>
                    <tfoot v-if="filtered.length">
                        <tr>
                            <td colspan="4">Итого: {{ filtered.length }}</td>
                            <td class="num">{{ money(totalSum) }}</td>
                            <td colspan="3">Долг поставщикам: {{ money(totalDebt) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <AppModal :open="open" :title="editingId ? 'Поставка' : 'Новая поставка'" @close="open = false">
            <div class="fld-row">
                <div class="fld"><label>Поставщик</label>
                    <div class="sel-add">
                        <select v-model="form.counterparty_id">
                            <option :value="null">— выбрать —</option>
                            <option v-for="c in suppliers" :key="c.id" :value="c.id">{{ c.name }}</option>
                        </select>
                        <button type="button" class="add-btn pressable" :class="{ on: showSup }" @click="showSup = !showSup" title="Создать поставщика">+</button>
                    </div>
                </div>
                <div class="fld"><label>Статус</label>
                    <select v-model="form.status"><option>Ожидает отправки</option><option>В пути</option><option>Завершено</option></select>
                </div>
            </div>
            <div v-if="showSup" class="quick-form">
                <input v-model="supForm.name" placeholder="Наименование" />
                <select v-model="supForm.type" style="max-width:140px">
                    <option>Поставщик</option><option>Покупатель</option><option>Оба</option>
                </select>
                <button type="button" class="btn-primary pressable" style="padding:8px 14px" :disabled="supSaving" @click="saveSup">Создать</button>
            </div>
            <div class="fld"><label>Название поставки</label><input v-model="form.name" /></div>
            <div class="fld-row">
                <div class="fld"><label>Дата заказа</label><input v-model="form.date" type="date" /></div>
                <div class="fld"><label>ETA</label><input v-model="form.eta" type="date" /></div>
            </div>
            <div class="fld-row">
                <div class="fld"><label>Перевозчик</label>
                    <select v-model="form.carrier_id">
                        <option :value="null">—</option>
                        <option v-for="c in carriers" :key="c.id" :value="c.id">{{ c.name }}</option>
                    </select>
                </div>
                <div class="fld"><label>Трек-номер</label><input v-model="form.tracking" placeholder="—" /></div>
            </div>
            <div class="fld"><label>Стоимость доставки</label><input v-model.number="form.delivery" type="number" /></div>

            <div>
                <div class="items-h">
                    <span class="h2">Товары</span>
                    <button class="btn-ghost" style="padding:6px 12px;font-size:13px" @click="addItem">Добавить</button>
                </div>
                <datalist id="goods-list">
                    <option v-for="g in goods" :key="g.id" :value="g.name" />
                </datalist>
                <div v-for="(it, i) in form.items" :key="i" class="ship-item">
                    <input v-model="it.name" list="goods-list" placeholder="наименование товара" />
                    <input v-model.number="it.qty" type="number" placeholder="кол-во" />
                    <input v-model.number="it.price" type="number" placeholder="цена" />
                    <button class="link-btn link-btn--bad" @click="removeItem(i)">✕</button>
                </div>
                <div v-if="!form.items.length" class="text-ink-3" style="padding:12px 0;font-size:14px">Добавьте позиции: введите наименование, количество и цену</div>
            </div>

            <div class="flex items-center justify-between" style="padding-top:6px;border-top:1px solid var(--glass-border)">
                <span class="text-ink-2 text-[14px]">Итого (товары + доставка)</span>
                <span class="tnum text-[18px] font-bold">{{ money(formTotal) }}</span>
            </div>

            <template #footer>
                <button class="btn-primary pressable" style="flex:1;justify-content:center" :disabled="form.processing" @click="submit">
                    {{ form.status === 'Ожидает отправки' ? 'Сохранить' : 'Сохранить и оприходовать' }}
                </button>
                <button v-if="editingId" class="btn-ghost pressable" @click="destroy">Удалить</button>
            </template>
        </AppModal>
    </AppShell>
</template>

<style scoped>
.ship-item { display: grid; grid-template-columns: 1fr 80px 100px 28px; gap: 8px; align-items: center; padding: 8px 0; border-top: 1px solid var(--glass-border); }
.ship-item input { height: 40px; box-sizing: border-box; border: 1px solid var(--glass-border); background: var(--glass-fill); border-radius: 10px; padding: 0 10px; color: var(--ink); font-size: 13px; font-family: inherit; outline: none; }
.sel-add { display: flex; gap: 8px; align-items: center; }
.sel-add select { flex: 1; min-width: 0; }
.add-btn { flex-shrink: 0; width: 42px; height: 42px; border-radius: 12px; border: 1px solid var(--glass-border); background: var(--glass-fill); color: var(--ink); font-size: 20px; line-height: 1; cursor: pointer; display: flex; align-items: center; justify-content: center; }
.add-btn.on { background: var(--ink); color: var(--bg); }
.quick-form { display: flex; gap: 8px; align-items: center; padding: 10px 12px; margin-top: 8px; background: var(--glass-fill); border: 1px solid var(--glass-border); border-radius: 12px; flex-wrap: wrap; }
.quick-form input, .quick-form select { flex: 1; min-width: 120px; height: 40px; box-sizing: border-box; border: 1px solid var(--glass-border); background: var(--bg); border-radius: 10px; padding: 0 12px; color: var(--ink); font-size: 14px; font-family: inherit; outline: none; }
.on-ghost { background: var(--ink) !important; color: var(--bg) !important; }
</style>
