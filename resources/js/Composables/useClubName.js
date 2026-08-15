import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'

export function useClubName() {
    const page = usePage()

    return computed(() => {
        const adminName = page.props.admin_location?.name
        const shared = page.props.club_name
        const club = page.props.club?.name
        const name = adminName || shared || club || ''

        return String(name).trim() || 'Клуб'
    })
}
