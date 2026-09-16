<template>
    <div class="map-wrapper w-full h-full relative rounded-[16px] overflow-hidden border border-white/5 bg-[#020202] flex items-center justify-center">
        <svg
            :viewBox="displayViewbox"
            preserveAspectRatio="xMidYMid meet"
            class="block w-full h-full select-none"
        >
            <defs>
                <filter id="wall-glow" x="-50%" y="-50%" width="200%" height="200%">
                    <feGaussianBlur stdDeviation="0.25" result="blur" />
                    <feMerge>
                        <feMergeNode in="blur" /><feMergeNode in="SourceGraphic" />
                    </feMerge>
                </filter>
                <clipPath v-for="z in zoneVisuals" :id="z.clipId" :key="'clip-'+z.clipId">
                    <polygon :points="z.points" />
                </clipPath>
            </defs>

            <g v-if="safeConfig?.walls" class="walls-layer">
                <path
                    v-for="(wall, i) in safeConfig.walls" :key="'w-'+i"
                    :d="wall.d"
                    :fill="wallIsClosed(wall.d) ? 'rgba(34,197,94,0.02)' : 'none'"
                    stroke="#22c55e"
                    stroke-width="0.2"
                    stroke-linejoin="round"
                    stroke-linecap="round"
                    filter="url(#wall-glow)"
                />
            </g>

            <g v-if="zoneVisuals.length" class="zones-layer">
                <g v-for="z in zoneVisuals" :key="'zr-'+z.clipId">
                    <polygon
                        :points="z.points"
                        :fill="z.r.c || '#22c55e'"
                        :fill-opacity="zoneFillOpacity(z.r)"
                        :stroke="z.r.c || '#22c55e'"
                        :stroke-opacity="isServiceZone(z.r) ? 0.1 : 1"
                        stroke-width="0.15"
                    />
                    <g v-if="z.badge" class="pointer-events-none" :clip-path="`url(#${z.clipId})`">
                        <rect
                            :x="z.badge.x"
                            :y="z.badge.y"
                            :width="z.badge.w"
                            :height="z.badge.h"
                            :rx="ZONE_BADGE_RX"
                            :ry="ZONE_BADGE_RX"
                            fill="rgba(0,0,0,0.72)"
                            stroke="rgba(255,255,255,0.22)"
                            stroke-width="0.06"
                        />
                        <text
                            :x="z.badge.cx"
                            :y="z.badge.titleY"
                            text-anchor="middle"
                            dominant-baseline="central"
                            fill="#ffffff"
                            fill-opacity="0.92"
                            :font-size="z.badge.font"
                            font-weight="700"
                            font-family="ui-sans-serif, system-ui, -apple-system, Segoe UI, Arial, sans-serif"
                            letter-spacing="0.01em"
                            class="uppercase"
                        >{{ z.badge.title }}</text>
                        <text
                            v-if="z.badge.sub"
                            :x="z.badge.cx"
                            :y="z.badge.subY"
                            text-anchor="middle"
                            dominant-baseline="central"
                            fill="#ffffff"
                            fill-opacity="0.78"
                            :font-size="z.badge.subFont"
                            font-weight="700"
                            font-family="ui-sans-serif, system-ui, -apple-system, Segoe UI, Arial, sans-serif"
                            letter-spacing="0.01em"
                            class="uppercase"
                        >{{ z.badge.sub }}</text>
                    </g>
                </g>
            </g>

            <g v-if="visibleLabels.length" class="labels-layer">
                <text
                    v-for="(l, i) in visibleLabels" :key="'l-'+i"
                    :x="l.x" :y="l.y"
                    :transform="l.rotate ? `rotate(${l.rotate} ${l.x} ${l.y})` : ''"
                    :fill="l.color || '#ffffff'"
                    fill-opacity="0.85"
                    :font-size="Math.max(0.3, Number(l.size) || 6)"
                    font-weight="700"
                    font-family="Arial, sans-serif"
                    class="uppercase pointer-events-none"
                >
                    {{ l.content }}
                </text>
            </g>

            <g v-for="pc in computers" :key="'pc-'+pc.id"
               :class="isOccupied(pc.id) ? 'cursor-not-allowed' : 'cursor-pointer group'"
               @click="handleClick(pc)">
                <rect
                    :x="Number(pc.x)" :y="Number(pc.y)"
                    width="6" height="4.5"
                    rx="0"
                    :fill="seatFill(pc)"
                    :stroke="seatStroke(pc)"
                    :stroke-width="isOccupied(pc.id) ? 0.15 : 0.2"
                    :opacity="isOccupied(pc.id) ? 0.55 : 1"
                    class="transition-colors duration-300"
                    :class="isOccupied(pc.id) ? '' : 'group-hover:stroke-white'"
                />
                <text
                    :x="Number(pc.x) + 3" :y="Number(pc.y) + 3.1"
                    font-size="2"
                    font-weight="900"
                    font-family="Arial, sans-serif"
                    text-anchor="middle"
                    :fill="seatText(pc)"
                    class="pointer-events-none transition-colors duration-300"
                >
                    {{ pc.name }}
                </text>
            </g>

            <!-- Опциональные допы (PS): зелёный квадрат в свободном месте зоны -->
            <g v-for="m in optionalAddonMarkers" :key="'addon-'+m.key"
               :class="m.blocked ? 'cursor-not-allowed opacity-55' : 'cursor-pointer group'"
               @click.stop="handleAddonClick(m)">
                <rect
                    :x="m.x" :y="m.y"
                    :width="PC_W" :height="PC_H"
                    rx="0.55" ry="0.55"
                    :fill="addonFill(m)"
                    :stroke="addonStroke(m)"
                    stroke-width="0.2"
                    class="transition-colors duration-300"
                    :class="m.blocked ? '' : 'group-hover:stroke-white'"
                />
                <text
                    :x="m.x + PC_W / 2" :y="m.y + 1.85"
                    font-size="1.65"
                    font-weight="800"
                    font-family="ui-sans-serif, system-ui, -apple-system, Segoe UI, Arial, sans-serif"
                    text-anchor="middle"
                    :fill="addonText(m)"
                    class="uppercase pointer-events-none transition-colors duration-300"
                >{{ m.label }}</text>
                <text
                    :x="m.x + PC_W / 2" :y="m.y + 3.45"
                    font-size="0.95"
                    font-weight="700"
                    font-family="ui-sans-serif, system-ui, -apple-system, Segoe UI, Arial, sans-serif"
                    text-anchor="middle"
                    :fill="addonText(m)"
                    fill-opacity="0.75"
                    class="uppercase pointer-events-none transition-colors duration-300"
                    letter-spacing="0.06em"
                >опция</text>
            </g>

            <!-- Знак «?» на краю комнаты, выходящем в проход (поверх мест) -->
            <g v-for="m in roomInfoMarkers" :key="'info-'+m.key"
               class="cursor-pointer"
               @click.stop="emit('show-info', m.payload)">
                <circle
                    :cx="m.cx" :cy="m.cy" :r="INFO_MARKER_R"
                    fill="#0a0a0a"
                    stroke="rgba(255,255,255,0.55)"
                    stroke-width="0.18"
                    class="transition-opacity hover:opacity-90"
                />
                <text
                    :x="m.cx" :y="m.cy + 0.15"
                    text-anchor="middle"
                    dominant-baseline="central"
                    fill="#ffffff"
                    font-size="1.55"
                    font-weight="800"
                    font-family="ui-sans-serif, system-ui, -apple-system, Segoe UI, Arial, sans-serif"
                    class="pointer-events-none"
                >?</text>
            </g>
        </svg>
    </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import {
    INFO_MARKER_R,
    isTvZone,
    resolveInfoEdge,
    type RoomInfoFields,
} from '@/utils/roomInfoEdge'
import {
    localToWorld,
    pointInZone,
    zoneEdgeMidpoint,
    zoneSvgPoints,
    zoneWorldCorners,
} from '@/utils/zoneGeom'

