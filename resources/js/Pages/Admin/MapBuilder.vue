<script setup lang="ts">
import { ref, computed } from 'vue'
import axios from 'axios'

const props = defineProps<{
    clubId?: number,
    initialConfig?: any,
    initialPcs?: any[],
    clubs?: { id: number, name: string }[]
}>()

// --- ИМИТАЦИЯ ДАННЫХ ИЗ GIZMO ---
const externalPcs = [
    { name: 'PC-01' }, { name: 'PC-02' }, { name: 'PC-03' }, { name: 'PC-04' }, { name: 'PC-05' },
    { name: 'PRO-01' }, { name: 'PRO-02' }, { name: 'PRO-03' },
    { name: 'VIP-01' }, { name: 'VIP-02' },
    { name: 'STREAM-01' }
]

const mode = ref<'walls' | 'zones' | 'labels' | 'pcs' | 'erase'>('walls')
const viewbox = ref(props.initialConfig?.viewbox || '-10 -10 120 200')
const svgRef = ref<SVGSVGElement | null>(null)
const gridSize = ref(2)
const isMagnetOn = ref(true)

const clubList = computed(() => {
    if (props.clubs?.length) return props.clubs;
    return [{ id: 4, name: 'Reactor Protocol' }];
});
const activeClubId = ref<number>(props.clubId || 4)

const walls = ref<any[]>(props.initialConfig?.walls || [])
const zones = ref<any[]>(props.initialConfig?.zoneRects || [])
const labels = ref<any[]>(props.initialConfig?.labels || [])
const computers = ref<any[]>(props.initialPcs || [])

const currentPoints = ref<any[]>([])
const isDragging = ref(false)
const dragTarget = ref<any>(null)
const draftZone = ref({ x: 0, y: 0, w: 0, h: 0 })
const startDragPos = ref({ x: 0, y: 0 })

const selectedLabel = ref<any>(null)
const selectedPc = ref<any>(null)

const currentZoneColor = ref('#22c55e')
const currentZoneType = ref('standart')
const zoneTypes = ['standart', 'single', 'dou', 'trio', 'bootcamp', 'profi']

const isSaving = ref(false)
const isLoading = ref(false)

const snap = (val: number) => Math.round(val / gridSize.value) * gridSize.value
const softRound = (val: number) => Math.round(val * 2) / 2

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

// --- ЛОГИКА АВТО-СИНХРОНИЗАЦИИ ---
const syncWithGizmo = () => {
    let addedCount = 0;
    externalPcs.forEach(gizmoPc => {
        // Проверяем, есть ли уже комп с таким именем на нашей карте
        const exists = computers.value.some(pc => pc.name === gizmoPc.name);

        if (!exists) {
            // Если нет - "высаживаем" его в точку (0,0) или (10,10)
            computers.value.push({
                id: Date.now() + addedCount, // временный ID
                name: gizmoPc.name,
                x: 0,
                y: 0
            });
            addedCount++;
        }
    });
    if (addedCount > 0) {
        alert(`Обнаружено и добавлено новых ПК: ${addedCount}. Расставьте их на карте.`);
    } else {
        alert('Все компьютеры из Gizmo уже на карте.');
    }
}

const handleItemMouseDown = (e: MouseEvent, item: any, type: 'zone' | 'wall' | 'pc' | 'label') => {
    if (mode.value === 'erase') {
        e.stopPropagation();
        if (type === 'zone') zones.value = zones.value.filter(z => z !== item);
        if (type === 'wall') walls.value = walls.value.filter(w => w !== item);
        if (type === 'pc') computers.value = computers.value.filter(p => p !== item);
        if (type === 'label') labels.value = labels.value.filter(l => l !== item);
        return;
    }

    if (mode.value === 'pcs' && type === 'pc') {
        e.stopPropagation();
        dragTarget.value = item;
        selectedPc.value = item;
    } else if (mode.value === 'labels' && type === 'label') {
        e.stopPropagation();
        dragTarget.value = item;
        selectedLabel.value = item;
    }
}

