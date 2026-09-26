<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { router, usePage } from '@inertiajs/vue3'

type RoleRate = {
    role: string
    label: string
    group: 'club' | 'store' | string
    shift_rate: number
    staff_count: number
}

const props = defineProps<{
    settings: {
        hourly: number
        day_hours: number
        night_hours: number
        night_coefficient: number
        day_pay: number
        night_pay: number
        official: number
        roles: RoleRate[]
    }
}>()

const page = usePage()
const busyRole = ref<string | null>(null)
const drafts = reactive<Record<string, string>>({})

const serverError = computed(() => (page.props as any).errors?.shift_rate as string | undefined)

const clubRoles = computed(() => props.settings.roles.filter((role) => role.group === 'club'))
const storeRoles = computed(() => props.settings.roles.filter((role) => role.group === 'store'))

const money = (value: number) => value.toLocaleString('ru-RU', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
}) + ' ₽'

const kopeks = (value: number) => {
    const [whole, frac = '00'] = value.toFixed(2).split('.')
    return Number(whole) * 100 + Number(frac.padEnd(2, '0').slice(0, 2))
}

function formatDraft(value: number) {
    const cents = kopeks(value)
    if (cents % 100 === 0) return String(cents / 100)
    return (cents / 100).toFixed(2)
}

function parseDraft(raw: string) {
    const trimmed = raw.trim().replace(',', '.')
    if (trimmed === '' || trimmed === '-' || trimmed === '.') return null
    const value = Number(trimmed)
    return Number.isFinite(value) ? value : null
}

function statusOf(raw: string) {
    const value = parseDraft(raw)
    if (value === null) return { kind: 'empty' as const }
    const entered = kopeks(value)
    const official = kopeks(props.settings.official)
    if (entered < official) return { kind: 'below' as const, value }
    return {
        kind: 'ok' as const,
        value,
        bonus: (entered - official) / 100,
    }
}

function bonusOf(raw: string) {
    const status = statusOf(raw)
    return status.kind === 'ok' ? status.bonus : null
}

function bonusLabel(raw: string) {
    const bonus = bonusOf(raw)
    return bonus === null ? '—' : money(bonus)
}

for (const role of props.settings.roles) {
    drafts[role.role] = formatDraft(role.shift_rate)
}

function unchanged(role: RoleRate) {
    const parsed = parseDraft(drafts[role.role] ?? '')
    if (parsed === null) return false
    return kopeks(parsed) === kopeks(role.shift_rate)
}

function save(role: RoleRate) {
    const status = statusOf(drafts[role.role] ?? '')
    if (status.kind !== 'ok' || busyRole.value) return
    busyRole.value = role.role
    router.post('/admin/staff/pay-settings', {
        role: role.role,
        shift_rate: status.value,
    }, {
        preserveScroll: true,
        onFinish: () => {
            busyRole.value = null
        },
    })
}

const inputClass = 'mt-2 w-full bg-black/40 border rounded-2xl px-4 py-3 text-sm text-white outline-none'
</script>

