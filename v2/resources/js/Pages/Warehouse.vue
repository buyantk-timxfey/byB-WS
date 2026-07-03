<script setup lang="ts">
import { ref, computed } from 'vue';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import AppShell from '@/Layouts/AppShell.vue';
import Icon from '@/Components/Icon.vue';
import StatusPill from '@/Components/StatusPill.vue';
import AppModal from '@/Components/AppModal.vue';
import SearchSelect from '@/Components/SearchSelect.vue';
import { money, money0, num, date as fdate } from '@/lib/format';

type Batch = { ship: string; date: string; qty: number; cost: number; days: number; transit: boolean; transit_qty: number };
type Row = { id: number; name: string; group: string; unit: string; qty: number; transit: number; reserved: number; available: number; value: number; transit_value: number; days: number; stale: boolean; negative: boolean; batches: Batch[] };
type Good = { id: number; name: string; unit: string; qty: number };

const props = defineProps<{
    rows: Row[]; staleDays: number; frozen: number; transitMoney: number; staleMoney: number; posCount: number; negCount: number; reservedCount?: number;
    goods: Good[];
}>();

const seg = ref<'all' | 'stock' | 'stale' | 'neg'>('all');
const q = ref('');
const rows = computed(() => props.rows.filter((p) => {
    if (seg.value === 'stock' && p.qty === 0 && p.transit === 0) return false;
    if (seg.value === 'stale' && !p.stale) return false;
    if (seg.value === 'neg' && !p.negative) return false;
    if (q.value && !(`${p.name} ${p.group}`.toLowerCase().includes(q.value.toLowerCase()))) return false;
    return true;
}));

const expanded = ref<number | null>(null);
const toggle = (id: number) => { expanded.value = expanded.value === id ? null : id; };

// ── Операции склада: оприходование / списание / инвентаризация ──
const open = ref(false);
const opType = ref<'receive' | 'writeoff' | 'adjust'>('receive');
const opTabs = [
    { id: 'receive', label: 'Оприходовать', icon: 'plus' },
    { id: 'writeoff', label: 'Списать', icon: 'alert' },
    { id: 'adjust', label: 'Инвентаризация', icon: 'check' },
] as const;
const form = useForm<{ nomenclature_id: number | null; qty: number | null; unit_cost: number | null; reason: string; qty_fact: number | null }>({
    nomenclature_id: null, qty: null, unit_cost: null, reason: '', qty_fact: null,
});
const curGood = computed(() => props.goods.find((g) => g.id === form.nomenclature_id) ?? null);
const srvError = computed(() => ((usePage().props as any).errors ?? {}).warehouse ?? null);

function openOp(type: 'receive' | 'writeoff' | 'adjust', nomenclatureId: number | null = null) {
    opType.value = type;
    form.reset();
    form.clearErrors();
    form.nomenclature_id = nomenclatureId;
    open.value = true;
}
const opTitle = computed(() => ({ receive: 'Оприходовать товар', writeoff: 'Списать товар', adjust: 'Инвентаризация' }[opType.value]));
function submitOp() {
    const urls = { receive: '/warehouse/receive', writeoff: '/warehouse/writeoff', adjust: '/warehouse/adjust' };
    form.post(urls[opType.value], { preserveScroll: true, onSuccess: () => { open.value = false; } });
}
const canSubmit = computed(() => {
    if (!form.nomenclature_id) return false;
    if (opType.value === 'receive') return (form.qty ?? 0) > 0 && form.unit_cost !== null && form.unit_cost >= 0;
    if (opType.value === 'writeoff') return (form.qty ?? 0) > 0;
    return form.qty_fact !== null && form.qty_fact >= 0;
});
</script>

