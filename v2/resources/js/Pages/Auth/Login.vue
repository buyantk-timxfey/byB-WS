<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';

defineProps<{ canResetPassword?: boolean; status?: string }>();

const form = useForm({ email: '', password: '', remember: true });
const submit = () => form.post(route('login'), { onFinish: () => form.reset('password') });
</script>

<template>
    <Head title="Вход" />
    <div class="login-wrap">
        <div class="orbs" aria-hidden="true"><i class="orb-a"></i><i class="orb-b"></i><i class="orb-c"></i><i class="orb-d"></i></div>

        <form class="login-card glass-strong" @submit.prevent="submit">
            <div class="login-logo">
                <img src="/images/logo-black.png" alt="byBuka" class="brand-logo-light" />
                <img src="/images/logo-white.png" alt="byBuka" class="brand-logo-dark" />
            </div>
            <div class="login-title">byBuka</div>
            <div class="login-sub">Вход в систему</div>

            <div v-if="status" class="login-status">{{ status }}</div>

            <label class="fld">
                <span>Логин</span>
                <input v-model="form.email" type="text" autocomplete="username" autofocus placeholder="Логин" />
            </label>
            <div v-if="form.errors.email" class="login-err">{{ form.errors.email }}</div>

            <label class="fld">
                <span>Пароль</span>
                <input v-model="form.password" type="password" autocomplete="current-password" placeholder="••••••••" />
            </label>
            <div v-if="form.errors.password" class="login-err">{{ form.errors.password }}</div>

            <label class="login-remember">
                <input v-model="form.remember" type="checkbox" />
                <span>Запомнить меня</span>
            </label>

            <button class="btn-primary pressable login-btn" :disabled="form.processing">Войти</button>

            <Link href="/pin" class="login-alt">Войти по PIN-коду</Link>
        </form>
    </div>
</template>

<style scoped>
.login-wrap { min-height: 100dvh; display: flex; align-items: center; justify-content: center; padding: 20px; }
.login-card { width: min(380px, 100%); border-radius: 28px; padding: 32px 30px; display: flex; flex-direction: column; gap: 14px; }
.login-logo { display: flex; justify-content: center; margin-bottom: -4px; }
.login-logo img { width: 44px; height: 44px; object-fit: contain; }
.login-title { font-size: 26px; font-weight: 800; letter-spacing: -.02em; text-align: center; }
.login-sub { font-size: 14px; color: var(--ink-2); text-align: center; margin-top: -8px; margin-bottom: 8px; }
.login-status { font-size: 13px; color: var(--income); text-align: center; }
.fld { display: flex; flex-direction: column; gap: 6px; }
.fld span { font-size: 12px; font-weight: 600; color: var(--ink-2); }
.fld input { border: 1px solid var(--glass-border); background: var(--glass-fill); border-radius: 12px; padding: 12px 14px; color: var(--ink); font-size: 15px; font-family: inherit; outline: none; }
.fld input:focus { border-color: var(--ink-3); }
.login-err { font-size: 13px; color: var(--expense); font-weight: 600; margin-top: -8px; }
.login-remember { display: flex; align-items: center; gap: 8px; font-size: 14px; color: var(--ink-2); }
.login-remember input { width: 16px; height: 16px; }
.login-btn { justify-content: center; padding: 13px; font-size: 15px; margin-top: 4px; }
.login-alt { text-align: center; font-size: 14px; color: var(--info, #0a84ff); text-decoration: none; margin-top: 2px; }
</style>
