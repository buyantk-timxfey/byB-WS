<script setup lang="ts">
import { ref, watch, onMounted, nextTick } from 'vue';
import { Head, useForm, Link } from '@inertiajs/vue3';

defineProps<{ locked?: boolean }>();

const form = useForm({ pin: '' });
const dots = ref('');
const inputEl = ref<HTMLInputElement | null>(null);

// Только физическая клавиатура — inputmode="none" не даёт мобильным браузерам
// показать экранную клавиатуру над этим полем, но набор с настоящей клавиатуры
// (в т.ч. Bluetooth) работает как обычно.
function onInput(e: Event) {
    const el = e.target as HTMLInputElement;
    const digits = el.value.replace(/\D/g, '').slice(0, 8);
    el.value = digits;
    dots.value = digits;
    if (digits.length >= 4) maybeSubmit();
}
function maybeSubmit() {
    form.pin = dots.value;
    form.post('/pin', {
        onError: () => { dots.value = ''; if (inputEl.value) inputEl.value.value = ''; },
        onFinish: () => { form.reset('pin'); },
    });
}
watch(() => form.errors.pin, () => { dots.value = ''; if (inputEl.value) inputEl.value.value = ''; });
onMounted(() => nextTick(() => inputEl.value?.focus()));
function focusInput() { inputEl.value?.focus(); }
</script>

<template>
    <Head title="Вход по PIN" />
    <div class="pin-wrap">
        <div class="orbs" aria-hidden="true"><i class="orb-a"></i><i class="orb-b"></i><i class="orb-c"></i><i class="orb-d"></i></div>
        <div class="pin-card glass-strong" @click="focusInput">
            <div class="pin-logo">
                <picture>
                    <source srcset="/images/logo-white.png" media="(prefers-color-scheme: dark)" />
                    <img src="/images/logo-black.png" alt="byBuka" />
                </picture>
            </div>
            <div class="pin-title">byBuka</div>
            <div class="pin-sub">{{ locked ? 'Сессия заблокирована из-за бездействия' : 'Введите PIN' }}</div>

            <div class="pin-dots">
                <span v-for="i in 8" :key="i" class="pin-dot" :class="{ filled: i <= dots.length }" v-show="i <= Math.max(4, dots.length)"></span>
            </div>
            <div v-if="form.errors.pin" class="pin-err">{{ form.errors.pin }}</div>
            <div class="pin-hint">Введите PIN с клавиатуры</div>

            <input
                ref="inputEl"
                class="pin-input"
                type="password"
                inputmode="none"
                autocomplete="off"
                maxlength="8"
                @input="onInput"
                @keydown.enter.prevent="maybeSubmit"
            />

            <Link v-if="locked" href="/logout" method="post" as="button" class="pin-alt">Выйти и войти паролем</Link>
            <Link v-else href="/login" class="pin-alt">Войти по паролю</Link>
        </div>
    </div>
</template>

<style scoped>
.pin-wrap { min-height: 100dvh; display: flex; align-items: center; justify-content: center; padding: 20px; }
.pin-card { position: relative; width: min(360px, 100%); border-radius: 28px; padding: 32px 28px; display: flex; flex-direction: column; align-items: center; }
.pin-logo { display: flex; align-items: center; justify-content: center; }
.pin-logo img { width: 48px; height: 48px; object-fit: contain; }
.pin-title { font-size: 22px; font-weight: 700; margin-top: 14px; }
.pin-sub { font-size: 14px; color: var(--ink-2); margin-top: 4px; }
.pin-dots { display: flex; gap: 14px; margin: 24px 0 6px; height: 16px; }
.pin-dot { width: 14px; height: 14px; border-radius: 50%; border: 2px solid var(--ink-3); transition: all .15s; }
.pin-dot.filled { background: var(--ink); border-color: var(--ink); }
.pin-err { color: var(--expense); font-size: 13px; font-weight: 600; margin: 8px 0; }
.pin-hint { font-size: 12px; color: var(--ink-3); margin-top: 4px; }
.pin-input { position: absolute; opacity: 0; width: 1px; height: 1px; pointer-events: none; }
.pin-alt { margin-top: 24px; font-size: 14px; color: var(--info, #0a84ff); text-decoration: none; background: transparent; border: 0; cursor: pointer; font-family: inherit; }
</style>
