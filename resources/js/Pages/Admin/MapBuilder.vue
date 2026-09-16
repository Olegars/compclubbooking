<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted, watch } from 'vue'
import axios from 'axios'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import {
    INFO_MARKER_R,
    emptyRoomInfo,
    isTvZone,
    normalizeRoomInfo,
    resolveInfoEdge,
} from '@/utils/roomInfoEdge'
import {
    ensureZonePoints,
    pointInZone,
    rotateZonePoints,
    syncZoneBounds,
    zoneCenter,
    zoneEdgeMidpoint,
    zonePoints,
    zoneRotate,
    zoneSvgPoints,
} from '@/utils/zoneGeom'

// --- ПРОПСЫ ---
const props = defineProps<{
    clubId?: number,
    initialConfig?: any,
    initialPcs?: any[],
    clubs?: { id: number, name: string }[]
    topologyZones?: { id: number, name: string, slug: string, color: string }[]
}>()

// --- СОСТОЯНИЕ ---
const mode = ref<'walls' | 'zones' | 'labels' | 'pcs' | 'addons' | 'erase'>('walls')
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
const wallCursor = ref<{ x: number; y: number } | null>(null)
const selectedWall = ref<any>(null)
const wallVertexDrag = ref<{ wall: any; index: number } | null>(null)
const wallStroke = ref(false)
const wallStrokeMoved = ref(false)
const wallStrokeFrom = ref<{ x: number; y: number } | null>(null)
const isDragging = ref(false)
const dragTarget = ref<any>(null)
const zoneDrag = ref<{
    zone: any
    kind: 'move' | 'vertex' | 'rotate'
    vertexIndex?: number
    startX: number
    startY: number
    startAngle?: number
    orig: { x: number; y: number; w: number; h: number; rotate: number; points: { x: number; y: number }[] }
} | null>(null)
/** Перетаскивание маркера optional-допа: { zone, addonId } */
const dragAddon = ref<{ zone: any; addonId: number } | null>(null)
const selectedAddon = ref<{ zone: any; addonId: number } | null>(null)
const draftZone = ref({ x: 0, y: 0, w: 0, h: 0 })
const startDragPos = ref({ x: 0, y: 0 })

const selectedLabel = ref<any>(null)
const selectedPc = ref<any>(null)
const selectedZone = ref<any>(null)
const currentSeatKind = ref<'pc' | 'tv' | 'ps5'>('pc')
const mapAddons = ref<{ id: number, name: string, color: string, billing_mode: string, price_per_hour: number }[]>([])
const currentAddonId = ref<number | null>(null)

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
    const zone = zones.value.find(z => pointInZone(z, x, y))
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
    // Ластик: все клики на холст → eraseAtPoint (иначе стены ловят клик сквозь зоны).
    if (mode.value === 'erase') return false
    if (mode.value === 'pcs') return type === 'pc'
    if (mode.value === 'labels') return type === 'label'
    if (mode.value === 'addons') return type === 'zone'
    if (mode.value === 'zones') return type === 'zone'
    // walls — только холст принимает события (рисование поверх)
    return false
}

// --- БЕЗОПАСНЫЕ ПАРСЕРЫ (ЗАЩИТА ОТ КРАША VUE) ---
const OBSOLATE_LABELS = new Set([
    'STANDART', 'STANDARD', 'СТАНДАРТ',
    'VIP', 'SOLO', 'SINGL', 'DUO', 'TRIO', 'KVATRO',
    'BOOTCAMP', 'BOOTCAMP PRO', 'BOOTCAMP-PRO', 'BOOTKAMP', 'BOTKAMP', 'BOTKAMP-PROFI', 'BOOTKAMP-PROFI', 'BOOTCAMP-PROFI',
    'TV', 'PS5', 'PS', 'WC', 'ТЕКСТ', 'TEXT',
])

const normalizeLabelText = (content: unknown) =>
    String(content ?? '').trim().toUpperCase().replace(/\s+/g, ' ')

const isObsoleteLabel = (content: unknown) => {
    const t = normalizeLabelText(content)
    return !t || OBSOLATE_LABELS.has(t)
}

const keepManualLabel = (l: any) => l && !isObsoleteLabel(l.content)

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
const snapFine = (val: number) => Math.round(val / gridSize.value) * gridSize.value
const softRound = (val: number) => Math.round(val * 2) / 2
const freeRound = (val: number) => Math.round(val * 100) / 100

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


/** Shift при стенах: горизонталь / вертикаль / 45°. Без Shift — любой угол. */
const constrainToOctant = (from: { x: number; y: number }, to: { x: number; y: number }) => {
    const dx = to.x - from.x
    const dy = to.y - from.y
    const adx = Math.abs(dx)
    const ady = Math.abs(dy)
    if (adx < 1e-6 && ady < 1e-6) return { ...to }
    if (ady < adx * 0.414) return { x: to.x, y: from.y }
    if (adx < ady * 0.414) return { x: from.x, y: to.y }
    const len = Math.max(adx, ady)
    return {
        x: from.x + Math.sign(dx || 1) * len,
        y: from.y + Math.sign(dy || 1) * len,
    }
}

const parseWallPath = (d: unknown): { points: { x: number; y: number }[]; closed: boolean } => {
    const s = String(d || '')
    const closed = /z\s*$/i.test(s.trim())
    const nums = s.match(/-?\d*\.?\d+(?:e[-+]?\d+)?/gi)?.map(Number) || []
    const points: { x: number; y: number }[] = []
    for (let i = 0; i + 1 < nums.length; i += 2) {
        if (Number.isFinite(nums[i]) && Number.isFinite(nums[i + 1])) {
            points.push({ x: nums[i], y: nums[i + 1] })
        }
    }
    return { points, closed }
}

const wallPathFromPoints = (points: { x: number; y: number }[], closed: boolean) => {
    if (!points.length) return ''
    const body = `M${points.map(p => `${p.x},${p.y}`).join(' L')}`
    return closed ? `${body} Z` : body
}

const wallPathPoints = (wall: any) => parseWallPath(wall?.d).points

const snapToNearbyVertex = (pt: { x: number; y: number }, ignore?: { x: number; y: number } | null) => {
    const threshold = Math.max(gridSize.value * 0.6, 0.35)
    let best: { x: number; y: number } | null = null
    let bestD = threshold
    const consider = (v: { x: number; y: number }) => {
        if (ignore && Math.hypot(v.x - ignore.x, v.y - ignore.y) < 1e-6) return
        const d = Math.hypot(pt.x - v.x, pt.y - v.y)
        if (d < bestD) {
            bestD = d
            best = v
        }
    }
    for (const w of walls.value) {
        for (const v of parseWallPath(w.d).points) consider(v)
    }
    for (const v of currentPoints.value) consider(v)
    return best
}

const applyWallMagnet = (x: number, y: number) => {
    if (isMagnetOn.value) return { x: snapFine(x), y: snapFine(y) }
    return { x: freeRound(x), y: freeRound(y) }
}

const getRawSVGPoint = (evt: MouseEvent) => {
    if (!svgRef.value) return { x: 0, y: 0 }
    const pt = svgRef.value.createSVGPoint()
    pt.x = evt.clientX
    pt.y = evt.clientY
    const cursorPt = pt.matrixTransform(svgRef.value.getScreenCTM()!.inverse())
    return { x: cursorPt.x, y: cursorPt.y }
}

const getSVGPoint = (evt: MouseEvent) => {
    if (!svgRef.value) return { x: 0, y: 0 }
    const raw = getRawSVGPoint(evt)
    let x = raw.x
    let y = raw.y

    if (mode.value === 'zones' && zoneDrag.value?.kind === 'rotate') {
        return raw
    }

    if (mode.value === 'zones' && zoneDrag.value?.kind === 'vertex') {
        const last = zoneDrag.value.orig.points[zoneDrag.value.vertexIndex ?? 0]
        if (evt.shiftKey && last) {
            const c = constrainToOctant(last, { x, y })
            return applyWallMagnet(c.x, c.y)
        }
        return applyWallMagnet(x, y)
    }

    if (mode.value === 'walls') {
        const drag = wallVertexDrag.value
        const dragPts = drag ? parseWallPath(drag.wall.d).points : []
        const draggedPt = drag ? dragPts[drag.index] : null
        const last = currentPoints.value.length
            ? currentPoints.value[currentPoints.value.length - 1]
            : (drag ? (dragPts[drag.index - 1] || dragPts[drag.index + 1] || null) : null)

        if (evt.shiftKey && last) {
            const c = constrainToOctant(last, { x, y })
            if (isMagnetOn.value) {
                const dx = c.x - last.x
                const dy = c.y - last.y
                if (Math.abs(dy) < 1e-6) {
                    x = snapFine(c.x)
                    y = last.y
                } else if (Math.abs(dx) < 1e-6) {
                    x = last.x
                    y = snapFine(c.y)
                } else {
                    const s = snapFine(Math.abs(dx))
                    x = last.x + Math.sign(dx) * s
                    y = last.y + Math.sign(dy) * s
                }
            } else {
                x = freeRound(c.x)
                y = freeRound(c.y)
            }
            return { x, y }
        }

        const magnet = applyWallMagnet(x, y)
        const snapped = snapToNearbyVertex(magnet, draggedPt || last)
        return snapped || magnet
    }

    return {
        x: isMagnetOn.value ? snap(x) : softRound(x),
        y: isMagnetOn.value ? snap(y) : softRound(y),
    }
}

