<script setup lang="ts">
import { ref, computed } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import AppShell from '@/Layouts/AppShell.vue';
import Icon from '@/Components/Icon.vue';
import StatusPill from '@/Components/StatusPill.vue';
import AppModal from '@/Components/AppModal.vue';
import SearchSelect from '@/Components/SearchSelect.vue';
import DatePicker from '@/Components/DatePicker.vue';
import { money, signed, initials } from '@/lib/format';

type Line = {
    id: number; date: string; party: string; purpose: string | null; account: string; amount: number;
    status: string; inn: string | null; link: string | null;
    match: { target_type: string; target_id: number | null } | null;
};
type Doc = { id: number; number: string; party: string; sum: number; debt: number; inn: string | null };

const props = defineProps<{
    accounts: any[]; lines: Line[]; articles: { id: number; name: string }[];
    openSales: Doc[]; openShipments: Doc[]; importAccounts: { id: number; name: string }[];
}>();

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
const totalIn = computed(() => rows.value.filter((o) => o.amount > 0).reduce((a, o) => a + o.amount, 0));
const totalOut = computed(() => rows.value.filter((o) => o.amount < 0).reduce((a, o) => a + Math.abs(o.amount), 0));
const unmatchedCount = computed(() => props.lines.filter((o) => o.status === 'unmatched' || o.status === 'partial').length);
const unmatchedSum = computed(() => props.lines.filter((o) => o.status === 'unmatched' || o.status === 'partial').reduce((a, o) => a + Math.abs(o.amount), 0));
const statusPill = (s: string) => ({ matched: { t: 'Разнесено', v: 'ok' }, partial: { t: 'Частично', v: 'warn' }, unmatched: { t: 'Не разнесено', v: 'bad' }, ignore: { t: 'Игнор', v: 'neutral' } } as any)[s];

// ── Сверка ──
const open = ref(false);
const cur = ref<Line | null>(null);
const sel = ref<{ target_type: string; target_id: number | null; amount: number } | null>(null);
const showAllCand = ref(false);
// Ближайшие по сумме к операции — сверху, чтобы не листать весь список долгов.
const candidates = computed<Doc[]>(() => {
    if (!cur.value) return [];
    const list = cur.value.amount > 0 ? props.openSales : props.openShipments;
    const amt = Math.abs(cur.value.amount);
    return [...list].sort((a, b) => Math.abs(a.debt - amt) - Math.abs(b.debt - amt));
});
const candidatesShown = computed(() => showAllCand.value ? candidates.value : candidates.value.slice(0, 5));

