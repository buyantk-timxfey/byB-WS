import { ref, onMounted, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';

// Подсветка строки, к которой привёл глобальный поиск: страница открывается
// с ?hl=<id>, строка с data-hl="<id>" подскролливается и мигает пару секунд.
export function useRowHighlight() {
    const hl = ref<number | null>(null);
    const page = usePage();

    const apply = () => {
        const m = (page.url.split('?')[1] ?? '').match(/(?:^|&)hl=(\d+)/);
        const id = m ? Number(m[1]) : null;
        if (id === null) return;
        hl.value = id;
        setTimeout(() => {
            document.querySelector(`[data-hl="${id}"]`)?.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }, 150);
        setTimeout(() => { if (hl.value === id) hl.value = null; }, 2800);
    };

    onMounted(apply);
    // повторный выбор из поиска, когда уже стоишь на этой странице
    watch(() => page.url, apply);

    return hl;
}
