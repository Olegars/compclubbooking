<script setup lang="ts">
import { computed, onMounted, onUnmounted } from 'vue'
import { Head, Link } from '@inertiajs/vue3'
import { useClubName } from '@/Composables/useClubName'

type TestStatus = 'pass' | 'fail' | 'warn' | 'skip'

type ResultRow = {
    id: string
    title: string
    group?: string | null
    group_title?: string | null
    kind?: string | null
    status?: TestStatus | null
    message?: string | null
    details?: string[] | null
    duration_ms?: number | null
}

const props = defineProps<{
    location: { id: number, name: string, type: string | null } | null
    printedAt: string
    owner: string
    results: ResultRow[]
    summary: {
        pass: number
        fail: number
        warn: number
        skip: number
        pending: number
        ran: number
    }
}>()

const clubName = useClubName()
let printTimer: number | null = null

onMounted(() => {
    printTimer = window.setTimeout(() => window.print(), 400)
})

onUnmounted(() => {
    if (printTimer) window.clearTimeout(printTimer)
})

const groups = computed(() => {
    const map = new Map<string, { title: string, items: ResultRow[] }>()
    for (const row of props.results) {
        const key = row.group || row.group_title || 'other'
        if (!map.has(key)) {
            map.set(key, { title: row.group_title || row.group || 'Прочее', items: [] })
        }
        map.get(key)!.items.push(row)
    }
    return [...map.values()]
})

const statusLabel = (status?: TestStatus | null) => {
    if (status === 'pass') return 'OK'
    if (status === 'fail') return 'Сбой'
    if (status === 'warn') return 'Внимание'
    if (status === 'skip') return 'Пропуск'
    return 'Не запускался'
}

const locationLine = computed(() => {
    if (!props.location) return 'Локация не выбрана'
    return props.location.name
})
</script>

<template>
    <Head :title="`Тесты ${clubName}`" />
    <div class="wrap">
        <div class="no-print toolbar">
            <button type="button" class="print-btn" @click="window.print()">Сохранить PDF / печать</button>
            <span class="hint">В диалоге печати выберите «Сохранить как PDF»</span>
            <Link href="/admin/system-tests">← К тестам</Link>
        </div>

        <article class="sheet">
            <header class="head">
                <div>
                    <div class="eyebrow">Протокол проверок</div>
                    <h1>{{ clubName }}</h1>
                    <div class="addr">{{ locationLine }} · владелец {{ owner }}</div>
                </div>
                <div class="head-right">
                    <div class="serial-label">Тесты системы</div>
                    <div class="serial">{{ printedAt }}</div>
                    <div class="meta-small">Прогнано {{ summary.ran }} · не запускалось {{ summary.pending }}</div>
                </div>
            </header>

            <table class="summary">
                <thead>
                    <tr>
                        <th>OK</th>
                        <th>Внимание</th>
                        <th>Сбой</th>
                        <th>Пропуск</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>{{ summary.pass }}</td>
                        <td>{{ summary.warn }}</td>
                        <td>{{ summary.fail }}</td>
                        <td>{{ summary.skip }}</td>
                    </tr>
                </tbody>
            </table>

            <section v-for="group in groups" :key="group.title" class="block">
                <h2>{{ group.title }}</h2>
                <article v-for="row in group.items" :key="row.id" class="item">
                    <div class="item-head">
                        <h3>{{ row.title }}</h3>
                        <span class="status" :data-status="row.status || 'pending'">{{ statusLabel(row.status) }}</span>
                    </div>
                    <p v-if="row.message" class="desc">{{ row.message }}</p>
                    <ul v-if="row.details?.length" class="details">
                        <li v-for="(line, idx) in row.details" :key="idx">{{ line }}</li>
                    </ul>
                    <div v-if="row.duration_ms" class="path">{{ row.duration_ms }} мс · {{ row.id }}</div>
                    <div v-else class="path">{{ row.id }}</div>
                </article>
            </section>
        </article>
    </div>
</template>

<style>
@page {
    size: A4;
    margin: 14mm;
}
* { box-sizing: border-box; }
body {
    margin: 0;
    background: #0a0a0a;
    color: #111;
    font-family: "Segoe UI", Tahoma, sans-serif;
}
.wrap { padding: 20px; display: flex; flex-direction: column; align-items: center; gap: 16px; }
.toolbar { display: flex; gap: 12px; flex-wrap: wrap; align-items: center; width: 210mm; max-width: 100%; }
.toolbar a, .print-btn {
    color: #fbbf24;
    background: transparent;
    border: 1px solid rgba(251,191,36,.4);
    border-radius: 10px;
    padding: 10px 16px;
    font-size: 11px;
    text-transform: uppercase;
    font-weight: 800;
    letter-spacing: .08em;
    cursor: pointer;
    text-decoration: none;
}
.hint { font-size: 11px; color: #888; }
.sheet {
    width: 210mm;
    max-width: 100%;
    min-height: 297mm;
    background: #fff;
    color: #111;
    padding: 18mm 16mm;
}
.head { display: flex; justify-content: space-between; gap: 20px; border-bottom: 2px solid #111; padding-bottom: 14px; }
.eyebrow { font-size: 11px; letter-spacing: .2em; text-transform: uppercase; color: #555; font-weight: 700; }
.head h1 { margin: 4px 0 0; font-size: 28px; letter-spacing: -.02em; }
.addr { margin-top: 6px; font-size: 12px; color: #555; }
.head-right { text-align: right; }
.serial-label { font-size: 11px; text-transform: uppercase; letter-spacing: .12em; color: #555; }
.serial { font-size: 18px; font-weight: 800; }
.meta-small { margin-top: 6px; font-size: 11px; color: #666; }
.summary {
    width: 100%;
    border-collapse: collapse;
    margin: 18px 0 8px;
    font-size: 13px;
}
.summary th, .summary td {
    border: 1px solid #ccc;
    padding: 8px;
    text-align: center;
}
.summary th { font-size: 10px; text-transform: uppercase; letter-spacing: .08em; color: #555; }
.summary td { font-size: 20px; font-weight: 800; }
.block { margin-top: 22px; }
.block h2 {
    font-size: 13px;
    text-transform: uppercase;
    letter-spacing: .16em;
    margin: 0 0 10px;
}
.item {
    border-top: 1px solid #ddd;
    padding: 12px 0 10px;
    break-inside: avoid;
}
.item-head { display: flex; justify-content: space-between; gap: 12px; align-items: baseline; }
.item h3 { margin: 0; font-size: 14px; }
.status {
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: .08em;
    font-weight: 800;
    white-space: nowrap;
}
.status[data-status="pass"] { color: #047857; }
.status[data-status="fail"] { color: #b91c1c; }
.status[data-status="warn"] { color: #b45309; }
.status[data-status="skip"] { color: #525252; }
.status[data-status="pending"] { color: #737373; }
.desc { margin: 8px 0 0; font-size: 12px; line-height: 1.55; color: #222; }
.details { margin: 6px 0 0; padding-left: 18px; font-size: 11px; color: #444; line-height: 1.45; }
.path { margin-top: 8px; font-size: 11px; font-family: Consolas, "Courier New", monospace; color: #444; }
@media print {
    body { background: #fff; }
    .no-print { display: none !important; }
    .wrap { padding: 0; }
    .sheet { width: auto; min-height: auto; padding: 0; }
}
</style>
