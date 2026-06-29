<script setup lang="ts">
import { ref, computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppShell from '@/Layouts/AppShell.vue';
import Icon from '@/Components/Icon.vue';
import StatusPill from '@/Components/StatusPill.vue';
import AppModal from '@/Components/AppModal.vue';

const money = (n: number) => new Intl.NumberFormat('ru-RU').format(Math.round(n)) + ' ₽';
const signed = (n: number) => (n > 0 ? '+ ' : '− ') + new Intl.NumberFormat('ru-RU').format(Math.abs(Math.round(n))) + ' ₽';

type Acc = { id: string; bank: string; last4: string; balance: number; unmatched: number; grad: string };
const accounts = ref<Acc[]>([
    { id: 'sber', bank: 'Сбербанк', last4: '7781', balance: 1284500, unmatched: 2, grad: 'linear-gradient(135deg,#1f9d57,#0c6b3a)' },
    { id: 'tochka', bank: 'Точка', last4: '5512', balance: 642300, unmatched: 1, grad: 'linear-gradient(135deg,#5b6cff,#8a3ffc)' },
    { id: 'alfa', bank: 'Альфа-Банк', last4: '3390', balance: 318900, unmatched: 0, grad: 'linear-gradient(135deg,#2b2b30,#e0203a)' },
]);

type Op = {
    id: number; date: string; party: string; purpose: string; acc: string; amount: number;
    status: 'matched' | 'unmatched' | 'partial' | 'ignore'; link: string; acquiring: boolean;
};
const ops = ref<Op[]>([
    { id: 1, date: '27.06.2026', party: 'ООО Строймонтаж', purpose: 'Оплата по счёту ИН-0029', acc: 'Сбер · 7781', amount: 236000, status: 'matched', link: 'Продажа ИН-0029', acquiring: false },
    { id: 2, date: '27.06.2026', party: 'ИП Васильев', purpose: 'Аванс по договору', acc: 'Точка · 5512', amount: 60000, status: 'partial', link: 'Продажа ИН-0030', acquiring: false },
    { id: 3, date: '26.06.2026', party: 'Эквайринг (Точка)', purpose: 'Зачисление по картам 24.06', acc: 'Точка · 5512', amount: 97797, status: 'unmatched', link: '', acquiring: true },
    { id: 4, date: '24.06.2026', party: 'ИП Сидоров', purpose: 'Оплата поставки ИН-0042', acc: 'Сбер · 7781', amount: -184000, status: 'matched', link: 'Поставка ИН-0042', acquiring: false },
    { id: 5, date: '22.06.2026', party: 'Между своими счетами', purpose: 'Перевод Сбер → Точка', acc: 'Сбер · 7781', amount: -200000, status: 'matched', link: 'Перевод', acquiring: false },
    { id: 6, date: '20.06.2026', party: 'ООО Деловые Сети', purpose: 'Аренда офиса, июнь', acc: 'Альфа · 3390', amount: -45000, status: 'matched', link: 'Статья: Аренда', acquiring: false },
    { id: 7, date: '19.06.2026', party: 'ПАО Ростелеком', purpose: 'Услуги связи', acc: 'Сбер · 7781', amount: -8400, status: 'unmatched', link: '', acquiring: false },
]);

const seg = ref<'all' | 'unmatched' | 'in' | 'out'>('all');
const q = ref('');
const rows = computed(() => ops.value.filter((o) => {
    if (seg.value === 'unmatched' && (o.status === 'matched' || o.status === 'ignore')) return false;
    if (seg.value === 'in' && o.amount < 0) return false;
    if (seg.value === 'out' && o.amount > 0) return false;
    if (q.value && !(`${o.party} ${o.purpose}`.toLowerCase().includes(q.value.toLowerCase()))) return false;
    return true;
}));

const totalIn = computed(() => rows.value.filter((o) => o.amount > 0).reduce((a, o) => a + o.amount, 0));
const totalOut = computed(() => rows.value.filter((o) => o.amount < 0).reduce((a, o) => a + Math.abs(o.amount), 0));
const unmatchedCount = computed(() => ops.value.filter((o) => o.status === 'unmatched' || o.status === 'partial').length);

const statusPill = (s: Op['status']) => ({
    matched: { t: 'Разнесено', v: 'ok' }, partial: { t: 'Частично', v: 'warn' },
    unmatched: { t: 'Не разнесено', v: 'bad' }, ignore: { t: 'Игнор', v: 'neutral' },
} as const)[s];

const initials = (s: string) => s.replace(/^(ООО|ИП|ПАО|АО)\s+/, '').trim().slice(0, 2).toUpperCase();

const open = ref(false);
const cur = ref<Op | null>(null);
function reconcile(o: Op) { cur.value = o; open.value = true; }

const imp = ref(false);
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

        <!-- Счета — премиальные карточки -->
        <div class="bank-cards">
            <div v-for="a in accounts" :key="a.id" class="bank-card" :style="{ background: a.grad }">
                <div class="bc-top">
                    <span class="bc-bank">{{ a.bank }}</span>
                    <span class="bc-chip"></span>
                </div>
                <div class="bc-bal tnum">{{ money(a.balance) }}</div>
                <div class="bc-bottom">
                    <span class="bc-num">•••• {{ a.last4 }}</span>
                    <span v-if="a.unmatched" class="bc-badge">{{ a.unmatched }} не разнесено</span>
                    <span v-else class="bc-ok">всё разнесено</span>
                </div>
            </div>
        </div>

        <!-- Лента операций -->
        <div class="toolbar" style="margin-top:18px">
            <h2 class="sec-h">Операции</h2>
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
                    <div class="op-purpose">{{ o.purpose }} · {{ o.acc }}</div>
                </div>
                <div class="op-meta">
                    <StatusPill :text="statusPill(o.status).t" :variant="statusPill(o.status).v" />
                    <span v-if="o.link" class="op-link">{{ o.link }}</span>
                </div>
                <div class="op-amt tnum" :style="o.amount > 0 ? { color: 'var(--income)' } : {}">{{ signed(o.amount) }}</div>
            </div>
            <div v-if="!rows.length" class="j-empty">Ничего не найдено</div>
            <div v-if="rows.length" class="op-foot">
                <span>Приход: <b :style="{ color: 'var(--income)' }">{{ money(totalIn) }}</b></span>
                <span>Расход: <b>{{ money(totalOut) }}</b></span>
            </div>
        </div>

        <!-- Экран сверки -->
        <AppModal :open="open" :title="cur ? signed(cur.amount) : ''" :subtitle="cur ? cur.party + ' · ' + cur.date : ''" @close="open = false">
            <template v-if="cur">
                <div class="rec-line">
                    <div class="rec-l">Назначение</div><div class="rec-v">{{ cur.purpose }}</div>
                    <div class="rec-l">Счёт</div><div class="rec-v">{{ cur.acc }}</div>
                    <div class="rec-l">Статус</div><div class="rec-v"><StatusPill :text="statusPill(cur.status).t" :variant="statusPill(cur.status).v" /></div>
                </div>

                <div v-if="cur.acquiring" class="rec-note">
                    <Icon name="alert" :size="16" />
                    <span>Эквайринг: банк зачислил за вычетом комиссии ~1,22%. Разница в пределах допуска уйдёт на статью «Эквайринг» автоматически при сопоставлении.</span>
                </div>

                <div>
                    <span class="h2">Кандидаты-документы</span>
                    <div class="cand cand--best">
                        <div class="cand-r">
                            <div class="cand-tick">✓</div>
                            <div><div class="cand-doc">{{ cur.amount > 0 ? 'Продажа ИН-0031' : 'Поставка ИН-0044' }}</div><div class="cand-sub">{{ cur.amount > 0 ? 'ООО Строймонтаж' : 'ООО Профиль' }} · совпадение по ИНН + сумме</div></div>
                            <button class="btn-primary pressable" style="padding:7px 14px">Привязать</button>
                        </div>
                    </div>
                    <div class="cand">
                        <div class="cand-r">
                            <div class="cand-tick cand-tick--off"></div>
                            <div><div class="cand-doc">{{ cur.amount > 0 ? 'Продажа ИН-0028' : 'Поставка ИН-0041' }}</div><div class="cand-sub">другой контрагент · сумма близка</div></div>
                            <button class="btn-ghost pressable" style="padding:7px 14px">Привязать</button>
                        </div>
                    </div>
                </div>

                <div class="fld">
                    <label>Или отнести на статью</label>
                    <select><option>— выбрать статью —</option><option>Аренда</option><option>Связь и интернет</option><option>Эквайринг</option><option>Прочие расходы</option><option>Перевод между счетами</option></select>
                </div>
            </template>
            <template #footer>
                <button class="btn-primary pressable" style="flex:1;justify-content:center">Разнести</button>
                <button class="btn-ghost pressable">Игнорировать</button>
            </template>
        </AppModal>

        <!-- Импорт выписки -->
        <AppModal :open="imp" title="Импорт выписки" subtitle="Формат 1CClientBankExchange (Windows-1251)" @close="imp = false">
            <div class="drop">
                <Icon name="wallet" :size="28" class="text-ink-3" />
                <div class="drop-t">Перетащите файл выписки сюда</div>
                <div class="drop-s">или нажмите, чтобы выбрать .txt от банк-клиента</div>
            </div>
            <div>
                <span class="h2">Превью батча</span>
                <div class="rec-line">
                    <div class="rec-l">Период</div><div class="rec-v">01.06.2026 — 27.06.2026</div>
                    <div class="rec-l">Счёт</div><div class="rec-v">Сбер · 7781</div>
                    <div class="rec-l">Строк</div><div class="rec-v">42 <span class="text-ink-3">(3 дубликата пропущены)</span></div>
                    <div class="rec-l">Приход</div><div class="rec-v" :style="{ color: 'var(--income)' }">{{ money(1340000) }}</div>
                    <div class="rec-l">Расход</div><div class="rec-v">{{ money(870500) }}</div>
                    <div class="rec-l">Баланс</div><div class="rec-v">{{ money(815000) }} → {{ money(1284500) }}</div>
                </div>
            </div>
            <template #footer>
                <button class="btn-primary pressable" style="flex:1;justify-content:center">Применить батч</button>
                <button class="btn-ghost pressable" @click="imp = false">Отмена</button>
            </template>
        </AppModal>
    </AppShell>
</template>
