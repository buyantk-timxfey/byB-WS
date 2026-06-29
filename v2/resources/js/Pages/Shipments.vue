<script setup lang="ts">
import { ref, computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppShell from '@/Layouts/AppShell.vue';
import Icon from '@/Components/Icon.vue';
import StatusPill from '@/Components/StatusPill.vue';
import AppModal from '@/Components/AppModal.vue';

const money = (n: number) => new Intl.NumberFormat('ru-RU').format(n) + ' ₽';

type Item = { name: string; qty: number; price: number };
type Ship = {
    id: string; date: string; supplier: string; name: string; sum: number; paid: number;
    status: 'Ожидает отправки' | 'В пути' | 'Завершено'; eta: string; problem: boolean;
    carrier: string; tracking: string; delivery: number; items: Item[];
};

const data = ref<Ship[]>([
    { id: 'ИЛ-0044', date: '01.07.2026', supplier: 'ООО Профиль', name: 'Кабель-канал', sum: 214000, paid: 0, status: 'Ожидает отправки', eta: '12.07.2026', problem: false, carrier: 'СДЭК', tracking: '', delivery: 6000, items: [{ name: 'Кабель-канал 40×40', qty: 200, price: 1040 }] },
    { id: 'ИН-0043', date: '28.06.2026', supplier: 'ИП Сидоров', name: 'Насосы Grundfos', sum: 96500, paid: 50000, status: 'В пути', eta: '10.07.2026', problem: false, carrier: 'Деловые линии', tracking: 'DL-882190', delivery: 4500, items: [{ name: 'Насос Grundfos UPS 25-40', qty: 5, price: 18400 }] },
    { id: 'ИН-0042', date: '20.06.2026', supplier: 'ИП Сидоров', name: 'Партия кабеля', sum: 184000, paid: 184000, status: 'Завершено', eta: '02.07.2026', problem: false, carrier: 'ПЭК', tracking: 'PEK-771203', delivery: 7000, items: [{ name: 'Кабель ВВГ 3×2.5', qty: 320, price: 553 }] },
    { id: 'ИН-0041', date: '15.06.2026', supplier: 'ИП Кузнецов', name: 'Автоматы ABB', sum: 142000, paid: 142000, status: 'Завершено', eta: '25.06.2026', problem: true, carrier: 'СДЭК', tracking: 'CDEK-5510', delivery: 3200, items: [{ name: 'Автомат ABB SH201 C16', qty: 100, price: 1388 }] },
    { id: 'ИН-0040', date: '12.06.2026', supplier: 'ООО Метком', name: 'Лотки металлические', sum: 78000, paid: 0, status: 'В пути', eta: '08.07.2026', problem: false, carrier: 'Байкал Сервис', tracking: 'BS-22019', delivery: 5400, items: [{ name: 'Лоток 100×50', qty: 60, price: 1210 }] },
]);

const seg = ref<'all' | 'Ожидает отправки' | 'В пути' | 'Завершено'>('all');
const q = ref('');

const rows = computed(() => data.value.filter((s) => {
    if (seg.value !== 'all' && s.status !== seg.value) return false;
    if (q.value && !(`${s.id} ${s.supplier} ${s.name}`.toLowerCase().includes(q.value.toLowerCase()))) return false;
    return true;
}));
const totalSum = computed(() => rows.value.reduce((a, s) => a + s.sum, 0));
const totalDebt = computed(() => rows.value.reduce((a, s) => a + (s.sum - s.paid), 0));

const statusVariant = (s: Ship['status']) => s === 'Завершено' ? 'ok' : s === 'В пути' ? 'info' : 'neutral';
const payText = (s: Ship) => s.paid >= s.sum ? 'Оплачено' : s.paid > 0 ? 'Частично' : 'Не оплачено';
const payVariant = (s: Ship) => s.paid >= s.sum ? 'ok' : s.paid > 0 ? 'warn' : 'bad';

const open = ref(false);
const cur = ref<Ship | null>(null);
function openDoc(s: Ship) { cur.value = s; open.value = true; }
function create() {
    cur.value = { id: 'ИН-0045', date: '', supplier: '', name: '', sum: 0, paid: 0, status: 'Ожидает отправки', eta: '', problem: false, carrier: '', tracking: '', delivery: 0, items: [] };
    open.value = true;
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
                            <th class="num">Сумма</th><th>Оплата</th><th>Статус</th><th>ETA</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="s in rows" :key="s.id" @click="openDoc(s)">
                            <td>{{ s.id }}</td>
                            <td class="text-ink-2">{{ s.date }}</td>
                            <td>{{ s.supplier }}</td>
                            <td>
                                {{ s.name }}
                                <StatusPill v-if="s.problem" text="Проблема" variant="bad" class="ml-2" />
                            </td>
                            <td class="num">{{ money(s.sum) }}</td>
                            <td><StatusPill :text="payText(s)" :variant="payVariant(s)" /></td>
                            <td><StatusPill :text="s.status" :variant="statusVariant(s.status)" /></td>
                            <td class="text-ink-2">{{ s.eta }}</td>
                        </tr>
                        <tr v-if="!rows.length"><td colspan="8"><div class="j-empty">Ничего не найдено</div></td></tr>
                    </tbody>
                    <tfoot v-if="rows.length">
                        <tr>
                            <td colspan="4">Итого: {{ rows.length }}</td>
                            <td class="num">{{ money(totalSum) }}</td>
                            <td colspan="3">Долг поставщикам: {{ money(totalDebt) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <!-- Форма-документ -->
        <AppModal :open="open" :title="cur?.id || 'Новая поставка'" :subtitle="cur?.supplier" @close="open = false">
            <template v-if="cur">
                <div class="fld-row">
                    <div class="fld"><label>Поставщик</label><input :value="cur.supplier" placeholder="Выберите контрагента" /></div>
                    <div class="fld"><label>Статус</label>
                        <select :value="cur.status"><option>Ожидает отправки</option><option>В пути</option><option>Завершено</option></select>
                    </div>
                </div>
                <div class="fld-row">
                    <div class="fld"><label>Дата заказа</label><input :value="cur.date" placeholder="дд.мм.гггг" /></div>
                    <div class="fld"><label>ETA</label><input :value="cur.eta" placeholder="дд.мм.гггг" /></div>
                </div>
                <div class="fld-row">
                    <div class="fld"><label>Перевозчик</label><input :value="cur.carrier" /></div>
                    <div class="fld"><label>Трек-номер</label><input :value="cur.tracking" placeholder="—" /></div>
                </div>
                <div class="fld"><label>Стоимость доставки (в себестоимость)</label><input :value="cur.delivery" /></div>

                <div>
                    <div class="items-h">
                        <span class="h2">Позиции</span>
                        <button class="btn-ghost" style="padding:6px 12px;font-size:13px">+ Товар</button>
                    </div>
                    <div v-for="(it, i) in cur.items" :key="i" class="item-row">
                        <div><div class="nm">{{ it.name }}</div><div class="sub">{{ it.qty }} × {{ money(it.price) }}</div></div>
                        <div class="num text-ink-2">{{ it.qty }}</div>
                        <div class="num">{{ money(it.qty * it.price) }}</div>
                        <div class="text-ink-3" style="text-align:center">✕</div>
                    </div>
                    <div v-if="!cur.items.length" class="text-ink-3" style="padding:12px 0;font-size:14px">Добавьте товары поступления</div>
                </div>

                <div class="flex items-center justify-between" style="padding-top:6px;border-top:1px solid var(--glass-border)">
                    <span class="text-ink-2 text-[14px]">Итого (товары + доставка)</span>
                    <span class="tnum text-[18px] font-bold">{{ money(cur.sum) }}</span>
                </div>
            </template>

            <template #footer>
                <button class="btn-primary pressable" style="flex:1;justify-content:center">
                    {{ cur && cur.status === 'Ожидает отправки' ? 'Провести (В путь)' : 'Сохранить' }}
                </button>
                <button class="btn-ghost pressable">Оплатить поставщику</button>
                <button class="btn-ghost pressable">Вложения</button>
            </template>
        </AppModal>
    </AppShell>
</template>
