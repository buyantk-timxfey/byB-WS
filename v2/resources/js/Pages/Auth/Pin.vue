<script setup lang="ts">
import { ref, watch } from 'vue';
import { Head, useForm, Link } from '@inertiajs/vue3';

defineProps<{ locked?: boolean }>();

const form = useForm({ pin: '' });
const dots = ref('');

function press(n: string) {
    if (dots.value.length >= 8) return;
    dots.value += n;
    if (dots.value.length >= 4) maybeSubmit();
}
function back() { dots.value = dots.value.slice(0, -1); }
function maybeSubmit() {
    form.pin = dots.value;
    form.post('/pin', { onError: () => { dots.value = ''; }, onFinish: () => { form.reset('pin'); } });
}
watch(() => form.errors.pin, () => { dots.value = ''; });
</script>

<template>
    <Head title="Вход по PIN" />
    <div class="pin-wrap">
        <div class="orbs" aria-hidden="true"><i class="orb-a"></i><i class="orb-b"></i><i class="orb-c"></i><i class="orb-d"></i></div>
        <div class="pin-card glass-strong">
            <div class="pin-logo"><span>bB</span></div>
            <div class="pin-title">byBuka</div>
            <div class="pin-sub">{{ locked ? 'Сессия заблокирована из-за бездействия' : 'Введите PIN' }}</div>

            <div class="pin-dots">
                <span v-for="i in 8" :key="i" class="pin-dot" :class="{ filled: i <= dots.length }" v-show="i <= Math.max(4, dots.length)"></span>
            </div>
            <div v-if="form.errors.pin" class="pin-err">{{ form.errors.pin }}</div>

            <div class="pin-pad">
                <button v-for="n in 9" :key="n" class="pin-key pressable" @click="press(String(n))">{{ n }}</button>
                <span></span>
                <button class="pin-key pressable" @click="press('0')">0</button>
                <button class="pin-key pin-key--act pressable" @click="back">⌫</button>
            </div>

            <Link v-if="locked" href="/logout" method="post" as="button" class="pin-alt">Выйти и войти паролем</Link>
            <Link v-else href="/login" class="pin-alt">Войти по паролю</Link>
        </div>
    </div>
</template>

<style scoped>
.pin-wrap { min-height: 100dvh; display: flex; align-items: center; justify-content: center; padding: 20px; }
.pin-card { width: min(360px, 100%); border-radius: 28px; padding: 32px 28px; display: flex; flex-direction: column; align-items: center; }
.pin-logo { width: 56px; height: 56px; border-radius: 16px; background: var(--ink); color: var(--bg); display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 20px; }
.pin-title { font-size: 22px; font-weight: 700; margin-top: 14px; }
.pin-sub { font-size: 14px; color: var(--ink-2); margin-top: 4px; }
.pin-dots { display: flex; gap: 14px; margin: 24px 0 6px; height: 16px; }
.pin-dot { width: 14px; height: 14px; border-radius: 50%; border: 2px solid var(--ink-3); transition: all .15s; }
.pin-dot.filled { background: var(--ink); border-color: var(--ink); }
.pin-err { color: var(--expense); font-size: 13px; font-weight: 600; margin: 8px 0; }
.pin-pad { display: grid; grid-template-columns: repeat(3, 72px); gap: 14px; margin-top: 20px; }
.pin-key { height: 72px; border-radius: 50%; border: 1px solid var(--glass-border); background: var(--glass-fill); color: var(--ink); font-size: 26px; font-weight: 500; cursor: pointer; }
.pin-key:hover { background: var(--glass-fill-strong); }
.pin-key--act { font-size: 22px; }
.pin-alt { margin-top: 24px; font-size: 14px; color: var(--info, #0a84ff); text-decoration: none; background: transparent; border: 0; cursor: pointer; font-family: inherit; }
</style>
