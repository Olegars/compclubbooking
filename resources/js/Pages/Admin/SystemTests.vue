<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { Head } from '@inertiajs/vue3'
import axios from 'axios'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import { useClubName } from '@/Composables/useClubName'

type TestKind = 'live' | 'phpunit'
type TestStatus = 'pass' | 'fail' | 'warn' | 'skip'

type CatalogItem = {
    id: string
    group: string
    group_title: string
    title: string
    description: string
    kind: TestKind
}

type RunResult = {
    id: string
    status: TestStatus
    message: string
    details: string[]
    duration_ms: number
    title?: string
}

const props = defineProps<{
    location: { id: number, name: string, type: string | null } | null
    tests: CatalogItem[]
}>()

const clubName = useClubName()
const runningId = ref<string | null>(null)
const runningAll = ref(false)
const results = reactive<Record<string, RunResult>>({})
const error = ref<string | null>(null)

const groups = computed(() => {
    const map = new Map<string, { id: string, title: string, items: CatalogItem[] }>()
    for (const item of props.tests) {
        if (!map.has(item.group)) {
            map.set(item.group, { id: item.group, title: item.group_title, items: [] })
        }
        map.get(item.group)!.items.push(item)
    }
    return [...map.values()]
})

const liveIds = computed(() => props.tests.filter(t => t.kind === 'live').map(t => t.id))

const summary = computed(() => {
    const counts = { pass: 0, fail: 0, warn: 0, skip: 0, ran: 0 }
    for (const id of Object.keys(results)) {
        const status = results[id]?.status
        if (!status) continue
        counts.ran++
        counts[status]++
    }
    return counts
})

const busy = computed(() => runningId.value !== null || runningAll.value)

const statusLabel = (status?: TestStatus | 'running') => {
    if (status === 'pass') return 'OK'
    if (status === 'fail') return 'Сбой'
    if (status === 'warn') return 'Внимание'
    if (status === 'skip') return 'Пропуск'
    if (status === 'running') return '…'
    return 'Ждёт'
}

const statusClass = (status?: TestStatus | 'running') => {
    if (status === 'pass') return 'border-emerald-500/40 bg-emerald-500/10 text-emerald-300'
    if (status === 'fail') return 'border-red-500/40 bg-red-500/10 text-red-300'
    if (status === 'warn') return 'border-amber-500/40 bg-amber-500/10 text-amber-300'
    if (status === 'skip') return 'border-white/10 bg-white/5 text-white/45'
    if (status === 'running') return 'border-cyan-500/40 bg-cyan-500/10 text-cyan-300'
    return 'border-white/10 bg-black/30 text-white/35'
}

const runOne = async (id: string) => {
    if (busy.value) return
    runningId.value = id
    error.value = null
    try {
        const isPhpunit = id.startsWith('phpunit:')
        const { data } = await axios.post('/admin/system-tests/run', { id }, {
            timeout: isPhpunit ? (id === 'phpunit:all' ? 620000 : 200000) : 45000,
        })
        results[id] = data
    } catch (e: any) {
        const message = e?.response?.data?.message
            || e?.message
            || 'Не удалось выполнить тест'
        results[id] = {
            id,
            status: 'fail',
            message,
            details: [],
            duration_ms: 0,
        }
        error.value = message
    } finally {
        runningId.value = null
    }
}

const runList = async (ids: string[]) => {
    if (busy.value || ids.length === 0) return
    runningAll.value = true
    error.value = null
    try {
        for (const id of ids) {
            runningId.value = id
            try {
                const isPhpunit = id.startsWith('phpunit:')
                const { data } = await axios.post('/admin/system-tests/run', { id }, {
                    timeout: isPhpunit ? 200000 : 45000,
                })
                results[id] = data
            } catch (e: any) {
                results[id] = {
                    id,
                    status: 'fail',
                    message: e?.response?.data?.message || e?.message || 'Ошибка запроса',
                    details: [],
                    duration_ms: 0,
                }
            }
        }
    } finally {
        runningId.value = null
        runningAll.value = false
    }
}

const runGroup = (groupId: string) => {
    const group = groups.value.find(g => g.id === groupId)
    if (!group) return
    if (groupId === 'phpunit') {
        runList(['phpunit:all'])
        return
    }
    runList(group.items.map(item => item.id))
}
</script>