const wallIsClosed = (d: unknown) => /z\s*$/i.test(String(d || '').trim())

const wallDraftPoints = computed(() => {
    if (!currentPoints.value.length) return []
    if (wallCursor.value) return [...currentPoints.value, wallCursor.value]
    return currentPoints.value
})

const zoneVertexHandles = (z: any) =>
    zonePoints(z).map((p, i) => ({ id: i, x: p.x, y: p.y, cursor: 'move' }))

const zoneRotateHandle = (z: any) => {
    const pts = zonePoints(z)
    const { cx } = zoneCenter(z)
    const top = pts.length ? Math.min(...pts.map(p => p.y)) : safeNum(z.y)
    return { x: cx, y: top - 2.4, fromY: top }
}

const zoneOrig = (zone: any) => {
    ensureZonePoints(zone)
    return {
        x: safeNum(zone.x),
        y: safeNum(zone.y),
        w: safeNum(zone.w),
        h: safeNum(zone.h),
        rotate: zoneRotate(zone),
        points: zonePoints(zone).map(p => ({ x: p.x, y: p.y })),
    }
}

const startZoneMove = (e: MouseEvent, zone: any) => {
    const pt = getSVGPoint(e)
    zoneDrag.value = {
        zone,
        kind: 'move',
        startX: pt.x,
        startY: pt.y,
        orig: zoneOrig(zone),
    }
}

const startZoneVertexDrag = (e: MouseEvent, zone: any, index: number) => {
    e.stopPropagation()
    e.preventDefault()
    ensureZoneInfo(zone)
    ensureZonePoints(zone)
    selectedZone.value = zone
    selectedPc.value = null
    selectedLabel.value = null
    selectedAddon.value = null
    const pt = getSVGPoint(e)
    zoneDrag.value = {
        zone,
        kind: 'vertex',
        vertexIndex: index,
        startX: pt.x,
        startY: pt.y,
        orig: zoneOrig(zone),
    }
}

const startZoneRotate = (e: MouseEvent, zone: any) => {
    e.stopPropagation()
    e.preventDefault()
    ensureZoneInfo(zone)
    ensureZonePoints(zone)
    selectedZone.value = zone
    selectedPc.value = null
    selectedLabel.value = null
    selectedAddon.value = null
    const pt = getRawSVGPoint(e)
    const { cx, cy } = zoneCenter(zone)
    zoneDrag.value = {
        zone,
        kind: 'rotate',
        startX: pt.x,
        startY: pt.y,
        startAngle: Math.atan2(pt.y - cy, pt.x - cx),
        orig: zoneOrig(zone),
    }
}

const applySelectedZoneType = (slug: string) => {
    if (!selectedZone.value) return
    const type = normalizeZoneType(slug) || slug
    selectedZone.value.type = type
    const c = colorForZoneType(type)
    if (c) selectedZone.value.c = c
}

const deleteSelectedZone = () => {
    const z = selectedZone.value
    if (!z) return
    zones.value = zones.value.filter(item => item !== z)
    selectedZone.value = null
    zoneDrag.value = null
}

const deleteSelectedWall = () => {
    const w = selectedWall.value
    if (!w) return
    walls.value = walls.value.filter(item => item !== w)
    selectedWall.value = null
    wallVertexDrag.value = null
}

const onWallMouseDown = (e: MouseEvent, wall: any) => {
    if (mode.value === 'erase') {
        e.stopPropagation()
        walls.value = walls.value.filter(item => item !== wall)
        if (selectedWall.value === wall) selectedWall.value = null
        return
    }
    if (mode.value !== 'walls' || currentPoints.value.length) return
    e.stopPropagation()
    selectedWall.value = wall
    selectedZone.value = null
    selectedPc.value = null
    selectedLabel.value = null
    selectedAddon.value = null
}

const startWallVertexDrag = (e: MouseEvent, wall: any, index: number) => {
    if (mode.value !== 'walls') return
    e.stopPropagation()
    selectedWall.value = wall
    wallVertexDrag.value = { wall, index }
    wallStroke.value = false
    wallStrokeMoved.value = false
}

const screenDragThreshold = () => {
    const ctm = svgRef.value?.getScreenCTM()
    const px = ctm ? (1 / (Math.hypot(ctm.a, ctm.b) || 1)) : 0.3
    return px * 8
}

// --- ОБРАБОТЧИКИ МЫШИ ---
const handleItemMouseDown = (e: MouseEvent, item: any, type: 'zone' | 'wall' | 'pc' | 'label') => {
    e.stopPropagation(); // Блокируем всплытие клика!

    if (mode.value === 'erase') {
        if (type === 'zone') {
            if (selectedZone.value === item) selectedZone.value = null
            zones.value = zones.value.filter(z => z !== item);
        }
        if (type === 'wall') walls.value = walls.value.filter(w => w !== item);
        if (type === 'pc') computers.value = computers.value.filter(p => p !== item);
        if (type === 'label') labels.value = labels.value.filter(l => l !== item);
        return;
    }

    if (mode.value === 'addons' && type === 'zone') {
        toggleAddonOnZone(item)
        return
    }

    if (mode.value === 'zones' && type === 'zone') {
        ensureZoneInfo(item)
        selectedZone.value = item
        selectedPc.value = null
        selectedLabel.value = null
        selectedAddon.value = null
        startZoneMove(e, item)
        return
    }

    if (mode.value === 'pcs' && type === 'pc') {
        dragTarget.value = item;
        selectedPc.value = item;
        selectedLabel.value = null;
        selectedAddon.value = null;
        selectedZone.value = null;
    } else if (mode.value === 'labels' && type === 'label') {
        dragTarget.value = item;
        selectedLabel.value = item;
        selectedPc.value = null;
        selectedAddon.value = null;
        selectedZone.value = null;
    }
}

const toggleAddonOnZone = (zone: any) => {
    if (!currentAddonId.value) return
    if (!Array.isArray(zone.addon_ids)) zone.addon_ids = []
    if (!zone.addon_positions || typeof zone.addon_positions !== 'object') zone.addon_positions = {}
    const id = currentAddonId.value
    const idx = zone.addon_ids.indexOf(id)
    if (idx >= 0) {
        zone.addon_ids.splice(idx, 1)
        delete zone.addon_positions[String(id)]
        delete zone.addon_positions[id]
        return
    }
    zone.addon_ids.push(id)
    const key = String(id)
    if (!zone.addon_positions[key]) {
        const optionalsBefore = zoneOptionalAddonBadges(zone).filter((a: any) => a.id !== id)
        const spot = defaultOptionalAddonSpot(zone, optionalsBefore.length)
        zone.addon_positions[key] = { x: spot.x, y: spot.y }
    }
}

const zoneHasAddon = (zone: any, addonId: number) =>
    Array.isArray(zone.addon_ids) && zone.addon_ids.includes(addonId)

const zoneAddonBadges = (zone: any) => {
    const ids = Array.isArray(zone.addon_ids) ? zone.addon_ids : []
    return mapAddons.value.filter(a => ids.includes(a.id))
}

const zoneAlwaysAddonBadges = (zone: any) =>
    zoneAddonBadges(zone).filter((a: any) => a.billing_mode !== 'optional')

const zoneOptionalAddonBadges = (zone: any) =>
    zoneAddonBadges(zone).filter((a: any) => a.billing_mode === 'optional')

const defaultOptionalAddonSpot = (zone: any, index: number) => {
    ensureZonePoints(zone)
    const pad = 0.6
    return {
        x: safeNum(zone.x) + safeNum(zone.w) - 6 - pad,
        y: safeNum(zone.y) + Math.max(pad, (safeNum(zone.h) - 4.5) / 2) + index * 5,
    }
}

const zoneOptionalAddonSpot = (zone: any, badge: any, index: number) => {
    const key = String(badge?.id ?? '')
    const saved = zone.addon_positions?.[key] ?? zone.addon_positions?.[badge?.id]
    if (saved && Number.isFinite(Number(saved.x)) && Number.isFinite(Number(saved.y))) {
        return { x: safeNum(saved.x), y: safeNum(saved.y) }
    }
    return defaultOptionalAddonSpot(zone, index)
}

const ensureAddonPosition = (zone: any, badge: any, index: number) => {
    if (!zone.addon_positions || typeof zone.addon_positions !== 'object') zone.addon_positions = {}
    const key = String(badge.id)
    if (!zone.addon_positions[key]) {
        const spot = defaultOptionalAddonSpot(zone, index)
        zone.addon_positions[key] = { x: spot.x, y: spot.y }
    }
    return zoneOptionalAddonSpot(zone, badge, index)
}

const startAddonDrag = (e: MouseEvent, zone: any, badge: any, index: number) => {
    if (mode.value === 'erase') return
    e.stopPropagation()
    ensureAddonPosition(zone, badge, index)
    const sel = { zone, addonId: Number(badge.id) }
    dragAddon.value = sel
    selectedAddon.value = sel
    selectedPc.value = null
    selectedLabel.value = null
    selectedZone.value = null
}

