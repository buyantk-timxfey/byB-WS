<script setup lang="ts">
import { ref, computed } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import axios from 'axios';
import AppShell from '@/Layouts/AppShell.vue';
import Icon from '@/Components/Icon.vue';
import StatusPill from '@/Components/StatusPill.vue';
import AppModal from '@/Components/AppModal.vue';
import SearchSelect from '@/Components/SearchSelect.vue';
import DatePicker from '@/Components/DatePicker.vue';
import { money, date as fdate } from '@/lib/format';

type Item = { nomenclature_id: number | null; qty: number | null; price: number | null; vat_rate: number | null; vat_amount: number | null };
type Payment = { match_id: number; bank_line_id: number; date: string | null; party: string; amount: number };
type Row = {
    id: number; number: string; date: string; supplier: string; counterparty_id: number | null;
    name: string | null; status: string; eta: string | null; carrier_id: number | null;
    tracking: string | null; delivery: number; problem: boolean; sum: number; paid: number;
    posted: boolean; items: Item[]; payments: Payment[];
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
const totalSum = computed(() => filtered.value.reduce((a, s) => a + s.sum, 0));
const totalDebt = computed(() => filtered.value.reduce((a, s) => a + (s.sum - s.paid), 0));

const statusVariant = (s: string) => s === 'Завершено' ? 'ok' : s === 'В пути' ? 'info' : 'neutral';
const payText = (s: Row) => s.paid >= s.sum && s.sum > 0 ? 'Оплачено' : s.paid > 0 ? 'Частично' : 'Не оплачено';
const payVariant = (s: Row) => s.paid >= s.sum && s.sum > 0 ? 'ok' : s.paid > 0 ? 'warn' : 'bad';

// ── Форма документа ──
const open = ref(false);
const editingId = ref<number | null>(null);
const blankForm = {
    date: '', counterparty_id: null as number | null, name: '', status: 'Ожидает отправки', eta: '',
    carrier_id: null as number | null, tracking: '', delivery: 0, problem: false, items: [] as Item[],
};
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
                            <th class="num">Сумма</th><th class="pay-col"><Icon name="link" :size="14" /></th><th>Статус</th><th>ETA</th>
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
                            <td class="pay-col" :title="payText(s)">
                                <Icon v-if="payVariant(s) === 'ok'" name="check" :size="16" style="color:var(--income)" />
                                <Icon v-else-if="payVariant(s) === 'warn'" name="minus" :size="16" style="color:var(--warn)" />
                                <Icon v-else name="x" :size="16" style="color:var(--expense)" />
                            </td>
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
                    <div class="fld"><label>ETA</label><DatePicker v-model="form.eta" placeholder="дд.мм.гггг" /></div>
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

                <div class="modal-total">
                    <span class="text-ink-2 text-[14px]">Итого (товары + доставка)</span>
                    <span class="tnum text-[18px] font-bold">{{ money(formTotal) }}</span>
                </div>
            </div>

            <template #footer>
                <button class="btn-primary pressable" style="flex:1;justify-content:center;gap:8px" :disabled="form.processing" @click="submit">
                    <Icon v-if="form.processing" name="loader" :size="16" style="animation:spin 1s linear infinite" />
                    {{ form.processing ? 'Сохранение…' : (form.status === 'Ожидает отправки' ? 'Сохранить' : 'Сохранить и оприходовать') }}
                </button>
                <button v-if="editingId" class="btn-ghost pressable" @click="destroy">Удалить</button>
            </template>
        </AppModal>
    </AppShell>
</template>

<style scoped>
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
