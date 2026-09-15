<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import { computed } from 'vue'
import { useClubName } from '@/Composables/useClubName'

type DocItem = {
    title: string
    description: string
    path: string | null
    audience: string
}

type DocSection = {
    id: string
    title: string
    items: DocItem[]
}

const props = defineProps<{
    sections: DocSection[]
    printedAt: string
    section: string
    query: string
}>()

const clubName = useClubName()
const totalItems = computed(() =>
    props.sections.reduce((n, s) => n + s.items.length, 0)
)

const scopeLabel = computed(() => {
    if (props.query) {
        return `Поиск: «${props.query}»`
    }
    if (props.section && props.section !== 'all') {
        return props.sections[0]?.title || 'Раздел'
    }
    return 'Все разделы'
})

const doPrint = () => window.print()
</script>

<template>
    <Head :title="`Справка ${clubName}`" />
    <div class="wrap">
        <div class="no-print toolbar">
            <button type="button" class="print-btn" @click="doPrint">Сохранить PDF / печать</button>
            <span class="hint">В диалоге печати выберите «Сохранить как PDF»</span>
            <Link href="/admin/docs">← К справке</Link>
        </div>

        <article class="sheet">
            <header class="head">
                <div>
                    <div class="eyebrow">Справочник функций</div>
                    <h1>{{ clubName }}</h1>
                    <div class="addr">{{ scopeLabel }} · {{ totalItems }} модулей</div>
                </div>
                <div class="head-right">
                    <div class="serial-label">Справка</div>
                    <div class="serial">О системе</div>
                    <div class="meta-small">{{ printedAt }}</div>
                </div>
            </header>

            <nav v-if="sections.length" class="toc">
                <h2>Содержание</h2>
                <ol>
                    <li v-for="section in sections" :key="section.id">
                        {{ section.title }}
                        <span class="muted">· {{ section.items.length }}</span>
                    </li>
                </ol>
            </nav>
            <p v-else class="empty">Ничего не найдено по текущему фильтру.</p>

            <section
                v-for="section in sections"
                :key="section.id"
                class="block"
            >
                <h2>{{ section.title }}</h2>
                <article
                    v-for="item in section.items"
                    :key="item.title"
                    class="item"
                >
                    <div class="item-head">
                        <h3>{{ item.title }}</h3>
                        <span class="audience">{{ item.audience }}</span>
                    </div>
                    <p class="desc">{{ item.description }}</p>
                    <div v-if="item.path" class="path">{{ item.path }}</div>
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
.serial { font-size: 22px; font-weight: 800; }
.meta-small { margin-top: 6px; font-size: 11px; color: #666; }
.toc { margin: 18px 0 8px; }
.toc h2, .block h2 {
    font-size: 13px;
    text-transform: uppercase;
    letter-spacing: .16em;
    margin: 0 0 10px;
}
.toc ol { margin: 0; padding-left: 18px; font-size: 13px; line-height: 1.6; }
.muted { color: #666; }
.empty { color: #777; font-size: 13px; margin: 24px 0; }
.block { margin-top: 22px; }
.item {
    border-top: 1px solid #ddd;
    padding: 12px 0 10px;
    break-inside: avoid;
}
.item-head { display: flex; justify-content: space-between; gap: 12px; align-items: baseline; break-after: avoid; }
.item h3 { margin: 0; font-size: 14px; }
.audience {
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: .08em;
    color: #555;
    white-space: nowrap;
}
.desc { margin: 8px 0 0; font-size: 12px; line-height: 1.55; color: #222; white-space: pre-line; }
.path { margin-top: 8px; font-size: 11px; font-family: Consolas, "Courier New", monospace; color: #444; }
@media print {
    body { background: #fff; }
    .no-print { display: none !important; }
    .wrap { padding: 0; }
    .sheet { width: auto; min-height: auto; padding: 0; }
}
</style>
