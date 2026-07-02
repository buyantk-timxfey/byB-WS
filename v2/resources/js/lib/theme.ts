// Тема: 'system' | 'light' | 'dark' | 'auto_time'. 'auto_time' сам решает
// светлая/тёмная по часам в фиксированном поясе (Сургут = Asia/Yekaterinburg,
// UTC+5) — не зависит от системных настроек компьютера или телефона.
export function resolveAutoTime(): 'light' | 'dark' {
    const hour = Number(
        new Intl.DateTimeFormat('en-GB', { hour: 'numeric', hour12: false, timeZone: 'Asia/Yekaterinburg' }).format(new Date()),
    );

    return hour >= 20 || hour < 8 ? 'dark' : 'light';
}

export function applyTheme(mode: string | undefined) {
    const html = document.documentElement;
    const resolved = mode === 'auto_time' ? resolveAutoTime() : mode;
    html.classList.toggle('theme-light', resolved === 'light');
    html.classList.toggle('theme-dark', resolved === 'dark');
}
