<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import axios from 'axios'
import AdminLayout from '@/Layouts/AdminLayout.vue'

// --- ПРОПСЫ ---
const props = defineProps<{
    clubId?: number,
    initialConfig?: any,
    initialPcs?: any[],
    clubs?: { id: number, name: string }[]
}>()

// --- СОСТОЯНИЕ ---
const mode = ref<'walls' | 'zones' | 'labels' | 'pcs' | 'erase'>('walls')
const viewbox = ref('-10 -10 120 200')
const svgRef = ref<SVGSVGElement | null>(null)
const gridSize = ref(2)
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

const currentZoneColor = ref('#22c55e')
const currentZoneType = ref('standart')
const zoneTypes = ['standart', 'single', 'dou', 'trio', 'bootcamp', 'profi']

const isSaving = ref(false)
const isLoading = ref(false)

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

    if (mode.value === 'walls') {
        currentPoints.value.push(pt)
    } else if (mode.value === 'zones') {
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
    if (mode.value === 'zones' && isDragging.value && draftZone.value.w > 0) {
        zones.value.push({ ...draftZone.value, c: currentZoneColor.value, type: currentZoneType.value })
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
            computers.value.push({ id: Date.now() + Math.random(), name: gizmoPc.name, x: 50, y: 50 });
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
            labels.value = cleanArray(rawConfig.labels).filter(l => l && l.content);
            if (rawConfig.viewbox) viewbox.value = rawConfig.viewbox;
        }

        computers.value = cleanArray(data.pcs).filter(pc => pc && pc.name);

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
            config: { walls: walls.value, zoneRects: zones.value, labels: labels.value, viewbox: viewbox.value },
            pcs: computers.value.map(pc => ({ name: pc.name, x: pc.x, y: pc.y }))
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
                        <select v-model="currentZoneType" class="bg-black border border-white/10 text-cyan-500 font-bold text-[10px] py-1.5 px-3 rounded-lg uppercase outline-none focus:border-cyan-500">
                            <option v-for="t in zoneTypes" :key="t" :value="t">{{ t }}</option>
                        </select>
                        <div class="flex gap-1.5">
                            <button v-for="color in ['#22c55e', '#06b6d4', '#3b82f6', '#ef4444', '#fbbf24', '#a855f7', '#4d4d4d']" :key="color" @click="currentZoneColor = color"
                                    :class="['w-6 h-6 rounded-full border-2 transition-transform hover:scale-110', currentZoneColor === color ? 'border-white scale-110 shadow-lg' : 'border-transparent']" :style="{ backgroundColor: color }"></button>
                        </div>
                    </div>

                    <button @click="isMagnetOn = !isMagnetOn"
                            :class="['px-4 py-1.5 text-[10px] font-black uppercase rounded-lg transition-colors border ml-2', isMagnetOn ? 'bg-cyan-500/20 border-cyan-500 text-cyan-400' : 'border-white/10 text-white/40 hover:text-white']">
                        🧲 {{ isMagnetOn ? 'МАГНИТ' : 'СВОБОДНО' }}
                    </button>

                    <button v-if="mode === 'walls' && currentPoints.length > 2" @click="finishWall" class="bg-blue-600/20 border border-blue-500 text-blue-400 px-4 py-1.5 text-[10px] font-black uppercase rounded-lg hover:bg-blue-600 hover:text-white transition-all">Замкнуть</button>
                    <button v-if="mode === 'pcs'" @click="syncWithGizmo" class="bg-purple-500/20 text-purple-400 border border-purple-500/30 px-4 py-1.5 text-[10px] uppercase font-black rounded-lg hover:bg-purple-500 hover:text-white transition-all">🔄 GIZMO SYNC</button>
                </div>

                <div class="flex gap-4">
                    <button @click="resetMap" class="text-red-500 text-[10px] font-black uppercase px-4 py-2 rounded-lg hover:bg-red-500/10 transition-colors">Сброс</button>
                    <button @click="saveToDB" :disabled="isSaving" class="bg-cyan-500 hover:bg-cyan-400 text-black px-8 py-2 text-xs font-black uppercase rounded-lg shadow-[0_0_15px_rgba(6,182,212,0.3)] disabled:opacity-30 transition-all">Сохранить</button>
                </div>
            </header>

            <div class="flex-1 flex overflow-hidden">
                <main class="flex-1 bg-[#020202] relative overflow-auto p-4 custom-scrollbar">
                    <svg ref="svgRef" :viewBox="viewbox" class="w-[150%] h-[200vh] border border-white/5 rounded-2xl bg-black"
                         @mousedown="handleSvgMouseDown" @mousemove="handleMouseMove" @mouseup="handleMouseUp" @mouseleave="handleMouseUp" @dblclick="finishWall">
                        <defs>
                            <pattern id="smallGrid" :width="gridSize" :height="gridSize" patternUnits="userSpaceOnUse">
                                <path :d="`M ${gridSize} 0 L 0 0 0 ${gridSize}`" fill="none" stroke="rgba(6, 182, 212, 0.1)" stroke-width="0.1"/>
                            </pattern>
                            <pattern id="grid" :width="gridSize * 5" :height="gridSize * 5" patternUnits="userSpaceOnUse">
                                <rect :width="gridSize * 5" :height="gridSize * 5" fill="url(#smallGrid)"/>
                                <path :d="`M ${gridSize * 5} 0 L 0 0 0 ${gridSize * 5}`" fill="none" stroke="rgba(6, 182, 212, 0.2)" stroke-width="0.2"/>
                            </pattern>
                        </defs>
                        <rect x="-1000" y="-1000" width="3000" height="5000" fill="url(#grid)" />

                        <path v-for="(w, i) in walls" :key="'w'+i" :d="w.d"
                              @mousedown.stop="handleItemMouseDown($event, w, 'wall')"
                              fill="rgba(6,182,212,0.02)" stroke="#06b6d4" stroke-width="0.5" stroke-linejoin="round" class="transition-colors hover:stroke-white cursor-pointer" />

                        <g v-for="(z, i) in zones" :key="'z'+i" @mousedown.stop="handleItemMouseDown($event, z, 'zone')">
                            <rect :x="safeNum(z.x)" :y="safeNum(z.y)" :width="safeNum(z.w)" :height="safeNum(z.h)"
                                  :fill="z.c || '#22c55e'" :fill-opacity="z.c === '#4d4d4d' ? 0.8 : 0.2"
                                  :stroke="z.c || '#22c55e'" stroke-width="0.5" class="transition-opacity hover:fill-opacity-50 cursor-pointer" />
                        </g>

                        <text v-for="(l, i) in labels" :key="'l'+i" :x="safeNum(l.x)" :y="safeNum(l.y)"
                              @mousedown.stop="handleItemMouseDown($event, l, 'label')"
                              :transform="l.rotate ? `rotate(${l.rotate} ${safeNum(l.x)} ${safeNum(l.y)})` : ''"
                              :fill="l.color || '#ffffff'" :font-size="safeNum(l.size, 6)" font-weight="900" class="uppercase cursor-move select-none hover:opacity-80 transition-all"
                              :class="selectedLabel === l ? 'drop-shadow-[0_0_10px_rgba(255,255,255,0.8)] fill-white' : ''">{{ l.content || 'ТЕКСТ' }}</text>

                        <g v-for="(pc, i) in computers" :key="'pc'+i" @mousedown.stop="handleItemMouseDown($event, pc, 'pc')" class="cursor-move group">
                            <rect :x="safeNum(pc.x)" :y="safeNum(pc.y)" width="6" height="4.5" fill="#000" stroke="#06b6d4" stroke-width="0.4" class="group-hover:stroke-white transition-colors" />
                            <text :x="safeNum(pc.x) + 3" :y="safeNum(pc.y) + 3.1" font-size="1.8" font-weight="900" text-anchor="middle" fill="#06b6d4" class="group-hover:fill-white transition-colors pointer-events-none">{{ pc.name || 'PC' }}</text>
                        </g>

                        <rect v-if="isDragging && mode === 'zones'" :x="draftZone.x" :y="draftZone.y" :width="draftZone.w" :height="draftZone.h" fill="none" stroke="#fff" stroke-width="0.3" stroke-dasharray="1,1" />
                        <polyline v-if="currentPoints.length" :points="currentPoints.map(p => `${p.x},${p.y}`).join(' ')" fill="none" stroke="#06b6d4" stroke-width="0.3" stroke-dasharray="1,1" />
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

                    <div v-if="selectedPc" class="p-4 bg-cyan-500/5 border border-cyan-500/20 rounded-2xl animate-in zoom-in duration-200">
                        <p class="text-[10px] text-cyan-500 font-black uppercase mb-1 tracking-widest">Выбран ПК: <span class="text-white text-sm">{{ selectedPc.name }}</span></p>
                        <p class="text-[9px] opacity-40 italic mt-2">Зажмите и потяните мышь на холсте.</p>
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
