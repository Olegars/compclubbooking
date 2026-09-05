<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import AdminConfirm from '@/Components/AdminConfirm.vue'

type SectionDraft = {
    id: number | null
    key: string
    title: string
    body: string
}

type DocumentDraft = {
    id: number | null
    key: string
    title: string
    kind: 'employment' | 'fire_safety'
    is_system: boolean
    sections: SectionDraft[]
}

type DocumentRow = {
    id: number
    title: string
    kind: 'employment' | 'fire_safety'
    slug: string
    is_system: boolean
    sort_order: number
    sections: Array<{ id: number; title: string; body: string; sort_order: number }>
}

const props = defineProps<{
    documents: DocumentRow[]
}>()

const emit = defineEmits<{
    error: [message: string]
}>()

let draftSeq = 0
const nextKey = () => `draft-${++draftSeq}`

const toDrafts = (rows: DocumentRow[]): DocumentDraft[] => rows.map((row) => ({
    id: row.id,
    key: `doc-${row.id}`,
    title: row.title,
    kind: row.kind,
    is_system: row.is_system,
    sections: row.sections.map((section) => ({
        id: section.id,
        key: `sec-${section.id}`,
        title: section.title,
        body: section.body,
    })),
}))

const drafts = ref<DocumentDraft[]>(toDrafts(props.documents))
const busyKey = ref<string | null>(null)
const confirmOpen = ref(false)
const pendingDelete = ref<DocumentDraft | null>(null)

watch(() => props.documents, (rows) => {
    drafts.value = toDrafts(rows)
}, { deep: true })

const kindLabel = (kind: DocumentDraft['kind']) => kind === 'fire_safety'
    ? 'После биометрии'
    : 'При устройстве'

const addSection = (doc: DocumentDraft) => {
    doc.sections.push({
        id: null,
        key: nextKey(),
        title: '',
        body: '',
    })
}

const removeSection = (doc: DocumentDraft, key: string) => {
    if (doc.sections.length <= 1) {
        emit('error', 'В документе должен остаться хотя бы один раздел.')
        return
    }
    doc.sections = doc.sections.filter((section) => section.key !== key)
}

const addDocument = () => {
    drafts.value.push({
        id: null,
        key: nextKey(),
        title: '',
        kind: 'employment',
        is_system: false,
        sections: [{ id: null, key: nextKey(), title: '', body: '' }],
    })
}

const save = (doc: DocumentDraft) => {
    if (busyKey.value) return
    busyKey.value = doc.key
    const payload = {
        title: doc.title,
        kind: doc.kind,
        sections: doc.sections.map((section) => ({
            id: section.id || null,
            title: section.title,
            body: section.body,
        })),
    }
    const options = {
        preserveScroll: true,
        preserveState: false,
        onFinish: () => { busyKey.value = null },
    }
    if (doc.id) {
        router.put(`/admin/config/documents/${doc.id}`, payload, options)
        return
    }
    router.post('/admin/config/documents', payload, options)
}

const askDelete = (doc: DocumentDraft) => {
    if (doc.is_system) return
    if (!doc.id) {
        drafts.value = drafts.value.filter((item) => item.key !== doc.key)
        return
    }
    pendingDelete.value = doc
    confirmOpen.value = true
}

const confirmDelete = () => {
    const doc = pendingDelete.value
    if (!doc?.id || busyKey.value) return
    busyKey.value = doc.key
    router.delete(`/admin/config/documents/${doc.id}`, {
        preserveScroll: true,
        onFinish: () => {
            busyKey.value = null
            confirmOpen.value = false
            pendingDelete.value = null
        },
    })
}

const inputClass = 'mt-2 w-full bg-black/40 border border-white/10 focus:border-cyan-500/50 rounded-2xl px-4 py-3 text-sm text-white outline-none'
const areaClass = `${inputClass} min-h-[140px] resize-y leading-relaxed`
const canAdd = computed(() => !busyKey.value)
</script>