const handleSvgMouseDown = (e: MouseEvent) => {
    const pt = getSVGPoint(e)
    if (mode.value === 'labels') selectedLabel.value = null;
    if (mode.value === 'pcs') selectedPc.value = null;
    if (mode.value === 'walls') {
        currentPoints.value.push(pt)
    } else if (mode.value === 'zones') {
        isDragging.value = true
        startDragPos.value = pt
        draftZone.value.x = pt.x; draftZone.value.y = pt.y; draftZone.value.w = 0; draftZone.value.h = 0;
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
    if (mode.value === 'zones' && isDragging.value && draftZone.value.w > 0) {
        zones.value.push({ ...draftZone.value, c: currentZoneColor.value, type: currentZoneType.value })
    }
    isDragging.value = false; dragTarget.value = null
}

const addLabel = () => {
    const text = prompt('Текст метки:');
    if (text) labels.value.push({ x: 10, y: 10, content: text, rotate: 0, size: 6, color: '#ffffff' });
}

const finishWall = () => {
    if (currentPoints.value.length > 2) {
        const d = `M${currentPoints.value.map(p => `${p.x},${p.y}`).join(' L')} Z`
        walls.value.push({ d }); currentPoints.value = []
    }
}

const loadFromDB = async () => {
    if (!activeClubId.value) return;
    isLoading.value = true;
    try {
        const { data } = await axios.get(`/admin/get-map?club_id=${activeClubId.value}`);
        let config = data.config || {};
        if (typeof config === 'string') config = JSON.parse(config);
        walls.value = config.walls || []; zones.value = config.zoneRects || []; labels.value = config.labels || [];
        computers.value = data.pcs || []; if (config.viewbox) viewbox.value = config.viewbox;
    } catch (e) { console.error(e); } finally { isLoading.value = false; }
}

const saveToDB = async () => {
    isSaving.value = true
    try {
        await axios.post('/admin/save-map', {
            club_id: activeClubId.value,
            config: { walls: walls.value, zoneRects: zones.value, labels: labels.value, viewbox: viewbox.value },
            pcs: computers.value.map(pc => ({ name: pc.name, x: pc.x, y: pc.y }))
        });
        alert('Сохранено');
    } catch (e) { console.error(e); } finally { isSaving.value = false }
}

const resetMap = () => {
    if (confirm('Очистить всё?')) {
        walls.value = []; zones.value = []; labels.value = []; computers.value = [];
        currentPoints.value = []; selectedLabel.value = null; selectedPc.value = null;
    }
}

const generatedJson = computed(() => JSON.stringify({ walls: walls.value, zoneRects: zones.value, labels: labels.value, viewbox: viewbox.value }, null, 2))
</script>

<template>
    <div class="h-screen bg-black text-white flex flex-col font-mono overflow-hidden">
        <header class="h-14 border-b border-white/10 flex justify-between items-center px-4 bg-[#0a0a0a] z-50 shrink-0">
            <div class="flex gap-3 items-center">
                <div class="flex bg-white/5 p-1 rounded border border-white/10">
                    <button v-for="m in [{id: 'walls', n: 'Стены'}, {id: 'zones', n: 'Зоны'}, {id: 'labels', n: 'Текст'}, {id: 'pcs', n: 'ПК'}, {id: 'erase', n: 'Ластик'}]"
                            :key="m.id" @click="mode = m.id as any"
                            :class="['px-3 py-1 text-[10px] font-bold uppercase rounded transition-all', mode === m.id ? (m.id === 'erase' ? 'bg-red-500' : 'bg-[#22c55e] text-black') : 'text-white/40']">
                        {{ m.n }}
                    </button>
                </div>

                <div v-if="mode === 'zones'" class="flex items-center gap-3 ml-2 px-3 border-l border-white/10">
                    <select v-model="currentZoneType" class="bg-[#111] border border-white/20 text-[#22c55e] text-[10px] py-1 px-2 rounded uppercase outline-none">
                        <option v-for="t in zoneTypes" :key="t" :value="t">{{ t }}</option>
                    </select>
                    <div class="flex gap-1">
                        <button v-for="color in ['#22c55e', '#3b82f6', '#ef4444', '#fbbf24', '#a855f7', '#4d4d4d']" :key="color" @click="currentZoneColor = color"
                                :class="['w-5 h-5 rounded-full border-2', currentZoneColor === color ? 'border-white' : 'border-transparent']" :style="{ backgroundColor: color }"></button>
                    </div>
                </div>

                <button @click="isMagnetOn = !isMagnetOn"
                        :class="['px-3 py-1 text-[10px] font-bold uppercase rounded transition-colors border ml-2', isMagnetOn ? 'bg-[#22c55e]/20 border-[#22c55e] text-[#22c55e]' : 'border-white/10 text-white/40 hover:text-white']">
                    🧲 Магнит: {{ isMagnetOn ? 'ВКЛ' : 'ВЫКЛ' }}
                </button>

                <button v-if="mode === 'walls' && currentPoints.length > 2" @click="finishWall" class="bg-blue-600 px-3 py-1 text-[10px] uppercase rounded">Замкнуть</button>
                <button v-if="mode === 'labels'" @click="addLabel" class="bg-white/10 px-3 py-1 text-[10px] uppercase rounded">+ Текст</button>

                <button v-if="mode === 'pcs'" @click="syncWithGizmo" class="bg-blue-500/20 text-blue-400 border border-blue-500/30 px-3 py-1 text-[10px] uppercase font-bold rounded hover:bg-blue-500/40 transition-all">
                    🔄 Синхронизировать с Gizmo
                </button>
            </div>

            <div class="flex gap-4">
                <button @click="resetMap" class="text-red-500 text-[10px] uppercase px-3 py-1 rounded">Сбросить</button>
                <button @click="saveToDB" :disabled="isSaving" class="bg-[#22c55e] text-black px-6 py-1.5 text-xs font-black uppercase disabled:opacity-30">Сохранить</button>
            </div>
        </header>

        <div class="flex-1 flex overflow-hidden">
            <main class="flex-1 bg-[#020202] relative overflow-auto p-4 custom-scrollbar">
                <svg ref="svgRef" :viewBox="viewbox" class="w-full h-[200vh] border border-white/5"
                     @mousedown="handleSvgMouseDown" @mousemove="handleMouseMove" @mouseup="handleMouseUp" @dblclick="finishWall">
                    <defs>
                        <pattern id="smallGrid" :width="gridSize" :height="gridSize" patternUnits="userSpaceOnUse">
                            <path :d="`M ${gridSize} 0 L 0 0 0 ${gridSize}`" fill="none" stroke="rgba(34, 197, 94, 0.15)" stroke-width="0.1"/>
                        </pattern>
                        <pattern id="grid" :width="gridSize * 5" :height="gridSize * 5" patternUnits="userSpaceOnUse">
                            <rect :width="gridSize * 5" :height="gridSize * 5" fill="url(#smallGrid)"/>
                            <path :d="`M ${gridSize * 5} 0 L 0 0 0 ${gridSize * 5}`" fill="none" stroke="rgba(34, 197, 94, 0.4)" stroke-width="0.3"/>
                        </pattern>
                    </defs>
                    <rect x="-2000" y="-2000" width="4000" height="4000" fill="url(#grid)" />

                    <path v-for="(w, i) in walls" :key="'w'+i" :d="w.d"
                          @mousedown="handleItemMouseDown($event, w, 'wall')"
                          :class="mode === 'erase' ? 'cursor-crosshair' : ''"
                          fill="rgba(34,197,94,0.03)" stroke="#22c55e" stroke-width="0.4" stroke-linejoin="round" />

                    <g v-for="(z, i) in zones" :key="'z'+i"
                       @mousedown="handleItemMouseDown($event, z, 'zone')"
                       :class="mode === 'erase' ? 'cursor-crosshair' : ''">
                        <rect :x="z.x" :y="z.y" :width="z.w" :height="z.h"
                              :fill="z.c"
                              :fill-opacity="z.c === '#4d4d4d' ? 1 : 0.25"
                              :stroke="z.c" stroke-width="0.6" />
                        <text v-if="z.type !== 'standart'" :x="z.x + 1" :y="z.y + 3" fill="#fff" fill-opacity="0.8" font-size="2" class="uppercase pointer-events-none">{{ z.type }}</text>
                    </g>

                    <text v-for="(l, i) in labels" :key="'l'+i" :x="l.x" :y="l.y"
                          @mousedown="handleItemMouseDown($event, l, 'label')"
                          :transform="l.rotate ? `rotate(${l.rotate} ${l.x} ${l.y})` : ''"
                          :fill="l.color" :stroke="selectedLabel === l ? '#fff' : 'none'" stroke-width="0.2"
                          :font-size="l.size" font-weight="900" font-family="Arial" class="uppercase cursor-move select-none">{{ l.content }}</text>

                    <g v-for="(pc, i) in computers" :key="'pc'+i" @mousedown="handleItemMouseDown($event, pc, 'pc')" :class="mode === 'pcs' ? 'cursor-move' : ''">
                        <rect :x="pc.x" :y="pc.y" width="6" height="4.5" fill="#001100" stroke="#22c55e" stroke-width="0.5" />
                        <text :x="pc.x + 3" :y="pc.y + 3.2" font-size="2" font-weight="900" font-family="Arial" text-anchor="middle" fill="#22c55e" class="pointer-events-none">{{ pc.name }}</text>
                    </g>

                    <rect v-if="isDragging && mode === 'zones'" :x="draftZone.x" :y="draftZone.y" :width="draftZone.w" :height="draftZone.h" fill="none" stroke="#fff" stroke-width="0.5" stroke-dasharray="2,2" class="pointer-events-none" />
                    <polyline v-if="currentPoints.length" :points="currentPoints.map(p => `${p.x},${p.y}`).join(' ')" fill="none" stroke="#22c55e" stroke-width="0.5" stroke-dasharray="2,1" class="pointer-events-none" />
                </svg>
            </main>

            <aside class="w-80 border-l border-white/10 p-5 bg-[#050505] flex flex-col gap-5 shrink-0 overflow-y-auto shadow-2xl">
                <div class="bg-[#22c55e]/5 p-3 rounded border border-[#22c55e]/20 flex gap-2">
                    <select v-model.number="activeClubId" class="flex-1 bg-[#111] p-2 text-sm text-white font-bold outline-none rounded">
                        <option v-for="club in clubList" :key="club.id" :value="club.id">{{ club.name }}</option>
                    </select>
                    <button @click="loadFromDB" :disabled="isLoading" class="bg-[#22c55e]/20 text-[#22c55e] px-3 rounded text-[10px] font-bold uppercase">Загрузить</button>
                </div>

                <div v-if="selectedPc && mode === 'pcs'" class="bg-white/5 p-3 rounded border border-white/10">
                    <label class="text-[9px] uppercase opacity-50 mb-2 block">Компьютер</label>
                    <div class="w-full bg-[#111] p-2 text-xs text-blue-400 font-bold rounded border border-blue-500/20">
                        {{ selectedPc.name }}
                    </div>
                    <p class="text-[8px] text-white/30 mt-2 italic">* Имя подтянуто из базы Gizmo</p>
                </div>

                <div v-if="selectedLabel && mode === 'labels'" class="bg-white/5 p-3 rounded border border-white/10 flex flex-col gap-3">
                    <div class="flex gap-2">
                        <button v-for="c in ['#ffffff', '#9ca3af', '#22c55e']" :key="c" @click="selectedLabel.color = c" :class="['w-6 h-6 rounded border', selectedLabel.color === c ? 'border-white' : 'border-transparent']" :style="{ backgroundColor: c }"></button>
                    </div>
                    <input type="range" v-model.number="selectedLabel.size" min="2" max="30" step="1" class="w-full accent-[#22c55e]">
                    <button @click="selectedLabel.rotate = (selectedLabel.rotate === 90 ? 0 : 90)" class="w-full py-2 bg-white/5 border border-white/10 text-[9px] uppercase font-bold rounded">Повернуть 90°</button>
                </div>

                <div class="flex-1 flex flex-col min-h-0">
                    <label class="text-[9px] uppercase opacity-30 mb-1">JSON Debug</label>
                    <textarea readonly :value="generatedJson" class="flex-1 w-full bg-black border border-white/5 p-2 text-[8px] text-white/20 font-mono resize-none outline-none rounded"></textarea>
                </div>
            </aside>
        </div>
    </div>
</template>
