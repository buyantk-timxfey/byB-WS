<script setup lang="ts">
import { ref, computed } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import AppShell from '@/Layouts/AppShell.vue';
import Icon from '@/Components/Icon.vue';
import StatusPill from '@/Components/StatusPill.vue';
import AppModal from '@/Components/AppModal.vue';
import SearchSelect from '@/Components/SearchSelect.vue';
import DatePicker from '@/Components/DatePicker.vue';
import Sparkline from '@/Components/Sparkline.vue';
import { money, money0, signed, initials } from '@/lib/format';
import { confirmDlg } from '@/lib/confirm';

type MatchSel = { target_type: string; target_id: number | null; amount: number; label?: string | null };
type Line = {
    id: number; date: string; party: string; purpose: string | null; account: string; amount: number;
    status: string; inn: string | null; link: string | null;
    matches: MatchSel[];
};
type Doc = { id: number; number: string; party: string; sum: number; debt: number; inn: string | null };

const props = defineProps<{
    accounts: any[]; lines: Line[]; articles: { id: number; name: string }[];
    incomeArticles: { id: number; name: string }[];
    openSales: Doc[]; openShipments: Doc[]; importAccounts: { id: number; name: string }[];
    balanceSeries: number[];
}>();

// Веер карточек — разворот и вертикальный сдвиг симметрично от центра, без наложения
// на текст (только небольшой нахлёст по краю), чтобы вся информация оставалась читаемой.
function fanRotate(i: number) {
    const mid = (props.accounts.length - 1) / 2;
    return Math.round((i - mid) * 6 * 10) / 10;
}
function fanY(i: number) {
    const mid = (props.accounts.length - 1) / 2;
    return Math.round(Math.abs(i - mid) * 10);
}

const totalBalance = computed(() => props.accounts.reduce((a, x) => a + x.balance, 0));
const balanceDelta = computed(() => {
    const s = props.balanceSeries;
    return s.length ? s[s.length - 1] - s[0] : 0;
});

const seg = ref<'all' | 'unmatched' | 'in' | 'out'>('all');
const q = ref('');
const dateFrom = ref('');
const dateTo = ref('');
const rows = computed(() => props.lines.filter((o) => {
    if (seg.value === 'unmatched' && (o.status === 'matched' || o.status === 'ignore')) return false;
    if (seg.value === 'in' && o.amount < 0) return false;
    if (seg.value === 'out' && o.amount > 0) return false;
    if (dateFrom.value && o.date < dateFrom.value) return false;
    if (dateTo.value && o.date > dateTo.value) return false;
    if (q.value && !(`${o.party} ${o.purpose ?? ''}`.toLowerCase().includes(q.value.toLowerCase()))) return false;
    return true;
}));
// Лента группируется по дням, как в Apple Wallet: «Сегодня», «Вчера», «30 июня 2026»
const isoDay = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
const todayIso = isoDay(new Date());
const yesterdayIso = isoDay(new Date(Date.now() - 864e5));
const dayFmt = new Intl.DateTimeFormat('ru-RU', { day: 'numeric', month: 'long', year: 'numeric' });
function dayLabel(d: string): string {
    if (d === todayIso) return 'Сегодня';
    if (d === yesterdayIso) return 'Вчера';
    return dayFmt.format(new Date(d + 'T00:00:00')).replace(' г.', '');
}
const dayGroups = computed(() => {
    const groups: { label: string; items: Line[] }[] = [];
    for (const o of rows.value) {
        const label = dayLabel(o.date);
        if (!groups.length || groups[groups.length - 1].label !== label) groups.push({ label, items: [] });
        groups[groups.length - 1].items.push(o);
    }
    return groups;
});
const opsWord = (n: number) => {
    const m10 = n % 10; const m100 = n % 100;
    if (m10 === 1 && m100 !== 11) return 'операция';
    if (m10 >= 2 && m10 <= 4 && (m100 < 12 || m100 > 14)) return 'операции';
    return 'операций';
};

const totalIn = computed(() => rows.value.filter((o) => o.amount > 0).reduce((a, o) => a + o.amount, 0));
const totalOut = computed(() => rows.value.filter((o) => o.amount < 0).reduce((a, o) => a + Math.abs(o.amount), 0));
const unmatchedCount = computed(() => props.lines.filter((o) => o.status === 'unmatched' || o.status === 'partial').length);
const unmatchedSum = computed(() => props.lines.filter((o) => o.status === 'unmatched' || o.status === 'partial').reduce((a, o) => a + Math.abs(o.amount), 0));
const statusPill = (s: string) => ({ matched: { t: 'Разнесено', v: 'ok' }, partial: { t: 'Частично', v: 'warn' }, unmatched: { t: 'Не разнесено', v: 'bad' }, ignore: { t: 'Игнор', v: 'neutral' } } as any)[s];

