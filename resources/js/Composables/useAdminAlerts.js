import { reactive } from 'vue'

// Счётчики бейджей сайдбара. Заполняются из shared-пропа `admin_alerts`
// (AdminLayout) и обновляются опросом дашбоарда без перезагрузки страницы.
const counts = reactive({
    pending_orders: 0,
    sos: 0,
    input: 0,
    incidents: 0,
    avito_unread: 0,
    tournament_inbox: 0,
})

let lastAvitoUnread = null

const setCounts = (next) => {
    if (!next) return
    Object.keys(counts).forEach(key => {
        if (next[key] !== undefined) counts[key] = Number(next[key]) || 0
    })
}

const avitoUnreadGrew = (next) => {
    const value = Number(next?.avito_unread)
    if (!Number.isFinite(value)) return false
    const grew = lastAvitoUnread !== null && value > lastAvitoUnread
    lastAvitoUnread = value
    return grew
}

const playAvitoPing = (url) => {
    const src = String(url || '').trim() || '/sounds/notification.mp3'
    new Audio(src).play().catch(() => {})
}

export function useAdminAlerts() {
    return { counts, setCounts, avitoUnreadGrew, playAvitoPing }
}
