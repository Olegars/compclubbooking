<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import axios from 'axios'
import AdminLayout from '@/Layouts/AdminLayout.vue'

// --- ПРОПСЫ ---
const props = defineProps<{
    clubId?: number,
    initialConfig?: any,
    initialPcs?: any[],
    clubs?: { id: number, name: string }[]
    topologyZones?: { id: number, name: string, slug: string, color: string }[]
}>()

// --- СОСТОЯНИЕ ---
const mode = ref<'walls' | 'zones' | 'labels' | 'pcs' | 'erase'>('walls')
const viewbox = ref('-10 -10 120 200')
const svgRef = ref<SVGSVGElement | null>(null)
const gridSize = ref(2 / 3)
const isMagnetOn = ref(true)

const clubList = computed(() => {
    if (props.clubs && props.clubs.length > 0) return props.clubs;
    return [{ id: 1, name: 'Main Sector' }];
});
const activeClubId = ref<number | null>(null)

const walls = ref<any[]>([])
const zones = ref<any[]>([])
const labels = ref<any[]>([])
const computers = ref<any[]>([])

const currentPoints = ref<any[]>([])
const isDragging = ref(false)
const dragTarget = ref<any>(null)
const draftZone = ref({ x: 0, y: 0, w: 0, h: 0 })
const startDragPos = ref({ x: 0, y: 0 })

const selectedLabel = ref<any>(null)
const selectedPc = ref<any>(null)
const currentSeatKind = ref<'pc' | 'tv' | 'ps5'>('pc')

const seatKindOptions = [
    { id: 'pc', label: 'ПК', color: '#06b6d4', prefix: 'PC' },
    { id: 'tv', label: 'ТВ', color: '#a855f7', prefix: 'TV' },
    { id: 'ps5', label: 'PS5', color: '#3b82f6', prefix: 'PS' },
] as const

const seatStroke = (kind?: string) => {
    if (kind === 'tv') return '#a855f7'
    if (kind === 'ps5') return '#3b82f6'
    return '#06b6d4'
}

const nextSeatName = (kind: 'pc' | 'tv' | 'ps5') => {
    const prefix = seatKindOptions.find(o => o.id === kind)?.prefix || 'PC'
    let max = 0
    for (const pc of computers.value) {
        if ((pc.kind || 'pc') !== kind) continue
        const match = String(pc.name || '').match(/(\d+)\s*$/)
        if (match) max = Math.max(max, Number(match[1]))
    }
    return `${prefix}-${String(max + 1).padStart(2, '0')}`
}

/** Общий booth_id для маркеров внутри одной zoneRect. */
const boothIdForPoint = (x: number, y: number) => {
    const zone = zones.value.find(z =>
        x >= Number(z.x) && x <= Number(z.x) + Number(z.w)
        && y >= Number(z.y) && y <= Number(z.y) + Number(z.h)
    )
    if (!zone) return null
    return `booth-${Math.round(Number(zone.x))}-${Math.round(Number(zone.y))}`
}

const topologyZones = computed(() => props.topologyZones || [])
const currentZoneType = ref(topologyZones.value[0]?.slug || '')
const currentZoneColor = ref(topologyZones.value[0]?.color || '#22c55e')
const selectedTopologyZone = computed(() =>
    topologyZones.value.find(z => z.slug === currentZoneType.value) || null
)

watch(currentZoneType, (slug) => {
    const zone = topologyZones.value.find(z => z.slug === slug)
    if (zone?.color) currentZoneColor.value = zone.color
})

watch(topologyZones, (list) => {
    if (!list.length) {
        currentZoneType.value = ''
        return
    }
    if (!list.some(z => z.slug === currentZoneType.value)) {
        currentZoneType.value = list[0].slug
        currentZoneColor.value = list[0].color || '#22c55e'
    }
}, { immediate: true })

const isSaving = ref(false)
const isLoading = ref(false)

// В режимах рисования клики должны проходить сквозь уже нарисованные объекты,
// иначе нельзя поставить текст/зону поверх существующей зоны.
const isLayerInteractive = (type: 'wall' | 'zone' | 'label' | 'pc') => {
    if (mode.value === 'erase') return true
    if (mode.value === 'pcs') return type === 'pc'
    if (mode.value === 'labels') return type === 'label'
    // walls / zones — только холст принимает события (рисование поверх)
    return false
}

