<script setup lang="ts">
import { ref, computed, onMounted } from 'vue';
import { Head, useForm, router, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import AppShell from '@/Layouts/AppShell.vue';
import Icon from '@/Components/Icon.vue';
import StatusPill from '@/Components/StatusPill.vue';
import AppModal from '@/Components/AppModal.vue';
import SearchSelect from '@/Components/SearchSelect.vue';
import DatePicker from '@/Components/DatePicker.vue';
import { money, num, date as fdate } from '@/lib/format';
import { confirmDlg } from '@/lib/confirm';
import { useRowHighlight } from '@/lib/highlight';

type Item = { id?: number; nomenclature_id: number | null; name?: string | null; qty: number | null; qty_received?: number; price: number | null; vat_rate: number | null; vat_amount: number | null };
type Payment = { match_id: number; bank_line_id: number; date: string | null; party: string; amount: number };
type Row = {
    id: number; number: string; date: string; supplier: string; counterparty_id: number | null;
    name: string | null; status: string; eta: string | null; carrier_id: number | null;
    eta_first: string | null; eta_shift: number; eta_changes: { old: string | null; new: string | null; at: string }[];
    tracking: string | null; delivery: number; problem: boolean; sum: number; paid: number;
    posted: boolean; total_qty: number; received_qty: number; receipts: { date: string; qty: number; name: string }[];
    items: Item[]; payments: Payment[];
};
type BankCandidate = { id: number; date: string | null; party: string; purpose: string | null; remaining: number };

const props = defineProps<{
    rows: Row[];
    suppliers: { id: number; name: string }[];
    carriers: { id: number; name: string }[];
    goods: { id: number; name: string; unit: string }[];
    vatRates: { id: number; rate: number }[];
    bankCandidates: BankCandidate[];
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
// ── Сортировка кликом по заголовку ──
type SortKey = 'date' | 'sum' | 'eta';
const sortKey = ref<SortKey | null>(null);
const sortDir = ref<'asc' | 'desc'>('asc');
function toggleSort(key: SortKey) {
    const firstDir = key === 'sum' ? 'desc' : 'asc'; // суммы удобнее сначала по убыванию
    if (sortKey.value !== key) { sortKey.value = key; sortDir.value = firstDir; }
    else if (sortDir.value === firstDir) sortDir.value = firstDir === 'asc' ? 'desc' : 'asc';
    else { sortKey.value = null; sortDir.value = 'asc'; } // третий клик — сброс к обычному порядку
}
const sorted = computed(() => {
    if (!sortKey.value) return filtered.value;
    const dir = sortDir.value === 'asc' ? 1 : -1;
    const key = sortKey.value;
    return [...filtered.value].sort((a, b) => {
        let av: string | number, bv: string | number;
        if (key === 'sum') { av = a.sum; bv = b.sum; }
        else if (key === 'date') { av = a.date || ''; bv = b.date || ''; }
        else { av = a.eta || '9999-99-99'; bv = b.eta || '9999-99-99'; } // без ETA — в конец
        return av < bv ? -dir : av > bv ? dir : 0;
    });
});

const totalSum = computed(() => filtered.value.reduce((a, s) => a + s.sum, 0));
const totalDebt = computed(() => filtered.value.reduce((a, s) => a + (s.sum - s.paid), 0));

const goodName = (id: number | null) => props.goods.find((g) => g.id === id)?.name ?? '';
const hl = useRowHighlight();
const statusVariant = (s: string) => s === 'Завершено' ? 'ok' : s === 'В пути' ? 'info' : 'neutral';

// ── Быстрая смена статуса из таблицы ──
const STATUSES = ['Ожидает отправки', 'В пути', 'Завершено'] as const;
const statusMenuFor = ref<number | null>(null);
function toggleStatusMenu(id: number, e: Event) {
    e.stopPropagation();
    statusMenuFor.value = statusMenuFor.value === id ? null : id;
}
function setStatus(s: Row, st: string, e: Event) {
    e.stopPropagation();
    statusMenuFor.value = null;
    if (st !== s.status) router.post(`/shipments/${s.id}/status`, { status: st }, { preserveScroll: true });
}
onMounted(() => document.addEventListener('click', () => { statusMenuFor.value = null; }));

// ── Приёмка части товара ──
const recvOpen = ref(false);
const recvRows = ref<{ item_id: number; name: string; qty: number; received: number; now: number | null }[]>([]);
function openReceive() {
    const row = currentShip.value;
    if (!row) return;
    recvRows.value = row.items
        .filter((i) => (Number(i.qty) || 0) - (Number(i.qty_received) || 0) > 0.0005)
        .map((i) => ({
            item_id: i.id ?? 0, name: i.name ?? goodName(i.nomenclature_id),
            qty: Number(i.qty) || 0, received: Number(i.qty_received) || 0,
            now: null,
        }));
    recvOpen.value = true;
}
const recvCanSubmit = computed(() => recvRows.value.some((r) => (r.now ?? 0) > 0)
    && recvRows.value.every((r) => (r.now ?? 0) <= r.qty - r.received + 0.0005));
function submitReceive() {
    if (!editingId.value) return;
    router.post(`/shipments/${editingId.value}/receive`, {
        items: recvRows.value.filter((r) => (r.now ?? 0) > 0).map((r) => ({ item_id: r.item_id, qty: r.now })),
    }, { preserveScroll: true, onSuccess: () => { recvOpen.value = false; } });
}
const payText = (s: Row) => s.paid >= s.sum && s.sum > 0 ? 'Оплачено' : s.paid > 0 ? 'Частично' : 'Не оплачено';
const payVariant = (s: Row) => s.paid >= s.sum && s.sum > 0 ? 'ok' : s.paid > 0 ? 'warn' : 'bad';

// ── Форма документа ──
const open = ref(false);
const editingId = ref<number | null>(null);
const blankForm = {
    date: '', counterparty_id: null as number | null, name: '', status: 'Ожидает отправки', eta: '',
    carrier_id: null as number | null, tracking: '', delivery: 0, problem: false, items: [] as Item[],
};
// Ошибки сервера (например, «товар уже продан — нельзя указать меньше»)
const srvError = computed(() => ((usePage().props as any).errors ?? {}).shipment ?? null);

const form = useForm<{
    date: string; counterparty_id: number | null; name: string; status: string; eta: string;
    carrier_id: number | null; tracking: string; delivery: number; problem: boolean; items: Item[];
}>({ ...blankForm });

const formTotal = computed(() =>
    form.items.reduce((a, i) => a + (Number(i.qty) || 0) * (Number(i.price) || 0), 0) + (Number(form.delivery) || 0));

function create() {
    editingId.value = null;
    form.reset();
    form.date = new Date().toISOString().slice(0, 10);
    form.items = [{ nomenclature_id: null, qty: null, price: null, vat_rate: null, vat_amount: null }];
    supHint.value = '';
    showSup.value = false;
    showPayPick.value = false; payLineId.value = null; payAmount.value = null;
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
    form.items = s.items.map((i) => ({ nomenclature_id: i.nomenclature_id, qty: i.qty, price: i.price, vat_rate: i.vat_rate ?? null, vat_amount: i.vat_amount ?? null }));
    supHint.value = '';
    showSup.value = false;
    showPayPick.value = false; payLineId.value = null; payAmount.value = null;
    open.value = true;
}
function addItem() { form.items.push({ nomenclature_id: null, qty: null, price: null, vat_rate: null, vat_amount: null }); }
function removeItem(i: number) { form.items.splice(i, 1); }

// ── Оплата — привязка операций из выписки прямо здесь (то же, что и разнесение на странице Банк) ──
const currentShip = computed(() => props.rows.find((r) => r.id === editingId.value) ?? null);
const currentRow = computed(() => props.rows.find((r) => r.id === editingId.value) ?? null);
const currentDebt = computed(() => currentRow.value ? Math.max(currentRow.value.sum - currentRow.value.paid, 0) : 0);
// Сортируем по близости остатка операции к долгу поставки — самое вероятное совпадение сверху,
// не нужно листать все неразнесённые операции банка.
const bankCandOptions = computed(() => [...props.bankCandidates]
    .sort((a, b) => Math.abs(a.remaining - currentDebt.value) - Math.abs(b.remaining - currentDebt.value))
    .map((c) => ({ id: c.id, name: `${fdate(c.date)} · ${c.party} · ${money(c.remaining)}` })));

const showPayPick = ref(false);
const payLineId = ref<number | null>(null);
const payAmount = ref<number | null>(null);
const payLineRemaining = computed(() => props.bankCandidates.find((c) => c.id === payLineId.value)?.remaining ?? 0);
// Сумма подставляется сама (остаток операции или долг поставки, что меньше), но поле
// видно всегда: один платёж часто закрывает несколько документов, разбивка должна быть явной.
function pickPayLine(id: number | null) {
    payLineId.value = id;
    const cand = props.bankCandidates.find((c) => c.id === id);
    if (cand) payAmount.value = Math.round(Math.min(currentDebt.value, cand.remaining) * 100) / 100;
}
function attachPayment() {
    if (!editingId.value || !payLineId.value || !payAmount.value) return;
    router.post(`/shipments/${editingId.value}/match`, { bank_line_id: payLineId.value, amount: payAmount.value }, {
        onSuccess: () => { showPayPick.value = false; payLineId.value = null; payAmount.value = null; },
    });
}
function detachPayment(p: Payment) {
    if (!editingId.value) return;
    router.delete(`/shipments/${editingId.value}/match/${p.match_id}`);
}

// НДС: цена в поставке уже с НДС, сумма выделяется по формуле Сумма × ставка / (100 + ставка).
// Пересчитывается при изменении кол-ва/цены/ставки, но остаётся редактируемой вручную.
function applyAutoVat(it: Item) {
    if (it.vat_rate === null || it.vat_rate === undefined) {
        it.vat_amount = null;
        return;
    }
    const sum = (Number(it.qty) || 0) * (Number(it.price) || 0);
    it.vat_amount = Math.round(sum * it.vat_rate / (100 + it.vat_rate) * 100) / 100;
}

// ── Быстрое создание поставщика прямо в модалке ──
const showSup = ref(false);
const supForm = ref({ name: '' });
const supSaving = ref(false);
const supHint = ref('');
async function saveSup() {
    if (!supForm.value.name.trim()) return;
    supSaving.value = true;
    try {
        const { data } = await axios.post('/quick/counterparties', { name: supForm.value.name });
        suppliers.value.push(data);
        form.counterparty_id = data.id;
        supHint.value = data.name;
        showSup.value = false; supForm.value = { name: '' };
    } catch { alert('Не удалось создать поставщика'); }
    supSaving.value = false;
}

// ── Быстрое создание товара прямо в позиции (чтобы не плодить дубли в номенклатуре) ──
const showGoodQuick = ref<number | null>(null);
const goodQuickForm = ref({ name: '', unit: 'шт' });
const goodQuickSaving = ref(false);
async function saveGood(i: number) {
    if (!goodQuickForm.value.name.trim()) return;
    goodQuickSaving.value = true;
    try {
        const { data } = await axios.post('/quick/nomenclature', goodQuickForm.value);
        goods.value.push(data);
        form.items[i].nomenclature_id = data.id;
        showGoodQuick.value = null;
        goodQuickForm.value = { name: '', unit: 'шт' };
    } catch { alert('Не удалось создать товар'); }
    goodQuickSaving.value = false;
}

function submit() {
    const wasCreate = !editingId.value;
    const opts = {
        onSuccess: () => {
            open.value = false;
            // Inertia после успешной отправки запоминает just-submitted значения как новый
            // дефолт для reset() — без этого следующее «Создать» открывалось бы с данными
            // только что сохранённой поставки.
            if (wasCreate) {
                form.defaults({ ...blankForm, items: [] });
                form.reset();
            }
        },
    };
    if (editingId.value) form.put(`/shipments/${editingId.value}`, opts);
    else form.post('/shipments', opts);
}
async function destroy() {
    if (editingId.value && await confirmDlg('Удалить поставку?')) {
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

        <!-- Десктоп/планшет: таблица -->
        <div class="jcard glass ship-desk">
            <div class="jscroll">
                <table class="jtable ship-table">
                    <thead>
                        <tr>
                            <th class="col-num">№</th>
                            <th class="col-date sortable" title="Сортировка. Третий клик — сброс" @click="toggleSort('date')">
                                <span class="ths">Дата<Icon name="chevron-up" :size="13" class="sort-ar" :class="{ 'sort-ar--on': sortKey === 'date', 'sort-ar--desc': sortKey === 'date' && sortDir === 'desc' }" /></span>
                            </th>
                            <th>Поставка</th>
                            <th class="num sortable" title="Сортировка. Третий клик — сброс" @click="toggleSort('sum')">
                                <span class="ths">Сумма<Icon name="chevron-up" :size="13" class="sort-ar" :class="{ 'sort-ar--on': sortKey === 'sum', 'sort-ar--desc': sortKey === 'sum' && sortDir === 'desc' }" /></span>
                            </th>
                            <th class="sortable" title="Сортировка. Третий клик — сброс" @click="toggleSort('eta')">
                                <span class="ths">Статус · ETA<Icon name="chevron-up" :size="13" class="sort-ar" :class="{ 'sort-ar--on': sortKey === 'eta', 'sort-ar--desc': sortKey === 'eta' && sortDir === 'desc' }" /></span>
                            </th>
                        </tr>
                    </thead>
                    <tbody v-stagger>
                        <tr v-for="s in sorted" :key="s.id" :data-hl="s.id" :class="{ 'row-hl': hl === s.id }" @click="openDoc(s)">
                            <td class="col-num text-ink-3">{{ s.number }}</td>
                            <td class="text-ink-2 col-date">{{ fdate(s.date) }}</td>
                            <td class="col-ship">
                                <div class="ship-name">
                                    <span class="sn-t">{{ s.name || s.supplier }}</span>
                                    <StatusPill v-if="s.problem" text="Проблема" variant="bad" />
                                </div>
                                <div class="ship-sup">{{ s.name ? s.supplier : '—' }}</div>
                            </td>
                            <td class="num col-sum">
                                <div class="sum-v tnum">{{ money(s.sum) }}</div>
                                <div class="pay-chip" :class="'pay-chip--' + payVariant(s)"><i></i>{{ payText(s) }}</div>
                            </td>
                            <td class="col-track" style="position:relative">
                                <button type="button" class="st-btn pressable" @click="toggleStatusMenu(s.id, $event)">
                                    <StatusPill :text="s.status" :variant="statusVariant(s.status)" />
                                    <Icon name="chevron-down" :size="12" class="text-ink-3" />
                                </button>
                                <div class="track-sub">
                                    <span v-if="s.eta" class="ts-eta text-ink-3">{{ s.status === 'Завершено' ? 'прибыло ' : 'до ' }}{{ fdate(s.eta) }}</span>
                                    <span v-if="s.eta_shift > 0" class="eta-shift eta-shift--late">+{{ s.eta_shift }} дн</span>
                                    <span v-else-if="s.eta_shift < 0" class="eta-shift eta-shift--early">−{{ -s.eta_shift }} дн</span>
                                </div>
                                <div v-if="s.status === 'В пути' && s.received_qty > 0" class="recv-line">
                                    <span class="recv-bar"><i :style="{ width: Math.min(100, s.received_qty / (s.total_qty || 1) * 100) + '%' }"></i></span>
                                    <span class="recv-mini">{{ num(s.received_qty) }} из {{ num(s.total_qty) }}</span>
                                </div>
                                <div v-if="statusMenuFor === s.id" class="st-menu" @click.stop>
                                    <button v-for="st in STATUSES" :key="st" type="button" class="st-menu-item pressable" :class="{ on: st === s.status }" @click="setStatus(s, st, $event)">{{ st }}</button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="!filtered.length"><td colspan="5">
                            <div class="empty-big">
                                <span class="eb-ic"><Icon name="package" :size="30" /></span>
                                <b>Поставок пока нет</b>
                                <span>Создайте первую поставку — товар оприходуется на склад при статусе «В пути»</span>
                                <button class="btn-primary pressable" @click="create"><Icon name="plus" :size="16" /> Новая поставка</button>
                            </div>
                        </td></tr>
                    </tbody>
                    <tfoot v-if="filtered.length">
                        <tr>
                            <td colspan="3">Итого: {{ filtered.length }}</td>
                            <td class="num">{{ money(totalSum) }}</td>
                            <td>Долг поставщикам: {{ money(totalDebt) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <!-- Телефон: карточки -->
        <div class="ship-cards" v-stagger>
            <div v-for="s in sorted" :key="s.id" class="ship-card pressable" :data-hl="s.id" :class="{ 'row-hl': hl === s.id }" @click="openDoc(s)">
                <div class="sc-top">
                    <div class="sc-title">
                        <span class="sc-name">{{ s.name || s.supplier }}</span>
                        <span class="sc-sup">{{ s.name ? s.supplier : ('№ ' + s.number) }}</span>
                    </div>
                    <div class="sc-status" style="position:relative">
                        <button type="button" class="st-btn pressable" @click="toggleStatusMenu(s.id, $event)">
                            <StatusPill :text="s.status" :variant="statusVariant(s.status)" />
                            <Icon name="chevron-down" :size="12" class="text-ink-3" />
                        </button>
                        <div v-if="statusMenuFor === s.id" class="st-menu st-menu--r" @click.stop>
                            <button v-for="st in STATUSES" :key="st" type="button" class="st-menu-item pressable" :class="{ on: st === s.status }" @click="setStatus(s, st, $event)">{{ st }}</button>
                        </div>
                    </div>
                </div>
                <div class="sc-mid">
                    <span class="sc-sum tnum">{{ money(s.sum) }}</span>
                    <span class="pay-chip" :class="'pay-chip--' + payVariant(s)"><i></i>{{ payText(s) }}</span>
                    <StatusPill v-if="s.problem" text="Проблема" variant="bad" />
                </div>
                <div class="sc-bot">
                    <span v-if="s.eta" class="text-ink-3">{{ s.status === 'Завершено' ? 'прибыло ' : 'до ' }}{{ fdate(s.eta) }}</span>
                    <span v-else class="text-ink-3">{{ fdate(s.date) }}</span>
                    <span v-if="s.eta_shift > 0" class="eta-shift eta-shift--late">+{{ s.eta_shift }} дн</span>
                    <span v-else-if="s.eta_shift < 0" class="eta-shift eta-shift--early">−{{ -s.eta_shift }} дн</span>
                    <span v-if="s.status === 'В пути' && s.received_qty > 0" class="recv-mini" style="margin-left:auto">приехало {{ num(s.received_qty) }} из {{ num(s.total_qty) }}</span>
                </div>
            </div>
            <div v-if="!filtered.length" class="empty-big">
                <span class="eb-ic"><Icon name="package" :size="30" /></span>
                <b>Поставок пока нет</b>
                <button class="btn-primary pressable" @click="create"><Icon name="plus" :size="16" /> Новая поставка</button>
            </div>
        </div>

        <AppModal :open="open" wide :title="editingId ? 'Поставка' : 'Новая поставка'" @close="open = false">
            <!-- Поставщик -->
            <div class="modal-sec">
                <div class="modal-sec-h"><Icon name="building" :size="14" /> Поставщик</div>
                <div class="fld"><label>Поставщик</label>
                    <div class="sel-add">
                        <SearchSelect v-model="form.counterparty_id" :options="suppliers" placeholder="— выбрать —" />
                        <button type="button" class="add-btn pressable" :class="{ on: showSup }" @click="showSup = !showSup" title="Создать поставщика">+</button>
                    </div>
                </div>
                <div v-if="showSup" class="quick-form">
                    <input v-model="supForm.name" placeholder="Наименование поставщика" @keyup.enter="saveSup" />
                    <button type="button" class="btn-primary pressable" style="padding:8px 14px" :disabled="supSaving" @click="saveSup">Создать</button>
                </div>
                <div v-if="supHint" class="sup-hint">Поставщик «{{ supHint }}» создан. Дозаполните карточку (ИНН, контакты) в Справочниках.</div>
                <div class="fld" style="margin-top:12px"><label>Название поставки</label><input v-model="form.name" placeholder="необязательно" /></div>
            </div>

            <!-- Статус и сроки -->
            <div class="modal-sec">
                <div class="modal-sec-h"><Icon name="calendar" :size="14" /> Статус и сроки</div>
                <div class="fld"><label>Статус</label>
                    <select v-model="form.status"><option>Ожидает отправки</option><option>В пути</option><option>Завершено</option></select>
                </div>
                <div class="fld-row" style="margin-top:12px">
                    <div class="fld"><label>Дата заказа</label><DatePicker v-model="form.date" placeholder="дд.мм.гггг" /></div>
                    <div class="fld"><label>ETA</label><DatePicker v-model="form.eta" placeholder="дд.мм.гггг" />
                        <div v-if="currentShip && currentShip.eta_shift !== 0" class="eta-note" :style="{ color: currentShip.eta_shift > 0 ? 'var(--warn)' : 'var(--income)' }">
                            {{ currentShip.eta_shift > 0 ? `задерживается на ${currentShip.eta_shift} дн` : `раньше срока на ${-currentShip.eta_shift} дн` }} · первоначально {{ fdate(currentShip.eta_first) }}
                        </div>
                    </div>
                </div>
            </div>

            <!-- Доставка -->
            <div class="modal-sec">
                <div class="modal-sec-h"><Icon name="truck" :size="14" /> Доставка</div>
                <div class="fld-row">
                    <div class="fld"><label>Транспортная компания</label>
                        <SearchSelect v-model="form.carrier_id" :options="carriers" placeholder="—" />
                    </div>
                    <div class="fld"><label>Трек-номер</label><input v-model="form.tracking" placeholder="—" /></div>
                </div>
                <div class="fld" style="margin-top:12px"><label>Стоимость доставки</label><input v-model.number="form.delivery" type="number" /></div>
            </div>

            <!-- Оплата -->
            <div class="modal-sec">
                <div class="modal-sec-h"><Icon name="wallet" :size="14" /> Оплата</div>
                <div v-if="!editingId" class="text-ink-3" style="font-size:13px">Привязать оплату можно после сохранения поставки.</div>
                <template v-else>
                    <div v-for="p in currentRow?.payments ?? []" :key="p.match_id" class="pay-row">
                        <div class="pay-row-info">
                            <span class="text-ink-2" style="font-size:13px">{{ fdate(p.date) }} · {{ p.party }}</span>
                        </div>
                        <span class="tnum" style="font-weight:600">{{ money(p.amount) }}</span>
                        <button type="button" class="good-card-remove pressable" style="width:32px;height:32px" @click="detachPayment(p)" title="Отвязать">✕</button>
                    </div>
                    <div v-if="!(currentRow?.payments ?? []).length" class="text-ink-3" style="font-size:13px;padding:4px 0">Оплат пока нет</div>

                    <template v-if="showPayPick">
                        <div class="quick-form" style="flex-wrap:wrap">
                            <div style="flex:1;min-width:220px">
                                <SearchSelect :modelValue="payLineId" :options="bankCandOptions" placeholder="— выбрать операцию из выписки —" @update:modelValue="pickPayLine" />
                            </div>
                            <template v-if="payLineId">
                                <input v-model.number="payAmount" type="number" step="0.01" placeholder="Сумма" style="max-width:120px" />
                                <button type="button" class="btn-primary pressable" style="padding:8px 14px" :disabled="!payAmount || (payAmount ?? 0) > payLineRemaining + 0.01" @click="attachPayment">Привязать</button>
                            </template>
                        </div>
                        <div v-if="payLineId" class="text-ink-3" style="font-size:12px;margin-top:6px">
                            У операции не разнесено {{ money(payLineRemaining) }} — можно привязать сюда часть, а остаток к другой поставке.
                            <span v-if="(payAmount ?? 0) > payLineRemaining + 0.01" style="color:var(--expense);font-weight:600">Сумма больше остатка операции.</span>
                        </div>
                    </template>
                    <button v-else type="button" class="btn-ghost pressable" style="margin-top:8px" @click="showPayPick = true"><Icon name="plus" :size="14" /> Привязать оплату</button>
                    <div v-if="showPayPick && !bankCandidates.length" class="text-ink-3" style="font-size:12px;margin-top:6px">Нет операций с неразнесённым остатком. Если платёж уже привязан к другой поставке целиком — отвяжите его там (✕) или уменьшите сумму привязки, и он снова появится здесь.</div>
                </template>
            </div>

            <!-- Товары -->
            <div class="modal-sec">
                <div class="modal-sec-h">
                    <Icon name="package" :size="14" /> Товары
                    <button class="btn-ghost pressable" style="margin-left:auto;padding:6px 12px;font-size:13px" @click="addItem"><Icon name="plus" :size="14" /> Добавить</button>
                </div>
                <div v-for="(it, i) in form.items" :key="i" class="good-card">
                    <div class="good-card-row1">
                        <SearchSelect v-model="it.nomenclature_id" :options="goods" placeholder="— выбрать товар —" />
                        <button type="button" class="add-btn-sm pressable" :class="{ on: showGoodQuick === i }" @click="showGoodQuick = showGoodQuick === i ? null : i" title="Создать товар">+</button>
                        <button type="button" class="good-card-remove pressable" @click="removeItem(i)" title="Удалить позицию">✕</button>
                    </div>
                    <div v-if="showGoodQuick === i" class="quick-form">
                        <input v-model="goodQuickForm.name" placeholder="Наименование товара" @keyup.enter="saveGood(i)" />
                        <input v-model="goodQuickForm.unit" placeholder="ед." style="max-width:70px" />
                        <button type="button" class="btn-primary pressable" style="padding:8px 14px" :disabled="goodQuickSaving" @click="saveGood(i)">Создать</button>
                    </div>
                    <div class="good-card-grid">
                        <div class="fld"><label>Количество</label><input v-model.number="it.qty" type="number" placeholder="0" @input="applyAutoVat(it)" /></div>
                        <div class="fld"><label>Цена за ед.</label><input v-model.number="it.price" type="number" placeholder="0" @input="applyAutoVat(it)" /></div>
                        <div class="fld"><label>НДС</label>
                            <select v-model="it.vat_rate" @change="applyAutoVat(it)">
                                <option :value="null">Без НДС</option>
                                <option v-for="v in vatRates" :key="v.id" :value="v.rate">{{ v.rate }}%</option>
                            </select>
                        </div>
                        <div class="fld"><label>Сумма НДС</label>
                            <input v-model.number="it.vat_amount" type="number" step="0.01" placeholder="0" :disabled="it.vat_rate === null" />
                        </div>
                    </div>
                </div>
                <div v-if="!form.items.length" class="text-ink-3" style="padding:12px 0;font-size:14px">Добавьте позиции: выберите товар, количество и цену</div>

                <!-- История переносов ETA -->
                <div v-if="currentShip && currentShip.eta_changes.length" class="eta-hist">
                    <div class="items-h"><span class="h2">Переносы ETA</span></div>
                    <div v-for="(c, i) in currentShip.eta_changes" :key="i" class="eta-hist-row">
                        <span class="tnum">{{ fdate(c.old) }} → {{ fdate(c.new) }}</span>
                        <span class="text-ink-3">перенесено {{ c.at }}</span>
                    </div>
                </div>

                <!-- Приёмка: что уже приехало -->
                <div v-if="currentShip && currentShip.receipts.length" class="eta-hist">
                    <div class="items-h"><span class="h2">Приёмки товара</span></div>
                    <div v-for="(rc, i) in currentShip.receipts" :key="i" class="eta-hist-row">
                        <span>{{ rc.name }} · {{ num(rc.qty) }}</span>
                        <span class="text-ink-3">{{ fdate(rc.date) }}</span>
                    </div>
                </div>

                <div class="modal-total">
                    <span class="text-ink-2 text-[14px]">Итого (товары + доставка)</span>
                    <span class="tnum text-[18px] font-bold">{{ money(formTotal) }}</span>
                </div>
                <div v-if="srvError" class="ship-err">{{ srvError }}</div>
            </div>

            <template #footer>
                <button class="btn-primary pressable" style="flex:1;justify-content:center;gap:8px" :disabled="form.processing" @click="submit">
                    <Icon v-if="form.processing" name="loader" :size="16" style="animation:spin 1s linear infinite" />
                    {{ form.processing ? 'Сохранение…' : (form.status === 'Ожидает отправки' ? 'Сохранить' : 'Сохранить и оприходовать') }}
                </button>
                <button v-if="editingId && currentShip?.status === 'В пути'" class="btn-ghost pressable" @click="openReceive"><Icon name="package" :size="15" /> Принять товар</button>
                <button v-if="editingId" class="btn-ghost pressable" @click="destroy">Удалить</button>
            </template>
        </AppModal>

        <!-- Приёмка части товара -->
        <AppModal :open="recvOpen" title="Принять товар" :subtitle="currentShip ? currentShip.number + ' · приехало ' + num(currentShip.received_qty) + ' из ' + num(currentShip.total_qty) : ''" @close="recvOpen = false">
            <div v-for="r in recvRows" :key="r.item_id" class="recv-row">
                <div class="recv-info">
                    <b>{{ r.name }}</b>
                    <i>принято {{ num(r.received) }} из {{ num(r.qty) }} · осталось {{ num(r.qty - r.received) }}</i>
                </div>
                <input v-model.number="r.now" type="number" step="0.001" min="0" :max="r.qty - r.received" placeholder="0" class="recv-inp tnum" />
                <button type="button" class="link-btn" @click="r.now = r.qty - r.received">всё</button>
            </div>
            <div v-if="!recvRows.length" class="text-ink-3" style="font-size:13px">Все позиции уже приняты.</div>
            <div class="text-ink-3" style="font-size:12px">Принятая часть перейдёт на складе из «В пути» в «Остаток». Когда приедет всё — поставка сама станет «Завершено».</div>
            <div v-if="srvError" class="ship-err">{{ srvError }}</div>
            <template #footer>
                <button class="btn-primary pressable" style="flex:1;justify-content:center" :disabled="!recvCanSubmit" @click="submitReceive">Принять</button>
                <button class="btn-ghost pressable" @click="recvOpen = false">Отмена</button>
            </template>
        </AppModal>
    </AppShell>
</template>

<style scoped>
/* Быстрая смена статуса из таблицы */
.st-btn { display: inline-flex; align-items: center; gap: 5px; background: transparent; border: 0; padding: 0; cursor: pointer; font: inherit; }
.recv-mini { font-size: 11px; font-weight: 700; color: var(--info); white-space: nowrap; }

/* ── Читабельная таблица поставок ── */
.ship-table th, .ship-table td { vertical-align: middle; }
.ship-table tbody tr { transition: background .15s ease; }
.col-num { width: 52px; }
.col-date { width: 110px; white-space: nowrap; }
.col-sum { width: 150px; }
.col-track { width: 210px; }

/* Кликабельные заголовки для сортировки — стрелка сразу после текста, место под неё зарезервировано, поэтому колонка не дёргается */
.sortable { cursor: pointer; user-select: none; }
.ths { display: inline-flex; align-items: center; gap: 4px; }
.sortable:hover { color: var(--ink); }
.sort-ar { flex-shrink: 0; opacity: 0; transition: opacity .15s ease, transform .15s ease; }
.sortable:hover .sort-ar { opacity: .35; }
.sort-ar--on { opacity: 1 !important; color: var(--ink); }
.sort-ar--desc { transform: rotate(180deg); }

/* Ячейка «Поставка»: название крупно, поставщик мелким серым */
.col-ship { min-width: 200px; }
.ship-name { display: flex; align-items: center; gap: 8px; }
.sn-t { font-weight: 600; font-size: 14.5px; color: var(--ink); overflow: hidden; text-overflow: ellipsis; }
.ship-sup { font-size: 12px; color: var(--ink-3); margin-top: 2px; }

/* Сумма + чип оплаты */
.sum-v { font-weight: 600; }
.pay-chip { display: inline-flex; align-items: center; gap: 5px; margin-top: 4px; font-size: 11px; font-weight: 600; color: var(--ink-2); white-space: nowrap; }
.pay-chip i { width: 7px; height: 7px; border-radius: 999px; flex-shrink: 0; }
.pay-chip--ok i { background: var(--income); }
.pay-chip--ok { color: var(--income); }
.pay-chip--warn i { background: var(--warn); }
.pay-chip--warn { color: var(--warn); }
.pay-chip--bad i { background: var(--expense); }
.pay-chip--bad { color: var(--expense); }
.col-sum .pay-chip { justify-content: flex-end; }

/* Трек-ячейка: статус + ETA + перенос + прогресс приёмки */
.track-sub { display: flex; align-items: center; gap: 6px; margin-top: 5px; }
.ts-eta { font-size: 12px; white-space: nowrap; }
.recv-line { display: flex; align-items: center; gap: 7px; margin-top: 6px; }
.recv-bar { flex: 1; max-width: 88px; height: 5px; border-radius: 999px; background: var(--glass-border); overflow: hidden; }
.recv-bar i { display: block; height: 100%; border-radius: 999px; background: var(--info); transition: width .3s ease; }

/* Карточки для телефона */
.ship-cards { display: none; flex-direction: column; gap: 10px; }
.ship-card { border-radius: 16px; padding: 13px 15px; background: var(--glass-fill); border: 1px solid var(--glass-border); cursor: pointer; }
.sc-top { display: flex; align-items: flex-start; justify-content: space-between; gap: 10px; }
.sc-title { min-width: 0; }
.sc-name { display: block; font-weight: 600; font-size: 15px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.sc-sup { display: block; font-size: 12px; color: var(--ink-3); margin-top: 2px; }
.sc-mid { display: flex; align-items: center; gap: 10px; margin-top: 10px; }
.sc-sum { font-weight: 700; font-size: 17px; }
.sc-mid .pay-chip { margin-top: 0; }
.sc-bot { display: flex; align-items: center; gap: 8px; margin-top: 8px; font-size: 12px; }
.st-menu--r { left: auto; right: 0; }

@media (max-width: 640px) {
    .ship-desk { display: none; }
    .ship-cards { display: flex; }
}
.st-menu { position: absolute; z-index: 25; top: calc(100% - 4px); left: 8px; min-width: 170px; padding: 5px; border-radius: 13px; background: var(--glass-solid); border: 1px solid var(--glass-border); box-shadow: var(--shadow-lg); display: flex; flex-direction: column; gap: 2px; }
.st-menu-item { text-align: left; padding: 8px 11px; border: 0; border-radius: 9px; background: transparent; color: var(--ink); font-size: 13.5px; font-weight: 500; cursor: pointer; }
.st-menu-item:hover { background: var(--glass-fill); }
.st-menu-item.on { font-weight: 700; color: var(--info); }
/* Приёмка */
.recv-row { display: flex; align-items: center; gap: 10px; padding: 9px 0; border-bottom: 1px solid var(--glass-border); }
.recv-row:last-of-type { border-bottom: 0; }
.recv-info { flex: 1; min-width: 0; display: flex; flex-direction: column; }
.recv-info b { font-size: 14px; font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.recv-info i { font-style: normal; font-size: 12px; color: var(--ink-2); }
.recv-inp { width: 96px; height: 36px; border: 1px solid var(--glass-border); background: var(--glass-fill); border-radius: 10px; padding: 0 10px; color: var(--ink); font-size: 14px; font-family: inherit; outline: none; text-align: right; }
.eta-shift { font-size: 11px; font-weight: 700; padding: 1px 7px; border-radius: 999px; margin-left: 5px; white-space: nowrap; }
.eta-shift--late { background: rgba(255, 159, 10, .16); color: var(--warn); }
.eta-shift--early { background: rgba(52, 199, 89, .16); color: var(--income); }
.eta-note { font-size: 12px; font-weight: 600; margin-top: 5px; }
.eta-hist { padding: 12px 14px; border: 1px solid var(--glass-border); border-radius: 14px; background: var(--glass-fill); }
.eta-hist-row { display: flex; justify-content: space-between; gap: 12px; font-size: 13px; padding: 5px 0; }
.ship-err { margin-top: 10px; padding: 10px 14px; border-radius: 12px; background: rgba(255,69,58,.12); border: 1px solid rgba(255,69,58,.35); color: var(--expense); font-size: 13px; font-weight: 600; }
/* Секции модалки — сгруппированные поля с заголовком, разделены тонкой линией */
.modal-sec { padding: 18px 0; border-top: 1px solid var(--glass-border); }
.modal-sec:first-child { padding-top: 0; border-top: 0; }
.modal-sec-h { display: flex; align-items: center; gap: 7px; font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; color: var(--ink-3); margin-bottom: 12px; }

/* Карточка товара — просторнее, все поля с подписями */
.good-card { border: 1px solid var(--glass-border); border-radius: 14px; padding: 12px; background: var(--glass-fill); margin-bottom: 10px; }
.good-card-row1 { display: flex; gap: 8px; align-items: center; }
.good-card-row1 .ssel { flex: 1; min-width: 0; }
.good-card-remove { flex-shrink: 0; width: 40px; height: 40px; border-radius: 10px; border: 1px solid var(--glass-border); background: transparent; color: var(--expense); font-size: 15px; cursor: pointer; display: flex; align-items: center; justify-content: center; }
.pay-row { display: flex; align-items: center; gap: 10px; padding: 8px 10px; border: 1px solid var(--glass-border); border-radius: 12px; background: var(--glass-fill); margin-bottom: 8px; }
.pay-row-info { flex: 1; min-width: 0; }
.good-card-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin-top: 12px; }
.good-card-grid label { font-size: 11px; }
.good-card-grid input, .good-card-grid select { height: 38px; box-sizing: border-box; border: 1px solid var(--glass-border); background: var(--bg); border-radius: 9px; padding: 0 10px; color: var(--ink); font-size: 13px; font-family: inherit; outline: none; }
.good-card-grid input:disabled { opacity: .5; }
@media (max-width: 640px) { .good-card-grid { grid-template-columns: repeat(2, 1fr); } }

.modal-total { display: flex; align-items: center; justify-content: space-between; padding-top: 12px; margin-top: 4px; border-top: 1px solid var(--glass-border); }

.add-btn-sm { flex-shrink: 0; width: 40px; height: 40px; border-radius: 10px; border: 1px solid var(--glass-border); background: var(--glass-fill); color: var(--ink); font-size: 17px; line-height: 1; cursor: pointer; display: flex; align-items: center; justify-content: center; }
.add-btn-sm.on { background: var(--ink); color: var(--bg); }
.sel-add { display: flex; gap: 8px; align-items: center; }
.sel-add .ssel { flex: 1; min-width: 0; }
.add-btn { flex-shrink: 0; width: 42px; height: 42px; border-radius: 12px; border: 1px solid var(--glass-border); background: var(--glass-fill); color: var(--ink); font-size: 20px; line-height: 1; cursor: pointer; display: flex; align-items: center; justify-content: center; }
.add-btn.on { background: var(--ink); color: var(--bg); }
.quick-form { display: flex; gap: 8px; align-items: center; padding: 10px 12px; margin-top: 8px; background: var(--glass-fill); border: 1px solid var(--glass-border); border-radius: 12px; flex-wrap: wrap; }
.quick-form input, .quick-form select { flex: 1; min-width: 120px; height: 40px; box-sizing: border-box; border: 1px solid var(--glass-border); background: var(--bg); border-radius: 10px; padding: 0 12px; color: var(--ink); font-size: 14px; font-family: inherit; outline: none; }
.on-ghost { background: var(--ink) !important; color: var(--bg) !important; }
.sup-hint { margin-top: 8px; font-size: 13px; color: var(--income); background: rgba(52,199,89,.12); border: 1px solid rgba(52,199,89,.3); border-radius: 10px; padding: 8px 12px; }
</style>
