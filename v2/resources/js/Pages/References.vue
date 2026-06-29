<script setup lang="ts">
import { ref, computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppShell from '@/Layouts/AppShell.vue';
import Icon from '@/Components/Icon.vue';
import StatusPill from '@/Components/StatusPill.vue';
import AppModal from '@/Components/AppModal.vue';

const money = (n: number) => new Intl.NumberFormat('ru-RU').format(Math.round(n)) + ' ₽';
const debtText = (n: number) => n > 0 ? '+ ' + money(n) + ' (нам)' : n < 0 ? '− ' + money(-n) + ' (мы)' : '—';

const dir = ref<'cp' | 'nom' | 'car' | 'acc' | 'art'>('cp');
const q = ref('');
const tabs = [
    { id: 'cp', label: 'Контрагенты' }, { id: 'nom', label: 'Номенклатура' },
    { id: 'car', label: 'Перевозчики' }, { id: 'acc', label: 'Счета' }, { id: 'art', label: 'Статьи затрат' },
] as const;

type CP = { id: number; type: 'Поставщик' | 'Покупатель' | 'Оба'; name: string; inn: string; contact: string; debt: number };
const cps = ref<CP[]>([
    { id: 1, type: 'Покупатель', name: 'ООО Строймонтаж', inn: '7701234567', contact: '+7 916 200-11-22', debt: 268000 },
    { id: 2, type: 'Поставщик', name: 'ИП Сидоров', inn: '7712345678', contact: 'sidorov@mail.ru', debt: -46500 },
    { id: 3, type: 'Покупатель', name: 'ИП Васильев', inn: '7723456789', contact: '+7 925 333-44-55', debt: 64000 },
    { id: 4, type: 'Поставщик', name: 'ООО Профиль', inn: '7734567890', contact: 'sales@profil.ru', debt: -214000 },
    { id: 5, type: 'Оба', name: 'ИП Кузнецов', inn: '7745678901', contact: '+7 903 111-22-33', debt: 0 },
]);

type Nom = { id: number; name: string; group: string; unit: string; art: string; qty: number };
const noms = ref<Nom[]>([
    { id: 1, name: 'Кабель-канал 40×40', group: 'Профиль', unit: 'шт', art: 'КК-4040', qty: 200 },
    { id: 2, name: 'Насос Grundfos UPS 25-40', group: 'Насосы', unit: 'шт', art: 'GF-2540', qty: 3 },
    { id: 3, name: 'Кабель ВВГ 3×2.5', group: 'Кабель', unit: 'м', art: 'VVG-325', qty: 150 },
    { id: 4, name: 'Автомат ABB SH201 C16', group: 'Автоматы', unit: 'шт', art: 'ABB-C16', qty: 40 },
    { id: 5, name: 'Светильник LED 36W', group: 'Освещение', unit: 'шт', art: 'LED-36', qty: -10 },
]);

type Car = { id: number; name: string; site: string; note: string };
const cars = ref<Car[]>([
    { id: 1, name: 'СДЭК', site: 'cdek.ru', note: 'основной по рознице' },
    { id: 2, name: 'Деловые линии', site: 'dellin.ru', note: 'крупногабарит' },
    { id: 3, name: 'ПЭК', site: 'pecom.ru', note: '' },
    { id: 4, name: 'Байкал Сервис', site: 'baikalsr.ru', note: '' },
]);

type Acc = { id: number; name: string; type: 'Банк' | 'Касса'; balance: number };
const accs = ref<Acc[]>([
    { id: 1, name: 'Сбер · 7781', type: 'Банк', balance: 1284500 },
    { id: 2, name: 'Точка · 5512', type: 'Банк', balance: 642300 },
    { id: 3, name: 'Альфа · 3390', type: 'Банк', balance: 318900 },
    { id: 4, name: 'Касса', type: 'Касса', balance: 35000 },
]);

type Art = { id: number; name: string; system: boolean };
const arts = ref<Art[]>([
    { id: 1, name: 'Аренда', system: false }, { id: 2, name: 'Связь и интернет', system: false },
    { id: 3, name: 'Транспорт / Топливо', system: false }, { id: 4, name: 'Эквайринг', system: true },
    { id: 5, name: 'Зарплата', system: true }, { id: 6, name: 'Прочие расходы', system: false },
]);

const f = (s: string) => !q.value || s.toLowerCase().includes(q.value.toLowerCase());
const cpRows = computed(() => cps.value.filter((x) => f(`${x.name} ${x.inn}`)));
const nomRows = computed(() => noms.value.filter((x) => f(`${x.name} ${x.group} ${x.art}`)));
const carRows = computed(() => cars.value.filter((x) => f(x.name)));
const accRows = computed(() => accs.value.filter((x) => f(x.name)));
const artRows = computed(() => arts.value.filter((x) => f(x.name)));

const cpVariant = (t: CP['type']) => t === 'Поставщик' ? 'info' : t === 'Покупатель' ? 'ok' : 'neutral';

const open = ref(false);
const cardCp = ref<CP | null>(null);
const cardNom = ref<Nom | null>(null);
function openCp(x: CP) { cardCp.value = x; cardNom.value = null; open.value = true; }
function openNom(x: Nom) { cardNom.value = x; cardCp.value = null; open.value = true; }
const createLabel = computed(() => ({ cp: 'контрагента', nom: 'товар', car: 'перевозчика', acc: 'счёт', art: 'статью' }[dir.value]));
</script>

<template>
    <Head title="Справочники" />
    <AppShell>
        <div class="toolbar">
            <h1>Справочники</h1>
            <div class="tb-search">
                <Icon name="search" :size="16" class="text-ink-3" />
                <input v-model="q" placeholder="Поиск…" />
            </div>
            <button class="btn-primary pressable"><Icon name="plus" :size="17" /> Добавить {{ createLabel }}</button>
        </div>

        <div class="seg ref-tabs">
            <button v-for="t in tabs" :key="t.id" :class="{ on: dir === t.id }" @click="dir = t.id; q = ''">{{ t.label }}</button>
        </div>

        <div class="jcard glass">
            <div class="jscroll">
                <!-- Контрагенты -->
                <table v-if="dir === 'cp'" class="jtable">
                    <thead><tr><th>Тип</th><th>Наименование</th><th>ИНН</th><th>Контакт</th><th class="num">Сальдо</th></tr></thead>
                    <tbody>
                        <tr v-for="x in cpRows" :key="x.id" @click="openCp(x)">
                            <td><StatusPill :text="x.type" :variant="cpVariant(x.type)" /></td>
                            <td>{{ x.name }}</td>
                            <td class="text-ink-2 tnum">{{ x.inn }}</td>
                            <td class="text-ink-2">{{ x.contact }}</td>
                            <td class="num" :style="x.debt > 0 ? { color: 'var(--income)' } : (x.debt < 0 ? { color: 'var(--expense)' } : {})">{{ debtText(x.debt) }}</td>
                        </tr>
                    </tbody>
                </table>

                <!-- Номенклатура -->
                <table v-else-if="dir === 'nom'" class="jtable">
                    <thead><tr><th>Наименование</th><th>Группа</th><th>Ед.</th><th>Артикул</th><th class="num">Остаток</th></tr></thead>
                    <tbody>
                        <tr v-for="x in nomRows" :key="x.id" @click="openNom(x)">
                            <td>{{ x.name }}</td>
                            <td class="text-ink-2">{{ x.group }}</td>
                            <td class="text-ink-2">{{ x.unit }}</td>
                            <td class="text-ink-2 tnum">{{ x.art }}</td>
                            <td class="num" :style="x.qty < 0 ? { color: 'var(--expense)' } : {}">{{ x.qty }}</td>
                        </tr>
                    </tbody>
                </table>

                <!-- Перевозчики -->
                <table v-else-if="dir === 'car'" class="jtable">
                    <thead><tr><th>Название</th><th>Сайт</th><th>Комментарий</th></tr></thead>
                    <tbody>
                        <tr v-for="x in carRows" :key="x.id">
                            <td>{{ x.name }}</td>
                            <td><a class="ref-link" :href="'https://' + x.site" target="_blank" @click.stop>{{ x.site }}</a></td>
                            <td class="text-ink-2">{{ x.note || '—' }}</td>
                        </tr>
                    </tbody>
                </table>

                <!-- Счета -->
                <table v-else-if="dir === 'acc'" class="jtable">
                    <thead><tr><th>Название</th><th>Тип</th><th class="num">Баланс</th></tr></thead>
                    <tbody>
                        <tr v-for="x in accRows" :key="x.id">
                            <td>{{ x.name }}</td>
                            <td><StatusPill :text="x.type" :variant="x.type === 'Банк' ? 'info' : 'neutral'" /></td>
                            <td class="num">{{ money(x.balance) }}</td>
                        </tr>
                        <tr><td colspan="2">Итого по счетам</td><td class="num" style="font-weight:700">{{ money(accRows.reduce((a, x) => a + x.balance, 0)) }}</td></tr>
                    </tbody>
                </table>

                <!-- Статьи затрат -->
                <table v-else class="jtable">
                    <thead><tr><th>Статья</th><th>Тип</th></tr></thead>
                    <tbody>
                        <tr v-for="x in artRows" :key="x.id">
                            <td>{{ x.name }}</td>
                            <td><StatusPill :text="x.system ? 'Системная' : 'Пользовательская'" :variant="x.system ? 'info' : 'neutral'" /></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Досье контрагента -->
        <AppModal :open="open && !!cardCp" :title="cardCp?.name || ''" :subtitle="cardCp ? cardCp.type + ' · ИНН ' + cardCp.inn : ''" @close="open = false">
            <template v-if="cardCp">
                <div class="card-kpis">
                    <div class="ck"><div class="ck-l">Сальдо</div><div class="ck-v tnum" :style="cardCp.debt > 0 ? { color: 'var(--income)' } : (cardCp.debt < 0 ? { color: 'var(--expense)' } : {})">{{ debtText(cardCp.debt) }}</div></div>
                    <div class="ck"><div class="ck-l">Контакт</div><div class="ck-v" style="font-size:13px">{{ cardCp.contact }}</div></div>
                </div>
                <div>
                    <span class="h2">История документов</span>
                    <div class="drill-row"><div><div class="dr-doc">Продажа ИН-0029</div><div class="dr-sub">22.06.2026 · оплачено</div></div><div class="dr-sum">{{ money(236000) }}</div></div>
                    <div class="drill-row"><div><div class="dr-doc">Продажа ИН-0031</div><div class="dr-sub">02.07.2026 · счёт</div></div><div class="dr-sum">{{ money(268000) }}</div></div>
                </div>
            </template>
            <template #footer>
                <button class="btn-primary pressable" style="flex:1;justify-content:center">Открыть в продажах</button>
                <button class="btn-ghost pressable">Изменить</button>
            </template>
        </AppModal>

        <!-- Карточка товара -->
        <AppModal :open="open && !!cardNom" :title="cardNom?.name || ''" :subtitle="cardNom ? cardNom.group + ' · ' + cardNom.unit + ' · ' + cardNom.art : ''" @close="open = false">
            <template v-if="cardNom">
                <div class="card-kpis">
                    <div class="ck"><div class="ck-l">Остаток</div><div class="ck-v tnum" :style="cardNom.qty < 0 ? { color: 'var(--expense)' } : {}">{{ cardNom.qty }} {{ cardNom.unit }}</div></div>
                    <div class="ck"><div class="ck-l">Ср. закупка</div><div class="ck-v tnum">{{ money(1070) }}</div></div>
                    <div class="ck"><div class="ck-l">Ср. продажа</div><div class="ck-v tnum">{{ money(1340) }}</div></div>
                    <div class="ck"><div class="ck-l">Маржа</div><div class="ck-v tnum">20,1 %</div></div>
                </div>
                <div>
                    <span class="h2">История цен</span>
                    <div class="drill-row"><div><div class="dr-doc">Закупка ИЛ-0044</div><div class="dr-sub">01.07.2026 · ООО Профиль</div></div><div class="dr-sum">{{ money(1070) }}</div></div>
                    <div class="drill-row"><div><div class="dr-doc">Продажа ИЛ-0031</div><div class="dr-sub">02.07.2026 · ООО Строймонтаж</div></div><div class="dr-sum">{{ money(1340) }}</div></div>
                </div>
            </template>
            <template #footer>
                <button class="btn-primary pressable" style="flex:1;justify-content:center">Открыть на складе</button>
                <button class="btn-ghost pressable">Изменить</button>
            </template>
        </AppModal>
    </AppShell>
</template>
