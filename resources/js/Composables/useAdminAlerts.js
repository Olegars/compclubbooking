import { reactive } from 'vue'

// Счётчики бейджей сайдбара. Заполняются из shared-пропа `admin_alerts`
// (AdminLayout) и обновляются опросом дашбоарда без перезагрузки страницы.
const counts = reactive({
    pending_orders: 0,
    sos: 0,
    input: 0,
    incidents: 0,
    avito_unread: 0,
})

const setCounts = (next) => {
    if (!next) return
    Object.keys(counts).forEach(key => {
        if (next[key] !== undefined) counts[key] = Number(next[key]) || 0
    })
}

export function useAdminAlerts() {
    return { counts, setCounts }
}