// ── Сверка: одна операция может закрывать несколько документов (мультивыбор) ──
const open = ref(false);
const cur = ref<Line | null>(null);
const sels = ref<MatchSel[]>([]);
const showAllCand = ref(false);
// Ближайшие по сумме к операции — сверху, чтобы не листать весь список долгов.
const candidates = computed<Doc[]>(() => {
    if (!cur.value) return [];
    const list = cur.value.amount > 0 ? props.openSales : props.openShipments;
    const amt = Math.abs(cur.value.amount);
    return [...list].sort((a, b) => Math.abs(a.debt - amt) - Math.abs(b.debt - amt));
});
const candidatesShown = computed(() => showAllCand.value ? candidates.value : candidates.value.slice(0, 5));

const lineAbs = computed(() => cur.value ? Math.abs(cur.value.amount) : 0);
const selTotal = computed(() => sels.value.reduce((a, s) => a + (Number(s.amount) || 0), 0));
const remaining = computed(() => Math.round((lineAbs.value - selTotal.value) * 100) / 100);
const overAllocated = computed(() => remaining.value < -0.01);
const isDocSel = (id: number) => sels.value.some((s) => (s.target_type === 'sale' || s.target_type === 'shipment') && s.target_id === id);

const makeRule = ref(false);
// Правило можно создать, когда выбрана статья расхода и у операции есть ИНН
const canMakeRule = computed(() => !!cur.value?.inn && sels.value[0]?.target_type === 'expense_article');

function reconcile(l: Line) {
    cur.value = l;
    showAllCand.value = false;
    makeRule.value = false;
    // Показываем текущее разнесение операции — его можно дополнить или изменить.
    sels.value = l.matches.map((m) => ({ ...m }));
    open.value = true;
}
function pickDoc(d: Doc) {
    if (!cur.value) return;
    const type = cur.value.amount > 0 ? 'sale' : 'shipment';
    const i = sels.value.findIndex((s) => s.target_type === type && s.target_id === d.id);
    if (i >= 0) {
        sels.value.splice(i, 1);   // повторный клик — убрать из выбора
        return;
    }
    // Документы и статья/перевод взаимоисключающие: выбор документа снимает статью.
    sels.value = sels.value.filter((s) => s.target_type === 'sale' || s.target_type === 'shipment');
    const amount = Math.round(Math.max(0, Math.min(remaining.value, d.debt)) * 100) / 100;
    sels.value.push({ target_type: type, target_id: d.id, amount, label: `${d.number} · ${d.party ?? ''}` });
}
// Статья расхода (для списаний) или дохода (для приходов: кэшбэк, проценты)
function pickArticle(id: number, type: 'expense_article' | 'income_article' = 'expense_article') {
    if (!cur.value) return;
    const list = type === 'income_article' ? props.incomeArticles : props.articles;
    const name = list.find((a) => a.id === id)?.name;
    const label = (type === 'income_article' ? 'Доход: ' : 'Статья: ') + (name ?? '');
    sels.value = [{ target_type: type, target_id: id, amount: lineAbs.value, label }];
}
function pickTransfer() {
    if (!cur.value) return;
    sels.value = [{ target_type: 'transfer', target_id: null, amount: lineAbs.value }];
}
function removeSel(i: number) { sels.value.splice(i, 1); }
function applyReconcile() {
    if (!cur.value || overAllocated.value) return;
    const matches = sels.value
        .filter((s) => (Number(s.amount) || 0) > 0)
        .map((s) => ({ target_type: s.target_type, target_id: s.target_id, amount: s.amount }));
    if (!matches.length) return;
    router.post(`/bank/lines/${cur.value.id}/reconcile`, { matches, make_rule: makeRule.value && canMakeRule.value }, { onSuccess: () => { open.value = false; } });
}
function ignore() {
    if (!cur.value) return;
    router.post(`/bank/lines/${cur.value.id}/ignore`, {}, { onSuccess: () => { open.value = false; } });
}
async function removeLine() {
    if (!cur.value) return;
    if (await confirmDlg('Удалить операцию? Связанные проводки тоже снимутся.')) {
        router.delete(`/bank/lines/${cur.value.id}`, { onSuccess: () => { open.value = false; } });
    }
}