<template>
    <Head :title="`${clubName} | Тесты системы`" />
    <AdminLayout>
        <div class="max-w-7xl mx-auto space-y-8 animate-in fade-in duration-500 font-mono pb-20 px-4">
            <div class="flex flex-col lg:flex-row lg:items-end justify-between gap-6 border-b border-white/10 pb-6">
                <div>
                    <h1 class="text-3xl font-black uppercase italic text-white tracking-tighter">
                        Тесты <span class="text-yellow-400">системы</span>
                    </h1>
                    <p class="text-white/20 text-[10px] uppercase tracking-[0.4em] font-black mt-2 italic">
                        Только владелец · текущая локация
                        <span v-if="location"> · {{ location.name }}</span>
                    </p>
                    <p class="text-white/40 text-xs font-bold mt-3 max-w-2xl">
                        Живые проверки не включают ПК, не бьют чек и не шлют SMS. Автотесты PHPUnit идут в sqlite и прод-базу не трогают.
                    </p>
                </div>
                <div class="flex flex-wrap gap-3">
                    <button type="button"
                            class="px-5 py-3 rounded-2xl text-xs font-black uppercase tracking-widest bg-yellow-500 text-black disabled:opacity-40"
                            :disabled="busy"
                            @click="runList(liveIds)">
                        Все живые проверки
                    </button>
                </div>
            </div>

            <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                <div class="bg-[#0a0a0a] border border-white/5 rounded-2xl px-5 py-4">
                    <div class="text-[10px] text-white/30 uppercase font-black tracking-widest">Прогнано</div>
                    <div class="text-white text-2xl font-black mt-1">{{ summary.ran }}</div>
                </div>
                <div class="bg-[#0a0a0a] border border-emerald-500/20 rounded-2xl px-5 py-4">
                    <div class="text-[10px] text-emerald-500/70 uppercase font-black tracking-widest">OK</div>
                    <div class="text-emerald-300 text-2xl font-black mt-1">{{ summary.pass }}</div>
                </div>
                <div class="bg-[#0a0a0a] border border-amber-500/20 rounded-2xl px-5 py-4">
                    <div class="text-[10px] text-amber-500/70 uppercase font-black tracking-widest">Внимание</div>
                    <div class="text-amber-300 text-2xl font-black mt-1">{{ summary.warn }}</div>
                </div>
                <div class="bg-[#0a0a0a] border border-red-500/20 rounded-2xl px-5 py-4">
                    <div class="text-[10px] text-red-500/70 uppercase font-black tracking-widest">Сбой</div>
                    <div class="text-red-300 text-2xl font-black mt-1">{{ summary.fail }}</div>
                </div>
            </div>

            <p v-if="error" class="text-red-300 text-xs font-bold">{{ error }}</p>

            <section v-for="group in groups" :key="group.id" class="space-y-4">
                <div class="flex items-center justify-between gap-4">
                    <h2 class="text-sm font-black uppercase italic tracking-widest text-white">{{ group.title }}</h2>
                    <button type="button"
                            class="px-4 py-2 rounded-xl text-[10px] font-black uppercase tracking-widest border border-yellow-500/30 text-yellow-300 hover:bg-yellow-500/10 disabled:opacity-40"
                            :disabled="busy"
                            @click="runGroup(group.id)">
                        Группу
                    </button>
                </div>

                <div class="grid grid-cols-1 xl:grid-cols-2 gap-3">
                    <article v-for="item in group.items" :key="item.id"
                             class="bg-[#0a0a0a] border border-white/5 rounded-[1.125rem] p-5 flex flex-col gap-3">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <div class="text-white text-sm font-black uppercase italic">{{ item.title }}</div>
                                <p class="text-white/40 text-xs mt-1">{{ item.description }}</p>
                            </div>
                            <span class="shrink-0 px-2.5 py-1 rounded-lg border text-[10px] font-black uppercase tracking-widest"
                                  :class="statusClass(runningId === item.id ? 'running' : results[item.id]?.status)">
                                {{ statusLabel(runningId === item.id ? 'running' : results[item.id]?.status) }}
                            </span>
                        </div>

                        <div v-if="results[item.id]" class="text-xs font-bold leading-relaxed"
                             :class="{
                                 'text-emerald-300': results[item.id].status === 'pass',
                                 'text-red-300': results[item.id].status === 'fail',
                                 'text-amber-300': results[item.id].status === 'warn',
                                 'text-white/50': results[item.id].status === 'skip',
                             }">
                            {{ results[item.id].message }}
                            <span v-if="results[item.id].duration_ms" class="text-white/30"> · {{ results[item.id].duration_ms }} мс</span>
                        </div>
                        <ul v-if="results[item.id]?.details?.length" class="text-[11px] text-white/35 space-y-1">
                            <li v-for="(line, idx) in results[item.id].details" :key="idx" class="break-all">{{ line }}</li>
                        </ul>

                        <button type="button"
                                class="mt-auto self-start px-4 py-2 rounded-xl text-[10px] font-black uppercase tracking-widest bg-white/5 hover:bg-yellow-500 hover:text-black transition-colors disabled:opacity-40"
                                :disabled="busy"
                                @click="runOne(item.id)">
                            {{ item.kind === 'phpunit' ? 'Запустить автотест' : 'Запустить' }}
                        </button>
                    </article>
                </div>
            </section>
        </div>
    </AdminLayout>
</template>
