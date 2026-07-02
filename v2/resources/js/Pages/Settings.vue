<script setup lang="ts">
import { ref } from 'vue';
import { Head, useForm, router, usePage } from '@inertiajs/vue3';
import AppShell from '@/Layouts/AppShell.vue';
import Icon from '@/Components/Icon.vue';
import { applyTheme } from '@/lib/theme';

const props = defineProps<{
    settings: Record<string, any>;
    rules: any[];
    articles: { id: number; name: string }[];
    vatRates: { id: number; rate: number }[];
    devices: any[];
    lastPatch: string | null;
    currentLogin: string;
    status?: string | null;
}>();

const form = useForm({
    tax_rate: props.settings.tax_rate ?? 16,
    acquiring_card_rate: props.settings.acquiring_card_rate ?? 1.22,
    acquiring_sbp_rate: props.settings.acquiring_sbp_rate ?? 0.7,
    recon_tolerance: props.settings.recon_tolerance ?? 1.5,
    acquiring_auto: props.settings.acquiring_auto === '1' || props.settings.acquiring_auto === true,
    stale_days: props.settings.stale_days ?? 60,
    idle_lock_minutes: props.settings.idle_lock_minutes ?? 30,
    theme_mode: props.settings.theme_mode ?? 'system',
    company_name: props.settings.company_name ?? '',
    company_inn: props.settings.company_inn ?? '',
    company_ogrnip: props.settings.company_ogrnip ?? '',
    company_account: props.settings.company_account ?? '',
});
const save = () => form.put('/settings');

// ── Смена логина/пароля ──
const credForm = useForm({
    email: props.currentLogin,
    password: '',
    password_confirmation: '',
});
function saveCredentials() {
    credForm.put('/settings/credentials', {
        onSuccess: () => credForm.reset('password', 'password_confirmation'),
    });
}

const actionLabel = (a: string) => ({
    expense_article: 'Расход → статья', sale_income: 'Приход эквайринга → к продаже',
    shipment_payment: 'Оплата поставки', acquiring: 'Расход → статья «Эквайринг»', transfer: 'Перевод между счетами',
}[a] ?? a);

const ruleForm = useForm({ match_field: 'purpose', match_value: '', action_type: 'expense_article', article_id: null as number | null, priority: 100 });
const addRule = () => ruleForm.post('/settings/rules', { onSuccess: () => ruleForm.reset() });
const delRule = (id: number) => router.delete(`/settings/rules/${id}`);

// Ставки НДС (справочник для товаров в поставках)
const vatRateForm = useForm({ rate: null as number | null });
const addVatRate = () => { if (vatRateForm.rate !== null) vatRateForm.post('/settings/vat-rates', { onSuccess: () => vatRateForm.reset() }); };
const delVatRate = (id: number) => router.delete(`/settings/vat-rates/${id}`);

// Меняем сразу, не дожидаясь «Сохранить» — тема должна быть видна мгновенно по клику.
function setTheme(mode: string) {
    form.theme_mode = mode;
    applyTheme(mode);
}

function changePin() {
    const pin = window.prompt('Новый PIN (4–8 цифр):');
    if (pin && /^\d{4,8}$/.test(pin)) router.put('/pin', { pin });
    else if (pin !== null) alert('PIN должен быть 4–8 цифр');
}

// ── Деплой ZIP-патча ──
const patchForm = useForm<{ archive: File | null }>({ archive: null });
const patchName = ref('');
function onPatch(e: Event) {
    const f = (e.target as HTMLInputElement).files?.[0] ?? null;
    patchForm.archive = f;
    patchName.value = f?.name ?? '';
}
const deployResult = ref('');
function applyPatch() {
    if (!patchForm.archive) return;
    deployResult.value = '';
    patchForm.post('/deploy', {
        forceFormData: true,
        onSuccess: () => {
            patchForm.reset(); patchName.value = '';
            // lastPatch обновится через Inertia — он покажет реальный счётчик
        },
        onError: () => { deployResult.value = 'Ошибка: не удалось применить патч'; },
    });
}
</script>

