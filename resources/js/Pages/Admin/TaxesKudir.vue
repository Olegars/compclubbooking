<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'

type Line = {
    n: number
    date: string
    id: number
    source: string
    description: string
    gross: number
    vat_rate: number
    vat: number
    net: number
    receipt: string | null
}

type Quarter = {
    gross: number
    vat: number
    net: number
    usn_raw: number
    deduction: number
    usn_advance: number
    deadline: string
}

type Employee = {
    id: number
    name: string
    gross: number
    ndfl: number
    employer: number
    injury: number
}

const props = defineProps<{
    year: number
    generated_at: string
    club: string
    legal: { entity: string, inn: string }
    profile: {
        entity: string
        regime: string
        usn_rate_percent: number
        has_employees: boolean
    }
    income: {
        gross: number
        net: number
        vat: number
        stub_gross: number
        fiscal_live: boolean
    }
    vat: { exempt: boolean, rate: number, reason: string }
    quarters: Record<number, Quarter>
    premiums: { fixed: number, extra: number, total: number }
    payroll: {
        employee_count: number
        employees: Employee[]
        year: { gross: number, ndfl: number, employer: number, injury: number, employer_total: number }
    }
    totals: { usn: number, vat: number, ip_premiums: number, employer_contributions: number, ndfl: number }
    lines: Line[]
}>()