export type RoomInfoShowPayload = {
    title: string
    color: string
    kind: 'pc' | 'tv'
    info: RoomInfoFields
}

const props = withDefaults(defineProps<{
    selectedIds?: string[],
    selectedAddonKeys?: string[],
    occupiedIds?: string[],
    computers?: any[],
    mapConfig?: any,
    viewbox?: string
}>(), {
    computers: () => [],
    selectedIds: () => [],
    selectedAddonKeys: () => [],
    occupiedIds: () => []
})

const emit = defineEmits<{
    (e: 'toggle-seat', id: string): void
    (e: 'toggle-addon-seats', payload: { addonId: number; seatIds: string[] }): void
    (e: 'seat-error'): void
    (e: 'show-info', payload: RoomInfoShowPayload): void
}>()

const PC_W = 6
const wallIsClosed = (d: unknown) => /z\s*$/i.test(String(d || '').trim())
const PC_H = 4.5
const FIT_PAD = 8
const clipNs = `zclip-${Math.random().toString(36).slice(2, 9)}`

const safeConfig = computed(() => {
    let data = props.mapConfig;
    if (!data) return null;
    if (typeof data === 'string') {
        try {
            data = JSON.parse(data);
            if (typeof data === 'string') data = JSON.parse(data);
        } catch { return null; }
    }
    return data;
})