<template>
    <Head title="Склад" />
    <AppShell>
        <div class="toolbar">
            <h1>Склад</h1>
            <div class="tb-search">
                <Icon name="search" :size="16" class="text-ink-3" />
                <input v-model="q" placeholder="Поиск по товару, группе…" />
            </div>
            <div class="seg">
                <button :class="{ on: seg === 'all' }" @click="seg = 'all'">Все</button>
                <button :class="{ on: seg === 'stock' }" @click="seg = 'stock'">Есть остаток</button>
                <button :class="{ on: seg === 'stale' }" @click="seg = 'stale'">Залежалые</button>
                <button :class="{ on: seg === 'neg' }" @click="seg = 'neg'">Под заказ</button>
            </div>
            <button class="btn-primary pressable" @click="openOp('receive')"><Icon name="plus" :size="17" /> Операция</button>
        </div>

        <div class="fin-kpi fin-kpi--3">
            <div class="fk glass">
                <div class="fk-l">Замороженные деньги</div>
                <div class="fk-v tnum">{{ money0(frozen) }}</div>
                <div class="fk-s"><span v-if="transitMoney > 0" :style="{ color: 'var(--info)' }">ещё {{ money0(transitMoney) }} едет</span><span v-else>стоимость остатков по себестоимости</span></div>
            </div>
            <div class="fk glass">
                <div class="fk-l">Зависло (старше {{ staleDays }} дн.)</div>
                <div class="fk-v tnum" :style="staleMoney > 0 ? { color: 'var(--warn)' } : {}">{{ money0(staleMoney) }}</div>
                <div class="fk-s">залежалый товар</div>
            </div>
            <div class="fk glass">
                <div class="fk-l">Позиций на складе</div>
                <div class="fk-v tnum">{{ posCount }}</div>
                <div class="fk-s"><span v-if="negCount" :style="{ color: 'var(--expense)' }">{{ negCount }} под заказ (минус)</span><span v-else>нет отрицательных</span></div>
            </div>
        </div>

        <div class="jcard glass">
            <div class="jscroll">
                <table class="jtable jtable--exp">
                    <thead>
                        <tr>
                            <th style="width:34px"></th><th>Товар</th><th>Группа</th><th>Ед.</th>
                            <th class="num">Остаток</th><th class="num">В пути</th><th class="num">Резерв</th><th class="num">Доступно</th><th class="num">Стоимость</th><th class="num">Дней</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="p in rows" :key="p.id">
                            <tr @click="toggle(p.id)" :class="{ 'tr-open': expanded === p.id }">
                                <td class="cell-chev"><Icon name="chevron-right" :size="16" class="chev" :class="{ 'chev-open': expanded === p.id }" /></td>
                                <td>
                                    {{ p.name }}
                                    <StatusPill v-if="p.stale" text="Залежалый" variant="warn" class="ml-2" />
                                    <StatusPill v-if="p.negative" text="Под заказ" variant="bad" class="ml-2" />
                                </td>
                                <td class="text-ink-2">{{ p.group }}</td>
                                <td class="text-ink-2">{{ p.unit }}</td>
                                <td class="num" :style="p.negative ? { color: 'var(--expense)' } : {}">{{ num(p.qty) }}</td>
                                <td class="num" :style="p.transit > 0 ? { color: 'var(--info)', fontWeight: 600 } : { color: 'var(--ink-3)' }">{{ p.transit > 0 ? num(p.transit) : '—' }}</td>
                                <td class="num" :style="p.reserved > 0 ? { color: 'var(--warn)' } : { color: 'var(--ink-3)' }">{{ p.reserved > 0 ? num(p.reserved) : '—' }}</td>
                                <td class="num" :style="p.available < 0 ? { color: 'var(--expense)' } : {}">{{ num(p.available) }}</td>
                                <td class="num">{{ money(p.value) }}</td>
                                <td class="num text-ink-2">{{ p.negative ? '—' : p.days }}</td>
                            </tr>
                            <tr v-if="expanded === p.id" class="batch-tr">
                                <td></td>
                                <td colspan="9">
                                    <div v-if="p.batches.length" class="batches">
                                        <div class="batch-head">
                                            <span>Поступление</span><span>Дата прихода</span>
                                            <span class="num">Кол-во</span><span class="num">Себест. ед.</span>
                                            <span class="num">Дней</span>
                                        </div>
                                        <div v-for="(b, i) in p.batches" :key="i" class="batch-row" :class="{ stale: !b.transit && b.days >= staleDays }">
                                            <span class="bship" :title="b.ship">{{ b.ship }} <span v-if="b.transit" class="b-transit">в пути</span><span v-else-if="b.transit_qty > 0" class="b-transit">в пути {{ num(b.transit_qty) }}</span></span>
                                            <span class="text-ink-2">{{ fdate(b.date) }}</span>
                                            <span class="num">{{ num(b.qty) }}</span>
                                            <span class="num">{{ money(b.cost) }}</span>
                                            <span class="num text-ink-2">{{ b.transit ? '—' : b.days }}</span>
                                        </div>
                                    </div>
                                    <div v-else class="text-ink-3" style="padding:8px 0;font-size:13px">
                                        Партий нет — товар продан под заказ, остаток отрицательный. Закроется ближайшим приходом.
                                    </div>
                                    <div class="batch-actions">
                                        <button type="button" class="link-btn" @click.stop="openOp('writeoff', p.id)">Списать</button>
                                        <button type="button" class="link-btn" @click.stop="openOp('adjust', p.id)">Инвентаризация</button>
                                        <button type="button" class="link-btn" @click.stop="openOp('receive', p.id)">Оприходовать</button>
                                    </div>
                                </td>
                            </tr>
                        </template>
                        <tr v-if="!rows.length"><td colspan="10"><div class="j-empty">На складе пусто — оприходуйте поставку</div></td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Операция склада -->
        <AppModal :open="open" :title="opTitle" @close="open = false">
            <div class="seg" style="margin-bottom:4px">
                <button v-for="t in opTabs" :key="t.id" :class="{ on: opType === t.id }" @click="opType = t.id">{{ t.label }}</button>
            </div>

            <div class="fld"><label>Товар</label>
                <SearchSelect v-model="form.nomenclature_id" :options="goods" placeholder="— выбрать товар —" />
                <div v-if="curGood" class="text-ink-3" style="font-size:12px;margin-top:5px">Учётный остаток: {{ num(curGood.qty) }} {{ curGood.unit }}</div>
            </div>

            <template v-if="opType === 'receive'">
                <div class="fld-row">
                    <div class="fld"><label>Количество</label><input v-model.number="form.qty" type="number" step="0.001" min="0" /></div>
                    <div class="fld"><label>Себестоимость за ед., ₽</label><input v-model.number="form.unit_cost" type="number" step="0.01" min="0" /></div>
                </div>
                <div class="text-ink-3" style="font-size:12px">Приход без поставки: излишек или ввод начальных остатков. Оформится документом инвентаризации.</div>
            </template>

            <template v-else-if="opType === 'writeoff'">
                <div class="fld-row">
                    <div class="fld"><label>Количество</label><input v-model.number="form.qty" type="number" step="0.001" min="0" /></div>
                    <div class="fld"><label>Причина</label><input v-model="form.reason" placeholder="брак, порча…" /></div>
                </div>
                <div class="text-ink-3" style="font-size:12px">Себестоимость списанного уйдёт в расходы (P&L) по FIFO.</div>
            </template>

            <template v-else>
                <div class="fld"><label>Фактический остаток{{ curGood ? ` (${curGood.unit})` : '' }}</label><input v-model.number="form.qty_fact" type="number" step="0.001" min="0" /></div>
                <div class="text-ink-3" style="font-size:12px">Недостача спишется в расходы по FIFO, излишек оприходуется по последней себестоимости.</div>
            </template>

            <div v-if="srvError" class="wh-err">{{ srvError }}</div>

            <template #footer>
                <button class="btn-primary pressable" style="flex:1;justify-content:center" :disabled="!canSubmit || form.processing" @click="submitOp">Провести</button>
                <button class="btn-ghost pressable" @click="open = false">Отмена</button>
            </template>
        </AppModal>
    </AppShell>
</template>

<style scoped>
.b-transit { font-size: 10px; font-weight: 700; padding: 1px 7px; border-radius: 999px; background: rgba(10,132,255,.14); color: var(--info); margin-left: 5px; white-space: nowrap; }
.batch-actions { display: flex; gap: 16px; padding: 8px 0 2px; border-top: 1px solid var(--glass-border); margin-top: 8px; }
.wh-err { padding: 10px 14px; border-radius: 12px; background: rgba(255,69,58,.12); border: 1px solid rgba(255,69,58,.35); color: var(--expense); font-size: 13px; font-weight: 600; }
</style>
