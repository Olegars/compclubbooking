<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { Head, router, useForm, usePage } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import AdminConfirm from '@/Components/AdminConfirm.vue'
import { useClubName } from '@/Composables/useClubName'
import { useToast } from '@/Composables/useToast'

const clubName = useClubName()
const page = usePage()
const { success, error } = useToast()

const props = withDefaults(defineProps<{
    staff: any[]
    can_hire?: boolean
    hire_roles?: Array<{ value: string; label: string }>
    clubs?: Array<{ id: number; name: string }>
    default_club_id?: number | null
}>(), {
    can_hire: false,
    hire_roles: () => [],
    clubs: () => [],
    default_club_id: null,
})

const flashSuccess = computed(() => (page.props as any).flash?.success as string | undefined)
watch(flashSuccess, (msg) => {
    if (msg) success(msg)
}, { immediate: true })

const formatDate = (iso: string | null | undefined) => {
    if (!iso) return '—'
    const date = new Date(iso)
    if (Number.isNaN(date.getTime())) return iso
    return date.toLocaleString('ru-RU', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    })
}

const formatDay = (iso: string | null | undefined) => {
    if (!iso) return '—'
    const date = new Date(iso.length <= 10 ? `${iso}T00:00:00` : iso)
    if (Number.isNaN(date.getTime())) return iso
    return date.toLocaleDateString('ru-RU', {
        day: 'numeric',
        month: 'long',
        year: 'numeric',
    })
}

const hasPassport = (person: any) => {
    const emp = person?.employment
    if (!emp) return false
    return Boolean(
        emp.has_scan
        || emp.passport_series
        || emp.passport_number
        || emp.full_name
        || emp.birth_date
        || emp.issued_by
    )
}

const payTypeLabel = (type: string | null | undefined) => {
    if (type === 'shift') return 'За смену'
    if (type === 'monthly') return 'Оклад'
    return '—'
}

const roleClass = (duty: string, role: string) => {
    if (duty === 'fired') return 'bg-red-500/10 text-red-400 border-red-500/30'
    if (duty === 'active') return 'bg-[#22c55e]/10 text-[#22c55e] border-[#22c55e]/30'
    if (duty === 'review') return 'bg-amber-500/10 text-amber-400 border-amber-500/30'
    if (duty === 'invited') return 'bg-[#22c55e]/10 text-[#22c55e] border-[#22c55e]/30'
    if (duty === 'fire_safety') return 'bg-cyan-500/10 text-cyan-400 border-cyan-500/30'
    if (duty === 'rejected') return 'bg-red-500/10 text-red-400 border-red-500/30'
    if (duty === 'intern') return 'bg-amber-500/10 text-amber-400 border-amber-500/30'
    if (duty === 'inactive') return 'bg-white/5 text-white/40 border-white/10'
    if (role === 'owner') return 'bg-purple-500/10 text-purple-500 border-purple-500/30'
    if (role === 'supervisor') return 'bg-blue-500/10 text-blue-500 border-blue-500/30'
    if (role === 'senior_manager') return 'bg-amber-500/10 text-amber-400 border-amber-500/30'
    if (role === 'store_manager') return 'bg-orange-500/10 text-orange-400 border-orange-500/30'
    if (role === 'assembler') return 'bg-cyan-500/10 text-cyan-400 border-cyan-500/30'
    return 'bg-white/5 text-white/50 border-white/10'
}

const floorRoleOptions = [
    { value: 'admin', label: 'Админ' },
    { value: 'intern', label: 'Стажёр' },
]

const roleTabs = [
    { value: 'admin', label: 'Админ' },
    { value: 'intern', label: 'Стажёр' },
    { value: 'supervisor', label: 'Управляющий' },
    { value: 'owner', label: 'Владелец' },
    { value: 'store_manager', label: 'Менеджер магазина' },
    { value: 'assembler', label: 'Сборщик' },
    { value: 'senior_manager', label: 'Старший менеджер' },
]

const activeTab = ref('all')

const workingStaff = computed(() => props.staff.filter((person) => !person.is_fired))
const firedCount = computed(() => props.staff.filter((person) => person.is_fired).length)
const countByRole = (role: string) => workingStaff.value.filter((person) => person.role === role).length
const pipelineStatuses = ['review', 'invited', 'fire_safety']
const isPipeline = (person: any) => pipelineStatuses.includes(person.employment?.status)
const reviewCount = computed(() => workingStaff.value.filter((person) => isPipeline(person)).length)

const visibleStaff = computed(() => {
    if (activeTab.value === 'fired') {
        return props.staff.filter((person) => person.is_fired)
    }
    if (activeTab.value === 'review') {
        return workingStaff.value.filter((person) => isPipeline(person))
    }
    if (activeTab.value === 'all') return workingStaff.value
    return workingStaff.value.filter((person) => person.role === activeTab.value)
})

const isPendingHire = (person: any) => {
    const status = person.employment?.status
    return status === 'draft' || status === 'review' || status === 'invited' || status === 'fire_safety' || status === 'rejected'
}

const formatAppointment = (iso: string | null | undefined) => {
    if (!iso) return ''
    const date = new Date(iso)
    if (Number.isNaN(date.getTime())) return iso
    return date.toLocaleString('ru-RU', {
        day: 'numeric',
        month: 'long',
        hour: '2-digit',
        minute: '2-digit',
    })
}

const roleBusyId = ref<number | null>(null)