const drawableZones = computed(() =>
    (safeConfig.value?.zoneRects || []).filter((z: any) =>
        z && Number(z.w) >= 0.5 && Number(z.h) >= 0.5
    )
)

const roomInfoMarkers = computed(() => {
    const zones = drawableZones.value
    return zones.flatMap((r: any, i: number) => {
        if (isServiceZone(r)) return []
        const others = zones.filter((_: any, j: number) => j !== i)
        const override = r.info_edge || r.info?.info_edge || null
        const edge = resolveInfoEdge(r, others, override)
        const { cx, cy } = zoneEdgeMidpoint(r, edge)
        const title = zoneTitle(r) || 'Комната'
        const kind: 'pc' | 'tv' = (r.info_kind === 'tv' || isTvZone(r)) ? 'tv' : 'pc'
        return [{
            key: `${i}-${edge}`,
            cx,
            cy,
            payload: {
                title,
                color: String(r.c || '#22c55e'),
                kind,
                info: (r.info && typeof r.info === 'object') ? r.info : {},
            } satisfies RoomInfoShowPayload,
        }]
    })
})

const HIDDEN_MANUAL_LABELS = new Set([
    'STANDART', 'STANDARD', 'СТАНДАРТ',
    'VIP', 'SOLO', 'SINGL', 'DUO', 'TRIO', 'KVATRO',
    'BOOTCAMP', 'BOOTCAMP PRO', 'BOOTCAMP-PRO', 'BOOTKAMP', 'BOTKAMP', 'BOTKAMP-PROFI', 'BOOTKAMP-PROFI', 'BOOTCAMP-PROFI',
    'TV', 'PS5', 'PS', 'WC', 'SERVICE', 'ТЕКСТ', 'TEXT',
])

const isPlaceholderLabel = (content: unknown) => {
    const text = String(content ?? '').trim().toUpperCase()
    return text === '' || HIDDEN_MANUAL_LABELS.has(text)
}

const zoneHasBoundSeat = (r: any) => {
    const pcs = props.computers || []
    const spaceId = Number(r?.space_id)
    if (spaceId > 0 && pcs.some((pc: any) => Number(pc.space_id) === spaceId)) {
        return true
    }
    return pcs.some((pc: any) =>
        pointInZoneRect(Number(pc.x), Number(pc.y), r)
        || pointInZoneRect(Number(pc.x) + PC_W / 2, Number(pc.y) + PC_H / 2, r)
    )
}

const isServiceZone = (r: any) => {
    if (zoneHasBoundSeat(r)) return false
    if (r?.service === false) return false
    return true
}

