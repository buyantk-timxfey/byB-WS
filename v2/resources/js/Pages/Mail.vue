<script setup lang="ts">
import { ref, computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppShell from '@/Layouts/AppShell.vue';
import Icon from '@/Components/Icon.vue';
import AppModal from '@/Components/AppModal.vue';

type Folder = { id: string; name: string; count: number };
type Account = { email: string; folders: Folder[] };
const accounts = ref<Account[]>([
    { email: 'info@bybuka.ru', folders: [{ id: 'in1', name: 'Входящие', count: 3 }, { id: 'sent1', name: 'Отправленные', count: 0 }, { id: 'draft1', name: 'Черновики', count: 1 }] },
    { email: 'zakaz@bybuka.ru', folders: [{ id: 'in2', name: 'Входящие', count: 2 }, { id: 'sent2', name: 'Отправленные', count: 0 }] },
]);
const activeFolder = ref('in1');

type Msg = { id: number; from: string; email: string; subject: string; preview: string; body: string; time: string; unread: boolean; attach: number; party: string };
const messages = ref<Msg[]>([
    { id: 1, from: 'ИП Сидоров', email: 'sidorov@mail.ru', subject: 'Отгрузка насосов Grundfos', time: '11:24', unread: true, attach: 1, party: 'ИП Сидоров',
      preview: 'Добрый день! Отгрузили сегодня, трек DL-882190…', body: 'Добрый день!\n\nОтгрузили сегодня партию насосов Grundfos UPS 25-40, 5 шт. Трек-номер Деловых линий: DL-882190. Ориентировочно у вас 10.07.\n\nСчёт во вложении.\n\nС уважением, Сидоров А.' },
    { id: 2, from: 'ООО Профиль', email: 'sales@profil.ru', subject: 'Счёт на кабель-канал №451', time: '09:50', unread: true, attach: 2, party: 'ООО Профиль',
      preview: 'Направляем счёт №451 на оплату партии…', body: 'Здравствуйте!\n\nНаправляем счёт №451 на оплату партии кабель-канала 40×40 — 200 шт. Сумма 214 000 ₽. Отгрузка в течение 3 дней после оплаты.\n\nДокументы во вложении.' },
    { id: 3, from: 'Точка Банк', email: 'noreply@tochka.com', subject: 'Выписка за июнь готова', time: 'вчера', unread: true, attach: 1, party: '',
      preview: 'Сформирована выписка по счёту 5512…', body: 'Сформирована выписка по счёту •••• 5512 за период 01.06–30.06.2026. Файл 1CClientBankExchange во вложении — можно импортировать в byBuka.' },
    { id: 4, from: 'ООО Строймонтаж', email: 'buh@strojmontazh.ru', subject: 'Оплата по ИН-0029', time: 'вчера', unread: false, attach: 0, party: 'ООО Строймонтаж',
      preview: 'Оплату произвели, проверьте поступление…', body: 'Оплату по счёту ИН-0029 на 236 000 ₽ произвели сегодня. Просьба подтвердить поступление.' },
    { id: 5, from: 'ИП Васильев', email: 'vasilev@mail.ru', subject: 'Запрос КП на автоматы ABB', time: '26 июн', unread: false, attach: 0, party: 'ИП Васильев',
      preview: 'Нужно коммерческое на 100 шт автоматов…', body: 'Здравствуйте! Нужно коммерческое предложение на 100 шт автоматов ABB SH201 C16. Сроки и цену пришлите, пожалуйста.' },
]);

const selectedId = ref(1);
const selected = computed(() => messages.value.find((m) => m.id === selectedId.value) || null);
function openMsg(m: Msg) { m.unread = false; selectedId.value = m.id; }
const initials = (s: string) => s.replace(/^(ООО|ИП|ПАО|АО)\s+/, '').trim().slice(0, 2).toUpperCase();

const compose = ref(false);
</script>

<template>
    <Head title="Почта" />
    <AppShell>
        <div class="toolbar">
            <h1>Почта</h1>
            <button class="btn-primary pressable" style="margin-left:auto" @click="compose = true"><Icon name="plus" :size="17" /> Написать</button>
        </div>

        <div class="mail-grid glass">
            <!-- Ящики -->
            <div class="mbox">
                <div v-for="a in accounts" :key="a.email" class="mbox-acc">
                    <div class="mbox-email"><Icon name="mail" :size="14" /> {{ a.email }}</div>
                    <button v-for="f in a.folders" :key="f.id" class="mbox-f" :class="{ on: activeFolder === f.id }" @click="activeFolder = f.id">
                        <span>{{ f.name }}</span>
                        <span v-if="f.count" class="mbox-c">{{ f.count }}</span>
                    </button>
                </div>
            </div>

            <!-- Список писем -->
            <div class="mlist">
                <div v-for="m in messages" :key="m.id" class="mitem" :class="{ on: selectedId === m.id, unread: m.unread }" @click="openMsg(m)">
                    <div class="mi-av">{{ initials(m.from) }}</div>
                    <div class="mi-main">
                        <div class="mi-top"><span class="mi-from">{{ m.from }}</span><span class="mi-time">{{ m.time }}</span></div>
                        <div class="mi-subj">{{ m.subject }} <Icon v-if="m.attach" name="doc" :size="12" class="mi-clip" /></div>
                        <div class="mi-prev">{{ m.preview }}</div>
                    </div>
                    <span v-if="m.unread" class="mi-dot"></span>
                </div>
            </div>

            <!-- Чтение -->
            <div class="mread" v-if="selected">
                <div class="mr-head">
                    <div class="mr-subj">{{ selected.subject }}</div>
                    <div class="mr-meta">
                        <div class="mr-av">{{ initials(selected.from) }}</div>
                        <div>
                            <div class="mr-from">{{ selected.from }} <span class="mr-email">&lt;{{ selected.email }}&gt;</span></div>
                            <div class="mr-to">кому: info@bybuka.ru · {{ selected.time }}</div>
                        </div>
                        <span v-if="selected.party" class="pill pill--info mr-link"><Icon name="building" :size="12" /> {{ selected.party }}</span>
                    </div>
                </div>
                <div class="mr-body">{{ selected.body }}</div>
                <div v-if="selected.attach" class="mr-attach">
                    <div v-for="n in selected.attach" :key="n" class="mr-file"><Icon name="doc" :size="16" /> вложение_{{ n }}.pdf</div>
                </div>
                <div class="mr-actions">
                    <button class="btn-primary pressable" @click="compose = true">Ответить</button>
                    <button class="btn-ghost pressable">Переслать</button>
                    <button class="btn-ghost pressable" v-if="selected.party">К контрагенту</button>
                </div>
            </div>
            <div class="mread mread--empty" v-else>Выберите письмо</div>
        </div>

        <!-- Написать -->
        <AppModal :open="compose" title="Новое письмо" subtitle="от info@bybuka.ru" @close="compose = false">
            <div class="fld"><label>Кому</label><input placeholder="email или выберите контрагента" /></div>
            <div class="fld"><label>Тема</label><input placeholder="Тема письма" /></div>
            <div class="fld"><label>Связать с документом (опц.)</label><input placeholder="№ поставки / продажи" /></div>
            <div class="fld"><label>Текст</label><textarea rows="6" class="mail-area" placeholder="Текст письма…"></textarea></div>
            <button class="btn-ghost pressable" style="align-self:flex-start"><Icon name="doc" :size="15" /> Прикрепить файл</button>
            <template #footer>
                <button class="btn-primary pressable" style="flex:1;justify-content:center">Отправить</button>
                <button class="btn-ghost pressable" @click="compose = false">В черновики</button>
            </template>
        </AppModal>
    </AppShell>
</template>
