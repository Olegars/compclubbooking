<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import { computed, onMounted } from 'vue'
import { useClubName } from '@/Composables/useClubName'

const props = defineProps<{
    warranty: any
    qrPayload: string
    qrImageUrl: string
    buildItems: Array<{ type?: string; type_label?: string; name?: string; warranty_number?: string | null }>
}>()

const brandName = useClubName()
const clubName = computed(() => props.warranty?.club?.name || brandName.value)

const fmt = (d: string | null | undefined) => {
    if (!d) return '—'
    return String(d).slice(0, 10).split('-').reverse().join('.')
}

const clientName = computed(() => props.warranty?.client?.name || '—')
const clientPhone = computed(() => props.warranty?.client?.phone || '')
const months = computed(() => props.warranty?.warranty_months || 12)
const repairDays = computed(() => props.warranty?.repair_days || 45)

onMounted(() => {
    // не автопечатать сразу — пользователь может проверить комплектацию
})
</script>

<template>
    <Head :title="`Гарантийный талон ${warranty.serial || ''}`" />
    <div class="wrap">
        <div class="no-print toolbar">
            <button type="button" class="print-btn" @click="window.print()">Распечатать талон</button>
            <Link href="/admin/store/built-pcs">← К сборкам</Link>
            <Link href="/admin/store/warranty">Гарантии</Link>
        </div>

        <article class="talon">
            <header class="head">
                <div>
                    <div class="eyebrow">Гарантийный талон</div>
                    <h1>{{ clubName }}</h1>
                </div>
                <div class="head-right">
                    <div class="serial-label">Серийный номер</div>
                    <div class="serial">{{ warranty.serial }}</div>
                </div>
            </header>

            <section class="meta">
                <div>
                    <div class="k">Изделие</div>
                    <div class="v">{{ warranty.product_name || 'Сборка ПК' }}</div>
                </div>
                <div>
                    <div class="k">Клиент</div>
                    <div class="v">{{ clientName }} <span v-if="clientPhone" class="muted">· {{ clientPhone }}</span></div>
                </div>
                <div>
                    <div class="k">Начало гарантии</div>
                    <div class="v">{{ fmt(warranty.started_at) }}</div>
                </div>
                <div>
                    <div class="k">Окончание гарантии ({{ months }} мес.)</div>
                    <div class="v">{{ fmt(warranty.ends_at) }}</div>
                </div>
                <div>
                    <div class="k">Срок ремонта</div>
                    <div class="v">до {{ repairDays }} дней</div>
                </div>
                <div v-if="warranty.built_pc_id || warranty.store_built_pc_id">
                    <div class="k">Заказ / сборка</div>
                    <div class="v">#{{ warranty.store_built_pc_id || warranty.built_pc?.id }}</div>
                </div>
                <div v-if="warranty.built_pc?.assembler">
                    <div class="k">Сборщик</div>
                    <div class="v">{{ warranty.built_pc.assembler.name }}</div>
                </div>
            </section>

            <section class="bom">
                <h2>Комплектация</h2>
                <table v-if="buildItems.length">
                    <thead>
                        <tr>
                            <th>Тип</th>
                            <th>Наименование</th>
                            <th>S/N (все планки)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(row, i) in buildItems" :key="i">
                            <td>{{ row.type_label || row.type || '—' }}</td>
                            <td>{{ row.name }}</td>
                            <td>{{ row.warranty_number || '—' }}</td>
                        </tr>
                    </tbody>
                </table>
                <p v-else class="empty">Комплектация не указана в заказе сборки.</p>
            </section>

            <section class="terms">
                <h2>Условия гарантии</h2>
                <ol>
                    <li>Срок гарантии на сборку — {{ months }} мес. с даты начала, указанной в талоне.</li>
                    <li>Гарантийный ремонт производится в срок до {{ repairDays }} дней с момента обращения.</li>
                    <li>Гарантия распространяется на исправность сборки и комплектующих при соблюдении условий эксплуатации.</li>
                    <li>Гарантия не действует при механических повреждениях, следах влаги, самостоятельного ремонта и вмешательства.</li>
                    <li>Для обращения предъявите этот талон и серийный номер изделия.</li>
                </ol>
            </section>

            <footer class="foot">
                <div class="qr-wrap">
                    <img class="qr" :src="qrImageUrl" :alt="qrPayload" width="180" height="180" />
                    <div class="qr-meta">
                        <div>S/N {{ warranty.serial || '—' }}</div>
                        <div>Гарантия до {{ fmt(warranty.ends_at) }}</div>
                    </div>
                </div>
                <div class="signs">
                    <div class="sign">
                        <div class="line" />
                        <div>Подпись продавца</div>
                    </div>
                    <div class="sign">
                        <div class="line" />
                        <div>Подпись покупателя</div>
                    </div>
                </div>
            </footer>
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
.talon {
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
.head-right { text-align: right; }
.serial-label { font-size: 11px; text-transform: uppercase; letter-spacing: .12em; color: #555; }
.serial { font-size: 26px; font-weight: 800; font-family: Consolas, monospace; letter-spacing: .08em; }
.meta {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 14px 24px;
    margin: 18px 0 22px;
}
.k { font-size: 10px; text-transform: uppercase; letter-spacing: .14em; color: #666; margin-bottom: 3px; }
.v { font-size: 14px; font-weight: 650; }
.muted { color: #666; font-weight: 500; }
.bom h2, .terms h2 {
    font-size: 13px;
    text-transform: uppercase;
    letter-spacing: .16em;
    margin: 0 0 10px;
}
table { width: 100%; border-collapse: collapse; font-size: 12px; }
th, td { border-bottom: 1px solid #ddd; padding: 8px 6px; text-align: left; vertical-align: top; }
th { font-size: 10px; text-transform: uppercase; letter-spacing: .1em; color: #555; }
.empty { color: #777; font-size: 13px; }
.terms { margin-top: 22px; }
.terms ol { margin: 0; padding-left: 18px; font-size: 12px; line-height: 1.55; color: #333; }
.foot { margin-top: 28px; display: flex; justify-content: space-between; gap: 24px; align-items: flex-end; }
.qr-wrap { display: flex; align-items: center; gap: 12px; }
.qr { width: 100px; height: 100px; image-rendering: pixelated; }
.qr-meta { font-size: 11px; font-weight: 700; line-height: 1.4; }
.signs { display: flex; gap: 28px; flex: 1; justify-content: flex-end; }
.sign { width: 160px; font-size: 11px; color: #555; text-align: center; }
.sign .line { height: 1px; background: #111; margin-bottom: 8px; margin-top: 36px; }
@media print {
    body { background: #fff; }
    .no-print { display: none !important; }
    .wrap { padding: 0; }
    .talon { width: auto; min-height: auto; padding: 0; }
}
</style>