const zoneFillOpacity = (r: any) => {
    if (isServiceZone(r)) return 0.1
    return r.c === '#4d4d4d' ? 1 : 0.25
}

const zoneTitle = (r: any) => {
    if (isServiceZone(r)) return 'SERVICE'
    const label = String(r?.label || '').trim()
    if (label) return label.replace(/[-_]/g, ' ').toUpperCase()
    const type = String(r?.type || '').trim()
    return type ? type.replace(/[-_]/g, ' ').toUpperCase() : ''
}

const ZONE_BADGE_FONT_MAX = 1.02
const ZONE_BADGE_FONT_MIN = 0.82
const ZONE_BADGE_GAP = 1.35
const ZONE_BADGE_PAD_X = 0.48
const ZONE_BADGE_PAD_Y = 0.2
const ZONE_BADGE_RX = 0.32
const ZONE_BADGE_CHAR_W = 0.82

const estimateBadgeTextWidth = (text: string, fontSize: number) => {
    const t = text.trim()
    if (!t) return 0
    const letters = t.length * fontSize * ZONE_BADGE_CHAR_W
    const tracking = Math.max(0, t.length - 1) * fontSize * 0.01
    return letters + tracking
}

const zoneBadge = (r: any) => {
    const title = zoneTitle(r)
    if (!title) return null
    const extras = isServiceZone(r) ? [] : alwaysAddons(r)
        .map((a: any) => String(a?.name || '').trim().toUpperCase())
        .filter(Boolean)
    const sub = extras.length ? extras.join(' ') : ''
    const zw = Number(r.w) || 0
    const zh = Number(r.h) || 0
    const gapX = Math.max(1.15, Math.min(ZONE_BADGE_GAP, zw * 0.16))
    const gapY = Math.max(0.95, Math.min(ZONE_BADGE_GAP, zh * 0.14))
    const innerW = Math.max(2.2, zw - gapX * 2)

    const widthAt = (fs: number) =>
        Math.max(estimateBadgeTextWidth(title, fs), sub ? estimateBadgeTextWidth(sub, fs * 0.86) : 0)
        + ZONE_BADGE_PAD_X * 2

    let font = ZONE_BADGE_FONT_MAX
    const natural = widthAt(font)
    if (natural > innerW) {
        font = Math.max(ZONE_BADGE_FONT_MIN, font * innerW / natural)
    }
    const subFont = sub ? font * 0.86 : 0
    const w = Math.min(widthAt(font), innerW)
    const lineGap = sub ? 0.16 : 0
    const h = (sub ? font + lineGap + subFont : font) + ZONE_BADGE_PAD_Y * 2
    const zx = Number(r.x)
    const zy = Number(r.y)
    const x = Math.max(zx + gapX, zx + zw - gapX - w)
    const y = zy + gapY
    const titleY = sub
        ? y + ZONE_BADGE_PAD_Y + font / 2
        : y + h / 2
    const subY = y + ZONE_BADGE_PAD_Y + font + lineGap + subFont / 2
    return {
        title,
        sub,
        font,
        subFont,
        x,
        y,
        w,
        h,
        cx: x + w / 2,
        titleY,
        subY,
    }
}

const zoneVisuals = computed(() =>
    drawableZones.value.map((r: any, i: number) => ({
        r,
        points: zoneSvgPoints(r),
        badge: zoneBadge(r),
        clipId: `${clipNs}-${i}`,
    }))
)

const zoneAddons = (r: any) =>
    Array.isArray(r?.addons) ? r.addons : []

const alwaysAddons = (r: any) =>
    zoneAddons(r).filter((a: any) => a?.billing_mode !== 'optional')

const optionalAddons = (r: any) =>
    zoneAddons(r).filter((a: any) => a?.billing_mode === 'optional')

const pointInZoneRect = (x: number, y: number, zone: any) =>
    pointInZone(zone, x, y)

const seatsInZone = (zone: any) =>
    (props.computers || []).filter((pc: any) =>
        pointInZoneRect(Number(pc.x) + PC_W / 2, Number(pc.y) + PC_H / 2, zone)
    )