<template>
    <div class="space-y-6">
        <div v-for="doc in drafts" :key="doc.key"
             class="bg-[#0a0a0a] border border-white/5 rounded-[1rem] p-8 space-y-6">
            <div class="flex flex-col md:flex-row md:items-start gap-4">
                <label class="flex-1 block">
                    <span class="text-[10px] uppercase tracking-[0.3em] text-white/40 font-black italic">Документ</span>
                    <input v-model="doc.title" type="text" :class="inputClass" placeholder="Заголовок документа">
                </label>
                <label class="block md:w-56">
                    <span class="text-[10px] uppercase tracking-[0.3em] text-white/40 font-black italic">Когда показывать</span>
                    <select v-model="doc.kind" :disabled="doc.is_system" :class="inputClass">
                        <option value="employment">При устройстве</option>
                        <option value="fire_safety">После биометрии</option>
                    </select>
                    <div class="mt-2 text-[10px] uppercase font-black tracking-widest text-white/25">
                        {{ kindLabel(doc.kind) }}
                    </div>
                </label>
            </div>

            <div class="space-y-4">
                <div v-for="(section, index) in doc.sections" :key="section.key"
                     class="border border-white/10 rounded-2xl p-5 space-y-4">
                    <div class="flex items-center justify-between gap-3">
                        <div class="text-[10px] uppercase tracking-[0.3em] text-white/40 font-black italic">
                            Раздел {{ index + 1 }}
                        </div>
                        <button type="button"
                                class="text-[10px] uppercase font-black tracking-widest text-red-400/70 hover:text-red-400"
                                @click="removeSection(doc, section.key)">
                            Удалить
                        </button>
                    </div>
                    <label class="block">
                        <span class="text-[10px] uppercase tracking-[0.3em] text-white/40 font-black italic">Заголовок</span>
                        <input v-model="section.title" type="text" :class="inputClass" placeholder="Название раздела">
                    </label>
                    <label class="block">
                        <span class="text-[10px] uppercase tracking-[0.3em] text-white/40 font-black italic">Текст</span>
                        <textarea v-model="section.body" :class="areaClass" placeholder="Текст раздела"></textarea>
                    </label>
                </div>
            </div>

            <div class="flex flex-wrap gap-2">
                <button type="button" :disabled="!canAdd"
                        class="px-6 py-4 border border-white/15 text-white rounded-2xl text-[10px] font-black uppercase tracking-widest disabled:opacity-40"
                        @click="addSection(doc)">
                    Добавить
                </button>
                <button type="button" :disabled="busyKey === doc.key"
                        class="px-6 py-4 bg-cyan-500 text-black rounded-2xl text-[10px] font-black uppercase tracking-widest disabled:opacity-40"
                        @click="save(doc)">
                    {{ busyKey === doc.key ? 'Сохранение…' : 'Сохранить' }}
                </button>
                <button v-if="!doc.is_system" type="button" :disabled="busyKey === doc.key"
                        class="px-6 py-4 border border-red-500/30 text-red-400 rounded-2xl text-[10px] font-black uppercase tracking-widest disabled:opacity-40"
                        @click="askDelete(doc)">
                    Удалить документ
                </button>
            </div>
        </div>

        <button type="button" :disabled="!canAdd"
                class="w-full py-5 border border-dashed border-white/15 text-white/60 hover:text-white rounded-[1rem] text-[10px] font-black uppercase tracking-widest disabled:opacity-40"
                @click="addDocument">
            Добавить документ
        </button>
    </div>

    <AdminConfirm
        :is-open="confirmOpen"
        tone="danger"
        title="Удалить документ"
        :message="pendingDelete ? `«${pendingDelete.title || 'Без названия'}» пропадёт с сайта устройства.` : ''"
        confirm-text="Удалить"
        cancel-text="Назад"
        :is-processing="Boolean(busyKey && pendingDelete)"
        @close="confirmOpen = false"
        @confirm="confirmDelete"
    />
</template>