const changeFloorRole = (person: any, role: string) => {
    if (!person?.is_floor_admin || person.role === role || roleBusyId.value) return
    roleBusyId.value = person.id
    router.post(`/admin/staff/${person.id}/role`, { role }, {
        preserveScroll: true,
        onFinish: () => { roleBusyId.value = null },
        onError: (errors) => {
            error((errors as any).role || 'Не удалось сменить роль')
        },
    })
}

const currentAdminId = computed(() => (page.props.admin_user as any)?.id)
const fineTarget = ref<any>(null)
const fineAmount = ref('')
const fineReason = ref('')
const fineProcessing = ref(false)

const openFine = (person: any) => {
    if (person.id === currentAdminId.value) return
    fineTarget.value = person
    fineAmount.value = ''
    fineReason.value = ''
}

const closeFine = () => {
    if (fineProcessing.value) return
    fineTarget.value = null
}

const submitFine = () => {
    if (!fineTarget.value || fineProcessing.value) return
    const amount = Number(fineAmount.value)
    const reason = fineReason.value.trim()
    if (!(amount > 0) || !reason) {
        error('Укажите сумму и причину штрафа')
        return
    }

    fineProcessing.value = true
    router.post(`/admin/staff/${fineTarget.value.id}/fines`, {
        amount,
        reason,
    }, {
        preserveScroll: true,
        onFinish: () => {
            fineProcessing.value = false
        },
        onSuccess: () => {
            fineTarget.value = null
        },
        onError: (errors) => {
            error((errors as any).reason || (errors as any).amount || 'Не удалось выписать штраф')
        },
    })
}

const rejectTarget = ref<any>(null)
const rejectReason = ref('')
const rejectProcessing = ref(false)
const appointmentTarget = ref<any>(null)
const appointmentAt = ref('')
const appointmentProcessing = ref(false)
const biometricsTarget = ref<any>(null)
const biometricsBusy = ref(false)

const openAppointment = (person: any) => {
    if (person.employment?.status !== 'review') return
    const next = new Date()
    next.setDate(next.getDate() + 1)
    next.setHours(12, 0, 0, 0)
    const pad = (n: number) => String(n).padStart(2, '0')
    appointmentAt.value = `${next.getFullYear()}-${pad(next.getMonth() + 1)}-${pad(next.getDate())}T${pad(next.getHours())}:${pad(next.getMinutes())}`
    appointmentTarget.value = person
}

const closeAppointment = () => {
    if (appointmentProcessing.value) return
    appointmentTarget.value = null
}

const submitAppointment = () => {
    if (!appointmentTarget.value || appointmentProcessing.value || !appointmentAt.value) {
        error('Укажите дату и время')
        return
    }
    appointmentProcessing.value = true
    router.post(`/admin/staff/${appointmentTarget.value.id}/employment/appointment`, {
        appointment_at: appointmentAt.value,
    }, {
        preserveScroll: true,
        onFinish: () => { appointmentProcessing.value = false },
        onSuccess: () => { appointmentTarget.value = null },
        onError: (errors) => {
            error((errors as any).appointment_at || (errors as any).employment || 'Не удалось назначить дату')
        },
    })
}

const startBiometrics = (person: any) => {
    if (biometricsBusy.value || person.employment?.status !== 'invited') return
    biometricsTarget.value = person
}

const closeBiometrics = () => {
    if (biometricsBusy.value) return
    biometricsTarget.value = null
}

const confirmBiometrics = () => {
    if (!biometricsTarget.value || biometricsBusy.value) return
    const applicantId = biometricsTarget.value.id
    biometricsBusy.value = true
    window.setTimeout(() => {
        router.post(`/admin/staff/${applicantId}/employment/biometrics`, {}, {
            preserveScroll: true,
            onFinish: () => { biometricsBusy.value = false },
            onSuccess: () => { biometricsTarget.value = null },
            onError: (errors) => {
                error((errors as any).employment || 'Не удалось снять биометрию')
            },
        })
    }, 1200)
}

const openReject = (person: any) => {
    if (!['review', 'invited'].includes(person.employment?.status)) return
    rejectTarget.value = person
    rejectReason.value = ''
}

const closeReject = () => {
    if (rejectProcessing.value) return
    rejectTarget.value = null
}

const submitReject = () => {
    if (!rejectTarget.value || rejectProcessing.value) return
    const reason = rejectReason.value.trim()
    if (reason.length < 5) {
        error('Укажите причину отклонения')
        return
    }

    rejectProcessing.value = true
    router.post(`/admin/staff/${rejectTarget.value.id}/employment/reject`, { reason }, {
        preserveScroll: true,
        onFinish: () => {
            rejectProcessing.value = false
        },
        onSuccess: () => {
            rejectTarget.value = null
        },
        onError: (errors) => {
            error((errors as any).reason || 'Не удалось отклонить анкету')
        },
    })
}

const scanTarget = ref<any>(null)
const scanUrl = computed(() => scanTarget.value ? `/admin/staff/${scanTarget.value.id}/employment/scan` : '')
const scanIsPdf = computed(() => scanTarget.value?.employment?.scan_kind === 'pdf')

const openScan = (person: any) => {
    if (!hasPassport(person)) return
    scanTarget.value = person
}

const closeScan = () => {
    scanTarget.value = null
}

const deleteTarget = ref<any>(null)
const deleteProcessing = ref(false)

const openDelete = (person: any) => {
    if (!person?.can_delete || deleteProcessing.value) return
    deleteTarget.value = person
}

const closeDelete = () => {
    if (deleteProcessing.value) return
    deleteTarget.value = null
}