// --- БЕЗОПАСНЫЕ ПАРСЕРЫ (ЗАЩИТА ОТ КРАША VUE) ---
const safeNum = (val: any, def = 0) => {
    const n = Number(val);
    return isNaN(n) ? def : n;
};

const cleanArray = (arr: any) => {
    if (!arr) return [];
    const items = Array.isArray(arr) ? arr : Object.values(arr);
    return items.filter(item => item !== null && item !== undefined);
};

// --- ФУНКЦИИ СЕТКИ ---
// Магнит тянет к крупным линиям; если далеко от них — к мелкой сетке.
const snap = (val: number) => {
    const major = majorStep.value
    const fine = gridSize.value
    const nearestMajor = Math.round(val / major) * major
    if (Math.abs(val - nearestMajor) <= major * 0.45) {
        return nearestMajor
    }
    return Math.round(val / fine) * fine
}
const softRound = (val: number) => Math.round(val * 2) / 2

const majorStep = computed(() => gridSize.value * 5)

const viewboxBox = computed(() => {
    const parts = String(viewbox.value).trim().split(/[\s,]+/).map(Number)
    const [x, y, w, h] = parts
    return {
        x: Number.isFinite(x) ? x : -10,
        y: Number.isFinite(y) ? y : -10,
        w: Number.isFinite(w) ? w : 120,
        h: Number.isFinite(h) ? h : 200,
    }
})

// По краю: X — верхний ряд, Y — левый столбец.
// Клетки 1,2,3…; первую не подписываем, дальше 2-3-4-… до конца сетки.
const edgeLabelsX = computed(() => {
    const step = majorStep.value
    const { x, y, w } = viewboxBox.value
    const start = Math.floor(x / step) * step
    const end = x + w
    const labels: { x: number; y: number; value: number }[] = []
    let index = 0
    for (let cx = start; cx < end - 0.0001; cx += step) {
        index += 1
        if (index < 2) continue
        labels.push({
            x: cx + step / 2,
            y: y + step * 0.2,
            value: index,
        })
    }
    return labels
})

const edgeLabelsY = computed(() => {
    const step = majorStep.value
    const { x, y, h } = viewboxBox.value
    const start = Math.floor(y / step) * step
    const end = y + h
    const labels: { x: number; y: number; value: number }[] = []
    let index = 0
    for (let cy = start; cy < end - 0.0001; cy += step) {
        index += 1
        if (index < 2) continue
        labels.push({
            // Самый левый край первой видимой клетки
            x: x + step * 0.08,
            y: cy + step / 2,
            value: index,
        })
    }
    return labels
})

const chessCells = computed(() => {
    const step = majorStep.value
    const { x, y, w, h } = viewboxBox.value
    const x0 = Math.floor(x / step) * step
    const y0 = Math.floor(y / step) * step
    const cells: { x: number; y: number; dark: boolean }[] = []
    for (let cy = y0; cy < y + h; cy += step) {
        for (let cx = x0; cx < x + w; cx += step) {
            const ix = Math.round(cx / step)
            const iy = Math.round(cy / step)
            cells.push({ x: cx, y: cy, dark: ((ix + iy) % 2 + 2) % 2 === 0 })
        }
    }
    return cells
})

const axisLabelSize = computed(() => Math.max(1.5, majorStep.value * 0.35))


const getSVGPoint = (evt: MouseEvent) => {
    if (!svgRef.value) return { x: 0, y: 0 }
    const pt = svgRef.value.createSVGPoint()
    pt.x = evt.clientX
    pt.y = evt.clientY
    const cursorPt = pt.matrixTransform(svgRef.value.getScreenCTM()!.inverse())

    return {
        x: isMagnetOn.value ? snap(cursorPt.x) : softRound(cursorPt.x),
        y: isMagnetOn.value ? snap(cursorPt.y) : softRound(cursorPt.y)
    }
}