<template>
    <Head title="Настройки" />
    <AppShell>
        <div class="toolbar">
            <h1>Настройки</h1>
            <button class="btn-primary pressable" style="margin-left:auto" :disabled="form.processing" @click="save">Сохранить</button>
        </div>

        <div class="set-wrap">
          <div class="set-col">
            <!-- Ставки -->
            <div class="set-card glass">
                <div class="set-h"><Icon name="chart" :size="18" /> Ставки и расчёты</div>
                <div class="set-grid">
                    <div class="fld"><label>Налог, %</label><input v-model="form.tax_rate" type="number" /></div>
                    <div class="fld"><label>Эквайринг карты, %</label><input v-model="form.acquiring_card_rate" type="number" step="0.01" /></div>
                    <div class="fld"><label>Эквайринг СБП, %</label><input v-model="form.acquiring_sbp_rate" type="number" step="0.01" /></div>
                    <div class="fld"><label>Допуск сопоставления, %</label><input v-model="form.recon_tolerance" type="number" step="0.1" /></div>
                </div>
                <div class="set-toggle">
                    <div><div class="st-t">Авто-относить комиссию эквайринга</div><div class="st-s">разницу в пределах допуска списывать на статью «Эквайринг»</div></div>
                    <button class="switch" :class="{ on: form.acquiring_auto }" @click="form.acquiring_auto = !form.acquiring_auto"><span></span></button>
                </div>
            </div>

            <!-- Склад -->
            <div class="set-card glass">
                <div class="set-h"><Icon name="warehouse" :size="18" /> Склад</div>
                <div class="set-grid"><div class="fld"><label>Порог залежалости, дней</label><input v-model="form.stale_days" type="number" /></div></div>
                <div class="set-hint">Товар на складе дольше этого срока подсвечивается как залежалый и попадает в сумму «зависших денег».</div>
            </div>

            <!-- Авто-правила -->
            <div class="set-card glass">
                <div class="set-h"><Icon name="wallet" :size="18" /> Авто-правила сверки</div>
                <div class="set-hint">Подсказки при разнесении выписки по тексту назначения или ИНН. Применяются в один клик.</div>
                <div class="rule" v-for="r in rules" :key="r.id">
                    <div class="rule-m">Если {{ r.match_field === 'inn' ? 'ИНН' : 'назначение содержит' }} «{{ r.match_value }}»</div>
                    <div class="rule-a">{{ actionLabel(r.action_type) }}{{ r.article ? ' «' + r.article.name + '»' : '' }}</div>
                    <button class="link-btn link-btn--bad" @click="delRule(r.id)">Удалить</button>
                </div>
                <div class="rule-add">
                    <select v-model="ruleForm.match_field"><option value="purpose">Назначение</option><option value="inn">ИНН</option></select>
                    <input v-model="ruleForm.match_value" placeholder="подстрока…" />
                    <select v-model="ruleForm.action_type">
                        <option value="expense_article">Статья</option><option value="acquiring">Эквайринг</option>
                        <option value="sale_income">К продаже</option><option value="transfer">Перевод</option>
                    </select>
                    <button class="btn-ghost pressable" @click="addRule">+</button>
                </div>
            </div>

            <!-- Ставки НДС -->
            <div class="set-card glass">
                <div class="set-h"><Icon name="doc" :size="18" /> Ставки НДС</div>
                <div class="set-hint">Используются при выборе НДС у товара в поставке. Цена товара уже с НДС — сумма выделяется из неё автоматически, но её можно поправить вручную.</div>
                <div class="rule" v-for="v in vatRates" :key="v.id">
                    <div class="rule-m">{{ v.rate }}%</div>
                    <button class="link-btn link-btn--bad" @click="delVatRate(v.id)">Удалить</button>
                </div>
                <div class="rule-add">
                    <input v-model.number="vatRateForm.rate" type="number" step="0.01" min="0" max="100" placeholder="ставка, %" />
                    <button class="btn-ghost pressable" @click="addVatRate">+</button>
                </div>
            </div>

          </div>
          <div class="set-col">
            <!-- Тема -->
            <div class="set-card glass">
                <div class="set-h"><Icon name="gear" :size="18" /> Тема</div>
                <div class="seg" style="width:100%">
                    <button type="button" :class="{ on: form.theme_mode === 'system' }" @click="setTheme('system')">Системная</button>
                    <button type="button" :class="{ on: form.theme_mode === 'light' }" @click="setTheme('light')">Светлая</button>
                    <button type="button" :class="{ on: form.theme_mode === 'dark' }" @click="setTheme('dark')">Тёмная</button>
                    <button type="button" :class="{ on: form.theme_mode === 'auto_time' }" @click="setTheme('auto_time')">По времени</button>
                </div>
                <div class="set-hint">«По времени» — тёмная с 20:00 до 8:00 по времени Сургута (UTC+5), не зависит от настроек компьютера или телефона. Применяется сразу, «Сохранить» — чтобы осталось и после перезахода.</div>
            </div>

            <!-- Реквизиты -->
            <div class="set-card glass">
                <div class="set-h"><Icon name="building" :size="18" /> Реквизиты компании</div>
                <div class="set-hint">Для будущих печатных форм и экспорта.</div>
                <div class="set-grid">
                    <div class="fld"><label>Наименование</label><input v-model="form.company_name" /></div>
                    <div class="fld"><label>ИНН</label><input v-model="form.company_inn" /></div>
                    <div class="fld"><label>ОГРНИП</label><input v-model="form.company_ogrnip" /></div>
                    <div class="fld"><label>Расчётный счёт</label><input v-model="form.company_account" /></div>
                </div>
            </div>

            <!-- Безопасность -->
            <div class="set-card glass">
                <div class="set-h"><Icon name="bell" :size="18" /> Безопасность</div>
                <div class="set-toggle">
                    <div><div class="st-t">PIN-код входа</div><div class="st-s">быстрый вход без пароля</div></div>
                    <button class="btn-ghost pressable" @click="changePin">Сменить PIN</button>
                </div>
                <div class="fld" style="margin-top:12px"><label>Автоблокировка после бездействия, мин</label><input v-model="form.idle_lock_minutes" type="number" min="1" /></div>
                <div class="set-hint">После этого времени без действий приложение запросит PIN — сессия не сбрасывается, только блокируется.</div>
                <div class="h2" style="margin-top:6px">Устройства (Face ID / WebAuthn)</div>
                <div class="rule" v-for="d in devices" :key="d.id">
                    <div class="rule-m">{{ d.name || 'Устройство' }}</div>
                    <div class="rule-a">активность: {{ d.last_used_at ? new Date(d.last_used_at).toLocaleDateString('ru-RU') : '—' }}</div>
                </div>
                <div v-if="!devices.length" class="set-hint">Устройства не привязаны.</div>
            </div>

            <!-- Вход в систему -->
            <div class="set-card glass">
                <div class="set-h"><Icon name="gear" :size="18" /> Вход в систему</div>
                <div class="set-hint">Логин и пароль меняются только у вас — нигде больше не сохраняются.</div>
                <div class="set-grid">
                    <div class="fld"><label>Логин</label><input v-model="credForm.email" autocomplete="username" /></div>
                    <div class="fld"><label>Новый пароль</label><input v-model="credForm.password" type="password" autocomplete="new-password" placeholder="оставьте пустым, если не меняете" /></div>
                    <div class="fld"><label>Повторите новый пароль</label><input v-model="credForm.password_confirmation" type="password" autocomplete="new-password" /></div>
                </div>
                <div v-if="credForm.errors.email" class="set-err">{{ credForm.errors.email }}</div>
                <div v-if="credForm.errors.password" class="set-err">{{ credForm.errors.password }}</div>
                <div v-if="status" class="set-ok">{{ status }}</div>
                <button class="btn-primary pressable" style="margin-top:10px" :disabled="credForm.processing" @click="saveCredentials">Сохранить</button>
            </div>

            <!-- Обновление (ZIP-патч) -->
            <div class="set-card glass">
                <div class="set-h"><Icon name="doc" :size="18" /> Обновление системы (ZIP-патч)</div>
                <div class="set-hint">Загрузите ZIP-патч сборки — система распакует его поверх приложения, применит новые миграции и сбросит кэш. Файлы <code>.env</code> и данные не трогаются.</div>
                <label class="drop" style="cursor:pointer;display:block">
                    <input type="file" accept=".zip" class="hidden" @change="onPatch" />
                    <Icon name="doc" :size="26" class="text-ink-3" />
                    <div class="drop-t">{{ patchName || 'Выберите ZIP-патч' }}</div>
                    <div class="drop-s">нажмите, чтобы выбрать файл</div>
                </label>
                <div v-if="patchForm.progress" class="set-hint">Загрузка… {{ patchForm.progress.percentage }}%</div>
                <div class="set-toggle">
                    <div>
                        <div class="st-t">Последний патч</div>
                        <div class="st-s" :style="lastPatch && lastPatch.includes('применено 0') ? 'color:var(--expense)' : ''">{{ lastPatch || 'ещё не применялся' }}</div>
                    </div>
                    <button class="btn-primary pressable" style="border-radius:12px" :disabled="!patchForm.archive || patchForm.processing" @click="applyPatch">Применить патч</button>
                </div>
            </div>
          </div>
        </div>
    </AppShell>
</template>

<style scoped>
.rule-add { display: grid; grid-template-columns: 1fr 1.4fr 1fr 40px; gap: 8px; align-items: center; padding-top: 12px; margin-top: 8px; border-top: 1px solid var(--glass-border); }
.rule-add select, .rule-add input { border: 1px solid var(--glass-border); background: var(--glass-fill); border-radius: 10px; padding: 8px 10px; color: var(--ink); font-size: 13px; font-family: inherit; outline: none; }
.rule-add .btn-ghost { padding: 8px 0; text-align: center; }
</style>
