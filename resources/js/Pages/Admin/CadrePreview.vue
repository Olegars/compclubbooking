<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import { useClubName } from '@/Composables/useClubName'

const props = defineProps<{
    event: {
        event_label: string
        name: string | null
        order_number: string
        order_date: string | null
        event_date: string | null
        okz_code: string
        work_function_title: string
        part_time_code: string | null
        deadline_label: string
        status_label: string
    }
    full_name: string | null
    snils: string | null
    inn: string | null
    birth_date: string | null
    gender: string | null
    passport: string
    issued_by: string | null
    issued_at: string | null
    department_code: string | null
    fire_reason: string | null
    employer: string | null
}>()

const clubName = useClubName()
const printCard = () => window.print()
</script>

<template>
    <Head :title="`${clubName} | Карточка ЕФС-1`" />
    <AdminLayout>
        <div class="max-w-3xl mx-auto font-mono px-4 pb-16 space-y-6">
            <div class="flex items-center justify-between print:hidden">
                <Link href="/admin/taxes/cadre" class="text-[10px] uppercase font-black tracking-widest text-white/40">Назад</Link>
                <button type="button" class="px-5 py-3 bg-white text-black rounded-xl text-[10px] font-black uppercase tracking-widest" @click="printCard">
                    Печать
                </button>
            </div>
            <article class="bg-white text-black rounded-[1rem] p-8 space-y-4">
                <div class="text-[10px] uppercase tracking-[0.3em] font-black">{{ employer || clubName }}</div>
                <h1 class="text-2xl font-black uppercase">{{ event.event_label }} · ЕФС-1</h1>
                <p class="text-sm">{{ event.status_label }}. Сдать {{ event.deadline_label }}.</p>
                <dl class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
                    <div><dt class="text-[10px] uppercase tracking-widest text-black/40">ФИО</dt><dd class="font-black mt-1">{{ full_name }}</dd></div>
                    <div><dt class="text-[10px] uppercase tracking-widest text-black/40">СНИЛС</dt><dd class="font-black mt-1">{{ snils || '—' }}</dd></div>
                    <div><dt class="text-[10px] uppercase tracking-widest text-black/40">ИНН</dt><dd class="font-black mt-1">{{ inn || '—' }}</dd></div>
                    <div><dt class="text-[10px] uppercase tracking-widest text-black/40">Дата рождения</dt><dd class="font-black mt-1">{{ birth_date || '—' }}</dd></div>
                    <div><dt class="text-[10px] uppercase tracking-widest text-black/40">Пол</dt><dd class="font-black mt-1">{{ gender || '—' }}</dd></div>
                    <div><dt class="text-[10px] uppercase tracking-widest text-black/40">Паспорт</dt><dd class="font-black mt-1">{{ passport || '—' }}</dd></div>
                    <div class="sm:col-span-2"><dt class="text-[10px] uppercase tracking-widest text-black/40">Кем выдан</dt><dd class="font-black mt-1">{{ issued_by || '—' }} · {{ issued_at || '' }} · {{ department_code || '' }}</dd></div>
                    <div><dt class="text-[10px] uppercase tracking-widest text-black/40">Приказ</dt><dd class="font-black mt-1">{{ event.order_number }} от {{ event.order_date }}</dd></div>
                    <div><dt class="text-[10px] uppercase tracking-widest text-black/40">Функция / ОКЗ</dt><dd class="font-black mt-1">{{ event.work_function_title }} · {{ event.okz_code }}</dd></div>
                    <div><dt class="text-[10px] uppercase tracking-widest text-black/40">Режим</dt><dd class="font-black mt-1">{{ event.part_time_code || 'полная ставка' }}</dd></div>
                    <div v-if="fire_reason" class="sm:col-span-2"><dt class="text-[10px] uppercase tracking-widest text-black/40">Основание</dt><dd class="font-black mt-1">{{ fire_reason }}</dd></div>
                </dl>
            </article>
        </div>
    </AdminLayout>
</template>
