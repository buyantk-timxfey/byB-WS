<script setup lang="ts">
import { ref } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import AppShell from '@/Layouts/AppShell.vue';
import Icon from '@/Components/Icon.vue';

const props = defineProps<{
    settings: Record<string, any>;
    rules: any[];
    articles: { id: number; name: string }[];
    devices: any[];
    mailAccounts: any[];
    imapAvailable: boolean;
    lastPatch: string | null;
}>();

const form = useForm({
    tax_rate: props.settings.tax_rate ?? 16,
    salary_rate: props.settings.salary_rate ?? 20,
    acquiring_card_rate: props.settings.acquiring_card_rate ?? 1.22,
    acquiring_sbp_rate: props.settings.acquiring_sbp_rate ?? 0.7,
    recon_tolerance: props.settings.recon_tolerance ?? 1.5,
    acquiring_auto: props.settings.acquiring_auto === '1' || props.settings.acquiring_auto === true,
    stale_days: props.settings.stale_days ?? 60,
    company_name: props.settings.company_name ?? '',
    company_inn: props.settings.company_inn ?? '',
    company_ogrnip: props.settings.company_ogrnip ?? '',
    company_account: props.settings.company_account ?? '',
});
const save = () => form.put('/settings');

const actionLabel = (a: string) => ({
    expense_article: 'Расход → статья', sale_income: 'Приход эквайринга → к продаже',
    shipment_payment: 'Оплата поставки', acquiring: 'Расход → статья «Эквайринг»', transfer: 'Перевод между счетами',
}[a] ?? a);

const ruleForm = useForm({ match_field: 'purpose', match_value: '', action_type: 'expense_article', article_id: null as number | null, priority: 100 });
const addRule = () => ruleForm.post('/settings/rules', { onSuccess: () => ruleForm.reset() });
const delRule = (id: number) => router.delete(`/settings/rules/${id}`);

// ── Почтовые ящики ──
const mailEditId = ref<number | null>(null);
const mailForm = useForm({ email: '', password: '' });
function editMail(a: any) {
    mailEditId.value = a.id;
    mailForm.email = a.email; mailForm.password = '';
}
function resetMail() { mailEditId.value = null; mailForm.reset(); }
function saveMail() {
    const opts = { onSuccess: () => resetMail() };
    if (mailEditId.value) mailForm.put(`/mail/accounts/${mailEditId.value}`, opts);
    else mailForm.post('/mail/accounts', opts);
}
function delMail(id: number) { if (confirm('Удалить почтовый ящик?')) router.delete(`/mail/accounts/${id}`, { onSuccess: () => resetMail() }); }

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
function applyPatch() {
    if (!patchForm.archive) return;
    patchForm.post('/deploy', {
        forceFormData: true,
        onSuccess: () => { patchForm.reset(); patchName.value = ''; alert('Патч применён ✓'); window.location.reload(); },
        onError: () => alert('Ошибка применения патча'),
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
            <!-- Ставки -->
            <div class="set-card glass">
                <div class="set-h"><Icon name="chart" :size="18" /> Ставки и расчёты</div>
                <div class="set-grid">
                    <div class="fld"><label>Налог, %</label><input v-model="form.tax_rate" type="number" /></div>
                    <div class="fld"><label>Зарплата (от чистой), %</label><input v-model="form.salary_rate" type="number" /></div>
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
                <div class="h2" style="margin-top:6px">Устройства (Face ID / WebAuthn)</div>
                <div class="rule" v-for="d in devices" :key="d.id">
                    <div class="rule-m">{{ d.name || 'Устройство' }}</div>
                    <div class="rule-a">активность: {{ d.last_used_at ? new Date(d.last_used_at).toLocaleDateString('ru-RU') : '—' }}</div>
                </div>
                <div v-if="!devices.length" class="set-hint">Устройства не привязаны.</div>
            </div>

            <!-- Почта -->
            <div class="set-card glass">
                <div class="set-h"><Icon name="mail" :size="18" /> Почтовые ящики</div>
                <div class="set-hint">Введите email (он же логин) и пароль от почты. Сервер (IMAP/SMTP) подставляется автоматически по домену. Пароль хранится в зашифрованном виде.</div>
                <div v-if="!imapAvailable" class="set-hint" style="color:var(--warn)">IMAP-расширение PHP на сервере недоступно — чтение писем работать не будет, отправка по SMTP доступна.</div>

                <div class="mail-acc" v-for="a in mailAccounts" :key="a.id">
                    <div class="ma-info">
                        <div class="ma-email">{{ a.email }}</div>
                        <div class="ma-host">{{ a.imap_host || '—' }}</div>
                    </div>
                    <button class="link-btn" @click="editMail(a)">Изменить</button>
                    <button class="link-btn link-btn--bad" @click="delMail(a.id)">Удалить</button>
                </div>
                <div v-if="!mailAccounts.length" class="set-hint">Ящики не добавлены.</div>

                <div class="mail-form">
                    <div class="mf-title">{{ mailEditId ? 'Изменить ящик' : 'Добавить ящик' }}</div>
                    <div class="set-grid">
                        <div class="fld"><label>Email (логин)</label><input v-model="mailForm.email" placeholder="info@bybuka.ru" /></div>
                        <div class="fld"><label>Пароль {{ mailEditId ? '(пусто — без изменений)' : '' }}</label><input v-model="mailForm.password" type="password" /></div>
                    </div>
                    <div class="mf-actions">
                        <button class="btn-primary pressable" style="border-radius:12px" :disabled="mailForm.processing || !mailForm.email" @click="saveMail">{{ mailEditId ? 'Сохранить' : 'Добавить' }}</button>
                        <button v-if="mailEditId" class="btn-ghost pressable" @click="resetMail">Отмена</button>
                    </div>
                </div>
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
                    <div><div class="st-t">Последний патч</div><div class="st-s">{{ lastPatch || 'ещё не применялся' }}</div></div>
                    <button class="btn-primary pressable" style="border-radius:12px" :disabled="!patchForm.archive || patchForm.processing" @click="applyPatch">Применить патч</button>
                </div>
            </div>
        </div>
    </AppShell>
</template>

<style scoped>
.rule-add { display: grid; grid-template-columns: 1fr 1.4fr 1fr 40px; gap: 8px; align-items: center; padding-top: 12px; margin-top: 8px; border-top: 1px solid var(--glass-border); }
.rule-add select, .rule-add input { border: 1px solid var(--glass-border); background: var(--glass-fill); border-radius: 10px; padding: 8px 10px; color: var(--ink); font-size: 13px; font-family: inherit; outline: none; }
.rule-add .btn-ghost { padding: 8px 0; text-align: center; }
.mail-form { padding-top: 12px; margin-top: 8px; border-top: 1px solid var(--glass-border); }
.mail-form .mf-title { font-size: 13px; font-weight: 600; color: var(--ink-2); margin-bottom: 10px; }
.mail-form .mf-actions { display: flex; gap: 10px; align-items: center; margin-top: 12px; }
.mail-acc { display: flex; align-items: center; gap: 12px; padding: 11px 0; border-top: 1px solid var(--glass-border); }
.mail-acc .ma-info { flex: 1; min-width: 0; }
.mail-acc .ma-email { font-size: 13px; font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.mail-acc .ma-host { font-size: 12px; color: var(--ink-2); }
</style>