const confirmDelete = () => {
    if (!deleteTarget.value || deleteProcessing.value) return
    deleteProcessing.value = true
    router.delete(`/admin/staff/${deleteTarget.value.id}`, {
        preserveScroll: true,
        onFinish: () => {
            deleteProcessing.value = false
        },
        onSuccess: () => {
            deleteTarget.value = null
        },
        onError: (errors) => {
            error((errors as any).staff || 'Не удалось удалить сотрудника')
        },
    })
}

const fireTarget = ref<any>(null)
const fireProcessing = ref(false)

const openFire = (person: any) => {
    if (!person?.can_fire || fireProcessing.value) return
    fireTarget.value = person
}

const closeFire = () => {
    if (fireProcessing.value) return
    fireTarget.value = null
}

const confirmFire = () => {
    if (!fireTarget.value || fireProcessing.value) return
    fireProcessing.value = true
    router.post(`/admin/staff/${fireTarget.value.id}/fire`, {}, {
        preserveScroll: true,
        onFinish: () => { fireProcessing.value = false },
        onSuccess: () => { fireTarget.value = null },
        onError: (errors) => {
            error((errors as any).staff || 'Не удалось уволить сотрудника')
        },
    })
}

const restoreBusyId = ref<number | null>(null)
const restoreEmployee = (person: any) => {
    if (!person?.can_restore || restoreBusyId.value) return
    restoreBusyId.value = person.id
    router.post(`/admin/staff/${person.id}/restore`, {}, {
        preserveScroll: true,
        onFinish: () => { restoreBusyId.value = null },
        onError: (errors) => {
            error((errors as any).staff || 'Не удалось вернуть сотрудника')
        },
    })
}

const hireOpen = ref(false)
const defaultRates: Record<string, number> = {
    intern: 1500,
    admin: 2000,
    supervisor: 3000,
    store_manager: 2500,
    assembler: 2200,
    senior_manager: 3500,
}

const hireForm = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
    role: 'intern',
    club_id: props.default_club_id as number | string | null,
    base_rate: 1500 as number | string,
    pay_type: 'shift',
    is_official_employee: false,
})

const openHire = () => {
    if (!props.can_hire) return
    const firstRole = props.hire_roles[0]?.value || 'intern'
    hireForm.reset()
    hireForm.clearErrors()
    hireForm.role = firstRole
    hireForm.club_id = props.default_club_id || props.clubs[0]?.id || null
    hireForm.base_rate = defaultRates[firstRole] ?? 1500
    hireForm.pay_type = firstRole === 'senior_manager' ? 'monthly' : 'shift'
    hireForm.is_official_employee = false
    hireOpen.value = true
}

watch(() => hireForm.role, (role) => {
    if (defaultRates[role] !== undefined) {
        hireForm.base_rate = defaultRates[role]
    }
    hireForm.pay_type = role === 'senior_manager' ? 'monthly' : 'shift'
})

const closeHire = () => {
    if (hireForm.processing) return
    hireOpen.value = false
}

const submitHire = () => {
    hireForm.post('/admin/staff', {
        preserveScroll: true,
        onSuccess: () => {
            hireOpen.value = false
            hireForm.reset()
        },
        onError: (errors) => {
            error((errors as any).email || (errors as any).role || (errors as any).message || 'Не удалось нанять сотрудника')
        },
    })
}

const inputClass = 'mt-2 w-full bg-black/40 border border-white/10 focus:border-purple-500/40 rounded-2xl px-4 py-3 text-sm text-white outline-none'
</script>

