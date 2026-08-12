<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { Head, router, useForm } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'

const props = defineProps<{
    locations: any[]
}>()

const typeLabel: Record<string, string> = {
    club: 'Только клуб',
    store: 'Только магазин',
    both: 'Клуб + магазин',
}

const showCreate = ref(false)
const createForm = useForm({
    name: '',
    slug: '',
    type: 'both',
    address: '',
})

const editForms = reactive<Record<number, { name: string, type: string, address: string }>>({})
const savingId = ref<number | null>(null)

onMounted(() => {
    for (const loc of props.locations) {
        editForms[loc.id] = {
            name: loc.name,
            type: loc.type || 'club',
            address: loc.address || '',
        }
    }
})

const create = () => {
    createForm.post('/admin/store/locations', {
        onSuccess: () => { showCreate.value = false; createForm.reset(); createForm.type = 'both' },
    })
}

const save = (id: number) => {
    const data = editForms[id]
    if (!data) return
    savingId.value = id
    router.put(`/admin/store/locations/${id}`, data, {
        preserveScroll: true,
        onFinish: () => { savingId.value = null },
    })
}
</script>

<template>
    <Head title="REACTOR | Локации" />
    <AdminLayout>
        <div class="max-w-5xl mx-auto space-y-8 pb-20 px-4 font-mono">
            <div class="flex justify-between items-end border-b border-white/10 pb-6">
                <div>
                    <h1 class="text-3xl font-black uppercase italic tracking-tighter">Locations</h1>
                    <p class="text-white/20 text-[10px] uppercase tracking-[0.4em] font-black mt-2 italic">Клуб / магазин / оба</p>
                </div>
                <button @click="showCreate = true"
                        class="px-6 py-4 bg-amber-500 text-black font-black uppercase tracking-widest text-[10px] rounded-2xl">
                    + Локация
                </button>
            </div>

            <div class="space-y-4">
                <div v-for="loc in locations" :key="loc.id" class="border border-white/5 bg-[#080808] rounded-2xl p-6 space-y-4">
                    <div class="text-[10px] uppercase tracking-widest text-white/30">ID {{ loc.id }} · {{ loc.slug }}</div>
                    <template v-if="editForms[loc.id]">
                        <input v-model="editForms[loc.id].name" class="w-full bg-black border border-white/10 rounded-xl px-4 py-3 text-sm" />
                        <select v-model="editForms[loc.id].type" class="w-full bg-black border border-white/10 rounded-xl px-4 py-3 text-sm">
                            <option value="club">{{ typeLabel.club }}</option>
                            <option value="both">{{ typeLabel.both }}</option>
                            <option value="store">{{ typeLabel.store }}</option>
                        </select>
                        <input v-model="editForms[loc.id].address" placeholder="Адрес" class="w-full bg-black border border-white/10 rounded-xl px-4 py-3 text-sm" />
                        <button @click="save(loc.id)" class="px-5 py-3 bg-amber-500 text-black text-[10px] uppercase font-black rounded-xl" :disabled="savingId === loc.id">
                            Сохранить
                        </button>
                    </template>
                </div>
            </div>
        </div>

        <div v-if="showCreate" class="fixed inset-0 bg-black/70 flex items-center justify-center z-50 p-4" @click.self="showCreate = false">
            <form class="bg-[#0a0a0a] border border-white/10 rounded-3xl p-8 w-full max-w-md space-y-4" @submit.prevent="create">
                <h3 class="font-black uppercase italic text-xl">Новая локация</h3>
                <input v-model="createForm.name" placeholder="Название" class="w-full bg-black border border-white/10 rounded-xl px-4 py-3 text-sm" required />
                <input v-model="createForm.slug" placeholder="slug (опционально)" class="w-full bg-black border border-white/10 rounded-xl px-4 py-3 text-sm" />
                <select v-model="createForm.type" class="w-full bg-black border border-white/10 rounded-xl px-4 py-3 text-sm">
                    <option value="both">{{ typeLabel.both }}</option>
                    <option value="club">{{ typeLabel.club }}</option>
                    <option value="store">{{ typeLabel.store }}</option>
                </select>
                <input v-model="createForm.address" placeholder="Адрес" class="w-full bg-black border border-white/10 rounded-xl px-4 py-3 text-sm" />
                <div class="flex gap-3 justify-end">
                    <button type="button" class="px-4 py-3 text-[10px] uppercase font-black text-white/40" @click="showCreate = false">Отмена</button>
                    <button class="px-6 py-3 bg-amber-500 text-black text-[10px] uppercase font-black rounded-xl" :disabled="createForm.processing">Создать</button>
                </div>
            </form>
        </div>
    </AdminLayout>
</template>
