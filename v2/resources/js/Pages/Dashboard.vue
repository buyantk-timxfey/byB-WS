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
    kpis: any[]; accounts: any[]; totalBalance: number; unrec: { count: number; amount: number };
    shipments: any[]; warehouse: any; mailboxes: any[]; reminders: any[]; tx: any[]; calendarData?: any;
}>();

const kpis = props.kpis;
const accounts = props.accounts;
const totalBalance = props.totalBalance;
const shipments = props.shipments;
const warehouse = props.warehouse;
const mailboxes = props.mailboxes;
const reminders = props.reminders;
const tx = props.tx;
const C = 214;

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

            <!-- Ряд 1: KPI (S) -->
            <div class="sec">
                <template v-for="k in kpis" :key="k.label">
                    <div v-if="isVis('kpi:' + k.label)" class="wwrap">
                        <button v-if="editMode" class="whide" @click.stop="hide('kpi:' + k.label)">×</button>
                        <div class="glass w-pad wgt-s pressable" @click="go('/finances')">
                            <div class="flex items-center justify-between">
                                <span class="h2">{{ k.label }}</span>
                                <span class="pill" :class="{ 'pill-down': k.down }" :style="k.down ? '' : 'background:rgba(52,199,89,.16);color:var(--income)'">{{ k.delta }}</span>
                            </div>
                            <div class="kpinum tnum">{{ k.value }}</div>
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
                    <div v-for="s in shipments" :key="s.name" class="glass w-pad wgt-s shipw pressable" @click="go('/shipments')">
                        <div class="cp">{{ s.cp }}</div>
                        <div class="nm">{{ s.name }}</div>
                        <div class="ring-s">
                            <svg viewBox="0 0 100 100" width="60" height="60">
                                <circle v-if="s.kind !== 'overdue'" cx="50" cy="50" r="34" fill="none" stroke="var(--glass-border)" stroke-width="9" />
                                <circle v-if="s.kind === 'pct'" cx="50" cy="50" r="34" fill="none" :stroke="s.color" stroke-width="9" stroke-linecap="round" :stroke-dasharray="C" :stroke-dashoffset="C * (1 - s.pct / 100)" transform="rotate(-90 50 50)" />
                                <circle v-if="s.kind === 'overdue'" cx="50" cy="50" r="34" fill="none" stroke="var(--expense)" stroke-width="9" />
                            </svg>
                            <span v-if="s.kind === 'pct'">{{ s.pct }}%</span>
                            <span v-else-if="s.kind === 'wait'" style="font-size:11px;color:var(--ink-2)">Ожидает</span>
                            <span v-else style="font-size:20px;font-weight:700;color:var(--expense)">×</span>
                        </div>
                        <div class="dts">{{ s.dates }}</div>
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
                                <div class="flex-1"><div class="text-[11px] text-ink-3">Приход / мес</div><div class="tnum" style="font-size:15px;font-weight:700;color:var(--income)">+1 284 000 ₽</div></div>
                                <div class="flex-1"><div class="text-[11px] text-ink-3">Расход / мес</div><div class="tnum" style="font-size:15px;font-weight:700;color:var(--expense)">−972 000 ₽</div></div>
                            </div>
                        </div>

                        <!-- Календарь -->
                        <Calendar v-else-if="element.id === 'calendar'" :data="calendarData" class="wgt-l overflow-hidden" />

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
                            <span class="h2">Почта</span>
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