// --- ОБРАБОТЧИКИ МЫШИ ---
const handleItemMouseDown = (e: MouseEvent, item: any, type: 'zone' | 'wall' | 'pc' | 'label') => {
    e.stopPropagation(); // Блокируем всплытие клика!

    if (mode.value === 'erase') {
        if (type === 'zone') zones.value = zones.value.filter(z => z !== item);
        if (type === 'wall') walls.value = walls.value.filter(w => w !== item);
        if (type === 'pc') computers.value = computers.value.filter(p => p !== item);
        if (type === 'label') labels.value = labels.value.filter(l => l !== item);
        return;
    }

    if (mode.value === 'pcs' && type === 'pc') {
        dragTarget.value = item;
        selectedPc.value = item;
        selectedLabel.value = null;
    } else if (mode.value === 'labels' && type === 'label') {
        dragTarget.value = item;
        selectedLabel.value = item;
        selectedPc.value = null;
    }
}

const handleSvgMouseDown = (e: MouseEvent) => {
    const pt = getSVGPoint(e)
    selectedLabel.value = null;
    selectedPc.value = null;

    if (mode.value === 'labels') {
        const newLabel = { x: pt.x, y: pt.y, content: 'ТЕКСТ', rotate: 0, size: 6, color: '#ffffff' };
        labels.value.push(newLabel);
        // Выделяем только что созданный текст для редактирования в панели справа
        selectedLabel.value = labels.value[labels.value.length - 1];
        return;
    }

    if (mode.value === 'pcs') {
        const kind = currentSeatKind.value
        const boothId = (kind === 'tv' || kind === 'ps5') ? boothIdForPoint(pt.x, pt.y) : null
        const pc = {
            id: Date.now() + Math.random(),
            name: nextSeatName(kind),
            x: pt.x,
            y: pt.y,
            kind,
            booth_id: boothId,
        }
        computers.value.push(pc)
        selectedPc.value = computers.value[computers.value.length - 1]
        return
    }

    if (mode.value === 'walls') {
        currentPoints.value.push(pt)
    } else if (mode.value === 'zones') {
        if (!currentZoneType.value || !topologyZones.value.length) return
        isDragging.value = true
        startDragPos.value = pt
        draftZone.value = { x: pt.x, y: pt.y, w: 0, h: 0 }
    }
}

const handleMouseMove = (e: MouseEvent) => {
    const pt = getSVGPoint(e)
    if (dragTarget.value) {
        dragTarget.value.x = pt.x; dragTarget.value.y = pt.y
    } else if (isDragging.value && mode.value === 'zones') {
        draftZone.value.x = Math.min(startDragPos.value.x, pt.x)
        draftZone.value.y = Math.min(startDragPos.value.y, pt.y)
        draftZone.value.w = Math.abs(pt.x - startDragPos.value.x)
        draftZone.value.h = Math.abs(pt.y - startDragPos.value.y)
    }
}

const handleMouseUp = () => {
    if (mode.value === 'zones' && isDragging.value && draftZone.value.w > 0 && currentZoneType.value) {
        const color = selectedTopologyZone.value?.color || currentZoneColor.value
        zones.value.push({
            ...draftZone.value,
            c: color,
            type: currentZoneType.value,
        })
    }
    if (dragTarget.value && (dragTarget.value.kind === 'tv' || dragTarget.value.kind === 'ps5')) {
        dragTarget.value.booth_id = boothIdForPoint(
            Number(dragTarget.value.x),
            Number(dragTarget.value.y),
        )
    }
    isDragging.value = false; dragTarget.value = null
}

const finishWall = () => {
    if (currentPoints.value.length > 2) {
        const d = `M${currentPoints.value.map(p => `${p.x},${p.y}`).join(' L')} Z`
        walls.value.push({ d });
        currentPoints.value = []
    }
}

const syncWithGizmo = () => {
    const externalPcs = [
        { name: 'PC-01' }, { name: 'PC-02' }, { name: 'PC-03' }, { name: 'PC-04' }, { name: 'PC-05' },
        { name: 'PRO-01' }, { name: 'PRO-02' }, { name: 'PRO-03' },
        { name: 'VIP-01' }, { name: 'VIP-02' }, { name: 'STREAM-01' }
    ]
    let addedCount = 0;
    externalPcs.forEach(gizmoPc => {
        if (!computers.value.some(pc => pc.name === gizmoPc.name)) {
            computers.value.push({
                id: Date.now() + Math.random(),
                name: gizmoPc.name,
                x: 50,
                y: 50,
                kind: 'pc',
                booth_id: null,
            });
            addedCount++;
        }
    });
    alert(addedCount > 0 ? `Добавлено новых ПК: ${addedCount}` : 'Все ПК уже на карте');
}

