<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import axios from 'axios'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import { useClubName } from '@/Composables/useClubName'

type TestKind = 'live' | 'phpunit'
type TestStatus = 'pass' | 'fail' | 'warn' | 'skip' | 'running'
type Outcome = 'pass' | 'fail' | 'warn' | 'skip'
type StatusFilter = 'all' | 'ran' | Outcome

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
const pdfBusy = ref(false)
const runCursor = ref(0)
const runTotal = ref(0)
const results = reactive<Record<string, RunResult>>({})
const error = ref<string | null>(null)
const statusFilter = ref<StatusFilter>('all')

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
const phpunitFileIds = computed(() => props.tests
    .filter(t => t.kind === 'phpunit' && t.id !== 'phpunit:all')
    .map(t => t.id))
const allIds = computed(() => [...liveIds.value, ...phpunitFileIds.value])

const outcomeOf = (status?: TestStatus): Outcome | null => {
    if (status === 'pass' || status === 'fail' || status === 'warn' || status === 'skip') {
        return status
    }
    return null
}

const summary = computed(() => {
    const counts = { pass: 0, fail: 0, warn: 0, skip: 0, ran: 0 }
    for (const id of Object.keys(results)) {
        const outcome = outcomeOf(results[id]?.status)
        if (!outcome) continue
        counts[outcome]++
        counts.ran++
    }
    return counts
})

const visibleGroups = computed(() => {
    const filter = statusFilter.value
    return groups.value
        .map((group) => ({
            ...group,
            items: group.items.filter((item) => {
                const outcome = outcomeOf(results[item.id]?.status)
                if (filter === 'all') return true
                if (filter === 'ran') return outcome !== null
                return outcome === filter
            }),
        }))
        .filter((group) => group.items.length > 0)
})

const filterHint = computed(() => {
    if (statusFilter.value === 'all') return ''
    if (statusFilter.value === 'ran') return 'Показаны только прогнанные'
    if (statusFilter.value === 'pass') return 'Показаны только OK'
    if (statusFilter.value === 'warn') return 'Показаны только «Внимание»'
    if (statusFilter.value === 'fail') return 'Показаны только сбои'
    return 'Показаны только пропуски'
})

const toggleFilter = (next: StatusFilter) => {
    statusFilter.value = statusFilter.value === next ? 'all' : next
}

const tileClass = (id: StatusFilter, active: string, idle: string) => {
    const on = statusFilter.value === id
    return [
        'text-left rounded-2xl px-5 py-4 border transition-colors cursor-pointer disabled:cursor-default',
        on ? active : idle,
    ]
}

const busy = computed(() => runningId.value !== null || runningAll.value || pdfBusy.value)

const progressLabel = computed(() => {
    if (!runningAll.value || runTotal.value === 0) return ''
    return `${runCursor.value} / ${runTotal.value}`
})

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

const timeoutFor = (id: string) => {
    if (id === 'phpunit:all') return 620000
    if (id.startsWith('phpunit:')) return 200000
    return 45000
}

const sleep = (ms: number) => new Promise(resolve => setTimeout(resolve, ms))

const requestRun = async (id: string): Promise<RunResult> => {
    const limit = timeoutFor(id)
    const started = Date.now()
    while (true) {
        const { data } = await axios.post('/admin/system-tests/run', { id }, {
            timeout: 25000,
        })
        if (data?.status !== 'running') {
            return data
        }
        results[id] = data
        if (Date.now() - started > limit) {
            return {
                id,
                status: 'fail',
                message: 'Таймаут ожидания PHPUnit. Процесс в фоне мог ещё работать — смотрите storage/logs/owner-phpunit.log',
                details: data?.details ?? [],
                duration_ms: Date.now() - started,
            }
        }
        await sleep(1500)
    }
}

const failFromAxios = (id: string, e: any, fallback: string): RunResult => ({
    id,
    status: 'fail',
    message: e?.response?.data?.message || e?.message || fallback,
    details: [],
    duration_ms: 0,
})

const runOne = async (id: string) => {
    if (busy.value) return
    runningId.value = id
    error.value = null
    try {
        results[id] = await requestRun(id)
    } catch (e: any) {
        const row = failFromAxios(id, e, 'Не удалось выполнить тест')
        results[id] = row
        error.value = row.message
    } finally {
        runningId.value = null
    }
}

const runList = async (ids: string[]) => {
    if (busy.value || ids.length === 0) return
    runningAll.value = true
    runCursor.value = 0
    runTotal.value = ids.length
    error.value = null
    try {
        for (const id of ids) {
            runningId.value = id
            runCursor.value += 1
            try {
                results[id] = await requestRun(id)
            } catch (e: any) {
                results[id] = failFromAxios(id, e, 'Ошибка запроса')
            }
        }
    } finally {
        runningId.value = null
        runningAll.value = false
        runCursor.value = 0
        runTotal.value = 0
    }
}

const runGroup = (groupId: string) => {
    const group = groups.value.find(g => g.id === groupId)
    if (!group) return
    if (groupId === 'phpunit') {
        runList(phpunitFileIds.value)
        return
    }
    runList(group.items.map(item => item.id))
}

