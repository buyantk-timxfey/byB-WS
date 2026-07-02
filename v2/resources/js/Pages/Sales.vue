<script setup lang="ts">
import { ref, computed } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import AppShell from '@/Layouts/AppShell.vue';
import Icon from '@/Components/Icon.vue';
import StatusPill from '@/Components/StatusPill.vue';
import AppModal from '@/Components/AppModal.vue';
import SearchSelect from '@/Components/SearchSelect.vue';
import DatePicker from '@/Components/DatePicker.vue';
import { money, date as fdate } from '@/lib/format';

type Item = { nomenclature_id: number | null; qty: number | null; price: number | null; cost?: number };
type Row = {
    id: number; number: string; date: string; buyer: string; counterparty_id: number | null;
    account_id: number | null; sale_type: string; payment_method: string | null; status: string;
    sum: number; cost: number; profit: number; paid: number; posted: boolean; items: Item[];
};

const props = defineProps<{
    rows: Row[];
    buyers: { id: number; name: string }[];
    goods: { id: number; name: string; unit: string }[];
    accounts: { id: number; name: string }[];
    defaultAccountId: number | null;
    rates: { card: number; sbp: number };
}>();

const seg = ref<'all' | 'Выставлен' | 'Оплачен' | 'Отменён'>('all');
const q = ref('');
const filtered = computed(() => props.rows.filter((s) => {
    if (seg.value !== 'all' && s.status !== seg.value) return false;
    if (q.value && !(`${s.number} ${s.buyer}`.toLowerCase().includes(q.value.toLowerCase()))) return false;
    return true;
}));
const paidSales = computed(() => filtered.value.filter((s) => s.status === 'Оплачен'));
const totalRevenue = computed(() => paidSales.value.reduce((a, s) => a + s.sum, 0));
const totalProfit = computed(() => paidSales.value.reduce((a, s) => a + s.profit, 0));
const avgCheck = computed(() => paidSales.value.length ? Math.round(totalRevenue.value / paidSales.value.length) : 0);
const totalDebt = computed(() => filtered.value.reduce((a, s) => a + Math.max(0, s.sum - s.paid), 0));

const statusVariant = (s: string) => s === 'Оплачен' ? 'ok' : s === 'Выставлен' ? 'info' : 'neutral';
const payText = (s: Row) => s.paid >= s.sum && s.sum > 0 ? 'Оплачено' : s.paid > 0 ? 'Частично' : 'Не оплачено';
const payVariant = (s: Row) => s.paid >= s.sum && s.sum > 0 ? 'ok' : s.paid > 0 ? 'warn' : 'bad';
const margin = (s: Row) => s.sum ? Math.round((s.profit / s.sum) * 100) : 0;
const buyerLabel = (s: Row) => s.sale_type === 'Касса' ? 'Касса · ' + (s.payment_method ?? '') : s.buyer;

