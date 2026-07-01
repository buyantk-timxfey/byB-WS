<script setup lang="ts">
import { ref, computed } from 'vue';
import DOMPurify from 'dompurify';
import Icon from '@/Components/Icon.vue';
import { initials } from '@/lib/format';

const props = defineProps<{ message: any; expanded: boolean }>();
const emit = defineEmits<{ (e: 'toggle'): void }>();

// Очищает HTML из тела — на случай если в БД уже сохранён сырой HTML
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

const isHtmlBody = computed(() => !!props.message?.body && /<[a-z!]/i.test(props.message.body));
const plainBody = computed(() => (isHtmlBody.value ? '' : stripHtml(props.message?.body ?? '')));

// Санитизирует HTML письма (DOMPurify) и оборачивает в полноценный документ для iframe.
const htmlDoc = computed(() => {
    if (!isHtmlBody.value) return '';
    const clean = DOMPurify.sanitize(props.message.body, {
        WHOLE_DOCUMENT: true,
        FORBID_TAGS: ['script', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'textarea', 'select', 'base', 'audio', 'video', 'meta'],
        FORBID_ATTR: ['srcdoc'],
    });
    const doc = new DOMParser().parseFromString(clean, 'text/html');
    const csp = doc.createElement('meta');
    csp.setAttribute('http-equiv', 'Content-Security-Policy');
    csp.setAttribute('content', "script-src 'none'; base-uri 'none';");
    doc.head.prepend(csp);
    const charset = doc.createElement('meta');
    charset.setAttribute('charset', 'utf-8');
    doc.head.prepend(charset);
    const base = doc.createElement('base');
    base.setAttribute('target', '_blank');
    doc.head.appendChild(base);
    const reset = doc.createElement('style');
    reset.textContent = 'html,body{margin:0;padding:10px;font-family:-apple-system,system-ui,sans-serif;font-size:14px;color:#1a1a1a;word-wrap:break-word;overflow-wrap:break-word}img{max-width:100%;height:auto}table{max-width:100%}';
    doc.head.appendChild(reset);
    return '<!DOCTYPE html>' + doc.documentElement.outerHTML;
});

const frame = ref<HTMLIFrameElement | null>(null);
function onFrameLoad() {
    try {
        const doc = frame.value?.contentDocument;
        const h = doc ? Math.max(doc.documentElement.scrollHeight, doc.body?.scrollHeight ?? 0) : 0;
        if (frame.value && h) frame.value.style.height = h + 'px';
    } catch {}
}
</script>

<template>
    <div class="thread-item" :class="{ open: expanded }">
        <button class="thread-item-head" @click="emit('toggle')">
            <div class="mi-av">{{ initials(message.from) }}</div>
            <div class="thread-item-main">
                <div class="thread-item-top">
                    <span class="mi-from">{{ message.from }}</span>
                    <span class="mi-time">{{ message.time }}</span>
                </div>
                <div v-if="!expanded" class="thread-item-prev">{{ message.preview }}</div>
            </div>
            <Icon :name="expanded ? 'chevron-up' : 'chevron-down'" :size="16" class="thread-item-chevron" />
        </button>
        <div v-if="expanded" class="thread-item-body">
            <iframe
                v-if="isHtmlBody"
                ref="frame"
                class="mr-body-frame"
                sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox"
                :srcdoc="htmlDoc"
                @load="onFrameLoad"
            ></iframe>
            <div v-else class="mr-body">{{ plainBody }}</div>
        </div>
    </div>
</template>

<style scoped>
.thread-item { border: 1px solid var(--glass-border); border-radius: 12px; margin-bottom: 10px; overflow: hidden; }
.thread-item.open { border-color: color-mix(in srgb, var(--ink) 15%, var(--glass-border)); }
.thread-item-head { display: flex; align-items: center; gap: 10px; width: 100%; padding: 10px 12px; background: transparent; border: 0; cursor: pointer; text-align: left; }
.thread-item-head:hover { background: var(--glass-fill); }
.thread-item-main { flex: 1; min-width: 0; }
.thread-item-top { display: flex; align-items: baseline; justify-content: space-between; gap: 8px; }
.thread-item-prev { font-size: 12px; color: var(--ink-3); margin-top: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.thread-item-chevron { flex-shrink: 0; color: var(--ink-3); }
.thread-item-body { padding: 0 12px 12px; }
.mr-body-frame { width: 100%; min-height: 100px; border: 0; background: #fff; border-radius: 8px; }
</style>