const exportPdf = () => {
    if (busy.value || summary.value.ran === 0) return
    pdfBusy.value = true
    error.value = null
    router.post('/admin/system-tests/pdf', {
        results: props.tests.map((item) => {
            const row = results[item.id]
            return {
                id: item.id,
                title: item.title,
                group: item.group,
                group_title: item.group_title,
                kind: item.kind,
                status: row?.status ?? null,
                message: String(row?.message ?? '').slice(0, 4000),
                details: (row?.details ?? []).slice(0, 40).map((line) => String(line).slice(0, 500)),
                duration_ms: row?.duration_ms ?? 0,
            }
        }),
    }, {
        onFinish: () => { pdfBusy.value = false },
    })
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
                        «Все тесты» — живые проверки и PHPUnit по файлам. Автотесты идут в фоне, иначе nginx отвечает 504.
                    </p>
                </div>
                <div class="flex flex-wrap gap-3">
                    <button type="button"
                            class="px-5 py-3 rounded-2xl text-xs font-black uppercase tracking-widest bg-yellow-500 text-black disabled:opacity-40"
                            :disabled="busy"
                            @click="runList(allIds)">
                        {{ runningAll ? `Идёт ${progressLabel}` : 'Запустить все тесты' }}
                    </button>
                    <button type="button"
                            class="px-5 py-3 rounded-2xl text-xs font-black uppercase tracking-widest border border-white/15 text-white/70 hover:border-yellow-500/40 hover:text-yellow-300 disabled:opacity-40"
                            :disabled="busy"
                            @click="runList(liveIds)">
                        Все живые проверки
                    </button>
                    <button type="button"
                            class="px-5 py-3 rounded-2xl text-xs font-black uppercase tracking-widest border border-yellow-500/30 text-yellow-300 hover:bg-yellow-500/10 disabled:opacity-40"
                            :disabled="busy || summary.ran === 0"
                            @click="exportPdf">
                        Вывести результаты в PDF
                    </button>
                </div>
            </div>

            <div class="space-y-2">
                <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-5 gap-3">
                <button type="button"
                        class="bg-[#0a0a0a] px-5 py-4"
                        :class="tileClass('ran', 'border-white/40 bg-white/10', 'border-white/5 hover:border-white/20')"
                        :aria-pressed="statusFilter === 'ran'"
                        @click="toggleFilter('ran')">
                    <div class="text-[10px] text-white/30 uppercase font-black tracking-widest">Прогнано</div>
                    <div class="text-white text-2xl font-black mt-1">{{ summary.ran }}</div>
                    <div class="text-[10px] text-white/25 mt-1 font-bold">OK + внимание + сбой + пропуск</div>
                </button>
                <button type="button"
                        class="bg-[#0a0a0a] px-5 py-4"
                        :class="tileClass('pass', 'border-emerald-400/70 bg-emerald-500/20', 'border-emerald-500/20 hover:border-emerald-400/50')"
                        :aria-pressed="statusFilter === 'pass'"
                        @click="toggleFilter('pass')">
                    <div class="text-[10px] text-emerald-500/70 uppercase font-black tracking-widest">OK</div>
                    <div class="text-emerald-300 text-2xl font-black mt-1">{{ summary.pass }}</div>
                </button>
                <button type="button"
                        class="bg-[#0a0a0a] px-5 py-4"
                        :class="tileClass('warn', 'border-amber-400/70 bg-amber-500/20', 'border-amber-500/20 hover:border-amber-400/50')"
                        :aria-pressed="statusFilter === 'warn'"
                        @click="toggleFilter('warn')">
                    <div class="text-[10px] text-amber-500/70 uppercase font-black tracking-widest">Внимание</div>
                    <div class="text-amber-300 text-2xl font-black mt-1">{{ summary.warn }}</div>
                </button>
                <button type="button"
                        class="bg-[#0a0a0a] px-5 py-4"
                        :class="tileClass('fail', 'border-red-400/70 bg-red-500/20', 'border-red-500/20 hover:border-red-400/50')"
                        :aria-pressed="statusFilter === 'fail'"
                        @click="toggleFilter('fail')">
                    <div class="text-[10px] text-red-500/70 uppercase font-black tracking-widest">Сбой</div>
                    <div class="text-red-300 text-2xl font-black mt-1">{{ summary.fail }}</div>
                </button>
                <button type="button"
                        class="bg-[#0a0a0a] px-5 py-4"
                        :class="tileClass('skip', 'border-white/30 bg-white/10', 'border-white/10 hover:border-white/25')"
                        :aria-pressed="statusFilter === 'skip'"
                        @click="toggleFilter('skip')">
                    <div class="text-[10px] text-white/40 uppercase font-black tracking-widest">Пропуск</div>
                    <div class="text-white/70 text-2xl font-black mt-1">{{ summary.skip }}</div>
                </button>
                </div>
                <p v-if="filterHint" class="text-white/40 text-xs font-bold">
                    {{ filterHint }}
                    <button type="button" class="ml-2 text-yellow-300 uppercase tracking-widest text-[10px] font-black" @click="statusFilter = 'all'">
                        Сбросить
                    </button>
                </p>
            </div>

            <p v-if="error" class="text-red-300 text-xs font-bold">{{ error }}</p>

            <p v-if="statusFilter !== 'all' && visibleGroups.length === 0" class="text-white/40 text-xs font-bold">
                Нет тестов с этим статусом.
            </p>

            <section v-for="group in visibleGroups" :key="group.id" class="space-y-4">
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