const money = (n: number) =>
    Number(n || 0).toLocaleString('ru-RU', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ₽'

const formatDay = (iso: string) => {
    if (!iso || !iso.includes('-')) return iso
    const [y, m, d] = iso.split('-')
    return `${d}.${m}.${y}`
}

const doPrint = () => window.print()
</script>

<template>
    <Head :title="`КУДиР ${year}`" />
    <div class="wrap">
        <div class="no-print toolbar">
            <button type="button" class="print-btn" @click="doPrint">Сохранить PDF / печать</button>
            <span class="hint">В диалоге печати — «Сохранить как PDF». Это внутренний регистр, не бланк Минфина.</span>
            <Link :href="`/admin/taxes?year=${year}`">← К налогам</Link>
        </div>

        <article class="sheet">
            <header class="head">
                <div>
                    <div class="eyebrow">Внутренний регистр учёта доходов</div>
                    <h1>{{ legal.entity || club }}</h1>
                    <div class="meta-line" v-if="legal.inn">ИНН {{ legal.inn }}</div>
                    <div class="meta-line">{{ club }} · {{ profile.entity }}, {{ profile.regime }} {{ profile.usn_rate_percent }}%{{ profile.has_employees ? ', есть наёмные' : '' }}</div>
                </div>
                <div class="head-right">
                    <div class="serial-label">За год</div>
                    <div class="serial">{{ year }}</div>
                    <div class="meta-small">Сформировано {{ generated_at }}</div>
                </div>
            </header>

            <p class="disclaimer">
                Документ собран из операций клуба для проверки. Это не официальная КУДиР по приказу Минфина и не налоговая декларация.
                В регистр входят только фискализированные пополнения (чек ККТ success). Демо-чеки и бонусы не включаются.
            </p>

            <section class="kpis">
                <div><div class="k">Поступило</div><div class="v">{{ money(income.gross) }}</div></div>
                <div><div class="k">НДС выделенный</div><div class="v">{{ money(income.vat) }}</div></div>
                <div><div class="k">Доход УСН</div><div class="v">{{ money(income.net) }}</div></div>
                <div><div class="k">УСН к уплате</div><div class="v">{{ money(totals.usn) }}</div></div>
            </section>

            <p class="note">{{ vat.exempt ? 'НДС: освобождение. ' : `НДС: ${vat.rate}%. ` }}{{ vat.reason }}</p>
            <p v-if="income.stub_gross > 0" class="note warn">
                Демо-чеки за год (не в регистре): {{ money(income.stub_gross) }}. Касса {{ income.fiscal_live ? 'включена' : 'выключена' }}.
            </p>

            <h2>1. Реестр доходов</h2>
            <table v-if="lines.length">
                <thead>
                    <tr>
                        <th class="num">№</th>
                        <th>Дата</th>
                        <th>Операция</th>
                        <th class="num">Сумма</th>
                        <th class="num">НДС</th>
                        <th class="num">Доход УСН</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in lines" :key="row.id">
                        <td class="num">{{ row.n }}</td>
                        <td>{{ row.date }}</td>
                        <td>
                            <div class="name">{{ row.source }} · #{{ row.id }}</div>
                            <div class="part">{{ row.description }}<template v-if="row.vat_rate"> · НДС {{ row.vat_rate }}%</template></div>
                        </td>
                        <td class="num">{{ money(row.gross) }}</td>
                        <td class="num">{{ money(row.vat) }}</td>
                        <td class="num">{{ money(row.net) }}</td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="3" class="total-label">Итого</td>
                        <td class="num">{{ money(income.gross) }}</td>
                        <td class="num">{{ money(income.vat) }}</td>
                        <td class="num">{{ money(income.net) }}</td>
                    </tr>
                </tfoot>
            </table>
            <p v-else class="empty">Фискализированных поступлений за {{ year }} нет.</p>

            <h2>2. Кварталы</h2>
            <table>
                <thead>
                    <tr>
                        <th>Период</th>
                        <th class="num">Поступило</th>
                        <th class="num">НДС</th>
                        <th class="num">База УСН</th>
                        <th class="num">Налог 6%</th>
                        <th class="num">Вычет</th>
                        <th class="num">К доплате</th>
                        <th>Срок УСН</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="q in 4" :key="q">
                        <td>Q{{ q }}</td>
                        <td class="num">{{ money(quarters[q].gross) }}</td>
                        <td class="num">{{ money(quarters[q].vat) }}</td>
                        <td class="num">{{ money(quarters[q].net) }}</td>
                        <td class="num">{{ money(quarters[q].usn_raw) }}</td>
                        <td class="num">{{ money(quarters[q].deduction) }}</td>
                        <td class="num">{{ money(quarters[q].usn_advance) }}</td>
                        <td>{{ formatDay(quarters[q].deadline) }}</td>
                    </tr>
                </tbody>
            </table>

            <h2>3. Взносы ИП и наёмные</h2>
            <table>
                <tbody>
                    <tr><td>Фикс взносы ИП</td><td class="num">{{ money(premiums.fixed) }}</td></tr>
                    <tr><td>1% свыше 300 тыс.</td><td class="num">{{ money(premiums.extra) }}</td></tr>
                    <tr><td>Начислено штату (ТК РФ), {{ payroll.employee_count }} чел.</td><td class="num">{{ money(payroll.year.gross) }}</td></tr>
                    <tr><td>НДФЛ (агент, в вычет УСН не идёт)</td><td class="num">{{ money(payroll.year.ndfl) }}</td></tr>
                    <tr><td>Взносы за штат + травматизм (вычет УСН, ≤ 50%)</td><td class="num">{{ money(payroll.year.employer_total) }}</td></tr>
                    <tr><td>УСН к уплате за год</td><td class="num"><strong>{{ money(totals.usn) }}</strong></td></tr>
                    <tr><td>НДС к уплате за год</td><td class="num"><strong>{{ money(totals.vat) }}</strong></td></tr>
                </tbody>
            </table>

            <table v-if="payroll.employees.length" class="staff">
                <thead>
                    <tr>
                        <th>Сотрудник</th>
                        <th class="num">Начислено</th>
                        <th class="num">НДФЛ</th>
                        <th class="num">Взносы</th>
                        <th class="num">НС</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="person in payroll.employees" :key="person.id">
                        <td>{{ person.name }}</td>
                        <td class="num">{{ money(person.gross) }}</td>
                        <td class="num">{{ money(person.ndfl) }}</td>
                        <td class="num">{{ money(person.employer) }}</td>
                        <td class="num">{{ money(person.injury) }}</td>
                    </tr>
                </tbody>
            </table>

            <footer class="foot">
                <div class="signs">
                    <div class="sign">
                        <div class="line"></div>
                        ИП / подпись
                    </div>
                    <div class="sign">
                        <div class="line"></div>
                        дата
                    </div>
                </div>
            </footer>
        </article>
    </div>
</template>

<style scoped>
.wrap { min-height: 100vh; background: #ececec; padding: 24px 16px 48px; color: #111; }
.toolbar { max-width: 920px; margin: 0 auto 16px; display: flex; flex-wrap: wrap; gap: 12px; align-items: center; font-family: system-ui, sans-serif; }
.print-btn { background: #111; color: #fff; border: 0; padding: 10px 16px; border-radius: 10px; font-weight: 700; cursor: pointer; }
.hint { font-size: 12px; color: #555; }
.toolbar a { color: #111; font-size: 13px; }
.sheet { max-width: 920px; margin: 0 auto; background: #fff; padding: 36px 40px 48px; box-shadow: 0 8px 30px rgba(0,0,0,.08); }
.head { display: flex; justify-content: space-between; gap: 24px; border-bottom: 2px solid #111; padding-bottom: 16px; }
.eyebrow { font-size: 11px; letter-spacing: .16em; text-transform: uppercase; color: #666; }
h1 { margin: 6px 0 8px; font-size: 26px; line-height: 1.15; }
.meta-line { font-size: 13px; color: #333; }
.head-right { text-align: right; }
.serial-label { font-size: 11px; text-transform: uppercase; letter-spacing: .12em; color: #666; }
.serial { font-size: 32px; font-weight: 800; }
.meta-small { font-size: 12px; color: #666; margin-top: 4px; }
.disclaimer { font-size: 12px; line-height: 1.45; color: #444; margin: 16px 0; }
.kpis { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin: 16px 0; }
.k { font-size: 10px; text-transform: uppercase; letter-spacing: .1em; color: #666; }
.v { font-size: 16px; font-weight: 800; margin-top: 4px; }
.note { font-size: 12px; color: #444; margin: 0 0 8px; }
.note.warn { color: #8a5a00; }
h2 { font-size: 14px; text-transform: uppercase; letter-spacing: .12em; margin: 28px 0 10px; }
table { width: 100%; border-collapse: collapse; font-size: 12px; }
th, td { border-bottom: 1px solid #ddd; padding: 7px 6px; vertical-align: top; text-align: left; }
th { font-size: 10px; text-transform: uppercase; letter-spacing: .06em; color: #555; }
.num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
.name { font-weight: 650; }
.part { margin-top: 2px; font-size: 11px; color: #666; }
tfoot td { font-weight: 800; border-bottom: none; padding-top: 10px; }
.total-label { text-align: right; text-transform: uppercase; letter-spacing: .08em; font-size: 11px; }
.empty { color: #777; font-size: 13px; }
.staff { margin-top: 14px; }
.foot { margin-top: 40px; }
.signs { display: flex; gap: 48px; justify-content: flex-end; }
.sign { width: 200px; font-size: 11px; color: #555; text-align: center; }
.sign .line { height: 1px; background: #111; margin: 40px 0 8px; }
@media print {
    body { background: #fff; }
    .no-print { display: none !important; }
    .wrap { padding: 0; background: #fff; }
    .sheet { box-shadow: none; max-width: none; padding: 0; }
    .kpis { grid-template-columns: repeat(4, 1fr); }
}
@media (max-width: 720px) {
    .kpis { grid-template-columns: 1fr 1fr; }
    .sheet { padding: 20px; }
}
</style>
