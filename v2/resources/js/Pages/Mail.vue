<script setup lang="ts">
import { ref, computed, watch, onMounted, onUnmounted } from 'vue';
import { Head, Link, useForm, router, usePage } from '@inertiajs/vue3';
import AppShell from '@/Layouts/AppShell.vue';
import Icon from '@/Components/Icon.vue';
import AppModal from '@/Components/AppModal.vue';
import MailThreadItem from '@/Components/MailThreadItem.vue';
import { initials } from '@/lib/format';

const props = defineProps<{
    accounts: any[]; messages: any[]; imapAvailable: boolean;
}>();

const page = usePage();
const syncError = computed(() => (page.props.errors as any)?.imap ?? null);

const activeFolder = ref(props.accounts[0]?.folders[0]?.id ?? '');
const activeAccountId = computed(() => Number(String(activeFolder.value).split(':')[1] ?? 0));
const activeFolderName = computed(() => String(activeFolder.value).split(':')[0]);

const list = computed(() => props.messages.filter((m) =>
    m.account_id === activeAccountId.value && m.folder === activeFolderName.value));

const selectedId = ref<number | null>(props.messages[0]?.id ?? null);
const selected = computed(() => props.messages.find((m) => m.id === selectedId.value) || null);

// Очищает HTML из превью в списке — на случай если в БД уже сохранён сырой HTML
function stripHtml(text: string): string {
    if (!text || !/<[a-z]/i.test(text)) return text;
    const div = document.createElement('div');
    div.innerHTML = text;
    div.querySelectorAll('style, script, head').forEach((el) => el.remove());
    div.querySelectorAll('div, p, br, tr, td, li, h1, h2, h3, h4, h5, h6, table, blockquote').forEach((el) => {
        el.after(document.createTextNode('\n'));
    });
    return (div.textContent ?? div.innerText ?? '').replace(/\n{3,}/g, '\n\n').trim();
}

// Ветка переписки: убираем "Re:"/"Fwd:"/"Ответ:" и т.п. из темы, чтобы сгруппировать
// письма одного диалога вместе (как conversation view в Apple Mail) — включая письма
// из других папок того же аккаунта (например, наш ответ из "Отправленные").
function normalizeSubject(s: string): string {
    let t = (s || '').trim();
    let changed = true;
    while (changed) {
        changed = false;
        const m = t.match(/^(re|fw|fwd|ответ|отв|пересылка|перес)\s*:\s*/i);
        if (m) {
            t = t.slice(m[0].length).trim();
            changed = true;
        }
    }
    return t.toLowerCase();
}

const threadMessages = computed(() => {
    if (!selected.value) return [];
    const key = normalizeSubject(selected.value.subject);
    const group = key
        ? props.messages.filter((m) => m.account_id === selected.value!.account_id && normalizeSubject(m.subject) === key)
        : [selected.value];
    return [...group].sort((a, b) => new Date(a.date ?? 0).getTime() - new Date(b.date ?? 0).getTime());
});

// По умолчанию раскрыто только последнее (самое новое) письмо в ветке
const expandedIds = ref<Set<number>>(new Set());
watch(threadMessages, (msgs) => {
    const last = msgs[msgs.length - 1];
    expandedIds.value = new Set(last ? [last.id] : []);
}, { immediate: true });

function toggleThreadItem(id: number) {
    const next = new Set(expandedIds.value);
    if (next.has(id)) next.delete(id); else next.add(id);
    expandedIds.value = next;
}

function openMsg(m: any) {
    selectedId.value = m.id;
    if (m.unread) router.post(`/mail/${m.id}/read`, {}, { preserveScroll: true, preserveState: true });
}

// Написать
const compose = ref(false);
const cForm = useForm({ account_id: props.accounts[0]?.id ?? null, to: '', subject: '', body: '', doc_type: '', doc_id: null });
function send() { cForm.post('/mail/compose', { onSuccess: () => { compose.value = false; cForm.reset(); } }); }

// Синхронизация
const syncing = ref(false);
function sync() {
    if (!activeAccountId.value || syncing.value) return;
    syncing.value = true;
    router.post(`/mail/accounts/${activeAccountId.value}/sync`, { folder: activeFolderName.value }, {
        onFinish: () => { syncing.value = false; },
    });
}

// Авто-синхр при переходе в папку без писем
watch(activeFolder, () => {
    if (list.value.length === 0 && activeAccountId.value && !syncing.value) {
        sync();
    }
});

// Авто-синхр каждые 5 минут
let autoTimer: ReturnType<typeof setInterval> | null = null;
onMounted(() => {
    autoTimer = setInterval(() => {
        if (!syncing.value && activeAccountId.value) sync();
    }, 5 * 60 * 1000);
});
onUnmounted(() => { if (autoTimer !== null) { clearInterval(autoTimer); autoTimer = null; } });
</script>

