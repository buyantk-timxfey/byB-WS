import { reactive } from 'vue';

// Свой диалог подтверждения вместо системного confirm() — единый стиль.
// Использование: if (await confirmDlg('Удалить запись?')) { … }
export const confirmState = reactive({
    open: false,
    message: '',
    action: 'Удалить',
    resolve: null as null | ((v: boolean) => void),
});

export function confirmDlg(message: string, action = 'Удалить'): Promise<boolean> {
    confirmState.message = message;
    confirmState.action = action;
    confirmState.open = true;
    return new Promise((res) => { confirmState.resolve = res; });
}

export function answerConfirm(v: boolean) {
    confirmState.open = false;
    confirmState.resolve?.(v);
    confirmState.resolve = null;
}
