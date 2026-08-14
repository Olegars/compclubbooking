<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import { computed } from 'vue'

const props = defineProps<{
    estimate: any
    lines: Array<{
        type?: string | null
        type_label?: string | null
        name?: string
        part?: string | null
        qty: number
        sale_price: number
        line_total: number
    }>
    statusLabel: string
    printedAt: string
}>()

const clubName = computed(() => props.estimate?.club?.name || 'REACTOR STORE')
const clubAddress = computed(() => props.estimate?.club?.address || '')
const clientName = computed(() => props.estimate?.client?.name || '—')
const clientPhone = computed(() => props.estimate?.client?.phone || '')
const title = computed(() => props.estimate?.title || 'Сборка ПК')
const saleTotal = computed(() => Number(props.estimate?.sale_total || 0))

const money = (n: number | string | null | undefined) =>
    Number(n || 0).toLocaleString('ru-RU', { maximumFractionDigits: 0 }) + ' ₽'

const doPrint = () => window.print()
</script>

<template>
    <Head :title="`Смета #${estimate.id}`" />
    <div class="wrap">
        <div class="no-print toolbar">
            <button type="button" class="print-btn" @click="doPrint">Сохранить PDF / печать</button>
            <span class="hint">В диалоге печати выберите «Сохранить как PDF»</span>
            <Link href="/admin/store/estimates">← К сметам</Link>
        </div>

        <article class="sheet">
            <header class="head">
                <div>
                    <div class="eyebrow">Коммерческое предложение</div>
                    <h1>{{ clubName }}</h1>
                    <div v-if="clubAddress" class="addr">{{ clubAddress }}</div>
                </div>
                <div class="head-right">
                    <div class="serial-label">Смета</div>
                    <div class="serial">#{{ estimate.id }}</div>
                    <div class="meta-small">{{ printedAt }}</div>
                </div>
            </header>

            <section class="meta">
                <div>
                    <div class="k">Название</div>
                    <div class="v">{{ title }}</div>
                </div>
                <div>
                    <div class="k">Клиент</div>
                    <div class="v">
                        {{ clientName }}
                        <span v-if="clientPhone" class="muted"> · {{ clientPhone }}</span>
                    </div>
                </div>
                <div>
                    <div class="k">Статус</div>
                    <div class="v">{{ statusLabel }}</div>
                </div>
                <div>
                    <div class="k">Итого</div>
                    <div class="v total">{{ money(saleTotal) }}</div>
                </div>
            </section>

            <section class="bom">
                <h2>Комплектация</h2>
                <table v-if="lines.length">
                    <thead>
                        <tr>
                            <th class="num">№</th>
                            <th>Тип</th>
                            <th>Наименование</th>
                            <th class="num">Кол-во</th>
                            <th class="num">Цена</th>
                            <th class="num">Сумма</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(row, i) in lines" :key="i">
                            <td class="num">{{ i + 1 }}</td>
                            <td>{{ row.type_label || row.type || '—' }}</td>
                            <td>
                                <div class="name">{{ row.name }}</div>
                                <div v-if="row.part" class="part">{{ row.part }}</div>
                            </td>
                            <td class="num">{{ row.qty }}</td>
                            <td class="num">{{ money(row.sale_price) }}</td>
                            <td class="num">{{ money(row.line_total) }}</td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="5" class="total-label">Итого к оплате</td>
                            <td class="num total">{{ money(saleTotal) }}</td>
                        </tr>
                    </tfoot>
                </table>
                <p v-else class="empty">Позиции не указаны.</p>
            </section>

            <section v-if="estimate.notes" class="notes">
                <h2>Примечание</h2>
                <p>{{ estimate.notes }}</p>
            </section>

            <footer class="foot">
                <div class="signs">
                    <div class="sign">
                        <div class="line" />
                        <div>Подпись продавца</div>
                    </div>
                    <div class="sign">
                        <div class="line" />
                        <div>Подпись клиента</div>
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
.serial { font-size: 26px; font-weight: 800; font-family: Consolas, monospace; letter-spacing: .08em; }
.meta-small { margin-top: 6px; font-size: 11px; color: #666; }
.meta {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 14px 24px;
    margin: 18px 0 22px;
}
.k { font-size: 10px; text-transform: uppercase; letter-spacing: .14em; color: #666; margin-bottom: 3px; }
.v { font-size: 14px; font-weight: 650; }
.v.total { font-size: 18px; font-weight: 800; }
.muted { color: #666; font-weight: 500; }
.bom h2, .notes h2 {
    font-size: 13px;
    text-transform: uppercase;
    letter-spacing: .16em;
    margin: 0 0 10px;
}
table { width: 100%; border-collapse: collapse; font-size: 12px; }
th, td { border-bottom: 1px solid #ddd; padding: 8px 6px; text-align: left; vertical-align: top; }
th { font-size: 10px; text-transform: uppercase; letter-spacing: .1em; color: #555; }
.num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
.name { font-weight: 650; }
.part { margin-top: 2px; font-size: 11px; color: #666; }
tfoot td { border-bottom: none; padding-top: 12px; font-weight: 800; }
.total-label { text-align: right; text-transform: uppercase; letter-spacing: .08em; font-size: 11px; }
.empty { color: #777; font-size: 13px; }
.notes { margin-top: 22px; }
.notes p { margin: 0; font-size: 12px; line-height: 1.5; color: #333; white-space: pre-wrap; }
.foot { margin-top: 36px; }
.signs { display: flex; gap: 40px; justify-content: flex-end; }
.sign { width: 180px; font-size: 11px; color: #555; text-align: center; }
.sign .line { height: 1px; background: #111; margin-bottom: 8px; margin-top: 40px; }
@media print {
    body { background: #fff; }
    .no-print { display: none !important; }
    .wrap { padding: 0; }
    .sheet { width: auto; min-height: auto; padding: 0; }
}
</style>