// ── Импорт ──
const imp = ref(false);
const importForm = useForm<{ file: File | null; account_id: number | null }>({ file: null, account_id: props.importAccounts[0]?.id ?? null });
function onFile(e: Event) { importForm.file = (e.target as HTMLInputElement).files?.[0] ?? null; }
function doImport() { importForm.post('/bank/import', { forceFormData: true, onSuccess: () => { imp.value = false; importForm.reset(); } }); }
</script>

<template>
    <Head title="Банк" />
    <AppShell>
        <div class="toolbar">
            <h1>Банк</h1>
            <div class="tb-search">
                <Icon name="search" :size="16" class="text-ink-3" />
                <input v-model="q" placeholder="Поиск по контрагенту, назначению…" />
            </div>
            <button class="btn-primary pressable" @click="imp = true"><Icon name="plus" :size="17" /> Импорт выписки</button>
        </div>

        <div class="bank-top-row">
            <div class="bank-cards">
                <div
                    v-for="(a, i) in accounts" :key="a.id" class="bank-card"
                    :style="{ background: a.color || 'linear-gradient(135deg,#2b2b30,#4b4b52)', '--fan-r': fanRotate(i) + 'deg', '--fan-y': fanY(i) + 'px', '--fan-z': i }"
                >
                    <div class="bc-top"><span class="bc-bank">{{ a.bank || a.name }}</span><span class="bc-chip"></span></div>
                    <div class="bc-bal tnum">{{ money0(a.balance) }}</div>
                    <div class="bc-bottom">
                        <span class="bc-num">{{ a.last4 ? '•••• •••• •••• ' + a.last4 : a.type }}</span>
                        <span v-if="a.unmatched" class="bc-badge">{{ a.unmatched }} не разнесено</span>
                        <span v-else class="bc-ok">всё разнесено</span>
                    </div>
                </div>
                <div v-if="!accounts.length" class="text-ink-3" style="padding:20px">Добавьте счёт в Справочниках.</div>
            </div>

            <div v-if="accounts.length" class="bank-right">
                <div class="bank-analytics glass">
                    <div class="ba-label">Общий баланс</div>
                    <div class="ba-sum tnum">{{ money0(totalBalance) }}</div>
                    <div class="ba-delta" :style="{ color: balanceDelta >= 0 ? 'var(--income)' : 'var(--expense)' }">
                        {{ balanceDelta >= 0 ? '+' : '' }}{{ money0(balanceDelta) }} за 30 дней
                    </div>
                    <Sparkline :data="balanceSeries" :color="balanceDelta >= 0 ? 'var(--income)' : 'var(--expense)'" class="ba-spark" />
                </div>

                <!-- Инфо о неразнесённых: клик включает фильтр «Не разнесено» в ленте -->
                <component :is="unmatchedCount ? 'button' : 'div'" :type="unmatchedCount ? 'button' : undefined"
                    class="unrec-card glass" :class="{ 'unrec-card--ok': !unmatchedCount, pressable: unmatchedCount }"
                    @click="unmatchedCount && (seg = 'unmatched')">
                    <span class="unrec-ic"><Icon :name="unmatchedCount ? 'alert' : 'check'" :size="18" /></span>
                    <span class="unrec-text">
                        <template v-if="unmatchedCount">
                            <b>Не разнесено</b>
                            <i>{{ unmatchedCount }} {{ opsWord(unmatchedCount) }} · {{ money(unmatchedSum) }}</i>
                        </template>
                        <template v-else>
                            <b>Все операции разнесены</b>
                            <i>выписка сверена полностью</i>
                        </template>
                    </span>
                </component>
            </div>
        </div>

        <div class="toolbar" style="margin-top:18px">
            <h2 class="sec-h">Операции</h2>
            <div class="bank-period">
                <DatePicker v-model="dateFrom" placeholder="с" />
                <span class="text-ink-3">—</span>
                <DatePicker v-model="dateTo" placeholder="по" />
            </div>
            <div class="seg" style="margin-left:auto">
                <button :class="{ on: seg === 'all' }" @click="seg = 'all'">Все</button>
                <button :class="{ on: seg === 'unmatched' }" @click="seg = 'unmatched'">Не разнесено<span v-if="unmatchedCount" class="seg-dot">{{ unmatchedCount }}</span></button>
                <button :class="{ on: seg === 'in' }" @click="seg = 'in'">Приход</button>
                <button :class="{ on: seg === 'out' }" @click="seg = 'out'">Расход</button>
            </div>
        </div>

        <div class="op-list glass">
            <template v-for="g in dayGroups" :key="g.label">
                <div class="op-day-head">{{ g.label }}</div>
                <div v-for="o in g.items" :key="o.id" class="op-row" @click="reconcile(o)">
                    <div class="op-av" :class="o.amount > 0 ? 'op-av--in' : 'op-av--out'">{{ initials(o.party) }}</div>
                    <div class="op-main">
                        <div class="op-party">{{ o.party }}</div>
                        <div class="op-purpose">{{ o.purpose }} · {{ o.account }}</div>
                    </div>
                    <div class="op-meta">
                        <StatusPill :text="statusPill(o.status).t" :variant="statusPill(o.status).v" />
                        <span v-if="o.link" class="op-link">{{ o.link }}</span>
                    </div>
                    <div class="op-amt tnum" :style="o.amount > 0 ? { color: 'var(--income)' } : {}">{{ signed(o.amount) }}</div>
                </div>
            </template>
            <div v-if="!rows.length" class="empty-big">
                <span class="eb-ic"><Icon name="building-columns" :size="30" /></span>
                <b>Операций пока нет</b>
                <span>Импортируйте выписку из банк-клиента (формат 1С) — операции появятся здесь</span>
                <button class="btn-primary pressable" @click="imp = true"><Icon name="plus" :size="16" /> Импортировать выписку</button>
            </div>
            <div v-if="rows.length" class="op-foot">
                <span>Приход: <b :style="{ color: 'var(--income)' }">{{ money(totalIn) }}</b></span>
                <span>Расход: <b>{{ money(totalOut) }}</b></span>
            </div>
        </div>

        <!-- Сверка -->
        <AppModal :open="open" :title="cur ? signed(cur.amount) : ''" :subtitle="cur ? cur.party + ' · ' + cur.date : ''" @close="open = false">
            <template v-if="cur">
                <div class="rec-line">
                    <div class="rec-l">Назначение</div><div class="rec-v">{{ cur.purpose }}</div>
                    <div class="rec-l">Счёт</div><div class="rec-v">{{ cur.account }}</div>
                </div>
                <!-- Выбранные цели: одна операция может закрывать несколько документов -->
                <div v-if="sels.length" class="sel-list">
                    <div v-for="(s, i) in sels" :key="i" class="sel-row">
                        <span class="sel-label">{{ s.label ?? (s.target_type === 'transfer' ? 'Перевод' : s.target_type === 'acquiring' ? 'Эквайринг' : 'Документ') }}</span>
                        <input v-model.number="s.amount" type="number" step="0.01" class="sel-amt tnum" />
                        <button type="button" class="sel-del pressable" @click="removeSel(i)" title="Убрать">✕</button>
                    </div>
                    <div class="sel-total" :class="{ 'sel-total--over': overAllocated }">
                        <span>Разнесено {{ money(selTotal) }} из {{ money(lineAbs) }}</span>
                        <span v-if="overAllocated">— больше суммы операции!</span>
                        <span v-else-if="remaining > 0.01" class="text-ink-3">· останется {{ money(remaining) }}</span>
                    </div>
                </div>
                <div>
                    <span class="h2">{{ cur.amount > 0 ? 'Продажи с долгом' : 'Поставки с долгом' }}</span>
                    <div v-for="d in candidatesShown" :key="d.id" class="cand" :class="{ 'cand--best': isDocSel(d.id) }" @click="pickDoc(d)" style="cursor:pointer">
                        <div class="cand-r">
                            <div class="cand-tick" :class="{ 'cand-tick--off': !isDocSel(d.id) }">{{ isDocSel(d.id) ? '✓' : '' }}</div>
                            <div><div class="cand-doc">{{ d.number }} · {{ d.party }}</div><div class="cand-sub">долг {{ money(d.debt) }}<span v-if="d.inn && cur.inn === d.inn"> · совпадение по ИНН</span></div></div>
                            <span class="tnum">{{ money(d.debt) }}</span>
                        </div>
                    </div>
                    <div v-if="!candidates.length" class="text-ink-3" style="font-size:13px;padding:8px 0">Нет подходящих документов с долгом.</div>
                    <button v-else-if="!showAllCand && candidates.length > candidatesShown.length" type="button" class="link-btn" @click="showAllCand = true">Показать ещё {{ candidates.length - candidatesShown.length }}</button>
                </div>
                <div class="fld" v-if="cur.amount < 0">
                    <label>Или отнести на статью</label>
                    <SearchSelect
                        :modelValue="sels[0]?.target_type === 'expense_article' ? sels[0].target_id : null"
                        :options="articles"
                        placeholder="— выбрать статью —"
                        @update:modelValue="(id) => id && pickArticle(id)"
                    />
                    <label v-if="canMakeRule" class="rule-check">
                        <input v-model="makeRule" type="checkbox" />
                        <span>Всегда относить операции этого контрагента (ИНН {{ cur.inn }}) на эту статью</span>
                    </label>
                </div>
                <div class="fld" v-else>
                    <label>Или отнести на статью дохода</label>
                    <SearchSelect
                        :modelValue="sels[0]?.target_type === 'income_article' ? sels[0].target_id : null"
                        :options="incomeArticles"
                        placeholder="— кэшбэк, проценты и т.п. —"
                        @update:modelValue="(id) => id && pickArticle(id, 'income_article')"
                    />
                </div>
                <button class="link-btn" @click="pickTransfer">Это перевод между своими счетами</button>
            </template>
            <template #footer>
                <button class="btn-primary pressable" style="flex:1;justify-content:center" :disabled="!sels.length || overAllocated" @click="applyReconcile">Разнести</button>
                <button class="btn-ghost pressable" @click="ignore">Игнорировать</button>
                <button class="btn-ghost pressable" style="color:var(--expense)" @click="removeLine">Удалить</button>
            </template>
        </AppModal>

        <!-- Импорт -->
        <AppModal :open="imp" title="Импорт выписки" subtitle="Формат 1CClientBankExchange (Windows-1251)" @close="imp = false">
            <div class="fld"><label>Счёт</label>
                <select v-model="importForm.account_id"><option v-for="a in importAccounts" :key="a.id" :value="a.id">{{ a.name }}</option></select>
            </div>
            <label class="drop" style="cursor:pointer;display:block">
                <input type="file" accept=".txt,.1c" class="hidden" @change="onFile" />
                <Icon name="doc" :size="26" class="text-ink-3" />
                <div class="drop-t">{{ importForm.file ? importForm.file.name : 'Выберите файл выписки' }}</div>
                <div class="drop-s">.txt от банк-клиента</div>
            </label>
            <div v-if="importForm.progress" class="text-ink-2 text-[13px]">Загрузка… {{ importForm.progress.percentage }}%</div>
            <template #footer>
                <button class="btn-primary pressable" style="flex:1;justify-content:center" :disabled="!importForm.file || !importForm.account_id || importForm.processing" @click="doImport">Импортировать</button>
                <button class="btn-ghost pressable" @click="imp = false">Отмена</button>
            </template>
        </AppModal>
    </AppShell>