// --- БД: БРОНЕБОЙНАЯ ЗАГРУЗКА ---
const loadFromDB = async () => {
    if (!activeClubId.value) return;

    isLoading.value = true;
    walls.value = []; zones.value = []; labels.value = []; computers.value = [];

    try {
        const { data } = await axios.get(`/admin/get-map?club_id=${activeClubId.value}`);
        let rawConfig = data.config || {};

        while (typeof rawConfig === 'string') {
            try { rawConfig = JSON.parse(rawConfig); }
            catch(e) { break; }
        }

        if (rawConfig) {
            walls.value = cleanArray(rawConfig.walls).filter(w => w && w.d);
            zones.value = cleanArray(rawConfig.zoneRects).filter(z => z && z.w !== undefined);
            labels.value = cleanArray(rawConfig.labels).filter(l => l && l.content && String(l.content).trim().toUpperCase() !== 'ТЕКСТ' && String(l.content).trim().toUpperCase() !== 'TEXT');
            if (rawConfig.viewbox) viewbox.value = rawConfig.viewbox;
        }

        computers.value = cleanArray(data.pcs).filter(pc => pc && pc.name).map(pc => ({
            ...pc,
            kind: pc.kind || 'pc',
            booth_id: pc.booth_id || null,
        }));

    } catch (e) {
        console.error("Ошибка загрузки карты с сервера:", e);
    } finally {
        isLoading.value = false;
        selectedPc.value = null;
        selectedLabel.value = null;
    }
}

const saveToDB = async () => {
    isSaving.value = true
    try {
        await axios.post('/admin/save-map', {
            club_id: activeClubId.value,
            config: {
                walls: walls.value,
                zoneRects: zones.value,
                labels: labels.value.filter(l => {
                    const t = String(l?.content ?? '').trim().toUpperCase()
                    return t && t !== 'ТЕКСТ' && t !== 'TEXT'
                }),
                viewbox: viewbox.value,
            },
            pcs: computers.value.map(pc => ({
                name: pc.name,
                x: pc.x,
                y: pc.y,
                kind: pc.kind || 'pc',
                booth_id: pc.booth_id || null,
            }))
        });
        alert('Данные карты успешно сохранены!');
    } catch (e) {
        console.error(e);
        alert('Ошибка при сохранении карты.');
    } finally {
        isSaving.value = false
    }
}

const resetMap = () => {
    if (confirm('Очистить карту? Это действие нельзя отменить без перезагрузки страницы.')) {
        walls.value = []; zones.value = []; labels.value = []; computers.value = [];
        currentPoints.value = [];
    }
}

const generatedJson = computed(() => JSON.stringify({ walls: walls.value, zoneRects: zones.value, labels: labels.value, viewbox: viewbox.value }, null, 2))

onMounted(() => {
    if (props.clubs && props.clubs.length > 0) {
        activeClubId.value = props.clubs[0].id;
    } else {
        activeClubId.value = 1;
    }
    loadFromDB();
})
</script>

