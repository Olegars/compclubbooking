/** Геометрия комнаты на карте: AABB + поворот вокруг центра. */

export type ZoneRect = {
    x?: number
    y?: number
    w?: number
    h?: number
    rotate?: number
}

export const zoneRotate = (z: ZoneRect | null | undefined) => {
    const n = Number(z?.rotate)
    return Number.isFinite(n) ? n : 0
}

export const zoneCenter = (z: ZoneRect) => ({
    cx: Number(z.x) + Number(z.w) / 2,
    cy: Number(z.y) + Number(z.h) / 2,
})

export const zoneSvgTransform = (z: ZoneRect) => {
    const r = zoneRotate(z)
    if (!r) return ''
    const { cx, cy } = zoneCenter(z)
    return `rotate(${r} ${cx} ${cy})`
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

export const pointInZone = (z: ZoneRect, x: number, y: number) => {
    const p = worldToLocal(z, x, y)
    const zx = Number(z.x) || 0
    const zy = Number(z.y) || 0
    const zw = Number(z.w) || 0
    const zh = Number(z.h) || 0
    return p.x >= zx && p.x <= zx + zw && p.y >= zy && p.y <= zy + zh
}

export const zoneWorldCorners = (z: ZoneRect) => {
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
