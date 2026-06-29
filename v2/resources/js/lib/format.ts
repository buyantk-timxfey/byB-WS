// Единые форматтеры. Использовать везде, не плодить локальные аналоги.

export const money = (n: number | string): string =>
    new Intl.NumberFormat('ru-RU').format(Math.round(Number(n) || 0)) + ' ₽';

export const signed = (n: number | string): string => {
    const v = Number(n) || 0;
    return (v > 0 ? '+ ' : '− ') + new Intl.NumberFormat('ru-RU').format(Math.abs(Math.round(v))) + ' ₽';
};

export const num = (n: number | string): string =>
    new Intl.NumberFormat('ru-RU').format(Number(n) || 0);

export const pct = (n: number | string): string =>
    (Number(n) || 0).toFixed(1).replace('.', ',') + ' %';

// «2026-06-28» → «28.06.2026»
export const date = (s: string | null): string => {
    if (!s) return '—';
    const d = new Date(s);
    if (isNaN(d.getTime())) return s;
    return d.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric' });
};

// Инициалы контрагента для аватара
export const initials = (s: string): string =>
    (s || '').replace(/^(ООО|ИП|ПАО|АО|ЗАО)\s+/, '').trim().slice(0, 2).toUpperCase();