<template>
    <AdminLayout>
        <div class="h-[calc(100vh-8rem)] min-h-[600px] flex flex-col bg-[#050505] border border-white/5 rounded-[2.5rem] overflow-hidden shadow-2xl animate-in fade-in duration-500 font-mono text-white relative">

            <div v-if="isLoading" class="absolute inset-0 bg-black/70 backdrop-blur-md z-[999] flex items-center justify-center">
                <div class="flex flex-col items-center gap-4">
                    <div class="w-16 h-16 border-4 border-cyan-500/20 border-t-cyan-500 rounded-full animate-spin"></div>
                    <span class="text-cyan-500 text-xs tracking-widest uppercase font-black animate-pulse">СИНХРОНИЗАЦИЯ...</span>
                </div>
            </div>

            <header class="h-16 border-b border-white/10 flex justify-between items-center px-6 bg-[#0a0a0a] z-50 shrink-0">
                <div class="flex gap-4 items-center">
                    <div class="flex bg-white/5 p-1 rounded-xl border border-white/10">
                        <button v-for="m in [{id: 'walls', n: 'Стены'}, {id: 'zones', n: 'Зоны'}, {id: 'labels', n: 'Текст'}, {id: 'pcs', n: 'ПК'}, {id: 'erase', n: 'Ластик'}]"
                                :key="m.id" @click="mode = m.id as any"
                                :class="['px-4 py-1.5 text-[10px] font-black uppercase rounded-lg transition-all', mode === m.id ? (m.id === 'erase' ? 'bg-red-500 text-black' : 'bg-cyan-500 text-black shadow-[0_0_10px_rgba(6,182,212,0.4)]') : 'text-white/40 hover:text-white']">
                            {{ m.n }}
                        </button>
                    </div>

                    <div v-if="mode === 'zones'" class="flex items-center gap-3 ml-2 px-4 border-l border-white/10">
                        <template v-if="topologyZones.length">
                            <select v-model="currentZoneType"
                                    class="bg-black border border-white/10 text-cyan-500 font-bold text-[10px] py-1.5 px-3 rounded-lg uppercase outline-none focus:border-cyan-500"
                                    :style="{ borderColor: currentZoneColor + '66', color: currentZoneColor }">
                                <option v-for="z in topologyZones" :key="z.id" :value="z.slug">{{ z.name }}</option>
                            </select>
                            <div class="w-6 h-6 rounded-full border-2 border-white/40 shrink-0"
                                 :style="{ backgroundColor: currentZoneColor, boxShadow: `0 0 10px ${currentZoneColor}66` }"
                                 :title="selectedTopologyZone ? `${selectedTopologyZone.name} (${selectedTopologyZone.slug})` : ''"></div>
                            <span class="text-[9px] text-white/30 font-mono uppercase tracking-widest hidden xl:inline">
                                {{ selectedTopologyZone?.slug }}
                            </span>
                        </template>
                        <span v-else class="text-[10px] text-amber-400/90 font-black uppercase tracking-widest">
                            Сначала добавьте зоны в «Топология залов»
                        </span>
                    </div>

                    <button @click="isMagnetOn = !isMagnetOn"
                            :class="['px-4 py-1.5 text-[10px] font-black uppercase rounded-lg transition-colors border ml-2 shrink-0', isMagnetOn ? 'bg-cyan-500/20 border-cyan-500 text-cyan-400' : 'border-white/10 text-white/40 hover:text-white']">
                        🧲 {{ isMagnetOn ? 'МАГНИТ' : 'СВОБОДНО' }}
                    </button>

                    <button v-if="mode === 'walls'"
                            @click="finishWall"
                            :disabled="currentPoints.length <= 2"
                            class="shrink-0 bg-blue-600/20 border border-blue-500 text-blue-400 px-4 py-1.5 text-[10px] font-black uppercase rounded-lg hover:bg-blue-600 hover:text-white transition-all disabled:opacity-30 disabled:hover:bg-blue-600/20 disabled:hover:text-blue-400">
                        Замкнуть контур
                        <span v-if="currentPoints.length" class="ml-1 opacity-70">({{ currentPoints.length }})</span>
                    </button>
                    <button v-if="mode === 'pcs'" @click="syncWithGizmo" class="bg-purple-500/20 text-purple-400 border border-purple-500/30 px-4 py-1.5 text-[10px] uppercase font-black rounded-lg hover:bg-purple-500 hover:text-white transition-all shrink-0">🔄 GIZMO SYNC</button>
                    <div v-if="mode === 'pcs'" class="flex items-center gap-2 ml-2 px-3 border-l border-white/10 shrink-0">
                        <button v-for="opt in seatKindOptions" :key="opt.id"
                                @click="currentSeatKind = opt.id"
                                :class="['px-3 py-1.5 text-[10px] font-black uppercase rounded-lg border transition-all',
                                         currentSeatKind === opt.id ? 'text-black' : 'text-white/50 border-white/10 hover:text-white']"
                                :style="currentSeatKind === opt.id
                                    ? { backgroundColor: opt.color, borderColor: opt.color }
                                    : { borderColor: opt.color + '55', color: opt.color }">
                            {{ opt.label }}
                        </button>
                        <span class="text-[9px] text-white/30 font-black uppercase tracking-widest hidden xl:inline">клик = поставить</span>
                    </div>
                </div>

                <div class="flex gap-4 shrink-0">
                    <button @click="resetMap" class="text-red-500 text-[10px] font-black uppercase px-4 py-2 rounded-lg hover:bg-red-500/10 transition-colors">Сброс</button>
                    <button @click="saveToDB" :disabled="isSaving" class="bg-cyan-500 hover:bg-cyan-400 text-black px-8 py-2 text-xs font-black uppercase rounded-lg shadow-[0_0_15px_rgba(6,182,212,0.3)] disabled:opacity-30 transition-all">Сохранить</button>
                </div>
            </header>

            <div class="flex-1 flex overflow-hidden">
                <main class="flex-1 bg-[#020202] relative overflow-auto p-4 custom-scrollbar">
                    <svg ref="svgRef" :viewBox="viewbox" preserveAspectRatio="xMinYMin meet" overflow="hidden"
                         class="w-[150%] h-[200vh] border border-white/5 rounded-2xl bg-black"
                         @mousedown="handleSvgMouseDown" @mousemove="handleMouseMove" @mouseup="handleMouseUp" @mouseleave="handleMouseUp()" @dblclick="finishWall">
                        <defs>
                            <pattern id="smallGrid" :width="gridSize" :height="gridSize" patternUnits="userSpaceOnUse">
                                <path :d="`M ${gridSize} 0 L 0 0 0 ${gridSize}`" fill="none" stroke="rgba(6, 182, 212, 0.1)" stroke-width="0.1"/>
                            </pattern>
                            <pattern id="grid" :width="majorStep" :height="majorStep" patternUnits="userSpaceOnUse">
                                <rect :width="majorStep" :height="majorStep" fill="url(#smallGrid)"/>
                                <path :d="`M ${majorStep} 0 L 0 0 0 ${majorStep}`" fill="none" stroke="rgba(6, 182, 212, 0.28)" stroke-width="0.25"/>
                            </pattern>
                            <clipPath id="mapViewboxClip">
                                <rect :x="viewboxBox.x" :y="viewboxBox.y" :width="viewboxBox.w" :height="viewboxBox.h" />
                            </clipPath>
                        </defs>

                        <g clip-path="url(#mapViewboxClip)">
                        <!-- Сетка только внутри viewBox — иначе при letterbox слева появляются «лишние» клетки -->
                        <rect :x="viewboxBox.x" :y="viewboxBox.y" :width="viewboxBox.w" :height="viewboxBox.h" fill="url(#grid)" class="pointer-events-none" />

                        <!-- Шахматная заливка крупных клеток -->
                        <g class="pointer-events-none" opacity="0.55">
                            <rect v-for="(cell, i) in chessCells" :key="'ch'+i"
                                  :x="cell.x" :y="cell.y" :width="majorStep" :height="majorStep"
                                  :fill="cell.dark ? 'rgba(6,182,212,0.07)' : 'rgba(255,255,255,0.015)'" />
                        </g>

                        <!-- Цифры по краю: сверху весь ряд, слева у самого края -->
                        <g class="pointer-events-none" style="font-family: ui-monospace, monospace;">
                            <text v-for="tick in edgeLabelsX" :key="'xt'+tick.value"
                                  :x="tick.x" :y="tick.y"
                                  :font-size="axisLabelSize" fill="#67e8f9" fill-opacity="0.95"
                                  font-weight="800" text-anchor="middle" dominant-baseline="middle">{{ tick.value }}</text>
                            <text v-for="tick in edgeLabelsY" :key="'yt'+tick.value"
                                  :x="tick.x" :y="tick.y"
                                  :font-size="axisLabelSize" fill="#a5f3fc" fill-opacity="0.95"
                                  font-weight="800" text-anchor="start" dominant-baseline="middle">{{ tick.value }}</text>
                        </g>

                        <!-- Порядок слоёв снизу вверх: стены → зоны → текст → ПК -->
                        <g class="layer-walls">
                            <path v-for="(w, i) in walls" :key="'w'+i" :d="w.d"
                                  :class="['transition-colors hover:stroke-white', isLayerInteractive('wall') ? 'cursor-pointer' : 'pointer-events-none']"
                                  @mousedown.stop="isLayerInteractive('wall') && handleItemMouseDown($event, w, 'wall')"
                                  fill="rgba(6,182,212,0.02)" stroke="#06b6d4" stroke-width="0.2" stroke-linejoin="miter" />
                        </g>

                        <g class="layer-zones">
                            <g v-for="(z, i) in zones" :key="'z'+i"
                               :class="isLayerInteractive('zone') ? '' : 'pointer-events-none'"
                               @mousedown.stop="isLayerInteractive('zone') && handleItemMouseDown($event, z, 'zone')">
                                <rect :x="safeNum(z.x)" :y="safeNum(z.y)" :width="safeNum(z.w)" :height="safeNum(z.h)"
                                      :fill="z.c || '#22c55e'" :fill-opacity="z.c === '#4d4d4d' ? 0.8 : 0.2"
                                      :stroke="z.c || '#22c55e'" stroke-width="0.15"
                                      :class="['transition-opacity', isLayerInteractive('zone') ? 'hover:fill-opacity-50 cursor-pointer' : '']" />
                            </g>
                        </g>

                        <g class="layer-labels">
                            <text v-for="(l, i) in labels" :key="'l'+i" :x="safeNum(l.x)" :y="safeNum(l.y)"
                                  :class="[
                                      'uppercase select-none transition-all',
                                      isLayerInteractive('label') ? 'cursor-move hover:opacity-80' : 'pointer-events-none',
                                      selectedLabel === l ? 'drop-shadow-[0_0_10px_rgba(255,255,255,0.8)] fill-white' : ''
                                  ]"
                                  @mousedown.stop="isLayerInteractive('label') && handleItemMouseDown($event, l, 'label')"
                                  :transform="l.rotate ? `rotate(${l.rotate} ${safeNum(l.x)} ${safeNum(l.y)})` : ''"
                                  :fill="l.color || '#ffffff'" :font-size="safeNum(l.size, 6)" font-weight="900">{{ l.content || 'ТЕКСТ' }}</text>
                        </g>

                        <g class="layer-pcs">
                            <g v-for="(pc, i) in computers" :key="'pc'+i"
                               :class="['group', isLayerInteractive('pc') ? 'cursor-move' : 'pointer-events-none']"
                               @mousedown.stop="isLayerInteractive('pc') && handleItemMouseDown($event, pc, 'pc')">
                                <rect :x="safeNum(pc.x)" :y="safeNum(pc.y)" width="6" height="4.5" rx="0" fill="#000"
                                      :stroke="selectedPc === pc ? '#fff' : seatStroke(pc.kind)" stroke-width="0.2"
                                      class="group-hover:stroke-white transition-colors" />
                                <text :x="safeNum(pc.x) + 3" :y="safeNum(pc.y) + 3.1" font-size="1.8" font-weight="900" text-anchor="middle"
                                      :fill="selectedPc === pc ? '#fff' : seatStroke(pc.kind)"
                                      class="group-hover:fill-white transition-colors pointer-events-none">{{ pc.name || 'PC' }}</text>
                            </g>
                        </g>

                        <rect v-if="isDragging && mode === 'zones'" class="pointer-events-none" :x="draftZone.x" :y="draftZone.y" :width="draftZone.w" :height="draftZone.h" fill="none" stroke="#fff" stroke-width="0.3" stroke-dasharray="1,1" />
                        <polyline v-if="currentPoints.length" class="pointer-events-none" :points="currentPoints.map(p => `${p.x},${p.y}`).join(' ')" fill="none" stroke="#06b6d4" stroke-width="0.3" stroke-dasharray="1,1" />
                        </g>
                    </svg>
                </main>

                <aside class="w-80 border-l border-white/5 p-6 bg-[#0a0a0a] flex flex-col gap-6 shrink-0 overflow-y-auto relative z-10">
                    <div class="bg-white/5 p-4 rounded-2xl border border-white/5">
                        <label class="text-[9px] uppercase text-cyan-500 block mb-2 font-black tracking-widest">Выбор локации</label>
                        <div class="flex gap-2">
                            <select v-model.number="activeClubId" @change="loadFromDB" class="w-full bg-black p-3 text-xs text-white font-bold outline-none rounded-xl border border-white/10 focus:border-cyan-500">
                                <option v-for="club in clubList" :key="club.id" :value="club.id">{{ club.name }}</option>
                            </select>
                        </div>
                    </div>

                    <div v-if="selectedPc" class="p-4 bg-cyan-500/5 border border-cyan-500/20 rounded-2xl animate-in zoom-in duration-200 flex flex-col gap-3">
                        <p class="text-[10px] text-cyan-500 font-black uppercase tracking-widest">Маркер</p>
                        <div>
                            <label class="text-[9px] uppercase text-white/40 block mb-1.5 font-black tracking-widest">Имя</label>
                            <input v-model="selectedPc.name" type="text" class="w-full bg-black border border-white/10 text-white text-xs px-3 py-2 rounded-lg outline-none focus:border-cyan-500 font-black" />
                        </div>
                        <div>
                            <label class="text-[9px] uppercase text-white/40 block mb-1.5 font-black tracking-widest">Тип</label>
                            <select v-model="selectedPc.kind"
                                    @change="selectedPc.booth_id = (selectedPc.kind === 'tv' || selectedPc.kind === 'ps5') ? boothIdForPoint(Number(selectedPc.x), Number(selectedPc.y)) : null"
                                    class="w-full bg-black border border-white/10 text-white text-xs px-3 py-2 rounded-lg outline-none focus:border-cyan-500 font-black uppercase">
                                <option v-for="opt in seatKindOptions" :key="opt.id" :value="opt.id">{{ opt.label }}</option>
                            </select>
                        </div>
                        <div v-if="selectedPc.kind === 'tv' || selectedPc.kind === 'ps5'">
                            <label class="text-[9px] uppercase text-white/40 block mb-1.5 font-black tracking-widest">Кабина (booth)</label>
                            <input v-model="selectedPc.booth_id" type="text" placeholder="авто из зоны"
                                   class="w-full bg-black border border-white/10 text-white text-xs px-3 py-2 rounded-lg outline-none focus:border-cyan-500 font-mono" />
                            <p class="text-[9px] opacity-40 italic mt-1">Одинаковый booth у TV и PS одной зоны</p>
                        </div>
                        <p class="text-[9px] opacity-40 italic">Зажмите и потяните мышь на холсте.</p>
                    </div>

                    <div v-if="selectedLabel" class="p-4 bg-white/5 border border-white/10 rounded-2xl flex flex-col gap-4 animate-in zoom-in duration-200">
                        <div>
                            <label class="text-[9px] uppercase text-white/40 block mb-1.5 font-black tracking-widest">Текст метки</label>
                            <input v-model="selectedLabel.content" type="text" class="w-full bg-black border border-white/10 text-white text-xs px-3 py-3 rounded-lg outline-none focus:border-cyan-500 transition-colors font-black" />
                        </div>
                        <div>
                            <label class="text-[9px] uppercase text-white/40 block mb-1.5 font-black tracking-widest">Размер</label>
                            <input type="range" v-model.number="selectedLabel.size" min="2" max="30" class="w-full accent-cyan-500">
                        </div>
                        <button @click="selectedLabel.rotate = (selectedLabel.rotate === 90 ? 0 : 90)" class="text-[10px] font-black tracking-widest uppercase bg-white/5 hover:bg-white/10 py-3 rounded-xl transition-colors border border-white/5 mt-2">Повернуть на 90°</button>
                    </div>

                    <div class="mt-auto pt-6 border-t border-white/5">
                        <label class="text-[9px] uppercase text-white/20 mb-2 block font-black tracking-widest">Data Flow</label>
                        <textarea readonly :value="generatedJson" class="w-full h-32 bg-black border border-white/5 p-3 text-[9px] text-white/30 font-mono resize-none rounded-xl outline-none custom-scrollbar"></textarea>
                    </div>
                </aside>
            </div>
        </div>
    </AdminLayout>
</template>

<style scoped>
@reference "../../../css/app.css";
.animate-in { animation: fade-in 0.4s ease-out forwards; }
@keyframes fade-in { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
@keyframes zoom-in { from { opacity: 0; transform: scale(0.95); } to { opacity: 1; transform: scale(1); } }

.custom-scrollbar::-webkit-scrollbar { width: 6px; height: 6px; }
.custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
.custom-scrollbar::-webkit-scrollbar-thumb { background: rgba(255, 255, 255, 0.1); border-radius: 10px; }
.custom-scrollbar:hover::-webkit-scrollbar-thumb { background: rgba(6, 182, 212, 0.5); }
</style>