<template>
    <Head title="Почта" />
    <AppShell>
        <div class="toolbar">
            <h1>Почта</h1>
            <button v-if="imapAvailable && accounts.length" class="btn-ghost pressable" style="margin-left:auto" :disabled="syncing" @click="sync">
                <Icon name="refresh" :size="16" :style="syncing ? 'animation:spin 1s linear infinite' : ''" />
            </button>
            <button class="btn-primary pressable" :style="!(imapAvailable && accounts.length) ? 'margin-left:auto' : ''" @click="compose = true" :disabled="!accounts.length"><Icon name="plus" :size="17" /> Написать</button>
        </div>

        <div v-if="syncError" class="sync-error">
            <Icon name="warning" :size="15" />
            {{ syncError }}
        </div>

        <div v-if="!accounts.length" class="jcard glass" style="padding:40px;text-align:center;color:var(--ink-3)">
            Почтовые ящики не настроены.
        </div>

        <div v-else class="mail-grid glass">
            <div class="mbox">
                <div v-for="a in accounts" :key="a.id" class="mbox-acc">
                    <div class="mbox-email"><Icon name="mail" :size="14" /> {{ a.email }}</div>
                    <button v-for="f in a.folders" :key="f.id" class="mbox-f" :class="{ on: activeFolder === f.id }" @click="activeFolder = f.id">
                        <span>{{ f.name }}</span><span v-if="f.count" class="mbox-c">{{ f.count }}</span>
                    </button>
                </div>
            </div>

            <div class="mlist">
                <div v-if="syncing && !list.length" class="j-empty" style="padding:30px;color:var(--ink-3)">
                    <Icon name="refresh" :size="18" style="animation:spin 1s linear infinite;display:block;margin:0 auto 8px" />
                    Загрузка…
                </div>
                <template v-else>
                    <div v-for="m in list" :key="m.id" class="mitem" :class="{ on: selectedId === m.id, unread: m.unread }" @click="openMsg(m)">
                        <div class="mi-av">{{ initials(m.from) }}</div>
                        <div class="mi-main">
                            <div class="mi-top"><span class="mi-from">{{ m.from }}</span><span class="mi-time">{{ m.time }}</span></div>
                            <div class="mi-subj">{{ m.subject }} <Icon v-if="m.attach" name="doc" :size="12" class="mi-clip" /></div>
                            <div class="mi-prev">{{ stripHtml(m.preview) }}</div>
                        </div>
                        <span v-if="m.unread" class="mi-dot"></span>
                    </div>
                    <div v-if="!list.length" class="j-empty" style="padding:30px">Писем нет</div>
                </template>
            </div>

            <div class="mread" v-if="selected">
                <div class="mr-head">
                    <div class="mr-subj">{{ selected.subject || '(без темы)' }}</div>
                    <span v-if="selected.party" class="pill pill--info mr-link"><Icon name="building" :size="12" /> {{ selected.party }}</span>
                </div>
                <div class="mr-thread">
                    <MailThreadItem
                        v-for="m in threadMessages"
                        :key="m.id"
                        :message="m"
                        :expanded="expandedIds.has(m.id)"
                        @toggle="toggleThreadItem(m.id)"
                    />
                </div>
                <div class="mr-actions">
                    <button class="btn-primary pressable" @click="compose = true">Ответить</button>
                    <button class="btn-ghost pressable" @click="router.delete(`/mail/${selected.id}`)">Удалить</button>
                </div>
            </div>
            <div class="mread mread--empty" v-else>Выберите письмо</div>
        </div>

        <!-- Написать -->
        <AppModal :open="compose" title="Новое письмо" @close="compose = false">
            <div class="fld"><label>От кого</label>
                <select v-model="cForm.account_id"><option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.email }}</option></select>
            </div>
            <div class="fld"><label>Кому</label><input v-model="cForm.to" placeholder="email" /></div>
            <div class="fld"><label>Тема</label><input v-model="cForm.subject" /></div>
            <div class="fld"><label>Текст</label><textarea v-model="cForm.body" rows="6" class="mail-area"></textarea></div>
            <template #footer>
                <button class="btn-primary pressable" style="flex:1;justify-content:center" :disabled="cForm.processing" @click="send">Отправить</button>
                <button class="btn-ghost pressable" @click="compose = false">Отмена</button>
            </template>
        </AppModal>

    </AppShell>
</template>

<style scoped>
.mail-area { border: 1px solid var(--glass-border); background: var(--glass-fill); border-radius: 12px; padding: 10px 12px; color: var(--ink); font-size: 14px; font-family: inherit; outline: none; resize: vertical; }
.sync-error { display: flex; align-items: center; gap: 8px; padding: 10px 14px; margin-bottom: 12px; background: color-mix(in srgb, var(--expense) 12%, transparent); border: 1px solid color-mix(in srgb, var(--expense) 30%, transparent); border-radius: 12px; color: var(--expense); font-size: 13px; }
@keyframes spin { to { transform: rotate(360deg); } }
</style>