<template>
    <section class="space-y-6">
        <div class="bg-[#050505] border border-white/10 rounded-[1.125rem] p-8">
            <div class="text-[10px] text-purple-400 uppercase font-black tracking-widest">Настройки зарплаты</div>
            <h2 class="text-xl font-black uppercase italic text-white mt-2">Ставка за смену</h2>
            <p class="text-white/40 text-[11px] mt-3 max-w-3xl leading-relaxed">
                Смена {{ settings.day_hours + settings.night_hours }} часа:
                {{ settings.day_hours }} дневных и {{ settings.night_hours }} ночных.
                Ночной час считается как дневной × {{ settings.night_coefficient.toLocaleString('ru-RU') }}.
                Окладная часть фиксируется по МРОТ и не растёт вместе со ставкой.
                Всё, что выше, падает в премию.
            </p>
            <p class="text-white/50 text-[11px] mt-3">
                Минимум за смену {{ money(settings.official) }}
                = {{ settings.day_hours }} × {{ money(settings.hourly) }}
                + {{ settings.night_hours }} × {{ money(settings.hourly) }} × {{ settings.night_coefficient.toLocaleString('ru-RU') }}.
            </p>
            <p v-if="serverError" class="text-red-400 text-[10px] uppercase font-black mt-4">{{ serverError }}</p>
        </div>

        <div v-for="block in [
            { title: 'Зал', roles: clubRoles },
            { title: 'Магазин', roles: storeRoles },
        ]" :key="block.title" class="space-y-4">
            <div class="text-[10px] text-white/30 uppercase font-black tracking-[0.3em]">{{ block.title }}</div>
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                <article v-for="role in block.roles" :key="role.role"
                         class="bg-[#050505] border border-white/5 rounded-[1rem] p-6 space-y-4">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <div class="text-white font-black uppercase tracking-tight">{{ role.label }}</div>
                            <div class="text-[10px] text-white/30 mt-1">
                                В штате {{ role.staff_count }}. Сохранение проставит эту ставку за смену им и новым сотрудникам.
                            </div>
                        </div>
                    </div>

                    <label class="block">
                        <span class="text-[10px] uppercase font-black tracking-widest text-white/40">Ставка за смену (₽)</span>
                        <input
                            v-model="drafts[role.role]"
                            type="number"
                            min="0"
                            step="0.01"
                            inputmode="decimal"
                            :class="[
                                inputClass,
                                statusOf(drafts[role.role] || '').kind === 'below'
                                    ? 'border-red-500/80 focus:border-red-400'
                                    : 'border-white/10 focus:border-purple-500/40',
                            ]"
                        >
                        <p v-if="statusOf(drafts[role.role] || '').kind === 'below'"
                           class="text-red-400 text-[10px] uppercase font-black mt-2">
                            Ниже минимальной ставки по МРОТ с учетом ночных
                        </p>
                    </label>

                    <dl class="space-y-2 text-[11px]" :class="statusOf(drafts[role.role] || '').kind === 'ok' ? '' : 'opacity-40'">
                        <div class="flex justify-between gap-4">
                            <dt class="text-white/40">Базовая ставка в час</dt>
                            <dd class="text-white font-bold">
                                {{ statusOf(drafts[role.role] || '').kind === 'ok' ? money(settings.hourly) + '/ч' : '—' }}
                            </dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="text-white/40">Оплата дневных часов · {{ settings.day_hours }} ч</dt>
                            <dd class="text-white font-bold">
                                {{ statusOf(drafts[role.role] || '').kind === 'ok' ? money(settings.day_pay) : '—' }}
                            </dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="text-white/40">Оплата ночных часов · {{ settings.night_hours }} ч × {{ settings.night_coefficient.toLocaleString('ru-RU') }}</dt>
                            <dd class="text-white font-bold">
                                {{ statusOf(drafts[role.role] || '').kind === 'ok' ? money(settings.night_pay) : '—' }}
                            </dd>
                        </div>
                        <div class="flex justify-between gap-4 pt-2 border-t border-white/5">
                            <dt class="text-white/40">Окладная часть по МРОТ</dt>
                            <dd class="text-white font-black">
                                {{ statusOf(drafts[role.role] || '').kind === 'ok' ? money(settings.official) : '—' }}
                            </dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="text-white/40">Премиальные баллы / бонус</dt>
                            <dd class="text-[#22c55e] font-black">
                                {{ bonusLabel(drafts[role.role] || '') }}
                            </dd>
                        </div>
                    </dl>

                    <button type="button"
                            class="w-full py-3 bg-purple-500 hover:bg-purple-400 text-black rounded-xl text-[10px] font-black uppercase tracking-widest disabled:opacity-40"
                            :disabled="busyRole !== null || statusOf(drafts[role.role] || '').kind !== 'ok' || unchanged(role)"
                            @click="save(role)">
                        {{ busyRole === role.role ? 'Сохранение…' : 'Сохранить' }}
                    </button>
                </article>
            </div>
        </div>
    </section>
</template>
