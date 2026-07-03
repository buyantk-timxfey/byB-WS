<script setup lang="ts">
import { ref, onMounted, computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import draggable from 'vuedraggable';
import AppShell from '@/Layouts/AppShell.vue';
import Icon from '@/Components/Icon.vue';
import Sparkline from '@/Components/Sparkline.vue';
import Calendar from '@/Components/Calendar.vue';

const money = (n: number) => new Intl.NumberFormat('ru-RU').format(Math.round(n)) + ' ₽';

const props = defineProps<{
    kpis: any[]; accounts: any[]; totalBalance: number; monthIn: number; monthOut: number; unrec: { count: number; amount: number };
    shipments: any[]; warehouse: any; mailboxes: any[]; reminders: any[]; tx: any[]; calendarData?: any;
}>();

const kpis = props.kpis;
const accounts = props.accounts;
const totalBalance = props.totalBalance;
const monthIn = props.monthIn;
const monthOut = props.monthOut;
const shipments = props.shipments;
const warehouse = props.warehouse;
const mailboxes = props.mailboxes;
const reminders = props.reminders;
const tx = props.tx;

// ── Настройка виджетов: скрыть/показать + порядок (localStorage) ──
const editMode = ref(false);
const hidden = ref<string[]>([]);
const lorder = ref([{ id: 'money' }, { id: 'calendar' }, { id: 'tx' }, { id: 'warehouse' }, { id: 'mail' }, { id: 'notes' }]);

const labels: Record<string, string> = {
    signal: 'Сверка', ships: 'Поставки', money: 'Деньги', calendar: 'Календарь',
    tx: 'Операции', warehouse: 'Склад', mail: 'Почта', notes: 'Заметки',
};
const labelFor = (id: string) => id.startsWith('kpi:') ? id.slice(4) : (labels[id] ?? id);

onMounted(() => {
    try {
        const h = JSON.parse(localStorage.getItem('dash.hidden') || '[]');
        if (Array.isArray(h)) hidden.value = h;
        const o = JSON.parse(localStorage.getItem('dash.lorder') || 'null');
        if (Array.isArray(o) && o.length) lorder.value = o.map((id: string) => ({ id }));
    } catch { /* ignore */ }
});
const persist = () => {
    localStorage.setItem('dash.hidden', JSON.stringify(hidden.value));
    localStorage.setItem('dash.lorder', JSON.stringify(lorder.value.map((x) => x.id)));
};
const isVis = (id: string) => !hidden.value.includes(id);
const hide = (id: string) => { if (!hidden.value.includes(id)) hidden.value.push(id); persist(); };
const restore = (id: string) => { hidden.value = hidden.value.filter((x) => x !== id); persist(); };
const hiddenList = computed(() => hidden.value.map((id) => ({ id, label: labelFor(id) })));

const go = (url: string) => { if (!editMode.value) router.visit(url); };

// Быстрая смена статуса поставки прямо с карточки трекера
const SHIP_STATUSES = ['Ожидает отправки', 'В пути', 'Завершено'];
const shipMenuFor = ref<number | null>(null);
function toggleShipMenu(id: number, e: Event) {
    e.stopPropagation();
    shipMenuFor.value = shipMenuFor.value === id ? null : id;
}
function setShipStatus(s: any, st: string) {
    shipMenuFor.value = null;
    if (st !== s.status) router.post(`/shipments/${s.id}/status`, { status: st }, { preserveScroll: true });
}
onMounted(() => document.addEventListener('click', () => { shipMenuFor.value = null; }));

// Подпись остатка дней на карточке поставки
function daysLabel(s: { kind: string; days: number | null }): string {
    if (s.kind === 'wait') return 'ожидает отправки';
    if (s.days === null) return 'без даты прибытия';
    if (s.days < 0) return `просрочка ${-s.days} дн`;
    if (s.days === 0) return 'прибывает сегодня';
    return `ещё ${s.days} дн`;
}

// Обновление почты: используется и кнопкой в виджете, и автоматически при заходе на главную
const refreshingMail = ref(false);
async function refreshMail() {
    if (refreshingMail.value || !mailboxes.length) return;
    refreshingMail.value = true;
    for (const mb of mailboxes) {
        if (!mb.id) continue;
        await new Promise<void>((resolve) => {
            router.post(`/mail/accounts/${mb.id}/sync`, { folder: 'INBOX' }, {
                preserveScroll: true, preserveState: true,
                onFinish: () => resolve(),
            });
        });
    }
    refreshingMail.value = false;
}
onMounted(() => { refreshMail(); });
</script>

<template>
    <Head title="Главная" />

    <AppShell>
        <div :class="{ editing: editMode }">
            <!-- Панель настройки -->
            <div class="edit-bar">
                <button class="editbtn" @click="editMode = !editMode">
                    {{ editMode ? 'Готово' : 'Настроить' }}
                </button>
            </div>

            <!-- Скрытые виджеты (восстановление) -->
            <div v-if="editMode && hiddenList.length" class="hidden-sheet glass">
                <span class="text-[13px] font-semibold text-ink-2" style="width:100%">Скрытые виджеты:</span>
                <button v-for="h in hiddenList" :key="h.id" class="hidden-chip" @click="restore(h.id)">
                    <Icon name="plus" :size="14" /> {{ h.label }}
                </button>
            </div>

            <!-- Ряд 1: KPI (S) — на телефоне горизонтальная карусель, как поставки -->
            <div class="sec kpi-sec">
                <template v-for="k in kpis" :key="k.label">
                    <div v-if="isVis('kpi:' + k.label)" class="wwrap">
                        <button v-if="editMode" class="whide" @click.stop="hide('kpi:' + k.label)">×</button>
                        <div class="glass w-pad wgt-s pressable kpi-card" @click="go(k.href ?? '/finances')">
                            <div class="flex items-center justify-between">
                                <span class="h2">{{ k.label }}</span>
                                <span v-if="k.delta" class="pill" :class="{ 'pill-down': k.down }" :style="k.down ? '' : 'background:rgba(52,199,89,.16);color:var(--income)'">{{ k.delta }}</span>
                            </div>
                            <div class="kpinum tnum">{{ k.value }}</div>
                            <div v-if="k.sub" class="kpi-sub">{{ k.sub }}</div>
                            <Sparkline :data="k.spark" :color="k.color" class="kpi-spark" />
                        </div>
                    </div>
                </template>
            </div>

            <!-- Поставки: лента -->
            <div v-if="isVis('ships')" class="wwrap">
                <button v-if="editMode" class="whide" style="right:6px" @click.stop="hide('ships')">×</button>
                <div class="ships-head">
                    <div><span class="ttl">Поставки в работе</span><span class="cnt">{{ shipments.length }}</span></div>
                    <a href="/shipments">Все →</a>
                </div>
                <div class="ships-scroll">
                    <div v-for="s in shipments" :key="s.name" class="glass w-pad shipw pressable" :class="'shipw--' + s.glow" @click="go('/shipments')">
                        <div class="sw-head">
                            <div class="cp">{{ s.cp }}</div>
                            <button type="button" class="sw-status pressable" @click.stop="toggleShipMenu(s.id, $event)">
                                {{ s.status }} <Icon name="chevron-down" :size="11" />
                            </button>
                        </div>
                        <div class="nm">{{ s.name }}</div>
                        <div class="sw-days" :class="'sw-days--' + s.glow">{{ daysLabel(s) }}</div>
                        <div v-if="s.received > 0" class="sw-recv">приехало {{ s.received }} из {{ s.totalQty }}<template v-if="s.lastReceipt"> · {{ s.lastReceipt }}</template></div>
                        <div v-if="shipMenuFor === s.id" class="sw-menu" @click.stop>
                            <button v-for="st in SHIP_STATUSES" :key="st" type="button" class="sw-menu-item pressable" :class="{ on: st === s.status }" @click="setShipStatus(s, st)">{{ st }}</button>
                        </div>
                        <div class="route">
                            <span class="rt-dot"></span>
                            <div class="rt-track">
                                <!-- Перенос ETA: добавленный участок пути (старый срок → новый) пунктиром -->
                                <span v-if="s.shiftPct !== null" class="rt-dash" :style="{ left: s.shiftPct + '%', width: (100 - s.shiftPct) + '%' }"></span>
                                <div class="rt-fill" :class="'rt-fill--' + s.glow" :style="{ width: s.pct + '%' }"></div>
                                <span class="rt-truck" :class="'rt-truck--' + s.glow" :style="{ left: s.pct + '%' }"><Icon name="truck" :size="17" /></span>
                            </div>
                            <span class="rt-end" :class="{ 'rt-end--bad': s.glow === 'bad' }"></span>
                        </div>
                        <div class="sw-dates">
                            <span>{{ s.start }}</span>
                            <span>{{ s.eta }}<em v-if="s.shift > 0" class="sw-shift">+{{ s.shift }} дн</em></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Крупные виджеты (L): перетаскивание + скрытие -->
            <draggable v-model="lorder" item-key="id" :disabled="!editMode" class="sec" style="max-width:1120px" :animation="180" @end="persist">
                <template #item="{ element }">
                    <div v-show="isVis(element.id)" class="wwrap">
                        <button v-if="editMode" class="whide" @click.stop="hide(element.id)">×</button>

                        <!-- Деньги -->
                        <div v-if="element.id === 'money'" class="glass w-pad wgt-l pressable" @click="go('/bank')">
                            <span class="h2">Деньги по счетам</span>
                            <div class="flex items-center justify-between" style="margin-top:6px">
                                <span class="text-[12px] text-ink-2">Всего</span>
                                <span class="tnum" style="font-size:24px;font-weight:700">{{ money(totalBalance) }}</span>
                            </div>
                            <div class="accw">
                                <div v-for="a in accounts" :key="a.name" class="it">
                                    <div class="flex items-center gap-3" style="min-width:0">
                                        <span class="chip"><Icon name="wallet" :size="16" /></span>
                                        <span class="text-[14px] font-medium">{{ a.name }}</span>
                                    </div>
                                    <span class="text-[14px] font-semibold tnum">{{ money(a.balance) }}</span>
                                </div>
                            </div>
                            <div style="margin-top:auto;padding-top:14px;border-top:1px solid var(--glass-border)" class="flex gap-2.5">
                                <div class="flex-1"><div class="text-[11px] text-ink-3">Приход / мес</div><div class="tnum" style="font-size:15px;font-weight:700;color:var(--income)">+ {{ money(monthIn) }}</div></div>
                                <div class="flex-1"><div class="text-[11px] text-ink-3">Расход / мес</div><div class="tnum" style="font-size:15px;font-weight:700;color:var(--expense)">− {{ money(monthOut) }}</div></div>
                            </div>
                        </div>

                        <!-- Календарь -->
                        <Calendar v-else-if="element.id === 'calendar'" :data="calendarData" class="wgt-l" />

                        <!-- Операции -->
                        <div v-else-if="element.id === 'tx'" class="glass w-pad wgt-l pressable" @click="go('/bank')">
                            <span class="h2">Последние операции</span>
                            <div class="txw">
                                <div v-for="t in tx" :key="t.who + t.amount" class="t">
                                    <div class="left">
                                        <span class="av">{{ t.who.slice(0, 2).toUpperCase() }}</span>
                                        <div style="min-width:0"><div class="nm">{{ t.who }}</div><div class="cat">{{ t.cat }}</div></div>
                                    </div>
                                    <span class="text-[14px] font-semibold tnum" :style="{ color: t.kind === 'in' ? 'var(--income)' : 'var(--ink)' }">{{ t.kind === 'in' ? '+' : '' }}{{ money(t.amount) }}</span>
                                </div>
                            </div>
                        </div>

                        <!-- Склад -->
                        <div v-else-if="element.id === 'warehouse'" class="glass w-pad wgt-l pressable" @click="go('/warehouse')">
                            <span class="h2">Склад</span>
                            <div class="text-[12px] text-ink-2" style="margin-top:8px">Замороженные деньги</div>
                            <div class="tnum" style="font-size:24px;font-weight:700">{{ money(warehouse.frozen) }}</div>
                            <div class="text-[12px] text-ink-3" style="margin-top:2px">{{ warehouse.positions }} позиций на складе</div>
                            <div class="h2" style="margin:14px 0 4px">Залежалое · 30+ дней</div>
                            <div class="txw" style="flex:1;overflow-y:auto">
                                <div v-for="p in warehouse.stale" :key="p.name" class="t">
                                    <div class="left">
                                        <span class="av" :style="p.warn ? 'color:var(--warn)' : 'color:var(--ink-2)'">{{ p.days }}</span>
                                        <div style="min-width:0"><div class="nm">{{ p.name }}</div><div class="cat">дней на складе</div></div>
                                    </div>
                                    <span class="text-[14px] font-semibold tnum">{{ money(p.cost) }}</span>
                                </div>
                            </div>
                        </div>

                        <!-- Почта -->
                        <div v-else-if="element.id === 'mail'" class="glass w-pad wgt-l pressable" @click="go('/mail')">
                            <div class="flex items-center justify-between">
                                <span class="h2">Почта</span>
                                <button
                                    class="pressable flex h-[26px] w-[26px] items-center justify-center rounded-full text-ink-2 hover:text-ink"
                                    :disabled="refreshingMail"
                                    @click.stop="refreshMail"
                                >
                                    <Icon name="refresh" :size="14" :style="refreshingMail ? 'animation:spin 1s linear infinite' : ''" />
                                </button>
                            </div>
                            <div style="flex:1;overflow-y:auto;margin-top:2px">
                                <div v-for="(mb, idx) in mailboxes" :key="mb.addr" :style="idx ? 'margin-top:14px' : 'margin-top:8px'">
                                    <div class="flex items-center justify-between" style="margin-bottom:4px">
                                        <span class="text-[13px] font-semibold">{{ mb.addr }}</span>
                                        <span class="pill" style="background:rgba(10,132,255,.18);color:#0a84ff">{{ mb.unread }} нов.</span>
                                    </div>
                                    <div v-for="(lt, i) in mb.letters" :key="i" class="mletter"><b>{{ lt.from }}</b> · {{ lt.sub }}</div>
                                </div>
                            </div>
                        </div>

                        <!-- Заметки -->
                        <div v-else-if="element.id === 'notes'" class="glass w-pad wgt-l">
                            <span class="h2">Заметки и напоминания</span>
                            <div class="addnote"><span style="font-size:16px;line-height:1">+</span> Добавить заметку…</div>
                            <div class="txw" style="flex:1;overflow-y:auto">
                                <div v-for="(r, i) in reminders" :key="i" class="t">
                                    <div class="left">
                                        <span v-if="r.kind === 'auto'" class="rdot" :style="`background:${r.dot}`"></span>
                                        <span v-else class="chk"></span>
                                        <div style="min-width:0"><div class="nm">{{ r.title }}</div><div class="cat">{{ r.sub }}</div></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </template>
            </draggable>
        </div>
    </AppShell>
</template>