const rectsOverlap = (ax: number, ay: number, aw: number, ah: number, bx: number, by: number, bw: number, bh: number) =>
    ax < bx + bw && ax + aw > bx && ay < by + bh && ay + ah > by

const findFreeSpot = (zone: any, seats: any[], used: Array<{ x: number; y: number }>) => {
    const zx = Number(zone.x)
    const zy = Number(zone.y)
    const zw = Number(zone.w)
    const zh = Number(zone.h)
    const pad = 0.6
    const candidates: Array<{ x: number; y: number }> = [
        // Свободная середина справа — типичная TV-комната: место слева, PS справа
        { x: zx + zw - PC_W - pad, y: zy + Math.max(pad, (zh - PC_H) / 2) },
        { x: zx + zw - PC_W - pad, y: zy + pad },
        { x: zx + zw - PC_W - pad, y: zy + zh - PC_H - pad },
        { x: zx + pad, y: zy + zh - PC_H - pad },
        { x: zx + Math.max(pad, (zw - PC_W) / 2), y: zy + zh - PC_H - pad },
        { x: zx + pad, y: zy + Math.max(pad, (zh - PC_H) / 2) },
    ]

    const blocked = [
        ...seats.map((pc: any) => ({ x: Number(pc.x), y: Number(pc.y), w: PC_W, h: PC_H })),
        ...used.map(u => ({ x: u.x, y: u.y, w: PC_W, h: PC_H })),
    ]

    for (const c of candidates) {
        if (c.x < zx + 0.2 || c.y < zy + 0.2) continue
        if (c.x + PC_W > zx + zw - 0.2 || c.y + PC_H > zy + zh - 0.2) continue
        const world = localToWorld(zone, c.x, c.y)
        const hits = blocked.some(b => rectsOverlap(world.x, world.y, PC_W, PC_H, b.x, b.y, b.w, b.h))
        if (!hits) return world
    }

    // Fallback — правый край, даже если тесно
    return localToWorld(zone, zx + Math.max(pad, zw - PC_W - pad), zy + Math.max(pad, (zh - PC_H) / 2))
}

type AddonMarker = {
    key: string
    addonId: number
    label: string
    x: number
    y: number
    seatIds: string[]
    blocked: boolean
    active: boolean
}

const addonLinkKey = (addonId: number, seatIds: string[]) =>
    `${addonId}:${[...seatIds].map(String).sort().join(',')}`

const optionalAddonMarkers = computed<AddonMarker[]>(() => {
    const markers: AddonMarker[] = []

    drawableZones.value.forEach((zone: any, zi: number) => {
        if (isServiceZone(zone)) return
        const seats = seatsInZone(zone)
        const used: Array<{ x: number; y: number }> = []
        optionalAddons(zone).forEach((addon: any, ai: number) => {
            const saved = zone.addon_positions?.[String(addon.id)] ?? zone.addon_positions?.[addon.id]
            const spot = (saved && Number.isFinite(Number(saved.x)) && Number.isFinite(Number(saved.y)))
                ? { x: Number(saved.x), y: Number(saved.y) }
                : findFreeSpot(zone, seats, used)
            used.push(spot)
            const allSeatIds = seats.map((pc: any) => pc.id.toString())
            const freeSeatIds = allSeatIds.filter((id: string) => !isOccupied(id))
            const preferred = freeSeatIds.filter((id: string) => {
                const pc = seats.find((s: any) => s.id.toString() === id)
                const kind = String(pc?.kind || 'pc')
                return kind === 'tv' || kind === 'ps5'
            })
            const targetIds = preferred.length ? preferred : freeSeatIds
            const active = targetIds.length > 0
                && props.selectedAddonKeys.includes(addonLinkKey(Number(addon.id) || 0, targetIds))
            markers.push({
                key: `${zi}-${addon.id || ai}`,
                addonId: Number(addon.id) || 0,
                label: String(addon.name || 'PS').trim().toUpperCase() || 'PS',
                x: spot.x,
                y: spot.y,
                seatIds: allSeatIds,
                blocked: freeSeatIds.length === 0,
                active,
            })
        })
    })

    return markers
})

