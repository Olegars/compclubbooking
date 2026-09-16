/** Геометрия комнаты на карте: прямоугольник, поворот или произвольный четырёхугольник. */

export type Pt = { x: number; y: number }

export type ZoneRect = {
    x?: number
    y?: number
    w?: number
    h?: number
    rotate?: number
    points?: Pt[]
}

export const zoneRotate = (z: ZoneRect | null | undefined) => {
    const n = Number(z?.rotate)
    return Number.isFinite(n) ? n : 0
}

export const zoneCenter = (z: ZoneRect) => {
    const pts = zonePoints(z)
    if (!pts.length) {
        return {
            cx: Number(z.x) + Number(z.w) / 2,
            cy: Number(z.y) + Number(z.h) / 2,
        }
    }
    return {
        cx: pts.reduce((s, p) => s + p.x, 0) / pts.length,
        cy: pts.reduce((s, p) => s + p.y, 0) / pts.length,
    }
}

const rotatePoint = (cx: number, cy: number, x: number, y: number, deg: number) => {
    if (!deg) return { x, y }
    const a = deg * Math.PI / 180
    const dx = x - cx
    const dy = y - cy
    return {
        x: cx + dx * Math.cos(a) - dy * Math.sin(a),
        y: cy + dx * Math.sin(a) + dy * Math.cos(a),
    }
}

export const worldToLocal = (z: ZoneRect, x: number, y: number) => {
    const { cx, cy } = zoneCenter(z)
    return rotatePoint(cx, cy, x, y, -zoneRotate(z))
}

export const localToWorld = (z: ZoneRect, x: number, y: number) => {
    const { cx, cy } = zoneCenter(z)
    return rotatePoint(cx, cy, x, y, zoneRotate(z))
}

export const zoneWorldCorners = (z: ZoneRect): Pt[] => {
    const x = Number(z.x) || 0
    const y = Number(z.y) || 0
    const w = Number(z.w) || 0
    const h = Number(z.h) || 0
    return [
        localToWorld(z, x, y),
        localToWorld(z, x + w, y),
        localToWorld(z, x + w, y + h),
        localToWorld(z, x, y + h),
    ]
}

export const zonePoints = (z: ZoneRect | null | undefined): Pt[] => {
    if (!z) return []
    if (Array.isArray(z.points) && z.points.length >= 3) {
        return z.points
            .map(p => ({ x: Number(p?.x), y: Number(p?.y) }))
            .filter(p => Number.isFinite(p.x) && Number.isFinite(p.y))
    }
    if (!Number.isFinite(Number(z.w)) || !Number.isFinite(Number(z.h))) return []
    return zoneWorldCorners(z)
}

export const zoneSvgPoints = (z: ZoneRect) =>
    zonePoints(z).map(p => `${p.x},${p.y}`).join(' ')

export const syncZoneBounds = (z: any) => {
    const pts = zonePoints(z)
    if (pts.length < 3) return z
    const xs = pts.map(p => p.x)
    const ys = pts.map(p => p.y)
    z.points = pts
    z.x = Math.min(...xs)
    z.y = Math.min(...ys)
    z.w = Math.max(0.5, Math.max(...xs) - z.x)
    z.h = Math.max(0.5, Math.max(...ys) - z.y)
    return z
}

export const ensureZonePoints = (z: any) => {
    if (!Array.isArray(z.points) || z.points.length < 3) {
        z.points = zoneWorldCorners(z)
    }
    return syncZoneBounds(z)
}

export const pointInPolygon = (pts: Pt[], x: number, y: number) => {
    let inside = false
    for (let i = 0, j = pts.length - 1; i < pts.length; j = i++) {
        const xi = pts[i].x
        const yi = pts[i].y
        const xj = pts[j].x
        const yj = pts[j].y
        const dy = yj - yi || 1e-12
        const intersect = ((yi > y) !== (yj > y)) && (x < (xj - xi) * (y - yi) / dy + xi)
        if (intersect) inside = !inside
    }
    return inside
}

export const pointInZone = (z: ZoneRect, x: number, y: number) =>
    pointInPolygon(zonePoints(z), x, y)

export const zoneSvgTransform = (_z: ZoneRect) => ''

export const zoneEdgeMidpoint = (z: ZoneRect, edge: 'left' | 'right' | 'top' | 'bottom') => {
    const pts = zonePoints(z)
    if (pts.length < 2) {
        const x = Number(z.x) || 0
        const y = Number(z.y) || 0
        const w = Number(z.w) || 0
        const h = Number(z.h) || 0
        if (edge === 'left') return { cx: x, cy: y + h / 2 }
        if (edge === 'right') return { cx: x + w, cy: y + h / 2 }
        if (edge === 'top') return { cx: x + w / 2, cy: y }
        return { cx: x + w / 2, cy: y + h }
    }
    let best = 0
    let bestScore = Infinity
    for (let i = 0; i < pts.length; i++) {
        const a = pts[i]
        const b = pts[(i + 1) % pts.length]
        const mx = (a.x + b.x) / 2
        const my = (a.y + b.y) / 2
        const score = edge === 'left' || edge === 'right' ? mx : my
        const better = edge === 'left' || edge === 'top' ? score < bestScore : score > bestScore
        if (i === 0 || better) {
            best = i
            bestScore = score
        }
    }
    const a = pts[best]
    const b = pts[(best + 1) % pts.length]
    return { cx: (a.x + b.x) / 2, cy: (a.y + b.y) / 2 }
}

export const rotateZonePoints = (pts: Pt[], cx: number, cy: number, deg: number): Pt[] =>
    pts.map(p => rotatePoint(cx, cy, p.x, p.y, deg))