const nudgeStep = (shift: boolean) => {
    const svg = svgRef.value
    const ctm = svg?.getScreenCTM()
    // 1 экранный пиксель в единицах карты
    const px = ctm ? (1 / (Math.hypot(ctm.a, ctm.b) || 1)) : 0.05
    return shift ? px * 10 : px
}

const applyNudgeCoord = (value: number, delta: number) =>
    // Без snap: иначе магнит съедает мелкий шаг
    Math.round((Number(value) + delta) * 1000) / 1000

const nudgeSelectedMarker = (dx: number, dy: number) => {
    if (selectedZone.value && mode.value === 'zones') {
        ensureZonePoints(selectedZone.value)
        selectedZone.value.points = zonePoints(selectedZone.value).map(p => ({
            x: applyNudgeCoord(p.x, dx),
            y: applyNudgeCoord(p.y, dy),
        }))
        selectedZone.value.rotate = 0
        syncZoneBounds(selectedZone.value)
        return true
    }
    if (selectedPc.value) {
        selectedPc.value.x = applyNudgeCoord(selectedPc.value.x, dx)
        selectedPc.value.y = applyNudgeCoord(selectedPc.value.y, dy)
        if (selectedPc.value.kind === 'tv' || selectedPc.value.kind === 'ps5') {
            selectedPc.value.booth_id = boothIdForPoint(
                Number(selectedPc.value.x),
                Number(selectedPc.value.y),
            )
        }
        return true
    }
    if (selectedAddon.value) {
        const { zone, addonId } = selectedAddon.value
        if (!zone.addon_positions || typeof zone.addon_positions !== 'object') zone.addon_positions = {}
        const key = String(addonId)
        const cur = zone.addon_positions[key] || { x: 0, y: 0 }
        zone.addon_positions[key] = {
            x: applyNudgeCoord(cur.x, dx),
            y: applyNudgeCoord(cur.y, dy),
        }
        return true
    }
    return false
}

const handleKeyDown = (e: KeyboardEvent) => {
    const target = e.target as HTMLElement | null
    const tag = target?.tagName
    const inField = tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || Boolean(target?.isContentEditable)

    if (!inField && mode.value === 'walls') {
        if (e.key === 'Escape') {
            currentPoints.value = []
            wallCursor.value = null
            wallStroke.value = false
            wallStrokeMoved.value = false
            selectedWall.value = null
            e.preventDefault()
            return
        }
        if (e.key === 'Enter') {
            finishWall(e.shiftKey)
            e.preventDefault()
            return
        }
        if (e.key === 'Backspace' && currentPoints.value.length) {
            currentPoints.value.pop()
            e.preventDefault()
            return
        }
        if ((e.key === 'Delete' || e.key === 'Backspace') && selectedWall.value && !currentPoints.value.length) {
            deleteSelectedWall()
            e.preventDefault()
            return
        }
    }

    if (!inField && (e.key === 'Delete' || e.key === 'Backspace') && selectedZone.value && mode.value === 'zones') {
        deleteSelectedZone()
        e.preventDefault()
        return
    }

    if (!['ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight'].includes(e.key)) return
    if (inField) return
    if (mode.value === 'erase') return

    let dx = 0
    let dy = 0
    const step = nudgeStep(e.shiftKey)
    if (e.key === 'ArrowLeft') dx = -step
    if (e.key === 'ArrowRight') dx = step
    if (e.key === 'ArrowUp') dy = -step
    if (e.key === 'ArrowDown') dy = step

    if (nudgeSelectedMarker(dx, dy)) {
        e.preventDefault()
    }
}

const ZONE_SLUG_ALIASES: Record<string, string> = {
    solo: 'singl',
    standart: 'singl',
    standard: 'singl',
    bootkamp: 'bootcamp',
    botkamp: 'bootcamp',
    'botkamp-profi': 'bootcamp-pro',
    'bootkamp-profi': 'bootcamp-pro',
    'bootcamp-profi': 'bootcamp-pro',
    bootcamp_pro: 'bootcamp-pro',
    bootcamp_profi: 'bootcamp-pro',
}

const normalizeZoneType = (type: unknown) => {
    const slug = String(type || '').trim().toLowerCase()
    if (!slug) return ''
    return ZONE_SLUG_ALIASES[slug] || slug
}

const colorForZoneType = (type: unknown) => {
    const slug = normalizeZoneType(type)
    const zone = topologyZones.value.find(z => z.slug === slug)
    return zone?.color || ''
}

const zoneAutoTitle = (zone: any) =>
    normalizeZoneType(zone.type).replace(/[-_]/g, ' ').toUpperCase()

const ensureZoneInfo = (zone: any) => {
    zone.info = normalizeRoomInfo(zone.info)
    ensureZonePoints(zone)
    return zone.info
}

const zoneInfoEdge = (zone: any, index: number) => {
    const others = zones.value.filter((z, i) => i !== index)
    return resolveInfoEdge(zone, others, zone.info?.info_edge)
}

const roomInfoMarkers = computed(() =>
    zones.value.map((z, i) => {
        const edge = zoneInfoEdge(z, i)
        const { cx, cy } = zoneEdgeMidpoint(z, edge)
        return { key: `info-${i}`, zone: z, cx, cy, edge }
    })
)

const ZONE_BADGE_FONT = 1.3
const ZONE_BADGE_SUB_FONT = 1.1
const ZONE_BADGE_INSET = 0.85
const ZONE_BADGE_PAD_X = 0.75
const ZONE_BADGE_PAD_Y = 0.4
const ZONE_BADGE_RX = 0.55
const ZONE_BADGE_CHAR_W = 0.7

const estimateBadgeTextWidth = (text: string, fontSize: number) => {
    const t = text.trim()
    if (!t) return 0
    const letters = t.length * fontSize * ZONE_BADGE_CHAR_W
    const tracking = Math.max(0, t.length - 1) * fontSize * 0.04
    return letters + tracking
}

const zoneBadgeMeta = (zone: any) => {
    const title = zoneAutoTitle(zone)
    if (!title) return null
    const extras = zoneAlwaysAddonBadges(zone)
        .map((a: any) => String(a?.name || '').trim().toUpperCase())
        .filter(Boolean)
    const sub = extras.length ? extras.join(' ') : ''
    const titleW = estimateBadgeTextWidth(title, ZONE_BADGE_FONT)
    const subW = sub ? estimateBadgeTextWidth(sub, ZONE_BADGE_SUB_FONT) : 0
    const w = Math.max(titleW, subW) + ZONE_BADGE_PAD_X * 2
    const lineGap = sub ? 0.35 : 0
    const contentH = sub
        ? ZONE_BADGE_FONT + lineGap + ZONE_BADGE_SUB_FONT
        : ZONE_BADGE_FONT
    const h = contentH + ZONE_BADGE_PAD_Y * 2
    const zw = safeNum(zone.w)
    const zh = safeNum(zone.h)
    const insetX = Math.min(ZONE_BADGE_INSET, Math.max(0.35, zw * 0.08))
    const insetY = Math.min(ZONE_BADGE_INSET, Math.max(0.35, zh * 0.1))
    const x = safeNum(zone.x) + zw - insetX - w
    const y = safeNum(zone.y) + insetY
    const titleY = sub
        ? y + ZONE_BADGE_PAD_Y + ZONE_BADGE_FONT / 2
        : y + h / 2
    const subY = y + ZONE_BADGE_PAD_Y + ZONE_BADGE_FONT + lineGap + ZONE_BADGE_SUB_FONT / 2
    return { title, sub, x, y, w, h, cx: x + w / 2, titleY, subY }
}

const eraseAddonAtPoint = (x: number, y: number): boolean => {
    // Сначала пробуем снять доп с зоны (не удаляя саму комнату / стены).
    for (let zi = zones.value.length - 1; zi >= 0; zi--) {
        const z = zones.value[zi]
        const optionals = zoneOptionalAddonBadges(z)
        for (let bi = optionals.length - 1; bi >= 0; bi--) {
            const spot = zoneOptionalAddonSpot(z, optionals[bi], bi)
            if (x >= spot.x - 0.5 && x <= spot.x + 6.5 && y >= spot.y - 0.5 && y <= spot.y + 5) {
                if (!Array.isArray(z.addon_ids)) return true
                z.addon_ids = z.addon_ids.filter((id: number) => id !== optionals[bi].id)
                if (z.addon_positions) {
                    delete z.addon_positions[String(optionals[bi].id)]
                    delete z.addon_positions[optionals[bi].id]
                }
                return true
            }
        }

        // Always-допы живут в бейдже SINGL + — попадание по рамке снимает только допы.
        const always = zoneAlwaysAddonBadges(z)
        if (always.length) {
            const badge = zoneBadgeMeta(z)
            if (
                badge
                && x >= badge.x - 0.3
                && x <= badge.x + badge.w + 0.3
                && y >= badge.y - 0.3
                && y <= badge.y + badge.h + 0.3
            ) {
                const alwaysIds = new Set(always.map((a: any) => a.id))
                z.addon_ids = (z.addon_ids || []).filter((id: number) => !alwaysIds.has(id))
                return true
            }
        }
    }
    return false
}

