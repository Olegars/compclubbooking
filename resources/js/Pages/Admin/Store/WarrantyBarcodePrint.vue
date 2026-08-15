<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import { onMounted } from 'vue'
import { useClubName } from '@/Composables/useClubName'

const props = defineProps<{
    warranty: any
    qrPayload: string
    qrImageUrl: string
    endsAtLabel?: string | null
}>()

const clubName = useClubName()

onMounted(() => {
    setTimeout(() => window.print(), 250)
})
</script>

<template>
    <Head :title="`QR ${warranty.serial || ''}`" />
    <div class="sheet">
        <div class="no-print actions">
            <button type="button" @click="window.print()">Печать на POS</button>
            <Link href="/admin/store/built-pcs">← К сборкам</Link>
            <Link href="/admin/store/warranty">Гарантии</Link>
        </div>
        <div class="label">
            <div class="brand">{{ clubName }}</div>
            <div class="title">{{ warranty.product_name || warranty.built_pc?.title || 'Сборка ПК' }}</div>
            <img class="qr" :src="qrImageUrl" :alt="qrPayload" width="240" height="240" />
            <div class="sn">S/N {{ warranty.serial || '—' }}</div>
            <div v-if="endsAtLabel" class="ends">Гарантия до {{ endsAtLabel }}</div>
            <div class="hint">Выберите POS / термопринтер в диалоге печати</div>
        </div>
    </div>
</template>

<style>
@page {
    size: 80mm auto;
    margin: 2mm;
}
* { box-sizing: border-box; }
body {
    margin: 0;
    background: #111;
    color: #111;
    font-family: Consolas, "Courier New", monospace;
}
.sheet { min-height: 100vh; display: flex; flex-direction: column; align-items: center; padding: 16px; }
.actions { display: flex; gap: 12px; margin-bottom: 16px; flex-wrap: wrap; align-items: center; }
.actions a, .actions button {
    color: #fbbf24;
    background: transparent;
    border: 1px solid rgba(251,191,36,.4);
    border-radius: 10px;
    padding: 8px 14px;
    font-size: 11px;
    text-transform: uppercase;
    font-weight: 800;
    letter-spacing: .08em;
    cursor: pointer;
    text-decoration: none;
}
.label {
    width: 72mm;
    background: #fff;
    color: #000;
    padding: 6mm 4mm;
    text-align: center;
}
.brand { font-size: 10px; font-weight: 900; letter-spacing: .25em; text-transform: uppercase; }
.title { margin-top: 6px; font-size: 12px; font-weight: 800; text-transform: uppercase; }
.qr { margin-top: 10px; width: 48mm; height: 48mm; image-rendering: pixelated; }
.sn { margin-top: 8px; font-size: 13px; font-weight: 800; letter-spacing: .04em; }
.ends { margin-top: 4px; font-size: 11px; font-weight: 700; }
.hint { margin-top: 8px; font-size: 9px; color: #666; }
@media print {
    body { background: #fff; }
    .no-print { display: none !important; }
    .sheet { padding: 0; min-height: auto; }
    .hint { display: none; }
}
</style>
