<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3'
import { computed, watch } from 'vue'
import StaffEmployment from '@/Components/StaffEmployment.vue'
import Toast from '@/Components/Toast.vue'
import { useClubName } from '@/Composables/useClubName'
import { useToast } from '@/Composables/useToast'
import AvatarWatermarkBg from '@/Components/AvatarWatermarkBg.vue'

type Employment = {
    required: boolean
    status?: 'draft' | 'review' | 'invited' | 'fire_safety' | 'rejected' | 'approved'
    rejection_reason?: string | null
    appointment_at?: string | null
    rules: Array<{ id: number; title: string; body: string }>
    accepted_ids: number[]
    rules_complete: boolean
    rules_title?: string
    fire_rules?: Array<{ id: number; title: string; body: string }>
    fire_rules_title?: string
    accepted_fire_ids?: number[]
    fire_rules_complete?: boolean
    profile: {
        full_name: string | null
        passport_series: string | null
        passport_number: string | null
        issued_by: string | null
        issued_at: string | null
        department_code: string | null
        birth_date: string | null
        has_scan: boolean
    }
}

const props = defineProps<{
    employment: Employment
}>()

const clubName = useClubName()
const page = usePage()
const { success, error } = useToast()

watch(() => (page.props as any).flash?.success as string | undefined, (msg) => {
    if (msg) success(msg)
}, { immediate: true })
watch(() => (page.props as any).errors?.message as string | undefined, (msg) => {
    if (msg) error(msg)
}, { immediate: true })

const adminName = computed(() => (page.props as any).admin_user?.name || 'Сотрудник')
</script>

<template>
    <Head :title="`${clubName} | Устройство в магазин`" />
    <div class="admin-ui min-h-screen bg-[#020202] font-mono text-white relative">
        <AvatarWatermarkBg />

        <header class="relative z-10 h-20 border-b border-white/5 flex items-center justify-between px-6 md:px-10 bg-[#020202]/70 backdrop-blur-md">
            <div>
                <div class="text-sm font-black uppercase tracking-tight">{{ clubName }} <span class="text-amber-400">Store</span></div>
                <div class="text-[10px] uppercase tracking-widest text-white/35 font-black mt-1">Устройство на работу</div>
            </div>
            <div class="flex items-center gap-4">
                <div class="text-right hidden sm:block">
                    <div class="text-xs font-semibold uppercase">{{ adminName }}</div>
                    <div class="text-[10px] uppercase tracking-widest text-amber-400/80">Магазин</div>
                </div>
                <Link href="/admin/logout" method="post" as="button"
                      class="px-4 py-2 border border-white/10 hover:border-red-500/40 text-white/50 hover:text-red-500 rounded-xl text-xs font-semibold uppercase">
                    Выйти
                </Link>
            </div>
        </header>

        <main class="relative z-10 max-w-4xl mx-auto px-4 py-10">
            <StaffEmployment
                :rules="employment.rules"
                :accepted-ids="employment.accepted_ids"
                :rules-complete="employment.rules_complete"
                :rules-title="employment.rules_title || 'Условия работы в магазине'"
                :profile="employment.profile"
                :status="employment.status || 'draft'"
                :rejection-reason="employment.rejection_reason || null"
                :appointment-at="employment.appointment_at || null"
                :fire-rules="employment.fire_rules || []"
                :fire-rules-title="employment.fire_rules_title || 'Техника пожарной безопасности'"
                :accepted-fire-ids="employment.accepted_fire_ids || []"
                :fire-rules-complete="employment.fire_rules_complete || false"
                subtitle="Правила, анкета, визит в магазин"
                venue="магазине"
                cabinet-hint="кабинет магазина"
                rules-action="/store/hire/rules"
                fire-action="/store/hire/fire-rules"
                hire-action="/store/hire"
            />
        </main>
        <Toast />
    </div>
</template>