const eraseWallAtPoint = (x: number, y: number): boolean => {
    const svg = svgRef.value
    if (!svg || !walls.value.length) return false

    const pt = svg.createSVGPoint()
    pt.x = x
    pt.y = y

    const paths = svg.querySelectorAll('.layer-walls path')
    for (let i = paths.length - 1; i >= 0; i--) {
        const path = paths[i] as SVGGeometryElement & {
            isPointInStroke?: (p: DOMPoint) => boolean
        }
        if (typeof path.isPointInStroke !== 'function') continue

        const prev = path.getAttribute('stroke-width') || '0.2'
        // Чуть шире hit-area, иначе в контур почти не попасть.
        path.setAttribute('stroke-width', '1.6')
        const hit = path.isPointInStroke(pt)
        path.setAttribute('stroke-width', prev)
        if (!hit) continue

        const d = path.getAttribute('d') || ''
        const wall = walls.value.find(w => w.d === d)
        if (wall) {
            walls.value = walls.value.filter(w => w !== wall)
            return true
        }
    }
    return false
}

const eraseAtPoint = (x: number, y: number) => {
    if (eraseAddonAtPoint(x, y)) return

    const labelHit = 8
    let bestLabel: any = null
    let bestLabelDist = labelHit
    for (const l of labels.value) {
        const size = safeNum(l.size, 6)
        const lx = safeNum(l.x)
        const ly = safeNum(l.y)
        const dx = Math.max(0, Math.abs(x - lx) - size * 1.6)
        const dy = Math.max(0, Math.abs(y - ly) - size * 0.6)
        const d = Math.hypot(dx, dy)
        if (d < bestLabelDist) {
            bestLabelDist = d
            bestLabel = l
        }
    }
    if (bestLabel) {
        labels.value = labels.value.filter(l => l !== bestLabel)
        return
    }

    const pcHit = 5
    let bestPc: any = null
    let bestPcDist = pcHit
    for (const pc of computers.value) {
        const cx = safeNum(pc.x) + 3
        const cy = safeNum(pc.y) + 2.25
        const d = Math.hypot(x - cx, y - cy)
        if (d < bestPcDist) {
            bestPcDist = d
            bestPc = pc
        }
    }
    if (bestPc) {
        computers.value = computers.value.filter(p => p !== bestPc)
        return
    }

    const zone = zones.value.find(z => pointInZone(z, x, y))
    if (zone) {
        // Доп уже проверен выше. Клик по комнате без допа / мимо бейджа — удалить зону.
        if (selectedZone.value === zone) selectedZone.value = null
        zones.value = zones.value.filter(z => z !== zone)
        return
    }

    eraseWallAtPoint(x, y)
}

const handleSvgMouseDown = (e: MouseEvent) => {
    const pt = getSVGPoint(e)
    selectedLabel.value = null;
    selectedPc.value = null;
    selectedAddon.value = null;
    selectedZone.value = null;
    if (mode.value !== 'walls') selectedWall.value = null

    // Ластик: попадание в тонкий SVG-текст почти невозможно — ищем ближайший объект.
    if (mode.value === 'erase') {
        eraseAtPoint(pt.x, pt.y)
        return
    }

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
        selectedWall.value = null
        currentPoints.value.push(pt)
        wallCursor.value = pt
        wallStroke.value = true
        wallStrokeMoved.value = false
        wallStrokeFrom.value = pt
    } else if (mode.value === 'zones') {
        if (!currentZoneType.value || !topologyZones.value.length) return
        isDragging.value = true
        startDragPos.value = pt
        draftZone.value = { x: pt.x, y: pt.y, w: 0, h: 0 }
    }
}

const handleMouseMove = (e: MouseEvent) => {
    const pt = getSVGPoint(e)
    if (wallVertexDrag.value) {
        const { wall, index } = wallVertexDrag.value
        const parsed = parseWallPath(wall.d)
        if (parsed.points[index]) {
            parsed.points[index] = { x: pt.x, y: pt.y }
            wall.d = wallPathFromPoints(parsed.points, parsed.closed)
        }
        return
    }
    if (mode.value === 'walls' && currentPoints.value.length) {
        wallCursor.value = pt
        if (wallStroke.value && wallStrokeFrom.value) {
            if (Math.hypot(pt.x - wallStrokeFrom.value.x, pt.y - wallStrokeFrom.value.y) > screenDragThreshold()) {
                wallStrokeMoved.value = true
            }
        }
    }
    if (zoneDrag.value) {
        const { zone, kind, orig, startX, startY, startAngle, vertexIndex } = zoneDrag.value
        if (kind === 'move') {
            const dx = pt.x - startX
            const dy = pt.y - startY
            zone.points = orig.points.map(p => ({ x: p.x + dx, y: p.y + dy }))
            zone.rotate = 0
            syncZoneBounds(zone)
        } else if (kind === 'rotate') {
            const raw = getRawSVGPoint(e)
            const { cx, cy } = zoneCenter({ points: orig.points, x: orig.x, y: orig.y, w: orig.w, h: orig.h })
            const ang = Math.atan2(raw.y - cy, raw.x - cx)
            let deg = (ang - (startAngle || 0)) * 180 / Math.PI
            if (e.shiftKey) deg = Math.round(deg / 15) * 15
            zone.points = rotateZonePoints(orig.points, cx, cy, deg)
            zone.rotate = 0
            syncZoneBounds(zone)
        } else if (kind === 'vertex' && vertexIndex != null) {
            const next = orig.points.map(p => ({ ...p }))
            next[vertexIndex] = { x: pt.x, y: pt.y }
            zone.points = next
            zone.rotate = 0
            syncZoneBounds(zone)
        }
        return
    }
    if (dragAddon.value) {
        const { zone, addonId } = dragAddon.value
        if (!zone.addon_positions || typeof zone.addon_positions !== 'object') zone.addon_positions = {}
        zone.addon_positions[String(addonId)] = { x: pt.x, y: pt.y }
    } else if (dragTarget.value) {
        dragTarget.value.x = pt.x; dragTarget.value.y = pt.y
    } else if (isDragging.value && mode.value === 'zones') {
        draftZone.value.x = Math.min(startDragPos.value.x, pt.x)
        draftZone.value.y = Math.min(startDragPos.value.y, pt.y)
        draftZone.value.w = Math.abs(pt.x - startDragPos.value.x)
        draftZone.value.h = Math.abs(pt.y - startDragPos.value.y)
    }
}

const handleMouseUp = () => {
    if (wallVertexDrag.value) {
        wallVertexDrag.value = null
        return
    }
    if (wallStroke.value) {
        const draggedLine = wallStrokeMoved.value
            && currentPoints.value.length === 1
            && wallCursor.value
            && Math.hypot(
                wallCursor.value.x - currentPoints.value[0].x,
                wallCursor.value.y - currentPoints.value[0].y,
            ) >= 0.2
        wallStroke.value = false
        wallStrokeMoved.value = false
        wallStrokeFrom.value = null
        if (draggedLine) {
            currentPoints.value.push({ ...wallCursor.value! })
            finishWall(false)
            return
        }
    }
    if (zoneDrag.value) {
        zoneDrag.value = null
        isDragging.value = false
        dragTarget.value = null
        dragAddon.value = null
        return
    }
    if (mode.value === 'zones' && isDragging.value && draftZone.value.w >= 0.5 && draftZone.value.h >= 0.5 && currentZoneType.value) {
        const color = selectedTopologyZone.value?.color || currentZoneColor.value
        zones.value.push({
            ...draftZone.value,
            c: color,
            type: currentZoneType.value,
            rotate: 0,
            addon_ids: [],
            addon_positions: {},
            info: emptyRoomInfo(),
        })
        selectedZone.value = zones.value[zones.value.length - 1]
        ensureZoneInfo(selectedZone.value)
        ensureZonePoints(selectedZone.value)
    }
    if (dragTarget.value && (dragTarget.value.kind === 'tv' || dragTarget.value.kind === 'ps5')) {
        dragTarget.value.booth_id = boothIdForPoint(
            Number(dragTarget.value.x),
            Number(dragTarget.value.y),
        )
    }
    isDragging.value = false
    dragTarget.value = null
    dragAddon.value = null
}

const handleMouseLeave = () => {
    if (wallVertexDrag.value || wallStroke.value || zoneDrag.value) return
    wallCursor.value = null
    handleMouseUp()
}

const handleWindowMouseMove = (e: MouseEvent) => {
    if (wallVertexDrag.value || wallStroke.value || zoneDrag.value) handleMouseMove(e)
}

const handleWindowMouseUp = () => {
    if (wallVertexDrag.value || wallStroke.value || zoneDrag.value) handleMouseUp()
}

const finishWall = (closed = false) => {
    const pts = currentPoints.value
    if (closed) {
        if (pts.length < 3) return
        walls.value.push({ d: `M${pts.map(p => `${p.x},${p.y}`).join(' L')} Z` })
    } else {
        if (pts.length < 2) return
        walls.value.push({ d: `M${pts.map(p => `${p.x},${p.y}`).join(' L')}` })
    }
    currentPoints.value = []
    wallCursor.value = null
    wallStroke.value = false
    wallStrokeMoved.value = false
    wallStrokeFrom.value = null
}