const visibleLabels = computed(() =>
    (safeConfig.value?.labels || []).filter((l: any) => l && !isPlaceholderLabel(l.content))
)

const fallbackViewbox = computed(() =>
    String(safeConfig.value?.viewbox || props.viewbox || '-10 -10 120 200').trim()
)

const contentBounds = computed(() => {
    let minX = Infinity
    let minY = Infinity
    let maxX = -Infinity
    let maxY = -Infinity
    let found = false

    const include = (x: number, y: number, w = 0, h = 0) => {
        if (![x, y, w, h].every(Number.isFinite)) return
        found = true
        minX = Math.min(minX, x)
        minY = Math.min(minY, y)
        maxX = Math.max(maxX, x + w)
        maxY = Math.max(maxY, y + h)
    }

    for (const wall of safeConfig.value?.walls || []) {
        const nums = String(wall?.d || '').match(/-?\d*\.?\d+/g)?.map(Number) || []
        for (let i = 0; i + 1 < nums.length; i += 2) include(nums[i], nums[i + 1])
    }

    for (const z of drawableZones.value) {
        for (const c of zoneWorldCorners(z)) include(c.x, c.y)
    }

    for (const pc of props.computers || []) {
        include(Number(pc.x), Number(pc.y), PC_W, PC_H)
    }

    if (!found) return null

    const pad = FIT_PAD
    return {
        x: minX - pad,
        y: minY - pad,
        w: Math.max(maxX - minX + pad * 2, 20),
        h: Math.max(maxY - minY + pad * 2, 20),
    }
})

const displayViewbox = computed(() => {
    const b = contentBounds.value
    if (b) return `${b.x} ${b.y} ${b.w} ${b.h}`
    return fallbackViewbox.value
})

const kindOf = (pc: any) => String(pc?.kind || 'pc')
const accentOf = (pc: any) => {
    const kind = kindOf(pc)
    if (kind === 'tv') return '#a855f7'
    if (kind === 'ps5') return '#3b82f6'
    return '#22c55e'
}

const isSelected = (id: any) => props.selectedIds.includes(id.toString())
const isOccupied = (id: any) => props.occupiedIds.includes(id.toString())

const seatFill = (pc: any) => {
    if (isOccupied(pc.id)) return '#1a1a1a'
    if (isSelected(pc.id)) return accentOf(pc)
    return '#001100'
}

const seatStroke = (pc: any) => {
    if (isOccupied(pc.id)) return '#444'
    if (isSelected(pc.id)) return '#fff'
    return accentOf(pc)
}

const seatText = (pc: any) => {
    if (isOccupied(pc.id)) return '#666'
    if (isSelected(pc.id)) return '#000'
    return accentOf(pc)
}

const addonFill = (m: AddonMarker) => {
    if (m.blocked) return '#1a1a1a'
    if (m.active) return '#22c55e'
    return '#001100'
}

const addonStroke = (m: AddonMarker) => {
    if (m.blocked) return '#444'
    if (m.active) return '#fff'
    return '#22c55e'
}

const addonText = (m: AddonMarker) => {
    if (m.blocked) return '#666'
    if (m.active) return '#000'
    return '#22c55e'
}

const handleClick = (pc: any) => {
    if (isOccupied(pc.id)) {
        emit('seat-error')
        return
    }
    emit('toggle-seat', pc.id.toString())
}

const handleAddonClick = (m: AddonMarker) => {
    if (m.blocked || !m.seatIds.length) {
        emit('seat-error')
        return
    }
    emit('toggle-addon-seats', { addonId: m.addonId, seatIds: m.seatIds })
}
</script>

<style scoped>
.map-wrapper {
    background-image: radial-gradient(circle at 50% 50%, rgba(34, 197, 94, 0.03) 0%, transparent 70%);
}
</style>