</template>

<style scoped>
.rule-check { display: flex; align-items: flex-start; gap: 8px; margin-top: 9px; font-size: 12.5px; color: var(--ink-2); cursor: pointer; }
.rule-check input { margin-top: 2px; accent-color: var(--info, #0a84ff); }
/* Мультивыбор в сверке: список выбранных целей с редактируемыми суммами */
.sel-list { display: flex; flex-direction: column; gap: 6px; padding: 10px 12px; border: 1px solid rgba(10,132,255,.3); background: rgba(10,132,255,.08); border-radius: 12px; }
.sel-row { display: flex; align-items: center; gap: 8px; }
.sel-label { flex: 1; min-width: 0; font-size: 13px; font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.sel-amt { width: 120px; height: 34px; border: 1px solid var(--glass-border); background: var(--bg); border-radius: 9px; padding: 0 10px; color: var(--ink); font-size: 13px; font-family: inherit; outline: none; text-align: right; }
.sel-del { flex-shrink: 0; width: 28px; height: 28px; border-radius: 8px; border: 1px solid var(--glass-border); background: transparent; color: var(--expense); font-size: 12px; cursor: pointer; display: flex; align-items: center; justify-content: center; }
.sel-total { display: flex; gap: 6px; flex-wrap: wrap; font-size: 12px; font-weight: 600; color: var(--ink-2); padding-top: 4px; border-top: 1px solid rgba(10,132,255,.2); }
.sel-total--over { color: var(--expense); }
.bank-period { display: flex; align-items: center; gap: 8px; }
.bank-period .dpick { width: 132px; }
.bank-period :deep(.dpick-input) { height: 38px; }
@media (max-width: 640px) {
    .bank-period { width: 100%; }
    .bank-period .dpick { flex: 1; width: auto; min-width: 0; }
}
</style>