const handleWallDblClick = (e: MouseEvent) => {
    if (mode.value !== 'walls') return
    e.preventDefault()
    const pts = currentPoints.value
    if (pts.length >= 2) {
        const a = pts[pts.length - 1]
        const b = pts[pts.length - 2]
        if (Math.hypot(a.x - b.x, a.y - b.y) < 0.35) pts.pop()
    }
    finishWall(false)
}

const syncDefaultPcs = () => {
    const templatePcs = [
        { name: 'PC-01' }, { name: 'PC-02' }, { name: 'PC-03' }, { name: 'PC-04' }, { name: 'PC-05' },
        { name: 'PRO-01' }, { name: 'PRO-02' }, { name: 'PRO-03' },
        { name: 'VIP-01' }, { name: 'VIP-02' }, { name: 'STREAM-01' }
    ]
    let addedCount = 0;
    templatePcs.forEach(templatePc => {
        if (!computers.value.some(pc => pc.name === templatePc.name)) {
            computers.value.push({
                id: Date.now() + Math.random(),
                name: templatePc.name,
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

    try {
        const { data } = await axios.get(`/admin/get-map?club_id=${activeClubId.value}`);
        let rawConfig = data.config || {};

        while (typeof rawConfig === 'string') {
            try { rawConfig = JSON.parse(rawConfig); }
            catch(e) { break; }
        }

        walls.value = []; zones.value = []; labels.value = []; computers.value = [];
        mapAddons.value = [];
        currentPoints.value = [];
        wallCursor.value = null;
        selectedZone.value = null;
        selectedWall.value = null;
        wallVertexDrag.value = null;
        zoneDrag.value = null;

        if (rawConfig) {
            walls.value = cleanArray(rawConfig.walls).filter(w => w && w.d);
            zones.value = cleanArray(rawConfig.zoneRects).filter(z => z && z.w !== undefined).map(z => {
                const type = normalizeZoneType(z.type) || z.type
                return {
                    ...z,
                    type,
                    c: colorForZoneType(type) || z.c || '#22c55e',
                    addon_ids: Array.isArray(z.addon_ids) ? z.addon_ids.map(Number) : [],
                    addon_positions: (z.addon_positions && typeof z.addon_positions === 'object')
                        ? { ...z.addon_positions }
                        : {},
                    info: normalizeRoomInfo(z.info),
                    rotate: Number(z.rotate) || 0,
                    points: Array.isArray(z.points) ? z.points : undefined,
                }
            }).filter(z => safeNum(z.w) >= 0.5 && safeNum(z.h) >= 0.5)
            zones.value.forEach(ensureZonePoints)
            labels.value = cleanArray(rawConfig.labels).filter(keepManualLabel);
            if (rawConfig.viewbox) viewbox.value = rawConfig.viewbox;
        }

        computers.value = cleanArray(data.pcs).filter(pc => pc && pc.name).map(pc => ({
            ...pc,
            kind: pc.kind || 'pc',
            booth_id: pc.booth_id || null,
        }));

        mapAddons.value = Array.isArray(data.addons) ? data.addons : []
        if (!mapAddons.value.some(a => a.id === currentAddonId.value)) {
            currentAddonId.value = mapAddons.value[0]?.id ?? null
        }

    } catch (e) {
        console.error("Ошибка загрузки карты с сервера:", e);
    } finally {
        isLoading.value = false;
        selectedPc.value = null;
        selectedLabel.value = null;
        selectedAddon.value = null;
        selectedZone.value = null;
    }
}

const saveToDB = async () => {
    isSaving.value = true
    try {
        const { data } = await axios.post('/admin/save-map', {
            club_id: activeClubId.value,
            config: {
                walls: walls.value,
                zoneRects: zones.value
                    .filter(z => safeNum(z.w) >= 0.5 && safeNum(z.h) >= 0.5)
                    .map(z => {
                    const synced = syncZoneBounds({ ...z, points: zonePoints(z) })
                    return {
                    x: synced.x, y: synced.y, w: synced.w, h: synced.h, c: z.c,
                    rotate: 0,
                    points: synced.points,
                    type: normalizeZoneType(z.type) || z.type,
                    addon_ids: Array.isArray(z.addon_ids) ? z.addon_ids : [],
                    addon_positions: (z.addon_positions && typeof z.addon_positions === 'object')
                        ? z.addon_positions
                        : {},
                    info: normalizeRoomInfo(z.info),
                    }
                    }),
                labels: labels.value.filter(keepManualLabel),
                viewbox: viewbox.value,
            },
            pcs: computers.value.map(pc => ({
                id: pc.id ?? null,
                name: pc.name,
                x: pc.x,
                y: pc.y,
                kind: pc.kind || 'pc',
                booth_id: pc.booth_id || null,
            }))
        });
        await loadFromDB();
        alert(data?.message || 'Данные карты успешно сохранены!');
    } catch (e) {
        console.error(e);
        const msg = e?.response?.data?.message || 'Ошибка при сохранении карты.';
        alert(msg);
    } finally {
        isSaving.value = false
    }
}

const resetMap = () => {
    if (confirm('Очистить карту? Это действие нельзя отменить без перезагрузки страницы.')) {
        walls.value = []; zones.value = []; labels.value = []; computers.value = [];
        currentPoints.value = [];
        wallCursor.value = null
        selectedZone.value = null
        selectedWall.value = null
        wallVertexDrag.value = null
        zoneDrag.value = null
    }
}

const generatedJson = computed(() => JSON.stringify({ walls: walls.value, zoneRects: zones.value, labels: labels.value, viewbox: viewbox.value }, null, 2))

watch(mode, () => {
    currentPoints.value = []
    wallCursor.value = null
    wallStroke.value = false
    wallStrokeMoved.value = false
    wallStrokeFrom.value = null
    wallVertexDrag.value = null
    if (mode.value !== 'walls') selectedWall.value = null
})

onMounted(() => {
    if (props.clubs && props.clubs.length > 0) {
        activeClubId.value = props.clubs[0].id;
    } else {
        activeClubId.value = 1;
    }
    loadFromDB();
    window.addEventListener('keydown', handleKeyDown)
    window.addEventListener('mousemove', handleWindowMouseMove)
    window.addEventListener('mouseup', handleWindowMouseUp)
})

onUnmounted(() => {
    window.removeEventListener('keydown', handleKeyDown)
    window.removeEventListener('mousemove', handleWindowMouseMove)
    window.removeEventListener('mouseup', handleWindowMouseUp)
})
</script>

<template>
    <AdminLayout>
        <div class="h-[calc(100vh-8rem)] min-h-[600px] flex flex-col bg-[#050505] border border-white/5 rounded-[1rem] overflow-hidden shadow-2xl animate-in fade-in duration-500 font-sans text-white relative" style="font-family: ui-sans-serif, system-ui, 'Segoe UI', Arial, sans-serif;">

            <div v-if="isLoading" class="absolute inset-0 bg-black/70 backdrop-blur-md z-[999] flex items-center justify-center">
                <div class="flex flex-col items-center gap-4">
                    <div class="w-16 h-16 border-4 border-cyan-500/20 border-t-cyan-500 rounded-full animate-spin"></div>
                    <span class="text-cyan-500 text-sm font-semibold">Синхронизация…</span>
                </div>
            </div>

            <header class="h-16 border-b border-white/10 flex justify-between items-center px-6 bg-[#0a0a0a] z-50 shrink-0">
                <div class="flex gap-4 items-center">
                    <div class="flex bg-white/5 p-1 rounded-xl border border-white/10">
                        <button v-for="m in [{id: 'walls', n: 'Стены'}, {id: 'zones', n: 'Зоны'}, {id: 'addons', n: 'Допы'}, {id: 'labels', n: 'Текст'}, {id: 'pcs', n: 'ПК'}, {id: 'erase', n: 'Ластик'}]"
                                :key="m.id" @click="mode = m.id as any"
                                :class="['px-4 py-1.5 text-xs font-semibold rounded-lg transition-all', mode === m.id ? (m.id === 'erase' ? 'bg-red-500 text-black' : 'bg-cyan-500 text-black shadow-[0_0_10px_rgba(6,182,212,0.4)]') : 'text-white/50 hover:text-white']">
                            {{ m.n }}
                        </button>
                    </div>

                    <div v-if="mode === 'zones'" class="flex items-center gap-3 ml-2 px-4 border-l border-white/10">
                        <template v-if="topologyZones.length">
                            <select v-model="currentZoneType"
                                    class="bg-black border border-white/10 text-cyan-500 font-semibold text-xs py-1.5 px-3 rounded-lg outline-none focus:border-cyan-500"
                                    :style="{ borderColor: currentZoneColor + '66', color: currentZoneColor }">
                                <option v-for="z in topologyZones" :key="z.id" :value="z.slug">{{ z.name }}</option>
                            </select>
                            <div class="w-6 h-6 rounded-full border-2 border-white/40 shrink-0"
                                 :style="{ backgroundColor: currentZoneColor, boxShadow: `0 0 10px ${currentZoneColor}66` }"
                                 :title="selectedTopologyZone ? `${selectedTopologyZone.name} (${selectedTopologyZone.slug})` : ''"></div>
                            <span class="text-[11px] text-white/40 hidden xl:inline">
                                {{ selectedTopologyZone?.slug }} · линия — сдвиг · угол — скос
                            </span>
                        </template>
                        <span v-else class="text-xs text-amber-400/90 font-semibold">
                            Сначала добавьте зоны в «Топология залов»
                        </span>
                    </div>

                    <div v-if="mode === 'addons'" class="flex items-center gap-3 ml-2 px-4 border-l border-white/10 shrink-0">
                        <template v-if="mapAddons.length">
                            <select :value="currentAddonId ?? ''"
                                    @change="currentAddonId = Number(($event.target as HTMLSelectElement).value) || null"
                                    class="bg-black border border-white/10 text-cyan-500 font-semibold text-xs py-1.5 px-3 rounded-lg outline-none focus:border-cyan-500">
                                <option v-for="a in mapAddons" :key="a.id" :value="a.id">
                                    {{ a.name }} · {{ Math.round(a.price_per_hour) }}₽/ч{{ a.billing_mode === 'optional' ? ' · опция' : '' }}
                                </option>
                            </select>
                            <span class="text-[11px] text-white/40 hidden lg:inline">клик по зоне · тяни PS</span>
                        </template>
                        <span v-else class="text-xs text-amber-400/90 font-semibold">
                            Создайте доп с ценой на странице тарифов
                        </span>
                    </div>

                    <button @click="isMagnetOn = !isMagnetOn"
                            :class="['px-4 py-1.5 text-xs font-semibold rounded-lg transition-colors border ml-2 shrink-0', isMagnetOn ? 'bg-cyan-500/20 border-cyan-500 text-cyan-400' : 'border-white/10 text-white/40 hover:text-white']">
                        🧲 {{ isMagnetOn ? 'МАГНИТ' : 'СВОБОДНО' }}
                    </button>

                    <template v-if="mode === 'walls'">
                        <button
                            @click="finishWall(false)"
                            :disabled="currentPoints.length < 2"
                            class="shrink-0 bg-cyan-600/20 border border-cyan-500 text-cyan-400 px-4 py-1.5 text-xs font-semibold rounded-lg hover:bg-cyan-600 hover:text-white transition-all disabled:opacity-30 disabled:hover:bg-cyan-600/20 disabled:hover:text-cyan-400">
                            Линия
                            <span v-if="currentPoints.length" class="ml-1 opacity-70">({{ currentPoints.length }})</span>
                        </button>
                        <button
                            @click="finishWall(true)"
                            :disabled="currentPoints.length < 3"
                            class="shrink-0 bg-blue-600/20 border border-blue-500 text-blue-400 px-4 py-1.5 text-xs font-semibold rounded-lg hover:bg-blue-600 hover:text-white transition-all disabled:opacity-30 disabled:hover:bg-blue-600/20 disabled:hover:text-blue-400">
                            Замкнуть контур
                        </button>
                        <span class="text-[10px] text-white/35 hidden xl:inline max-w-[260px] leading-tight">
                            тяни линию под любым углом · клики — ломаная · Shift — 0°/45°/90°
                        </span>
                    </template>
                    <button v-if="mode === 'pcs'" @click="syncDefaultPcs" class="bg-purple-500/20 text-purple-400 border border-purple-500/30 px-4 py-1.5 text-xs font-semibold rounded-lg hover:bg-purple-500 hover:text-white transition-all shrink-0">Добавить ПК</button>
                    <div v-if="mode === 'pcs'" class="flex items-center gap-2 ml-2 px-3 border-l border-white/10 shrink-0">
                        <button v-for="opt in seatKindOptions" :key="opt.id"
                                @click="currentSeatKind = opt.id"
                                :class="['px-3 py-1.5 text-xs font-semibold rounded-lg border transition-all',
                                         currentSeatKind === opt.id ? 'text-black' : 'text-white/50 border-white/10 hover:text-white']"
                                :style="currentSeatKind === opt.id
                                    ? { backgroundColor: opt.color, borderColor: opt.color }
                                    : { borderColor: opt.color + '55', color: opt.color }">
                            {{ opt.label }}
                        </button>
                        <span class="text-[11px] text-white/40 hidden xl:inline">клик = поставить</span>
                    </div>
                </div>

                <div class="flex gap-4 shrink-0">
                    <button @click="resetMap" class="text-red-500 text-xs font-semibold px-4 py-2 rounded-lg hover:bg-red-500/10 transition-colors">Сброс</button>
                    <button @click="saveToDB" :disabled="isSaving" class="bg-cyan-500 hover:bg-cyan-400 text-black px-8 py-2 text-sm font-semibold rounded-lg shadow-[0_0_15px_rgba(6,182,212,0.3)] disabled:opacity-30 transition-all">Сохранить</button>
                </div>
            </header>

            <div class="flex-1 flex overflow-hidden">
                <main class="flex-1 bg-[#020202] relative overflow-auto p-4 custom-scrollbar">
                    <svg ref="svgRef" :viewBox="viewbox" preserveAspectRatio="xMinYMin meet" overflow="hidden"
                         :class="['w-[150%] h-[200vh] border border-white/5 rounded-2xl bg-black select-none', mode === 'walls' ? 'cursor-crosshair' : '']"
                         @mousedown="handleSvgMouseDown" @mousemove="handleMouseMove" @mouseup="handleMouseUp" @mouseleave="handleMouseLeave" @dblclick="handleWallDblClick">
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
                        <g class="pointer-events-none" style="font-family: ui-sans-serif, system-ui, 'Segoe UI', Arial, sans-serif;">
                            <text v-for="tick in edgeLabelsX" :key="'xt'+tick.value"
                                  :x="tick.x" :y="tick.y"
                                  :font-size="axisLabelSize" fill="#67e8f9" fill-opacity="0.95"
                                  font-weight="600" text-anchor="middle" dominant-baseline="middle">{{ tick.value }}</text>
                            <text v-for="tick in edgeLabelsY" :key="'yt'+tick.value"
                                  :x="tick.x" :y="tick.y"
                                  :font-size="axisLabelSize" fill="#a5f3fc" fill-opacity="0.95"
                                  font-weight="600" text-anchor="start" dominant-baseline="middle">{{ tick.value }}</text>
                        </g>

                        <!-- Порядок слоёв снизу вверх: стены → зоны → текст → ПК -->
                        <g class="layer-walls">
                            <g v-for="(w, i) in walls" :key="'w'+i">
                                <path :d="w.d"
                                      fill="none"
                                      stroke="transparent"
                                      stroke-width="1.4"
                                      stroke-linejoin="round"
                                      stroke-linecap="round"
                                      pointer-events="stroke"
                                      :class="(mode === 'walls' && !currentPoints.length) || mode === 'erase' ? 'cursor-pointer' : 'pointer-events-none'"
                                      @mousedown.stop="onWallMouseDown($event, w)" />
                                <path :d="w.d" class="pointer-events-none"
                                      :fill="wallIsClosed(w.d) ? 'rgba(6,182,212,0.02)' : 'none'"
                                      :stroke="selectedWall === w ? '#fff' : '#06b6d4'"
                                      :stroke-width="selectedWall === w ? 0.35 : 0.2"
                                      stroke-linejoin="round" stroke-linecap="round" />
                                <g v-if="mode === 'walls' && selectedWall === w && !currentPoints.length">
                                    <circle v-for="(p, vi) in wallPathPoints(w)" :key="'wv'+i+'-'+vi"
                                            :cx="p.x" :cy="p.y" r="0.55"
                                            fill="#fff" stroke="#06b6d4" stroke-width="0.12"
                                            class="cursor-move"
                                            @mousedown.stop="startWallVertexDrag($event, w, vi)" />
                                </g>
                            </g>
                        </g>

                        <g class="layer-zones">
                            <g v-for="(z, i) in zones" :key="'z'+i"
                               :class="isLayerInteractive('zone') ? '' : 'pointer-events-none'"
                               @mousedown.stop="isLayerInteractive('zone') && handleItemMouseDown($event, z, 'zone')">
                                <polygon :points="zoneSvgPoints(z)"
                                      :fill="z.c || '#22c55e'" :fill-opacity="z.c === '#4d4d4d' ? 0.8 : 0.2"
                                      :stroke="selectedZone === z ? '#fff' : (mode === 'addons' && currentAddonId && zoneHasAddon(z, currentAddonId) ? '#fff' : (z.c || '#22c55e'))"
                                      :stroke-width="selectedZone === z || (mode === 'addons' && currentAddonId && zoneHasAddon(z, currentAddonId)) ? 0.35 : 0.15"
                                      :class="['transition-opacity', isLayerInteractive('zone') ? (selectedZone === z ? 'cursor-move' : 'hover:fill-opacity-50 cursor-pointer') : '']" />
                                <polygon v-if="mode === 'zones'"
                                      :points="zoneSvgPoints(z)"
                                      fill="none" stroke="transparent" stroke-width="1.4"
                                      pointer-events="stroke" class="cursor-move" />
                                <g v-if="mode === 'zones' && selectedZone === z">
                                    <line :x1="zoneRotateHandle(z).x" :y1="zoneRotateHandle(z).fromY"
                                          :x2="zoneRotateHandle(z).x" :y2="zoneRotateHandle(z).y"
                                          stroke="#fff" stroke-width="0.12" class="pointer-events-none" />
                                    <circle :cx="zoneRotateHandle(z).x" :cy="zoneRotateHandle(z).y" r="0.55"
                                            fill="#06b6d4" stroke="#fff" stroke-width="0.12"
                                            class="cursor-grab"
                                            @mousedown.stop="startZoneRotate($event, z)" />
                                    <rect v-for="h in zoneVertexHandles(z)" :key="'zh'+h.id"
                                          :x="h.x - 0.45" :y="h.y - 0.45" width="0.9" height="0.9"
                                          fill="#fff" stroke="#06b6d4" stroke-width="0.12"
                                          class="cursor-move"
                                          @mousedown.stop="startZoneVertexDrag($event, z, h.id)" />
                                </g>
                                <g v-if="zoneBadgeMeta(z)" class="pointer-events-none">
                                    <rect
                                        :x="zoneBadgeMeta(z).x"
                                        :y="zoneBadgeMeta(z).y"
                                        :width="zoneBadgeMeta(z).w"
                                        :height="zoneBadgeMeta(z).h"
                                        :rx="ZONE_BADGE_RX"
                                        :ry="ZONE_BADGE_RX"
                                        fill="rgba(0,0,0,0.72)"
                                        stroke="rgba(255,255,255,0.28)"
                                        stroke-width="0.12"
                                    />
                                    <text
                                        :x="zoneBadgeMeta(z).cx"
                                        :y="zoneBadgeMeta(z).titleY"
                                        text-anchor="middle"
                                        dominant-baseline="central"
                                        fill="#ffffff"
                                        fill-opacity="0.92"
                                        :font-size="ZONE_BADGE_FONT"
                                        font-weight="700"
                                        font-family="ui-sans-serif, system-ui, -apple-system, Segoe UI, Arial, sans-serif"
                                        letter-spacing="0.04em"
                                        class="uppercase"
                                    >{{ zoneBadgeMeta(z).title }}</text>
                                    <text
                                        v-if="zoneBadgeMeta(z).sub"
                                        :x="zoneBadgeMeta(z).cx"
                                        :y="zoneBadgeMeta(z).subY"
                                        text-anchor="middle"
                                        dominant-baseline="central"
                                        fill="#ffffff"
                                        fill-opacity="0.78"
                                        :font-size="ZONE_BADGE_SUB_FONT"
                                        font-weight="700"
                                        font-family="ui-sans-serif, system-ui, -apple-system, Segoe UI, Arial, sans-serif"
                                        letter-spacing="0.04em"
                                        class="uppercase"
                                    >{{ zoneBadgeMeta(z).sub }}</text>
                                </g>
                                <g v-for="(badge, bi) in zoneOptionalAddonBadges(z)" :key="'op'+i+'-'+badge.id"
                                   :class="mode === 'erase' ? 'pointer-events-none' : 'cursor-move pointer-events-auto'"
                                   @mousedown.stop="startAddonDrag($event, z, badge, bi)">
                                    <rect
                                        :x="zoneOptionalAddonSpot(z, badge, bi).x"
                                        :y="zoneOptionalAddonSpot(z, badge, bi).y"
                                        width="6" height="4.5"
                                        rx="0.55" ry="0.55"
                                        fill="#001100"
                                        :stroke="(selectedAddon?.addonId === badge.id && selectedAddon?.zone === z) || (dragAddon?.addonId === badge.id && dragAddon?.zone === z) ? '#fff' : '#22c55e'"
                                        stroke-width="0.2"
                                    />
                                    <text
                                        :x="zoneOptionalAddonSpot(z, badge, bi).x + 3"
                                        :y="zoneOptionalAddonSpot(z, badge, bi).y + 1.85"
                                        text-anchor="middle"
                                        font-size="1.65"
                                        font-weight="800"
                                        font-family="ui-sans-serif, system-ui, -apple-system, Segoe UI, Arial, sans-serif"
                                        fill="#22c55e"
                                        class="uppercase pointer-events-none"
                                    >{{ badge.name }}</text>
                                    <text
                                        :x="zoneOptionalAddonSpot(z, badge, bi).x + 3"
                                        :y="zoneOptionalAddonSpot(z, badge, bi).y + 3.45"
                                        text-anchor="middle"
                                        font-size="0.95"
                                        font-weight="700"
                                        font-family="ui-sans-serif, system-ui, -apple-system, Segoe UI, Arial, sans-serif"
                                        fill="#22c55e"
                                        fill-opacity="0.75"
                                        letter-spacing="0.06em"
                                        class="uppercase pointer-events-none"
                                    >опция</text>
                                </g>
                            </g>

                            <g v-for="m in roomInfoMarkers" :key="m.key" class="pointer-events-none">
                                <circle
                                    :cx="m.cx" :cy="m.cy" :r="INFO_MARKER_R"
                                    fill="#0a0a0a"
                                    :stroke="selectedZone === m.zone ? '#fff' : 'rgba(255,255,255,0.55)'"
                                    stroke-width="0.18"
                                />
                                <text
                                    :x="m.cx" :y="m.cy + 0.15"
                                    text-anchor="middle"
                                    dominant-baseline="central"
                                    fill="#ffffff"
                                    font-size="1.55"
                                    font-weight="800"
                                    font-family="ui-sans-serif, system-ui, -apple-system, Segoe UI, Arial, sans-serif"
                                >?</text>
                            </g>
                        </g>

                        <g class="layer-labels">
                            <g v-for="(l, i) in labels" :key="'l'+i"
                               :class="isLayerInteractive('label') ? 'cursor-pointer' : 'pointer-events-none'"
                               @mousedown.stop="isLayerInteractive('label') && handleItemMouseDown($event, l, 'label')">
                                <!-- Невидимая зона клика: по глифам текста почти не попасть -->
                                <rect
                                    :x="safeNum(l.x) - safeNum(l.size, 6) * 0.2"
                                    :y="safeNum(l.y) - safeNum(l.size, 6) * 0.85"
                                    :width="Math.max(safeNum(l.size, 6) * Math.max(2, String(l.content || '').length * 0.65), 6)"
                                    :height="safeNum(l.size, 6) * 1.2"
                                    fill="transparent"
                                />
                                <text :x="safeNum(l.x)" :y="safeNum(l.y)"
                                      :class="[
                                          'uppercase select-none transition-all pointer-events-none',
                                          selectedLabel === l ? 'drop-shadow-[0_0_10px_rgba(255,255,255,0.8)] fill-white' : ''
                                      ]"
                                      :transform="l.rotate ? `rotate(${l.rotate} ${safeNum(l.x)} ${safeNum(l.y)})` : ''"
                                      :fill="l.color || '#ffffff'" :font-size="Math.max(0.3, safeNum(l.size, 6))" font-weight="700">{{ l.content || 'ТЕКСТ' }}</text>
                            </g>
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
                        <polyline v-if="wallDraftPoints.length" class="pointer-events-none" :points="wallDraftPoints.map(p => `${p.x},${p.y}`).join(' ')" fill="none" stroke="#06b6d4" stroke-width="0.3" stroke-dasharray="1,1" stroke-linecap="round" />
                        <circle v-for="(p, i) in currentPoints" :key="'wp'+i" class="pointer-events-none" :cx="p.x" :cy="p.y" r="0.4" fill="#06b6d4" />
                        </g>
                    </svg>
                </main>

                <aside class="w-80 border-l border-white/5 p-6 bg-[#0a0a0a] flex flex-col gap-6 shrink-0 overflow-y-auto relative z-10">
                    <div class="bg-white/5 p-4 rounded-2xl border border-white/5">
                        <label class="text-[11px] text-cyan-500 block mb-2 font-semibold">Выбор локации</label>
                        <div class="flex gap-2">
                            <select v-model.number="activeClubId" @change="loadFromDB" class="w-full bg-black p-3 text-xs text-white font-bold outline-none rounded-xl border border-white/10 focus:border-cyan-500">
                                <option v-for="club in clubList" :key="club.id" :value="club.id">{{ club.name }}</option>
                            </select>
                        </div>
                    </div>

                    <div v-if="selectedWall && mode === 'walls'" class="p-4 bg-cyan-500/5 border border-cyan-500/20 rounded-2xl animate-in zoom-in duration-200 flex flex-col gap-3">
                        <p class="text-[10px] text-cyan-500 font-black uppercase tracking-widest">Линия</p>
                        <p class="text-[11px] text-white/60 leading-snug">
                            Тяни белые точки — линия под любым углом. Shift удерживает 0° / 45° / 90°.
                        </p>
                        <button @click="deleteSelectedWall"
                                class="text-[10px] font-black tracking-widest uppercase bg-red-500/10 hover:bg-red-500 text-red-400 hover:text-white py-2.5 rounded-xl transition-colors border border-red-500/20">
                            Удалить линию
                        </button>
                    </div>

                    <div v-if="selectedZone" class="p-4 bg-white/5 border border-white/10 rounded-2xl animate-in zoom-in duration-200 flex flex-col gap-3">
                        <p class="text-[10px] text-cyan-500 font-black uppercase tracking-widest">
                            Комната · {{ zoneAutoTitle(selectedZone) || 'ZONE' }}
                        </p>

                        <div v-if="topologyZones.length">
                            <label class="text-[11px] text-white/50 block mb-1.5 font-semibold">Тип зоны</label>
                            <select :value="selectedZone.type"
                                    @change="applySelectedZoneType(($event.target as HTMLSelectElement).value)"
                                    class="w-full bg-black border border-white/10 text-white text-xs px-3 py-2 rounded-lg outline-none focus:border-cyan-500 font-black uppercase"
                                    :style="{ color: selectedZone.c || '#22c55e' }">
                                <option v-for="z in topologyZones" :key="z.id" :value="z.slug">{{ z.name }}</option>
                            </select>
                        </div>

                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <label class="text-[11px] text-white/50 block mb-1.5 font-semibold">X</label>
                                <input v-model.number="selectedZone.x" type="number" step="0.1"
                                       class="w-full bg-black border border-white/10 text-white text-xs px-3 py-2 rounded-lg outline-none focus:border-cyan-500 font-mono" />
                            </div>
                            <div>
                                <label class="text-[11px] text-white/50 block mb-1.5 font-semibold">Y</label>
                                <input v-model.number="selectedZone.y" type="number" step="0.1"
                                       class="w-full bg-black border border-white/10 text-white text-xs px-3 py-2 rounded-lg outline-none focus:border-cyan-500 font-mono" />
                            </div>
                            <div>
                                <label class="text-[11px] text-white/50 block mb-1.5 font-semibold">Ширина</label>
                                <input v-model.number="selectedZone.w" type="number" step="0.1" min="0.5"
                                       class="w-full bg-black border border-white/10 text-white text-xs px-3 py-2 rounded-lg outline-none focus:border-cyan-500 font-mono" />
                            </div>
                            <div>
                                <label class="text-[11px] text-white/50 block mb-1.5 font-semibold">Высота</label>
                                <input v-model.number="selectedZone.h" type="number" step="0.1" min="0.5"
                                       class="w-full bg-black border border-white/10 text-white text-xs px-3 py-2 rounded-lg outline-none focus:border-cyan-500 font-mono" />
                            </div>
                        </div>

                        <template v-if="isTvZone(selectedZone)">
                            <div>
                                <label class="text-[11px] text-white/50 block mb-1.5 font-semibold">Диагональ экрана</label>
                                <input v-model="selectedZone.info.screen_diagonal" type="text" placeholder="55&quot;"
                                       class="w-full bg-black border border-white/10 text-white text-xs px-3 py-2 rounded-lg outline-none focus:border-cyan-500" />
                            </div>
                            <div>
                                <label class="text-[11px] text-white/50 block mb-1.5 font-semibold">Модель PS</label>
                                <input v-model="selectedZone.info.ps_model" type="text" placeholder="PlayStation 5"
                                       class="w-full bg-black border border-white/10 text-white text-xs px-3 py-2 rounded-lg outline-none focus:border-cyan-500" />
                            </div>
                        </template>
                        <template v-else>
                            <div>
                                <label class="text-[11px] text-white/50 block mb-1.5 font-semibold">Процессор</label>
                                <input v-model="selectedZone.info.cpu" type="text" placeholder="AMD Ryzen…"
                                       class="w-full bg-black border border-white/10 text-white text-xs px-3 py-2 rounded-lg outline-none focus:border-cyan-500" />
                            </div>
                            <div>
                                <label class="text-[11px] text-white/50 block mb-1.5 font-semibold">Видеокарта</label>
                                <input v-model="selectedZone.info.gpu" type="text" placeholder="RTX…"
                                       class="w-full bg-black border border-white/10 text-white text-xs px-3 py-2 rounded-lg outline-none focus:border-cyan-500" />
                            </div>
                            <div>
                                <label class="text-[11px] text-white/50 block mb-1.5 font-semibold">Монитор</label>
                                <input v-model="selectedZone.info.monitor" type="text" placeholder="27&quot; 240Hz"
                                       class="w-full bg-black border border-white/10 text-white text-xs px-3 py-2 rounded-lg outline-none focus:border-cyan-500" />
                            </div>
                        </template>

                        <div>
                            <label class="text-[11px] text-white/50 block mb-1.5 font-semibold">Край «?»</label>
                            <select v-model="selectedZone.info.info_edge"
                                    class="w-full bg-black border border-white/10 text-white text-xs px-3 py-2 rounded-lg outline-none focus:border-cyan-500 font-black uppercase">
                                <option value="">Авто (проход)</option>
                                <option value="right">Справа</option>
                                <option value="left">Слева</option>
                                <option value="top">Сверху</option>
                                <option value="bottom">Снизу</option>
                            </select>
                        </div>
                        <button @click="deleteSelectedZone"
                                class="text-[10px] font-black tracking-widest uppercase bg-red-500/10 hover:bg-red-500 text-red-400 hover:text-white py-2.5 rounded-xl transition-colors border border-red-500/20">
                            Удалить зону
                        </button>
                        <p class="text-[9px] opacity-40 italic">Линия — сдвиг. Угол комнаты тяни — получится скос, как трапеция. Кружок сверху — поворот. Shift — 0°/45°/90°.</p>
                    </div>

                    <div v-if="selectedPc" class="p-4 bg-cyan-500/5 border border-cyan-500/20 rounded-2xl animate-in zoom-in duration-200 flex flex-col gap-3">
                        <p class="text-[10px] text-cyan-500 font-black uppercase tracking-widest">Маркер</p>
                        <div>
                            <label class="text-[11px] text-white/50 block mb-1.5 font-semibold">Имя</label>
                            <input v-model="selectedPc.name" type="text" class="w-full bg-black border border-white/10 text-white text-xs px-3 py-2 rounded-lg outline-none focus:border-cyan-500 font-black" />
                        </div>
                        <div>
                            <label class="text-[11px] text-white/50 block mb-1.5 font-semibold">Тип</label>
                            <select v-model="selectedPc.kind"
                                    @change="selectedPc.booth_id = (selectedPc.kind === 'tv' || selectedPc.kind === 'ps5') ? boothIdForPoint(Number(selectedPc.x), Number(selectedPc.y)) : null"
                                    class="w-full bg-black border border-white/10 text-white text-xs px-3 py-2 rounded-lg outline-none focus:border-cyan-500 font-black uppercase">
                                <option v-for="opt in seatKindOptions" :key="opt.id" :value="opt.id">{{ opt.label }}</option>
                            </select>
                        </div>
                        <div v-if="selectedPc.kind === 'tv' || selectedPc.kind === 'ps5'">
                            <label class="text-[11px] text-white/50 block mb-1.5 font-semibold">Кабина (booth)</label>
                            <input v-model="selectedPc.booth_id" type="text" placeholder="авто из зоны"
                                   class="w-full bg-black border border-white/10 text-white text-xs px-3 py-2 rounded-lg outline-none focus:border-cyan-500 font-mono" />
                            <p class="text-[9px] opacity-40 italic mt-1">Одинаковый booth у TV и PS одной зоны</p>
                        </div>
                        <p class="text-[9px] opacity-40 italic">Мышь или стрелки (1px; Shift — ×10).</p>
                    </div>

                    <div v-if="selectedAddon" class="p-4 bg-[#22c55e]/5 border border-[#22c55e]/20 rounded-2xl animate-in zoom-in duration-200 flex flex-col gap-2">
                        <p class="text-[10px] text-[#22c55e] font-black uppercase tracking-widest">Опция на карте</p>
                        <p class="text-[11px] text-white/70 font-bold uppercase">
                            {{ mapAddons.find(a => a.id === selectedAddon.addonId)?.name || 'PS' }}
                        </p>
                        <p class="text-[9px] opacity-40 italic">Мышь или стрелки (1px; Shift — ×10).</p>
                    </div>

                    <div v-if="selectedLabel" class="p-4 bg-white/5 border border-white/10 rounded-2xl flex flex-col gap-4 animate-in zoom-in duration-200">
                        <div>
                            <label class="text-[11px] text-white/50 block mb-1.5 font-semibold">Текст метки</label>
                            <input v-model="selectedLabel.content" type="text" class="w-full bg-black border border-white/10 text-white text-xs px-3 py-3 rounded-lg outline-none focus:border-cyan-500 transition-colors font-black" />
                        </div>
                        <div>
                            <label class="text-[11px] text-white/50 block mb-1.5 font-semibold">Размер</label>
                            <div class="flex items-center gap-3">
                                <input type="range" v-model.number="selectedLabel.size" min="0.3" max="30" step="0.1" class="flex-1 accent-cyan-500">
                                <input type="number" v-model.number="selectedLabel.size" min="0.3" max="30" step="0.1"
                                       class="w-16 bg-black border border-white/10 text-white text-xs px-2 py-1.5 rounded-lg outline-none focus:border-cyan-500 text-center" />
                            </div>
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
