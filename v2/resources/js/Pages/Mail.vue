<script setup lang="ts">
import { ref, computed } from 'vue';
import { Head, Link, useForm, router, usePage } from '@inertiajs/vue3';
import AppShell from '@/Layouts/AppShell.vue';
import Icon from '@/Components/Icon.vue';
import AppModal from '@/Components/AppModal.vue';
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
function openMsg(m: any) {
    selectedId.value = m.id;
    if (m.unread) router.post(`/mail/${m.id}/read`, {}, { preserveScroll: true, preserveState: true });
}

// Написать
const compose = ref(false);
const cForm = useForm({ account_id: props.accounts[0]?.id ?? null, to: '', subject: '', body: '', doc_type: '', doc_id: null });
function send() { cForm.post('/mail/compose', { onSuccess: () => { compose.value = false; cForm.reset(); } }); }

const syncing = ref(false);
function sync() {
    if (!activeAccountId.value || syncing.value) return;
    syncing.value = true;
    router.post(`/mail/accounts/${activeAccountId.value}/sync`, {}, {
        onFinish: () => { syncing.value = false; },
    });
}
</script>

<template>
    <Head title="Почта" />
    <AppShell>
        <div class="toolbar">
            <h1>Почта</h1>
            <button v-if="imapAvailable && accounts.length" class="btn-ghost pressable" style="margin-left:auto" :disabled="syncing" @click="sync">
                <Icon name="refresh" :size="15" :style="syncing ? 'animation:spin 1s linear infinite' : ''" />
                {{ syncing ? 'Синхронизация…' : 'Синхр.' }}
            </button>
            <button class="btn-primary pressable" :style="!(imapAvailable && accounts.length) ? 'margin-left:auto' : ''" @click="compose = true" :disabled="!accounts.length"><Icon name="plus" :size="17" /> Написать</button>
        </div>

        <div v-if="syncError" class="sync-error">
            <Icon name="warning" :size="15" />
            {{ syncError }}
        </div>

        <div v-if="!accounts.length" class="jcard glass" style="padding:40px;text-align:center;color:var(--ink-3)">
            Почтовые ящики не настроены. Добавьте IMAP/SMTP-аккаунт в разделе
            <Link href="/settings" class="link-btn">Настройки → Почтовые ящики</Link>.
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
                <div v-for="m in list" :key="m.id" class="mitem" :class="{ on: selectedId === m.id, unread: m.unread }" @click="openMsg(m)">
                    <div class="mi-av">{{ initials(m.from) }}</div>
                    <div class="mi-main">
                        <div class="mi-top"><span class="mi-from">{{ m.from }}</span><span class="mi-time">{{ m.time }}</span></div>
                        <div class="mi-subj">{{ m.subject }} <Icon v-if="m.attach" name="doc" :size="12" class="mi-clip" /></div>
                        <div class="mi-prev">{{ m.preview }}</div>
                    </div>
                    <span v-if="m.unread" class="mi-dot"></span>
                </div>
                <div v-if="!list.length" class="j-empty" style="padding:30px">Писем нет</div>
            </div>

            <div class="mread" v-if="selected">
                <div class="mr-head">
                    <div class="mr-subj">{{ selected.subject || '(без темы)' }}</div>
                    <div class="mr-meta">
                        <div class="mr-av">{{ initials(selected.from) }}</div>
                        <div>
                            <div class="mr-from">{{ selected.from }} <span class="mr-email">&lt;{{ selected.email }}&gt;</span></div>
                            <div class="mr-to">{{ selected.time }}</div>
                        </div>
                        <span v-if="selected.party" class="pill pill--info mr-link"><Icon name="building" :size="12" /> {{ selected.party }}</span>
                    </div>
                </div>
                <div class="mr-body">{{ selected.body }}</div>
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