const rub2 = (n: number) => new Intl.NumberFormat('ru-RU', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(n) + ' ₽';
const goodName = (id: number | null) => props.goods.find((g) => g.id === id)?.name ?? '';

// ── Состояние ──
const chooser = ref(false);          // выбор типа продажи
const open = ref(false);             // активная модалка
const saleType = ref<'Касса' | 'Безналичная'>('Безналичная');
const editingId = ref<number | null>(null);

const form = useForm<{
    date: string; sale_type: string; counterparty_id: number | null; account_id: number | null;
    payment_method: string | null; status: string; comment: string; items: Item[];
}>({
    date: '', sale_type: 'Безналичная', counterparty_id: null, account_id: null,
    payment_method: null, status: 'Выставлен', comment: '', items: [],
});

const formSum = computed(() => form.items.reduce((a, i) => a + (Number(i.qty) || 0) * (Number(i.price) || 0), 0));
const feeRate = computed(() => form.payment_method === 'СБП' ? props.rates.sbp : props.rates.card);
const feeAmount = computed(() => Math.round(formSum.value * feeRate.value) / 100);
const netAmount = computed(() => formSum.value - feeAmount.value);

function create() { chooser.value = true; }

function pickType(t: 'Касса' | 'Безналичная') {
    chooser.value = false;
    editingId.value = null;
    saleType.value = t;
    form.reset();
    form.date = new Date().toISOString().slice(0, 10);
    form.sale_type = t;
    form.items = [{ nomenclature_id: null, qty: null, price: null }];
    if (t === 'Касса') {
        form.payment_method = 'Карта';
        form.status = 'Оплачен';
        form.account_id = props.defaultAccountId;
        form.counterparty_id = null;
    } else {
        form.payment_method = null;
        form.status = 'Выставлен';
        form.account_id = props.defaultAccountId;
    }
    open.value = true;
}

function openDoc(s: Row) {
    editingId.value = s.id;
    saleType.value = (s.sale_type as 'Касса' | 'Безналичная') ?? 'Безналичная';
    form.date = s.date ?? '';
    form.sale_type = s.sale_type ?? 'Безналичная';
    form.counterparty_id = s.counterparty_id;
    form.account_id = s.account_id;
    form.payment_method = s.payment_method ?? (s.sale_type === 'Касса' ? 'Карта' : null);
    form.status = s.status;
    form.comment = '';
    form.items = s.items.map((i) => ({ nomenclature_id: i.nomenclature_id, qty: i.qty, price: i.price }));
    open.value = true;
}

function addItem() { form.items.push({ nomenclature_id: null, qty: null, price: null }); }
function removeItem(i: number) { form.items.splice(i, 1); }

function submit() {
    if (saleType.value === 'Касса') { form.status = 'Оплачен'; form.counterparty_id = null; }
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
                <button :class="{ on: seg === 'Выставлен' }" @click="seg = 'Выставлен'">Выставлены</button>
                <button :class="{ on: seg === 'Оплачен' }" @click="seg = 'Оплачен'">Оплачены</button>
                <button :class="{ on: seg === 'Отменён' }" @click="seg = 'Отменён'">Отменены</button>
            </div>
            <button class="btn-primary pressable" @click="create"><Icon name="plus" :size="17" /> Создать</button>
        </div>

        <div class="jcard glass">
            <div class="jscroll">
                <table class="jtable">
                    <thead>
                        <tr><th>№</th><th>Дата</th><th>Покупатель</th><th class="num">Сумма</th><th class="pay-col"><Icon name="link" :size="14" /></th><th class="num">Прибыль</th><th>Статус</th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="s in filtered" :key="s.id" @click="openDoc(s)">
                            <td>{{ s.number }}</td>
                            <td class="text-ink-2">{{ fdate(s.date) }}</td>
                            <td>
                                <span v-if="s.sale_type === 'Касса'" class="type-chip"><Icon name="wallet" :size="12" /> Касса</span>
                                {{ buyerLabel(s) }}
                            </td>
                            <td class="num">{{ money(s.sum) }}</td>
                            <td class="pay-col" :title="payText(s)">
                                <Icon v-if="payVariant(s) === 'ok'" name="check" :size="16" style="color:var(--income)" />
                                <Icon v-else-if="payVariant(s) === 'warn'" name="minus" :size="16" style="color:var(--warn)" />
                                <Icon v-else name="x" :size="16" style="color:var(--expense)" />
                            </td>
                            <td class="num">
                                <template v-if="s.status === 'Оплачен'">
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
                            <td colspan="3">Выручка (оплачено): {{ paidSales.length }}</td>
                            <td class="num">{{ money(totalRevenue) }}</td>
                            <td>Ср. чек {{ money(avgCheck) }}</td>
                            <td class="num" :style="{ color: 'var(--income)' }">{{ money(totalProfit) }}</td>
                            <td>Долг: {{ money(totalDebt) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <!-- Выбор типа продажи -->
        <AppModal :open="chooser" title="Новая продажа" @close="chooser = false">
            <div class="type-pick">
                <button class="type-card pressable" @click="pickType('Касса')">
                    <Icon name="wallet" :size="30" />
                    <div class="tc-t">Касса</div>
                </button>
                <button class="type-card pressable" @click="pickType('Безналичная')">
                    <Icon name="building" :size="30" />
                    <div class="tc-t">Безналичная</div>
                </button>
            </div>
        </AppModal>

        <!-- Касса: кассовый чек -->
        <AppModal v-if="saleType === 'Касса'" :open="open" wide :title="editingId ? 'Кассовый чек' : 'Новый чек'" @close="open = false">
            <div class="receipt">
                <div class="rc-head">
                    <div class="rc-title">КАССОВЫЙ ЧЕК</div>
                    <DatePicker v-model="form.date" />
                </div>
                <div class="rc-pay">
                    <button :class="{ on: form.payment_method === 'Карта' }" @click="form.payment_method = 'Карта'">Карта</button>
                    <button :class="{ on: form.payment_method === 'СБП' }" @click="form.payment_method = 'СБП'">СБП</button>
                </div>

                <div class="rc-lines">
                    <div v-for="(it, i) in form.items" :key="i" class="rc-line">
                        <SearchSelect v-model="it.nomenclature_id" :options="goods" placeholder="— товар —" class="rc-good" />
                        <input v-model.number="it.qty" type="number" placeholder="кол-во" class="rc-qty" />
                        <span class="rc-x">×</span>
                        <input v-model.number="it.price" type="number" placeholder="цена" class="rc-price" />
                        <span class="rc-sum">{{ money((Number(it.qty) || 0) * (Number(it.price) || 0)) }}</span>
                        <button class="rc-del" @click="removeItem(i)">✕</button>
                    </div>
                </div>
                <button class="rc-add pressable" @click="addItem"><Icon name="plus" :size="15" /> Добавить товар</button>

                <div class="rc-totals">
                    <div class="rc-tline"><span>Итого</span><span class="tnum">{{ money(formSum) }}</span></div>
                    <div class="rc-tline rc-fee"><span>Комиссия · {{ form.payment_method }} ({{ feeRate }}%)</span><span class="tnum">−{{ rub2(feeAmount) }}</span></div>
                    <div class="rc-tline rc-net"><span>К зачислению</span><span class="tnum">{{ rub2(netAmount) }}</span></div>
                </div>
                <div class="rc-note">Приход на «{{ accounts.find((a) => a.id === form.account_id)?.name ?? 'счёт' }}» создаётся автоматически. Комиссия — на статью «Эквайринг».</div>
            </div>

            <template #footer>
                <button class="btn-primary pressable" style="flex:1;justify-content:center" :disabled="form.processing" @click="submit">
                    {{ editingId ? 'Сохранить чек' : 'Пробить чек' }}
                </button>
                <button v-if="editingId" class="btn-ghost pressable" @click="destroy">Удалить</button>
            </template>
        </AppModal>

        <!-- Безналичная продажа -->
        <AppModal v-else :open="open" wide :title="editingId ? 'Безналичная продажа' : 'Новая продажа'" @close="open = false">
            <div class="fld-row">
                <div class="fld"><label>Покупатель</label>
                    <SearchSelect v-model="form.counterparty_id" :options="buyers" placeholder="— выбрать —" />
                </div>
                <div class="fld"><label>Статус</label>
                    <select v-model="form.status"><option>Выставлен</option><option>Оплачен</option><option>Отменён</option></select>
                </div>
            </div>
            <div class="fld-row">
                <div class="fld"><label>Дата</label><DatePicker v-model="form.date" /></div>
                <div class="fld"><label>Счёт зачисления</label>
                    <SearchSelect v-model="form.account_id" :options="accounts" placeholder="—" />
                </div>
            </div>

            <div>
                <div class="items-h">
                    <span class="h2">Товары</span>
                    <button class="btn-ghost" style="padding:6px 12px;font-size:13px" @click="addItem">+ Товар</button>
                </div>
                <div v-for="(it, i) in form.items" :key="i" class="sale-item">
                    <SearchSelect v-model="it.nomenclature_id" :options="goods" placeholder="— товар —" />
                    <input v-model.number="it.qty" type="number" placeholder="кол-во" />
                    <input v-model.number="it.price" type="number" placeholder="цена" />
                    <button class="link-btn link-btn--bad" @click="removeItem(i)">✕</button>
                </div>
                <div v-if="!form.items.length" class="text-ink-3" style="padding:12px 0;font-size:14px">Добавьте товары со склада</div>
            </div>

            <div class="sale-totals">
                <div class="st-line">
                    <span class="text-ink-2 text-[14px]">Сумма</span>
                    <span class="tnum text-[18px] font-bold">{{ money(formSum) }}</span>
                </div>
                <div class="st-note">«Выставлен» резервирует товар. Деньги/выручка — при сопоставлении прихода из выписки.</div>
            </div>

            <template #footer>
                <button class="btn-primary pressable" style="flex:1;justify-content:center" :disabled="form.processing" @click="submit">
                    {{ form.status === 'Выставлен' ? 'Выставить счёт' : 'Сохранить' }}
                </button>
                <button v-if="editingId" class="btn-ghost pressable" @click="payNow">Оплачено сразу</button>
                <button v-if="editingId" class="btn-ghost pressable" @click="destroy">Удалить</button>
            </template>
        </AppModal>
    </AppShell>
</template>

<style scoped>
.type-chip { display: inline-flex; align-items: center; gap: 3px; font-size: 11px; padding: 1px 7px; border-radius: 8px; background: var(--glass-fill); border: 1px solid var(--glass-border); color: var(--ink-2); margin-right: 6px; vertical-align: middle; }

/* Выбор типа */
.type-pick { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; padding: 4px 0 6px; }
.type-card { display: flex; flex-direction: column; align-items: center; gap: 8px; text-align: center; padding: 24px 16px; border-radius: 18px; border: 1px solid var(--glass-border); background: var(--glass-fill); color: var(--ink); cursor: pointer; transition: transform .12s, border-color .12s; }
.type-card:hover { border-color: var(--accent, #0a84ff); transform: translateY(-2px); }
.type-card .tc-t { font-size: 17px; font-weight: 700; }

/* Безнал-позиции */
.sale-item { display: grid; grid-template-columns: 1fr 80px 100px 28px; gap: 8px; align-items: center; padding: 6px 0; border-top: 1px solid var(--glass-border); }
.sale-item select, .sale-item input { border: 1px solid var(--glass-border); background: var(--glass-fill); border-radius: 10px; padding: 8px 10px; color: var(--ink); font-size: 13px; font-family: inherit; outline: none; }
.sale-totals { padding-top: 8px; margin-top: 4px; border-top: 1px solid var(--glass-border); display: flex; flex-direction: column; gap: 4px; }
.sale-totals .st-line { display: flex; align-items: center; justify-content: space-between; }
.sale-totals .st-note { font-size: 11px; color: var(--ink-3); line-height: 1.35; padding-top: 2px; }

/* Кассовый чек */
.receipt { display: flex; flex-direction: column; gap: 12px; }
.rc-head { display: flex; align-items: center; justify-content: space-between; gap: 10px; }
.rc-title { font-family: 'SF Mono', ui-monospace, monospace; letter-spacing: 2px; font-size: 13px; font-weight: 700; color: var(--ink-2); }
.rc-pay { display: grid; grid-template-columns: 1fr 1fr; gap: 6px; padding: 4px; background: var(--glass-fill); border: 1px solid var(--glass-border); border-radius: 12px; }
.rc-pay button { padding: 9px 0; border-radius: 9px; border: none; background: transparent; color: var(--ink-2); font-size: 14px; font-weight: 600; font-family: inherit; cursor: pointer; transition: background .12s, color .12s; }
.rc-pay button.on { background: var(--accent, #0a84ff); color: #fff; }
.rc-lines { display: flex; flex-direction: column; }
.rc-line { display: grid; grid-template-columns: 1fr 56px 12px 72px 84px 26px; gap: 6px; align-items: center; padding: 7px 0; border-bottom: 1px dashed var(--glass-border); }
.rc-line select, .rc-line input { border: 1px solid var(--glass-border); background: var(--glass-fill); border-radius: 9px; padding: 7px 8px; color: var(--ink); font-size: 13px; font-family: inherit; outline: none; min-width: 0; }
.rc-x { text-align: center; color: var(--ink-3); font-size: 12px; }
.rc-sum { text-align: right; font-variant-numeric: tabular-nums; font-size: 13px; color: var(--ink); }
.rc-del { border: none; background: transparent; color: var(--expense); cursor: pointer; display: flex; justify-content: center; }
.rc-add { align-self: flex-start; display: inline-flex; align-items: center; gap: 5px; border: 1px dashed var(--glass-border); background: transparent; color: var(--ink-2); border-radius: 10px; padding: 8px 14px; font-size: 13px; font-family: inherit; cursor: pointer; }
.rc-totals { display: flex; flex-direction: column; gap: 5px; padding-top: 10px; border-top: 2px solid var(--ink-3); }
.rc-tline { display: flex; align-items: center; justify-content: space-between; font-size: 14px; }
.rc-tline .tnum { font-variant-numeric: tabular-nums; }
.rc-fee { color: var(--expense); font-size: 13px; }
.rc-net { font-size: 17px; font-weight: 700; }
.rc-note { font-size: 11px; color: var(--ink-3); line-height: 1.4; }
</style>
