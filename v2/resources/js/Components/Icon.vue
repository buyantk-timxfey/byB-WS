<script setup lang="ts">
// Минимальный набор иконок в стиле SF Symbols (монолинейные, скруглённые).
// stroke = currentColor, наследует цвет от родителя.
defineProps<{ name: string; size?: number }>();

const paths: Record<string, string> = {
    grid: 'M4 4h6v6H4zM14 4h6v6h-6zM4 14h6v6H4zM14 14h6v6h-6z',
    box: 'M21 8l-9-5-9 5 9 5 9-5zM3 8v8l9 5 9-5V8M12 13v8',
    bag: 'M6 8h12l-1 12H7L6 8zM9 8V6a3 3 0 0 1 6 0v2',
    warehouse: 'M3 21V9l9-5 9 5v12M7 21v-7h10v7',
    wallet: 'M3 7h15a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7zM3 7l3-3h11M16 13h2',
    chart: 'M4 20V10M10 20V4M16 20v-7M22 20H2',
    book: 'M4 5a2 2 0 0 1 2-2h13v16H6a2 2 0 0 0-2 2V5zM19 19H6',
    mail: 'M3 6h18v12H3zM3 7l9 6 9-6',
    car: 'M5 16l1.5-5h11L19 16M3 16h18v3H3zM6 19v1M18 19v1',
    gear: 'M12 9a3 3 0 1 0 0 6 3 3 0 0 0 0-6zM19 12l2-1-1-3-2 .5a7 7 0 0 0-1.7-1L16 5h-3l-.3 2.4a7 7 0 0 0-1.7 1L9 8 6 9l1 2.5L5 12l1 3 2-.5a7 7 0 0 0 1.7 1L11 19h3l.3-2.4a7 7 0 0 0 1.7-1L19 16l1-3-1-1z',
    bell: 'M18 8a6 6 0 1 0-12 0c0 7-3 9-3 9h18s-3-2-3-9M13.7 21a2 2 0 0 1-3.4 0',
    search: 'M11 19a8 8 0 1 0 0-16 8 8 0 0 0 0 16zM21 21l-4.3-4.3',
    plus: 'M12 5v14M5 12h14',
    truck: 'M3 6h11v9H3zM14 9h4l3 3v3h-7zM7 18a2 2 0 1 0 0-.01M17 18a2 2 0 1 0 0-.01',
    sun: 'M12 4V2M12 22v-2M4 12H2M22 12h-2M6 6L4.5 4.5M19.5 19.5L18 18M6 18l-1.5 1.5M19.5 4.5L18 6M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8z',
    alert: 'M12 9v4M12 17h.01M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z',
};
</script>

<template>
    <svg
        :width="size ?? 22"
        :height="size ?? 22"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="1.75"
        stroke-linecap="round"
        stroke-linejoin="round"
        aria-hidden="true"
    >
        <path :d="paths[name] ?? paths.grid" />
    </svg>
</template>