<template>
    <Head :title="`${clubName} | Штат`" />
    <AdminLayout>
        <div class="max-w-7xl mx-auto space-y-8 animate-in fade-in duration-500 font-mono pb-20 px-4">

            <div class="flex justify-between items-end mb-10 border-b border-white/10 pb-6">
                <div>
                    <h1 class="text-3xl font-black uppercase italic text-white tracking-tighter">Staff <span class="text-purple-500">Directory</span></h1>
                    <p class="text-white/20 text-[10px] uppercase tracking-[0.4em] font-black mt-2 italic">Управление персоналом и ставками</p>
                </div>
                <button v-if="can_hire" type="button"
                        class="px-6 py-4 bg-purple-500 hover:bg-purple-400 text-black font-black uppercase tracking-widest text-[10px] rounded-2xl"
                        @click="openHire">
                    + Нанять сотрудника
                </button>
            </div>

            <div class="flex flex-wrap gap-2">
                <button type="button"
                        class="px-5 py-3 rounded-xl text-[10px] font-black uppercase tracking-widest"
                        :class="activeTab === 'all' ? 'bg-purple-500 text-black' : 'border border-white/10 text-white/50 hover:text-white'"
                        @click="activeTab = 'all'">
                    Все {{ workingStaff.length }}
                </button>
                <button type="button"
                        class="px-5 py-3 rounded-xl text-[10px] font-black uppercase tracking-widest"
                        :class="activeTab === 'review' ? 'bg-amber-400 text-black' : 'border border-amber-500/30 text-amber-400/80 hover:text-amber-300'"
                        @click="activeTab = 'review'">
                    На проверке {{ reviewCount }}
                </button>
                <button type="button"
                        class="px-5 py-3 rounded-xl text-[10px] font-black uppercase tracking-widest"
                        :class="activeTab === 'fired' ? 'bg-red-500 text-black' : 'border border-red-500/30 text-red-400/80 hover:text-red-300'"
                        @click="activeTab = 'fired'">
                    Уволенные {{ firedCount }}
                </button>
                <button v-for="tab in roleTabs" :key="tab.value" type="button"
                        class="px-5 py-3 rounded-xl text-[10px] font-black uppercase tracking-widest"
                        :class="activeTab === tab.value ? 'bg-purple-500 text-black' : 'border border-white/10 text-white/50 hover:text-white'"
                        @click="activeTab = tab.value">
                    {{ tab.label }} {{ countByRole(tab.value) }}
                </button>
            </div>

            <div v-if="visibleStaff.length === 0" class="py-20 text-center">
                <div class="text-white/10 text-xl font-black uppercase tracking-widest italic mb-2">Нет сотрудников</div>
                <div class="text-white/30 text-[10px] uppercase tracking-widest">
                    {{ activeTab === 'review' ? 'Анкет на проверке нет' : (activeTab === 'fired' ? 'Уволенных нет' : 'В этой роли пока никого нет') }}
                </div>
            </div>

            <div v-else class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                <div v-for="person in visibleStaff" :key="person.id"
                     class="bg-[#050505] border border-white/5 rounded-[0.875rem] p-8 relative group hover:border-purple-500/30 transition-all shadow-xl">

                    <div class="absolute top-6 right-6 text-[9px] uppercase font-black tracking-widest px-3 py-1 rounded-full border"
                         :class="roleClass(person.duty, person.role)">
                        {{ person.duty_label || person.role_label || person.role }}
                    </div>

                    <div class="flex items-center gap-4 mb-8">
                        <div class="w-14 h-14 rounded-2xl bg-white/5 border border-white/10 flex items-center justify-center text-xl font-black text-white group-hover:bg-purple-500/20 group-hover:text-purple-500 transition-colors">
                            {{ person.name.charAt(0) }}
                        </div>
                        <div>
                            <div class="text-white font-black uppercase tracking-tight text-lg">{{ person.name }}</div>
                            <div class="text-[10px] text-white/30 mt-1">{{ person.email }}</div>
                        </div>
                    </div>

                    <div class="space-y-4 pt-6 border-t border-white/5">
                        <div class="flex justify-between items-center text-xs">
                            <span class="text-white/30 uppercase font-black tracking-widest text-[9px]">Должность</span>
                            <span class="text-white font-bold text-[11px]">{{ person.role_label || person.role }}</span>
                        </div>
                        <div class="flex justify-between items-center text-xs">
                            <span class="text-white/30 uppercase font-black tracking-widest text-[9px]">Локация</span>
                            <span class="text-white font-bold text-[11px]">{{ person.club_name || (person.role === 'owner' ? 'Все' : '—') }}</span>
                        </div>
                        <div class="flex justify-between items-center text-xs">
                            <span class="text-white/30 uppercase font-black tracking-widest text-[9px]">Оформление</span>
                            <span class="text-white font-bold text-[11px]">{{ person.is_official_employee ? 'ТК РФ' : 'ИП / Неофициально' }}</span>
                        </div>
                        <div class="flex justify-between items-center text-xs">
                            <span class="text-white/30 uppercase font-black tracking-widest text-[9px]">Ставка</span>
                            <span class="text-white font-black">{{ formatMoney(person.base_rate) }}</span>
                        </div>
                        <div class="flex justify-between items-center text-xs">
                            <span class="text-white/30 uppercase font-black tracking-widest text-[9px]">Тип оплаты</span>
                            <span class="text-white font-black uppercase text-[11px]">{{ payTypeLabel(person.pay_type) }}</span>
                        </div>
                        <div class="flex justify-between items-center text-xs">
                            <span class="text-white/30 uppercase font-black tracking-widest text-[9px]">Начислено</span>
                            <span class="text-white font-black">{{ formatMoney(person.accrued_total) }}</span>
                        </div>
                        <div class="flex justify-between items-center text-xs">
                            <span class="text-white/30 uppercase font-black tracking-widest text-[9px]">Штрафы / убытки</span>
                            <span class="font-black" :class="Number(person.fines_total) > 0 ? 'text-red-400' : 'text-white'">{{ formatMoney(person.fines_total) }}</span>
                        </div>
                        <div class="flex justify-between items-center text-xs">
                            <span class="text-white/30 uppercase font-black tracking-widest text-[9px]">К выводу</span>
                            <span class="text-[#22c55e] font-black">{{ formatMoney(person.available) }}</span>
                        </div>
                        <div class="flex justify-between items-center text-xs">
                            <span class="text-white/30 uppercase font-black tracking-widest text-[9px]">В штате с</span>
                            <span class="text-white font-bold text-[11px]">{{ formatDate(person.hired_at || person.created_at) }}</span>
                        </div>
                        <div v-if="person.fired_at" class="flex justify-between items-center text-xs">
                            <span class="text-white/30 uppercase font-black tracking-widest text-[9px]">Уволен</span>
                            <span class="text-red-400 font-bold text-[11px]">{{ formatDate(person.fired_at) }}</span>
                        </div>
                    </div>

                    <div v-if="person.employment" class="mt-6 space-y-3 pt-6 border-t border-white/5">
                        <div class="text-[9px] text-white/30 uppercase font-black tracking-widest">Документы</div>
                        <div class="text-white text-xs font-bold">{{ person.employment.full_name || person.name }}</div>
                        <div class="text-white/50 text-[11px] font-bold space-y-1">
                            <div>Статус: {{ person.employment.status_label || person.employment.status }}</div>
                            <div v-if="person.employment.birth_date">Дата рождения {{ formatDay(person.employment.birth_date) }}</div>
                            <div v-if="person.employment.passport_series || person.employment.passport_number">
                                Паспорт {{ person.employment.passport_series }} {{ person.employment.passport_number }}
                            </div>
                            <div v-if="person.employment.issued_by">{{ person.employment.issued_by }}</div>
                            <div v-if="person.employment.issued_at || person.employment.department_code">
                                Выдан {{ person.employment.issued_at ? formatDay(person.employment.issued_at) : '—' }}
                                <span v-if="person.employment.department_code"> · код {{ person.employment.department_code }}</span>
                            </div>
                            <div v-if="person.employment.submitted_at">Анкета {{ formatDate(person.employment.submitted_at) }}</div>
                            <div v-if="person.employment.appointment_at">Визит {{ formatAppointment(person.employment.appointment_at) }}</div>
                            <div v-if="person.employment.biometrics_captured_at">Биометрия {{ formatDate(person.employment.biometrics_captured_at) }}</div>
                            <div v-if="person.employment.reviewer_name">Проверил {{ person.employment.reviewer_name }}</div>
                            <div v-if="person.employment.rejection_reason" class="text-red-400">{{ person.employment.rejection_reason }}</div>
                        </div>
                        <button v-if="hasPassport(person)"
                                type="button"
                                class="w-full py-3 border border-amber-500/30 hover:border-amber-400/60 text-amber-400 rounded-xl text-[10px] font-black uppercase tracking-widest"
                                @click="openScan(person)">
                            {{ person.employment.has_scan ? 'Открыть паспорт' : 'Паспортные данные' }}
                        </button>
                    </div>

                    <div v-if="isPipeline(person) && !person.is_fired" class="mt-6 space-y-3 pt-6 border-t border-amber-500/20">
                        <div class="text-[9px] text-amber-400 uppercase font-black tracking-widest">Анкета устройства</div>
                        <div v-if="person.employment.status === 'review'" class="flex gap-2">
                            <button type="button"
                                    class="flex-1 py-3 bg-[#22c55e] hover:bg-[#1ea34d] text-black rounded-xl text-[10px] font-black uppercase tracking-widest"
                                    @click="openAppointment(person)">
                                Назначить дату
                            </button>
                            <button type="button"
                                    class="flex-1 py-3 border border-red-500/30 text-red-400 rounded-xl text-[10px] font-black uppercase tracking-widest"
                                    @click="openReject(person)">
                                Отклонить
                            </button>
                        </div>
                        <div v-else-if="person.employment.status === 'invited'" class="flex gap-2 pt-2">
                            <button type="button"
                                    :disabled="biometricsBusy"
                                    class="flex-1 py-3 bg-cyan-500 hover:bg-cyan-400 text-black rounded-xl text-[10px] font-black uppercase tracking-widest disabled:opacity-40"
                                    @click="startBiometrics(person)">
                                Биометрия
                            </button>
                            <button type="button"
                                    class="flex-1 py-3 border border-red-500/30 text-red-400 rounded-xl text-[10px] font-black uppercase tracking-widest"
                                    @click="openReject(person)">
                                Отклонить
                            </button>
                        </div>
                        <div v-else class="pt-2 text-[10px] uppercase font-black tracking-widest text-white/40">
                            Ждём согласие с правилами ПБ
                        </div>
                    </div>

                    <button v-if="person.id !== currentAdminId && !isPendingHire(person) && !person.is_fired"
                            type="button"
                            @click="openFine(person)"
                            class="mt-6 w-full py-3 border border-red-500/20 hover:border-red-500/50 text-red-400/80 hover:text-red-400 rounded-xl text-[10px] font-black uppercase tracking-widest transition-all">
                        Штраф
                    </button>

                    <div v-if="person.is_floor_admin && !isPendingHire(person) && !person.is_fired" class="mt-3">
                        <label class="text-[9px] text-white/30 uppercase font-black tracking-widest">Должность</label>
                        <select
                            class="mt-2 w-full bg-black/40 border border-white/10 rounded-xl px-3 py-2.5 text-[11px] font-black uppercase tracking-wider text-white outline-none"
                            :value="person.role"
                            :disabled="roleBusyId === person.id"
                            @change="changeFloorRole(person, ($event.target as HTMLSelectElement).value)"
                        >
                            <option v-for="opt in floorRoleOptions" :key="opt.value" :value="opt.value">
                                {{ opt.label }}
                            </option>
                        </select>
                    </div>

                    <button v-if="person.can_fire"
                            type="button"
                            @click="openFire(person)"
                            class="mt-3 w-full py-3 border border-red-500/20 hover:border-red-500/50 text-red-400/80 hover:text-red-400 rounded-xl text-[10px] font-black uppercase tracking-widest transition-all">
                        Уволить сотрудника
                    </button>

                    <button v-if="person.can_restore"
                            type="button"
                            :disabled="restoreBusyId === person.id"
                            @click="restoreEmployee(person)"
                            class="mt-3 w-full py-3 border border-[#22c55e]/30 hover:border-[#22c55e]/60 text-[#22c55e] rounded-xl text-[10px] font-black uppercase tracking-widest transition-all disabled:opacity-40">
                        Вернуть на работу
                    </button>

                    <button v-if="person.can_delete"
                            type="button"
                            @click="openDelete(person)"
                            class="mt-3 w-full py-3 border border-white/10 hover:border-red-500/50 text-white/40 hover:text-red-400 rounded-xl text-[10px] font-black uppercase tracking-widest transition-all">
                        Удалить сотрудника
                    </button>
                </div>
            </div>

        </div>

        <div v-if="scanTarget" class="fixed inset-0 z-[99999] flex items-center justify-center p-4 font-mono">
            <div class="absolute inset-0 bg-black/85 backdrop-blur-sm" @click="closeScan"></div>
            <div class="relative z-10 w-full max-w-4xl max-h-[92vh] flex flex-col bg-[#0a0a0a] border border-amber-500/30 rounded-[1rem] p-5 shadow-[0_0_80px_rgba(245,158,11,0.12)]">
                <div class="flex items-start justify-between gap-4 mb-4">
                    <div>
                        <h3 class="text-xl font-black uppercase italic tracking-tighter text-white">Паспорт</h3>
                        <p class="text-[10px] uppercase tracking-widest text-white/40 font-black mt-1">{{ scanTarget.name }}</p>
                    </div>
                    <button type="button"
                            class="px-4 py-2 border border-white/10 text-white/50 hover:text-white rounded-xl text-[10px] font-black uppercase tracking-widest"
                            @click="closeScan">
                        Закрыть
                    </button>
                </div>
                <div v-if="scanTarget.employment" class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-4 text-[11px] font-bold">
                    <div>
                        <div class="text-[9px] uppercase tracking-widest text-white/30 font-black">ФИО</div>
                        <div class="text-white mt-1">{{ scanTarget.employment.full_name || scanTarget.name }}</div>
                    </div>
                    <div>
                        <div class="text-[9px] uppercase tracking-widest text-white/30 font-black">Дата рождения</div>
                        <div class="text-white mt-1">{{ formatDay(scanTarget.employment.birth_date) }}</div>
                    </div>
                    <div>
                        <div class="text-[9px] uppercase tracking-widest text-white/30 font-black">Серия и номер</div>
                        <div class="text-white mt-1">{{ scanTarget.employment.passport_series }} {{ scanTarget.employment.passport_number }}</div>
                    </div>
                    <div>
                        <div class="text-[9px] uppercase tracking-widest text-white/30 font-black">Код подразделения</div>
                        <div class="text-white mt-1">{{ scanTarget.employment.department_code || '—' }}</div>
                    </div>
                    <div class="sm:col-span-2">
                        <div class="text-[9px] uppercase tracking-widest text-white/30 font-black">Кем выдан</div>
                        <div class="text-white mt-1">{{ scanTarget.employment.issued_by || '—' }}</div>
                    </div>
                    <div>
                        <div class="text-[9px] uppercase tracking-widest text-white/30 font-black">Дата выдачи</div>
                        <div class="text-white mt-1">{{ formatDay(scanTarget.employment.issued_at) }}</div>
                    </div>
                </div>
                <div class="min-h-[40vh] flex-1 overflow-auto rounded-2xl border border-white/10 bg-black/60">
                    <iframe v-if="scanTarget.employment?.has_scan && scanIsPdf"
                            :src="scanUrl"
                            title="Скан паспорта"
                            class="w-full h-[70vh] border-0 rounded-2xl bg-white"></iframe>
                    <img v-else-if="scanTarget.employment?.has_scan"
                         :src="scanUrl"
                         alt="Скан паспорта"
                         class="mx-auto max-h-[70vh] w-auto max-w-full object-contain">
                    <div v-else class="h-[30vh] flex items-center justify-center text-white/30 text-[10px] uppercase font-black tracking-widest">
                        Скан не загружен
                    </div>
                </div>
            </div>
        </div>

        <div v-if="fineTarget" class="fixed inset-0 z-[99998] flex items-center justify-center p-4 font-mono">
            <div class="absolute inset-0 bg-black/80 backdrop-blur-sm" @click="closeFine"></div>
            <div class="relative z-10 w-full max-w-md bg-[#0a0a0a] border border-red-500/30 rounded-[1rem] p-8 shadow-[0_0_80px_rgba(239,68,68,0.12)]">
                <h3 class="text-xl font-black uppercase italic tracking-tighter text-white mb-2">Штраф</h3>
                <p class="text-[10px] uppercase tracking-widest text-white/40 font-black mb-6">{{ fineTarget.name }}</p>

                <label class="text-[10px] text-white/30 uppercase font-black tracking-widest">Сумма, ₽</label>
                <input v-model="fineAmount"
                       type="number"
                       min="0.01"
                       step="0.01"
                       class="mt-2 mb-5 w-full bg-black/40 border border-white/10 focus:border-red-500/40 rounded-2xl px-4 py-3 text-white font-black outline-none">

                <label class="text-[10px] text-white/30 uppercase font-black tracking-widest">За что</label>
                <textarea v-model="fineReason"
                          rows="3"
                          maxlength="255"
                          placeholder="Опоздание, недостача кассы…"
                          class="mt-2 mb-6 w-full bg-black/40 border border-white/10 focus:border-red-500/40 rounded-2xl px-4 py-3 text-white text-sm outline-none resize-none"></textarea>

                <div class="flex gap-3">
                    <button type="button" @click="closeFine"
                            class="flex-1 py-4 bg-white/5 border border-white/10 text-white/50 hover:text-white rounded-xl text-[10px] font-black uppercase tracking-widest">
                        Отмена
                    </button>
                    <button type="button" @click="submitFine" :disabled="fineProcessing"
                            class="flex-1 py-4 bg-red-500 hover:bg-red-400 text-black rounded-xl text-[10px] font-black uppercase tracking-widest disabled:opacity-40">
                        Начислить
                    </button>
                </div>
            </div>
        </div>

        <div v-if="appointmentTarget" class="fixed inset-0 z-[99998] flex items-center justify-center p-4 font-mono">
            <div class="absolute inset-0 bg-black/80 backdrop-blur-sm" @click="closeAppointment"></div>
            <div class="relative z-10 w-full max-w-md bg-[#0a0a0a] border border-[#22c55e]/30 rounded-[1rem] p-8 shadow-[0_0_80px_rgba(34,197,94,0.12)]">
                <h3 class="text-xl font-black uppercase italic tracking-tighter text-white mb-2">Назначить дату</h3>
                <p class="text-[10px] uppercase tracking-widest text-white/40 font-black mb-6">{{ appointmentTarget.name }}</p>
                <p class="text-white/50 text-xs font-bold mb-5">Кандидат придёт в клуб подписать документы в это время.</p>

                <label class="text-[10px] text-white/30 uppercase font-black tracking-widest">Дата и время</label>
                <input v-model="appointmentAt"
                       type="datetime-local"
                       class="mt-2 mb-6 w-full bg-black/40 border border-white/10 focus:border-[#22c55e]/40 rounded-2xl px-4 py-3 text-white font-black outline-none">

                <div class="flex gap-3">
                    <button type="button" @click="closeAppointment"
                            class="flex-1 py-4 bg-white/5 border border-white/10 text-white/50 hover:text-white rounded-xl text-[10px] font-black uppercase tracking-widest">
                        Отмена
                    </button>
                    <button type="button" @click="submitAppointment" :disabled="appointmentProcessing"
                            class="flex-1 py-4 bg-[#22c55e] hover:bg-[#1ea34d] text-black rounded-xl text-[10px] font-black uppercase tracking-widest disabled:opacity-40">
                        Назначить
                    </button>
                </div>
            </div>
        </div>

        <div v-if="biometricsTarget" class="fixed inset-0 z-[99998] flex items-center justify-center p-4 font-mono">
            <div class="absolute inset-0 bg-black/80 backdrop-blur-sm" @click="closeBiometrics"></div>
            <div class="relative z-10 w-full max-w-md bg-[#0a0a0a] border border-cyan-500/30 rounded-[1rem] p-8 shadow-[0_0_80px_rgba(6,182,212,0.12)]">
                <h3 class="text-xl font-black uppercase italic tracking-tighter text-white mb-2">Биометрия</h3>
                <p class="text-[10px] uppercase tracking-widest text-white/40 font-black mb-4">{{ biometricsTarget.name }}</p>
                <p class="text-white/50 text-xs font-bold mb-5">Попросите кандидата посмотреть в камеру клуба. Съёмка пока заглушка.</p>

                <div class="relative mb-6 overflow-hidden rounded-2xl border border-cyan-500/30 bg-black aspect-video">
                    <div class="absolute inset-6 border border-cyan-400/40 rounded-xl"></div>
                    <div class="absolute left-8 right-8 h-px bg-cyan-400/80 animate-pulse"
                         :class="biometricsBusy ? 'top-1/2' : 'top-8'"></div>
                    <div class="absolute inset-0 flex items-center justify-center">
                        <div class="text-[10px] uppercase font-black tracking-widest"
                             :class="biometricsBusy ? 'text-cyan-300' : 'text-white/30'">
                            {{ biometricsBusy ? 'Съёмка…' : 'Камера клуба' }}
                        </div>
                    </div>
                </div>

                <div class="flex gap-3">
                    <button type="button" @click="closeBiometrics" :disabled="biometricsBusy"
                            class="flex-1 py-4 bg-white/5 border border-white/10 text-white/50 hover:text-white rounded-xl text-[10px] font-black uppercase tracking-widest disabled:opacity-40">
                        Отмена
                    </button>
                    <button type="button" @click="confirmBiometrics" :disabled="biometricsBusy"
                            class="flex-1 py-4 bg-cyan-500 hover:bg-cyan-400 text-black rounded-xl text-[10px] font-black uppercase tracking-widest disabled:opacity-40">
                        {{ biometricsBusy ? 'Снимаем…' : 'Снять' }}
                    </button>
                </div>
            </div>
        </div>

        <div v-if="rejectTarget" class="fixed inset-0 z-[99998] flex items-center justify-center p-4 font-mono">
            <div class="absolute inset-0 bg-black/80 backdrop-blur-sm" @click="closeReject"></div>
            <div class="relative z-10 w-full max-w-md bg-[#0a0a0a] border border-red-500/30 rounded-[1rem] p-8 shadow-[0_0_80px_rgba(239,68,68,0.12)]">
                <h3 class="text-xl font-black uppercase italic tracking-tighter text-white mb-2">Отклонить анкету</h3>
                <p class="text-[10px] uppercase tracking-widest text-white/40 font-black mb-6">{{ rejectTarget.name }}</p>

                <label class="text-[10px] text-white/30 uppercase font-black tracking-widest">Причина</label>
                <textarea v-model="rejectReason"
                          rows="4"
                          maxlength="500"
                          placeholder="Что нужно исправить…"
                          class="mt-2 mb-6 w-full bg-black/40 border border-white/10 focus:border-red-500/40 rounded-2xl px-4 py-3 text-white text-sm outline-none resize-none"></textarea>

                <div class="flex gap-3">
                    <button type="button" @click="closeReject"
                            class="flex-1 py-4 bg-white/5 border border-white/10 text-white/50 hover:text-white rounded-xl text-[10px] font-black uppercase tracking-widest">
                        Отмена
                    </button>
                    <button type="button" @click="submitReject" :disabled="rejectProcessing"
                            class="flex-1 py-4 bg-red-500 hover:bg-red-400 text-black rounded-xl text-[10px] font-black uppercase tracking-widest disabled:opacity-40">
                        Отклонить
                    </button>
                </div>
            </div>
        </div>

        <AdminConfirm
            :is-open="!!deleteTarget"
            title="Удалить сотрудника"
            :message="deleteTarget ? `${deleteTarget.name} потеряет доступ. Смены в архиве останутся.` : ''"
            confirm-text="Удалить"
            cancel-text="Отмена"
            :is-processing="deleteProcessing"
            @close="closeDelete"
            @confirm="confirmDelete"
        />

        <AdminConfirm
            :is-open="!!fireTarget"
            title="Уволить сотрудника"
            :message="fireTarget ? `${fireTarget.name} останется в штате, но вход будет закрыт.` : ''"
            confirm-text="Уволить"
            cancel-text="Отмена"
            :is-processing="fireProcessing"
            @close="closeFire"
            @confirm="confirmFire"
        />

        <div v-if="hireOpen" class="fixed inset-0 z-[99998] flex items-center justify-center p-4 font-mono">
            <div class="absolute inset-0 bg-black/80 backdrop-blur-sm" @click="closeHire"></div>
            <div class="relative z-10 w-full max-w-lg max-h-[90vh] overflow-y-auto bg-[#0a0a0a] border border-purple-500/30 rounded-[1rem] p-8 shadow-[0_0_80px_rgba(168,85,247,0.12)]">
                <h3 class="text-xl font-black uppercase italic tracking-tighter text-white mb-2">Нанять сотрудника</h3>
                <p class="text-[10px] uppercase tracking-widest text-white/40 font-black mb-6">Доступ сразу, без анкеты устройства</p>

                <form class="space-y-4" @submit.prevent="submitHire">
                    <label class="block">
                        <span class="text-[10px] text-white/30 uppercase font-black tracking-widest">ФИО</span>
                        <input v-model="hireForm.name" type="text" :class="inputClass" placeholder="Иванов Иван">
                        <p v-if="hireForm.errors.name" class="text-red-400 text-[10px] uppercase font-black mt-2">{{ hireForm.errors.name }}</p>
                    </label>
                    <label class="block">
                        <span class="text-[10px] text-white/30 uppercase font-black tracking-widest">Email</span>
                        <input v-model="hireForm.email" type="email" :class="inputClass" placeholder="staff@club.local">
                        <p v-if="hireForm.errors.email" class="text-red-400 text-[10px] uppercase font-black mt-2">{{ hireForm.errors.email }}</p>
                    </label>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <label class="block">
                            <span class="text-[10px] text-white/30 uppercase font-black tracking-widest">Пароль</span>
                            <input v-model="hireForm.password" type="password" :class="inputClass">
                            <p v-if="hireForm.errors.password" class="text-red-400 text-[10px] uppercase font-black mt-2">{{ hireForm.errors.password }}</p>
                        </label>
                        <label class="block">
                            <span class="text-[10px] text-white/30 uppercase font-black tracking-widest">Повтор пароля</span>
                            <input v-model="hireForm.password_confirmation" type="password" :class="inputClass">
                        </label>
                    </div>
                    <label class="block">
                        <span class="text-[10px] text-white/30 uppercase font-black tracking-widest">Должность</span>
                        <select v-model="hireForm.role" :class="inputClass">
                            <option v-for="opt in hire_roles" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                        </select>
                        <p v-if="hireForm.errors.role" class="text-red-400 text-[10px] uppercase font-black mt-2">{{ hireForm.errors.role }}</p>
                    </label>
                    <label v-if="clubs.length > 1" class="block">
                        <span class="text-[10px] text-white/30 uppercase font-black tracking-widest">Локация</span>
                        <select v-model="hireForm.club_id" :class="inputClass">
                            <option v-for="club in clubs" :key="club.id" :value="club.id">{{ club.name }}</option>
                        </select>
                        <p v-if="hireForm.errors.club_id" class="text-red-400 text-[10px] uppercase font-black mt-2">{{ hireForm.errors.club_id }}</p>
                    </label>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <label class="block">
                            <span class="text-[10px] text-white/30 uppercase font-black tracking-widest">Ставка, ₽</span>
                            <input v-model="hireForm.base_rate" type="number" min="0" step="0.01" :class="inputClass">
                            <p v-if="hireForm.errors.base_rate" class="text-red-400 text-[10px] uppercase font-black mt-2">{{ hireForm.errors.base_rate }}</p>
                        </label>
                        <label class="block">
                            <span class="text-[10px] text-white/30 uppercase font-black tracking-widest">Тип оплаты</span>
                            <select v-model="hireForm.pay_type" :class="inputClass">
                                <option value="shift">За смену</option>
                                <option value="monthly">Оклад</option>
                            </select>
                        </label>
                    </div>
                    <label class="flex items-center gap-3 text-[10px] uppercase font-black tracking-widest text-white/50">
                        <input v-model="hireForm.is_official_employee" type="checkbox" class="rounded border-white/20 bg-black">
                        Оформлен по ТК РФ
                    </label>

                    <div class="flex gap-3 pt-2">
                        <button type="button" @click="closeHire"
                                class="flex-1 py-4 bg-white/5 border border-white/10 text-white/50 hover:text-white rounded-xl text-[10px] font-black uppercase tracking-widest">
                            Отмена
                        </button>
                        <button type="submit" :disabled="hireForm.processing"
                                class="flex-1 py-4 bg-purple-500 hover:bg-purple-400 text-black rounded-xl text-[10px] font-black uppercase tracking-widest disabled:opacity-40">
                            {{ hireForm.processing ? 'Сохранение…' : 'Нанять' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </AdminLayout>
</template>

