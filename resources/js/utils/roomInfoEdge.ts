/** Край «?» — сторона комнаты, выходящая в проход (зеркало PHP RoomInfoEdge). */

export type InfoEdge = 'left' | 'right' | 'top' | 'bottom'

export type RoomInfoFields = {
  cpu?: string | null
  gpu?: string | null
  monitor?: string | null
  screen_diagonal?: string | null
  ps_model?: string | null
  info_edge?: string | null
}

const EDGE_ORDER: InfoEdge[] = ['right', 'bottom', 'left', 'top']

function blockedLength(
  others: Array<{ x?: number; y?: number; w?: number; h?: number }>,
  bx: number,
  by: number,
  bw: number,
  bh: number,
  axis: 'v' | 'h',
): number {
  let blocked = 0
  for (const o of others) {
    const ox = Number(o.x) || 0
    const oy = Number(o.y) || 0
    const ow = Number(o.w) || 0
    const oh = Number(o.h) || 0
    if (ow < 0.5 || oh < 0.5) continue
    const ix0 = Math.max(bx, ox)
    const iy0 = Math.max(by, oy)
    const ix1 = Math.min(bx + bw, ox + ow)
    const iy1 = Math.min(by + bh, oy + oh)
    if (ix1 <= ix0 || iy1 <= iy0) continue
    blocked += axis === 'v' ? iy1 - iy0 : ix1 - ix0
  }
  return blocked
}

export function resolveInfoEdge(
  rect: { x?: number; y?: number; w?: number; h?: number },
  others: Array<{ x?: number; y?: number; w?: number; h?: number }>,
  override?: string | null,
): InfoEdge {
  const o = String(override || '').trim().toLowerCase()
  if (o === 'left' || o === 'right' || o === 'top' || o === 'bottom') return o

  const x = Number(rect.x) || 0
  const y = Number(rect.y) || 0
  const w = Number(rect.w) || 0
  const h = Number(rect.h) || 0
  const band = 2

  const scores: Record<InfoEdge, number> = {
    right: blockedLength(others, x + w, y, band, h, 'v'),
    left: blockedLength(others, x - band, y, band, h, 'v'),
    bottom: blockedLength(others, x, y + h, w, band, 'h'),
    top: blockedLength(others, x, y - band, w, band, 'h'),
  }

  let best: InfoEdge = 'right'
  let bestScore = Number.POSITIVE_INFINITY
  for (const edge of EDGE_ORDER) {
    if (scores[edge] < bestScore) {
      bestScore = scores[edge]
      best = edge
    }
  }
  return best
}

export function infoMarkerCenter(
  rect: { x?: number; y?: number; w?: number; h?: number },
  edge: InfoEdge,
): { cx: number; cy: number } {
  const x = Number(rect.x) || 0
  const y = Number(rect.y) || 0
  const w = Number(rect.w) || 0
  const h = Number(rect.h) || 0
  if (edge === 'left') return { cx: x, cy: y + h / 2 }
  if (edge === 'right') return { cx: x + w, cy: y + h / 2 }
  if (edge === 'top') return { cx: x + w / 2, cy: y }
  return { cx: x + w / 2, cy: y + h }
}

export const INFO_MARKER_R = 1.35

export function emptyRoomInfo(): RoomInfoFields {
  return {
    cpu: '',
    gpu: '',
    monitor: '',
    screen_diagonal: '',
    ps_model: '',
    info_edge: '',
  }
}

export function normalizeRoomInfo(info: unknown): RoomInfoFields {
  const src = info && typeof info === 'object' ? (info as Record<string, unknown>) : {}
  const edge = String(src.info_edge ?? '').trim().toLowerCase()
  return {
    cpu: String(src.cpu ?? '').trim(),
    gpu: String(src.gpu ?? '').trim(),
    monitor: String(src.monitor ?? '').trim(),
    screen_diagonal: String(src.screen_diagonal ?? '').trim(),
    ps_model: String(src.ps_model ?? '').trim(),
    info_edge: (edge === 'left' || edge === 'right' || edge === 'top' || edge === 'bottom') ? edge : '',
  }
}

export function isTvZone(zone: { type?: unknown; info_kind?: unknown }): boolean {
  if (String(zone.info_kind || '').toLowerCase() === 'tv') return true
  return String(zone.type || '').trim().toLowerCase() === 'tv'
}
