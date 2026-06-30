<script setup lang="ts">
import { ref, computed } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import AppShell from '@/Layouts/AppShell.vue';
import Icon from '@/Components/Icon.vue';
import StatusPill from '@/Components/StatusPill.vue';
import AppModal from '@/Components/AppModal.vue';
import { money, date as fdate } from '@/lib/format';

type Item = { nomenclature_id: number | null; qty: number | null; price: number | null; cost?: number };
type Row = {
    id: number; number: string; date: string; buyer: string; counterparty_id: number | null;
    account_id: number | null; payment_method: string | null; status: string; sum: number; cost: number; profit: number;
    paid: number; posted: boolean; items: Item[];
};

const props = defineProps<{
    rows: Row[];
    buyers: { id: number; name: string }[];
    goods: { id: number; name: string; unit: string }[];
    accounts: { id: number; name: string }[];
    defaultAccountId: number | null;
    rates: { card: number; sbp: number };
}>();

const seg = ref<'all' | 'Счёт' | 'Отгружено' | 'Отменено'>('all');
const q = ref('');
const filtered = computed(() => props.rows.filter((s) => {
    if (seg.value !== 'all' && s.status !== seg.value) return false;
    if (q.value && !(`${s.number} ${s.buyer}`.toLowerCase().includes(q.value.toLowerCase()))) return false;
    return true;
}));
const shipped = computed(() => filtered.value.filter((s) => s.status === 'Отгружено'));
const totalRevenue = computed(() => shipped.value.reduce((a, s) => a + s.sum, 0));
const totalProfit = computed(() => shipped.value.reduce((a, s) => a + s.profit, 0));
const avgCheck = computed(() => shipped.value.length ? Math.round(totalRevenue.value / shipped.value.length) : 0);
const totalDebt = computed(() => shipped.value.reduce((a, s) => a + (s.sum - s.paid), 0));

const statusVariant = (s: string) => s === 'Отгружено' ? 'ok' : s === 'Счёт' ? 'info' : 'neutral';
const payText = (s: Row) => s.paid >= s.sum && s.sum > 0 ? 'Оплачено' : s.paid > 0 ? 'Частично' : 'Не оплачено';
const payVariant = (s: Row) => s.paid >= s.sum && s.sum > 0 ? 'ok' : s.paid > 0 ? 'warn' : 'bad';
const margin = (s: Row) => s.sum ? Math.round((s.profit / s.sum) * 100) : 0;

const open = ref(false);
const editingId = ref<number | null>(null);
const form = useForm<{ date: string; counterparty_id: number | null; account_id: number | null; payment_method: string; status: string; comment: string; items: Item[] }>({
    date: '', counterparty_id: null, account_id: null, payment_method: 'Без комиссии', status: 'Счёт', comment: '', items: [],
});
const formSum = computed(() => form.items.reduce((a, i) => a + (Number(i.qty) || 0) * (Number(i.price) || 0), 0));

