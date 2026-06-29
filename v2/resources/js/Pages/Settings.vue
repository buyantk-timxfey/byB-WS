<script setup lang="ts">
import { ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppShell from '@/Layouts/AppShell.vue';
import Icon from '@/Components/Icon.vue';

// Ставки и расчёты
const taxRate = ref(16);
const salaryRate = ref(20);
const cardRate = ref(1.22);
const sbpRate = ref(0.7);
const tolerance = ref(1.5);
const autoAcquiring = ref(true);

// Склад
const staleDays = ref(60);

// Авто-правила сверки (по назначению/ИНН — подсказки категоризации)
type Rule = { id: number; match: string; action: string };
const rules = ref<Rule[]>([
    { id: 1, match: 'назначение содержит «Комиссия к возм.»', action: 'Расход → статья «Эквайринг»' },
    { id: 2, match: 'назначение содержит «СБП» / «Возмещение»', action: 'Приход эквайринга → к продаже' },
    { id: 3, match: 'назначение содержит «Аренда»', action: 'Расход → статья «Аренда»' },
    { id: 4, match: 'ИНН 7708xxxxxx (Ростелеком)', action: 'Расход → статья «Связь и интернет»' },
]);

// Безопасность
const devices = ref([
    { id: 1, name: 'iPad Pro · Safari', last: 'сейчас', current: true },
    { id: 2, name: 'iPhone 16 · Face ID', last: '2 дня назад', current: false },
]);

// Обновление (ZIP-патч)
const lastPatch = 'patch-20260629-finances · 14 файлов · 1 SQL';
const lastPatchAt = '29.06.2026, 03:12';
</script>

<template>
    <Head title="Настройки" />
    <AppShell>
        <div class="toolbar">
            <h1>Настройки</h1>
            <button class="btn-primary pressable" style="margin-left:auto">Сохранить</button>
        </div>

        <div class="set-wrap">
            <!-- Ставки и расчёты -->
            <div class="set-card glass">
                <div class="set-h"><Icon name="chart" :size="18" /> Ставки и расчёты</div>
                <div class="set-grid">
                    <div class="fld"><label>Налог, %</label><input v-model="taxRate" type="number" /></div>
                    <div class="fld"><label>Зарплата (от чистой), %</label><input v-model="salaryRate" type="number" /></div>
                    <div class="fld"><label>Эквайринг карты, %</label><input v-model="cardRate" type="number" step="0.01" /></div>
                    <div class="fld"><label>Эквайринг СБП, %</label><input v-model="sbpRate" type="number" step="0.01" /></div>
                    <div class="fld"><label>Допуск сопоставления, %</label><input v-model="tolerance" type="number" step="0.1" /></div>
                </div>
                <div class="set-toggle">
                    <div><div class="st-t">Авто-относить комиссию эквайринга</div><div class="st-s">разницу в пределах допуска списывать на статью «Эквайринг»</div></div>
                    <button class="switch" :class="{ on: autoAcquiring }" @click="autoAcquiring = !autoAcquiring"><span></span></button>
                </div>
            </div>

            <!-- Склад -->
            <div class="set-card glass">
                <div class="set-h"><Icon name="warehouse" :size="18" /> Склад</div>
                <div class="set-grid">
                    <div class="fld"><label>Порог залежалости, дней</label><input v-model="staleDays" type="number" /></div>
                </div>
                <div class="set-hint">Товар на складе дольше этого срока подсвечивается как залежалый и попадает в сумму «зависших денег».</div>
            </div>

            <!-- Авто-правила сверки -->
            <div class="set-card glass">
                <div class="set-h"><Icon name="wallet" :size="18" /> Авто-правила сверки</div>
                <div class="set-hint">Подсказки при разнесении выписки по тексту назначения или ИНН. Применяются в один клик, ничего не делают молча.</div>
                <div class="rule" v-for="r in rules" :key="r.id">
                    <div class="rule-m">Если {{ r.match }}</div>
                    <div class="rule-a">{{ r.action }}</div>
                    <button class="link-btn link-btn--bad">Удалить</button>
                </div>
                <button class="btn-ghost pressable" style="margin-top:10px"><Icon name="plus" :size="15" /> Добавить правило</button>
            </div>

            <!-- Реквизиты компании -->
            <div class="set-card glass">
                <div class="set-h"><Icon name="building" :size="18" /> Реквизиты компании</div>
                <div class="set-hint">Для будущих печатных форм и экспорта.</div>
                <div class="set-grid">
                    <div class="fld"><label>Наименование</label><input value="ИП Тимофеев Т. А." /></div>
                    <div class="fld"><label>ИНН</label><input value="770912345678" /></div>
                    <div class="fld"><label>ОГРНИП</label><input value="321774600123456" /></div>
                    <div class="fld"><label>Расчётный счёт</label><input value="40802810…7781" /></div>
                </div>
            </div>

            <!-- Безопасность -->
            <div class="set-card glass">
                <div class="set-h"><Icon name="bell" :size="18" /> Безопасность</div>
                <div class="set-toggle">
                    <div><div class="st-t">PIN-код входа</div><div class="st-s">быстрый вход без пароля</div></div>
                    <button class="btn-ghost pressable">Сменить PIN</button>
                </div>
                <div class="h2" style="margin-top:6px">Устройства (Face ID / WebAuthn)</div>
                <div class="rule" v-for="d in devices" :key="d.id">
                    <div class="rule-m">{{ d.name }} <span v-if="d.current" class="pill pill--ok" style="margin-left:6px">текущее</span></div>
                    <div class="rule-a">активность: {{ d.last }}</div>
                    <button v-if="!d.current" class="link-btn link-btn--bad">Отключить</button>
                </div>
            </div>

            <!-- Обновление (ZIP-патч) -->
            <div class="set-card glass">
                <div class="set-h"><Icon name="doc" :size="18" /> Обновление системы (ZIP-патч)</div>
                <div class="set-hint">Загрузите патч сборки (.zip). Перед применением автоматически создаётся бэкап БД; SQL-миграции из патча выполняются по порядку.</div>
                <div class="drop">
                    <Icon name="doc" :size="26" class="text-ink-3" />
                    <div class="drop-t">Перетащите patch-*.zip сюда</div>
                    <div class="drop-s">или нажмите, чтобы выбрать файл</div>
                </div>
                <div class="set-toggle">
                    <div><div class="st-t">Последний патч</div><div class="st-s">{{ lastPatch }} · {{ lastPatchAt }}</div></div>
                    <button class="btn-primary pressable" style="border-radius:12px">Применить патч</button>
                </div>
            </div>
        </div>
    </AppShell>
</template>