function reconcile(l: Line) {
    cur.value = l;
    showAllCand.value = false;
    // Если операция уже разнесена (полностью или частично) — подставляем текущий выбор,
    // чтобы модалка при повторном открытии показывала, куда операция отнесена сейчас.
    sel.value = l.match ? { target_type: l.match.target_type, target_id: l.match.target_id, amount: Math.abs(l.amount) } : null;
    open.value = true;
}
function pickDoc(d: Doc) {
    if (!cur.value) return;
    sel.value = { target_type: cur.value.amount > 0 ? 'sale' : 'shipment', target_id: d.id, amount: Math.min(Math.abs(cur.value.amount), d.debt) };
}
function pickArticle(id: number) {
    if (!cur.value) return;
    sel.value = { target_type: 'expense_article', target_id: id, amount: Math.abs(cur.value.amount) };
}
function pickTransfer() {
    if (!cur.value) return;
    sel.value = { target_type: 'transfer', target_id: null, amount: Math.abs(cur.value.amount) };
}
function applyReconcile() {
    if (!cur.value || !sel.value) return;
    router.post(`/bank/lines/${cur.value.id}/reconcile`, { matches: [sel.value] }, { onSuccess: () => { open.value = false; } });
}
function ignore() {
    if (!cur.value) return;
    router.post(`/bank/lines/${cur.value.id}/ignore`, {}, { onSuccess: () => { open.value = false; } });
}
function removeLine() {
    if (!cur.value) return;
    if (confirm('Удалить операцию? Связанные проводки тоже снимутся.')) {
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

        <div class="bank-cards">
            <div v-for="a in accounts" :key="a.id" class="bank-card" :style="{ background: a.color || 'linear-gradient(135deg,#2b2b30,#4b4b52)' }">
                <div class="bc-top"><span class="bc-bank">{{ a.bank || a.name }}</span><span class="bc-chip"></span></div>
                <div class="bc-bal tnum">{{ money(a.balance) }}</div>
                <div class="bc-bottom">
                    <span class="bc-num">{{ a.last4 ? '•••• ' + a.last4 : a.type }}</span>
                    <span v-if="a.unmatched" class="bc-badge">{{ a.unmatched }} не разнесено</span>
                    <span v-else class="bc-ok">всё разнесено</span>
                </div>
            </div>
            <div v-if="!accounts.length" class="text-ink-3" style="padding:20px">Добавьте счёт в Справочниках.</div>
        </div>

        <!-- Неразнесённые строки выписки -->
        <div v-if="unmatchedCount" class="recon-banner glass">
            <span class="rb-ic"><Icon name="alert" :size="18" /></span>
            <div class="rb-text">
                <div class="rb-t">Неразнесённые строки выписки</div>
                <div class="rb-s">{{ unmatchedCount }} операций · {{ money(unmatchedSum) }} ждут сверки</div>
            </div>
            <button class="btn-primary pressable rb-btn" @click="seg = 'unmatched'">Свести</button>
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
            <div v-for="o in rows" :key="o.id" class="op-row" @click="reconcile(o)">
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
            <div v-if="!rows.length" class="j-empty">Операций нет — импортируйте выписку</div>
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
                <div v-if="cur.link" class="rec-note" style="background:rgba(10,132,255,.12);border-color:rgba(10,132,255,.3);color:var(--info,#0a84ff)">
                    Уже сопоставлено: {{ cur.link }}
                    <span v-if="(sel?.target_type === 'sale' || sel?.target_type === 'shipment') && !candidates.some((c) => c.id === sel?.target_id)"> · документ полностью закрыт, поэтому его нет в списке ниже</span>
                </div>
                <div>
                    <span class="h2">{{ cur.amount > 0 ? 'Продажи с долгом' : 'Поставки с долгом' }}</span>
                    <div v-for="d in candidatesShown" :key="d.id" class="cand" :class="{ 'cand--best': sel?.target_id === d.id && (sel?.target_type==='sale'||sel?.target_type==='shipment') }" @click="pickDoc(d)" style="cursor:pointer">
                        <div class="cand-r">
                            <div class="cand-tick" :class="{ 'cand-tick--off': !(sel?.target_id === d.id) }">{{ sel?.target_id === d.id ? '✓' : '' }}</div>
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
                        :modelValue="sel?.target_type === 'expense_article' ? sel.target_id : null"
                        :options="articles"
                        placeholder="— выбрать статью —"
                        @update:modelValue="(id) => id && pickArticle(id)"
                    />
                </div>
                <button class="link-btn" @click="pickTransfer">Это перевод между своими счетами</button>
                <div v-if="sel" class="rec-note" style="background:rgba(52,199,89,.12);border-color:rgba(52,199,89,.3);color:var(--income)">
                    Выбрано: разнести {{ money(sel.amount) }}
                </div>
            </template>
            <template #footer>
                <button class="btn-primary pressable" style="flex:1;justify-content:center" :disabled="!sel" @click="applyReconcile">Разнести</button>
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
.recon-banner { display: flex; align-items: center; gap: 14px; padding: 14px 18px; margin-top: 14px; border: 1px solid rgba(255,159,10,.35); }
.rb-ic { width: 36px; height: 36px; border-radius: 10px; display: flex; align-items: center; justify-content: center; background: rgba(255,159,10,.14); color: var(--warn); flex-shrink: 0; }
.rb-text { min-width: 0; }
.rb-t { font-size: 15px; font-weight: 600; }
.rb-s { font-size: 13px; color: var(--ink-2); margin-top: 1px; }
.rb-btn { margin-left: auto; border-radius: 12px; padding: 9px 18px; }
.bank-period { display: flex; align-items: center; gap: 8px; }
.bank-period .dpick { width: 132px; }
</style>