// Касса → ожидаемая комиссия (подсказка для разнесения выписки, расход не создаётся)
const feeRate = computed(() => form.payment_method === 'Эквайринг' ? props.rates.card : form.payment_method === 'СБП' ? props.rates.sbp : 0);
const feeAmount = computed(() => Math.round(formSum.value * feeRate.value) / 100);
const netAmount = computed(() => formSum.value - feeAmount.value);
const rub2 = (n: number) => new Intl.NumberFormat('ru-RU', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(n) + ' ₽';

function create() {
    editingId.value = null;
    form.reset();
    form.date = new Date().toISOString().slice(0, 10);
    form.account_id = props.defaultAccountId;
    form.items = [{ nomenclature_id: null, qty: null, price: null }];
    open.value = true;
}
function openDoc(s: Row) {
    editingId.value = s.id;
    form.date = s.date ?? '';
    form.counterparty_id = s.counterparty_id;
    form.account_id = s.account_id;
    form.payment_method = (s as any).payment_method ?? 'Без комиссии';
    form.status = s.status;
    form.comment = '';
    form.items = s.items.map((i) => ({ nomenclature_id: i.nomenclature_id, qty: i.qty, price: i.price }));
    open.value = true;
}
function addItem() { form.items.push({ nomenclature_id: null, qty: null, price: null }); }
function removeItem(i: number) { form.items.splice(i, 1); }
function submit() {
    const opts = { onSuccess: () => { open.value = false; } };
    if (editingId.value) form.put(`/sales/${editingId.value}`, opts);
    else form.post('/sales', opts);
}
function destroy() {
    if (editingId.value && confirm('Удалить продажу?')) router.delete(`/sales/${editingId.value}`, { onSuccess: () => { open.value = false; } });
}
function payNow() {
    if (editingId.value) router.post(`/sales/${editingId.value}/pay`, {}, { onSuccess: () => { open.value = false; } });
}
</script>

<template>
    <Head title="Продажи" />
    <AppShell>
        <div class="toolbar">
            <h1>Продажи</h1>
            <div class="tb-search">
                <Icon name="search" :size="16" class="text-ink-3" />
                <input v-model="q" placeholder="Поиск по покупателю, №…" />
            </div>
            <div class="seg">
                <button :class="{ on: seg === 'all' }" @click="seg = 'all'">Все</button>
                <button :class="{ on: seg === 'Счёт' }" @click="seg = 'Счёт'">Счета</button>
                <button :class="{ on: seg === 'Отгружено' }" @click="seg = 'Отгружено'">Отгружено</button>
                <button :class="{ on: seg === 'Отменено' }" @click="seg = 'Отменено'">Отменено</button>
            </div>
            <button class="btn-primary pressable" @click="create"><Icon name="plus" :size="17" /> Создать</button>
        </div>

        <div class="jcard glass">
            <div class="jscroll">
                <table class="jtable">
                    <thead>
                        <tr><th>№</th><th>Дата</th><th>Покупатель</th><th class="num">Сумма</th><th>Оплата</th><th class="num">Прибыль</th><th>Статус</th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="s in filtered" :key="s.id" @click="openDoc(s)">
                            <td>{{ s.number }}</td>
                            <td class="text-ink-2">{{ fdate(s.date) }}</td>
                            <td>{{ s.buyer }}</td>
                            <td class="num">{{ money(s.sum) }}</td>
                            <td><StatusPill :text="payText(s)" :variant="payVariant(s)" /></td>
                            <td class="num">
                                <template v-if="s.status === 'Отгружено'">
                                    <span :style="{ color: 'var(--income)' }">{{ money(s.profit) }}</span>
                                    <span class="text-ink-3" style="font-size:12px"> · {{ margin(s) }}%</span>
                                </template>
                                <span v-else class="text-ink-3">—</span>
                            </td>
                            <td><StatusPill :text="s.status" :variant="statusVariant(s.status)" /></td>
                        </tr>
                        <tr v-if="!filtered.length"><td colspan="7"><div class="j-empty">Продаж пока нет — создайте первую</div></td></tr>
                    </tbody>
                    <tfoot v-if="filtered.length">
                        <tr>
                            <td colspan="3">Выручка (отгружено): {{ shipped.length }}</td>
                            <td class="num">{{ money(totalRevenue) }}</td>
                            <td>Ср. чек {{ money(avgCheck) }}</td>
                            <td class="num" :style="{ color: 'var(--income)' }">{{ money(totalProfit) }}</td>
                            <td>Долг: {{ money(totalDebt) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <AppModal :open="open" :title="editingId ? 'Продажа' : 'Новая продажа'" @close="open = false">
            <div class="fld-row">
                <div class="fld"><label>Покупатель</label>
                    <select v-model="form.counterparty_id">
                        <option :value="null">— выбрать —</option>
                        <option v-for="c in buyers" :key="c.id" :value="c.id">{{ c.name }}</option>
                    </select>
                </div>
                <div class="fld"><label>Статус</label>
                    <select v-model="form.status"><option>Счёт</option><option>Отгружено</option><option>Отменено</option></select>
                </div>
            </div>
            <div class="fld-row">
                <div class="fld"><label>Дата</label><input v-model="form.date" type="date" /></div>
                <div class="fld"><label>Касса</label>
                    <select v-model="form.payment_method">
                        <option>Эквайринг</option>
                        <option>СБП</option>
                        <option>Без комиссии</option>
                    </select>
                </div>
            </div>
            <div class="fld-row">
                <div class="fld"><label>Счёт зачисления</label>
                    <select v-model="form.account_id"><option :value="null">—</option><option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.name }}</option></select>
                </div>
                <div class="fld"></div>
            </div>

            <div>
                <div class="items-h">
                    <span class="h2">Позиции (списываются по FIFO)</span>
                    <button class="btn-ghost" style="padding:6px 12px;font-size:13px" @click="addItem">+ Товар</button>
                </div>
                <div v-for="(it, i) in form.items" :key="i" class="sale-item">
                    <select v-model="it.nomenclature_id">
                        <option :value="null">— товар —</option>
                        <option v-for="g in goods" :key="g.id" :value="g.id">{{ g.name }}</option>
                    </select>
                    <input v-model.number="it.qty" type="number" placeholder="кол-во" />
                    <input v-model.number="it.price" type="number" placeholder="цена" />
                    <button class="link-btn link-btn--bad" @click="removeItem(i)">✕</button>
                </div>
                <div v-if="!form.items.length" class="text-ink-3" style="padding:12px 0;font-size:14px">Добавьте товары со склада</div>
            </div>

            <div class="sale-totals">
                <div class="st-line">
                    <span class="text-ink-2 text-[14px]">Выручка</span>
                    <span class="tnum text-[18px] font-bold">{{ money(formSum) }}</span>
                </div>
                <div v-if="feeRate > 0" class="st-line st-fee">
                    <span>Комиссия · {{ form.payment_method }} ({{ feeRate }}%)</span>
                    <span class="tnum">−{{ rub2(feeAmount) }}</span>
                </div>
                <div v-if="feeRate > 0" class="st-line">
                    <span class="text-ink-2 text-[14px]">К зачислению</span>
                    <span class="tnum text-[15px] font-bold">{{ rub2(netAmount) }}</span>
                </div>
                <div v-if="feeRate > 0" class="st-note">Подсказка для разнесения выписки — расход эквайринга учитывается из банка, не дублируется.</div>
            </div>

            <template #footer>
                <button class="btn-primary pressable" style="flex:1;justify-content:center" :disabled="form.processing" @click="submit">
                    {{ form.status === 'Счёт' ? 'Сохранить счёт' : 'Провести (Отгрузить)' }}
                </button>
                <button v-if="editingId" class="btn-ghost pressable" @click="payNow">Оплачено сразу</button>
                <button v-if="editingId" class="btn-ghost pressable" @click="destroy">Удалить</button>
            </template>
        </AppModal>
    </AppShell>
</template>

<style scoped>
.sale-item { display: grid; grid-template-columns: 1fr 80px 100px 28px; gap: 8px; align-items: center; padding: 6px 0; border-top: 1px solid var(--glass-border); }
.sale-item select, .sale-item input { border: 1px solid var(--glass-border); background: var(--glass-fill); border-radius: 10px; padding: 8px 10px; color: var(--ink); font-size: 13px; font-family: inherit; outline: none; }
.sale-totals { padding-top: 8px; margin-top: 4px; border-top: 1px solid var(--glass-border); display: flex; flex-direction: column; gap: 4px; }
.sale-totals .st-line { display: flex; align-items: center; justify-content: space-between; }
.sale-totals .st-fee { color: var(--expense); font-size: 13px; }
.sale-totals .st-note { font-size: 11px; color: var(--ink-3); line-height: 1.35; padding-top: 2px; }
</style>
