<script setup lang="ts">
import { computed, watch } from 'vue'
import { Head, usePage } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import ClubDocumentsEditor from '@/Components/ClubDocumentsEditor.vue'
import { useClubName } from '@/Composables/useClubName'
import { useToast } from '@/Composables/useToast'

const clubName = useClubName()
const page = usePage()
const { success, error } = useToast()

type DocumentRow = {
    id: number
    title: string
    kind: 'employment' | 'fire_safety'
    slug: string
    is_system: boolean
    sort_order: number
    sections: Array<{ id: number; title: string; body: string; sort_order: number }>
}

defineProps<{
    documents: DocumentRow[]
}>()

const flashSuccess = computed(() => (page.props as any).flash?.success as string | undefined)
watch(flashSuccess, (msg) => { if (msg) success(msg) }, { immediate: true })
watch(() => (page.props as any).errors as Record<string, string> | undefined, (errors) => {
    if (!errors) return
    const first = errors.message || Object.values(errors)[0]
    if (first) error(String(first))
}, { immediate: true })
</script>

<template>
    <Head :title="`${clubName} | Документы`" />
    <AdminLayout>
        <div class="max-w-3xl mx-auto space-y-8 animate-in fade-in duration-500 font-mono pb-20 px-4">
            <div class="bg-[#0a0a0a] border border-white/5 p-8 rounded-[1rem] shadow-2xl">
                <h1 class="text-3xl font-black uppercase italic text-cyan-400 tracking-tighter">
                    Документы
                </h1>
                <p class="text-white/20 text-[10px] uppercase tracking-[0.4em] font-black mt-2 italic">
                    Тексты на сайте устройства
                </p>
                <p class="text-white/50 text-xs font-bold mt-4">
                    Кандидат видит свёрнутые заголовки. Клик раскрывает один блок, внутри — «Принимаю».
                    Кнопка «Добавить» создаёт новый раздел.
                </p>
            </div>

            <ClubDocumentsEditor :documents="documents" @error="error" />
        </div>
    </AdminLayout>
</template>
