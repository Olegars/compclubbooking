<script setup lang="ts">
import { computed } from 'vue'
import { useClubName } from '@/Composables/useClubName'

type Contacts = {
    club_name?: string
    city?: string
    address?: string
    hours?: string
    phone?: string
    map_url?: string
    socials?: Record<string, string>
    legal?: {
        entity?: string
        inn?: string
        offer_url?: string
        privacy_url?: string
        rules_url?: string
    }
}

const props = withDefaults(defineProps<{ contacts?: Contacts | null }>(), {
    contacts: null,
})

const socialLabels: Record<string, string> = {
    telegram: 'Telegram',
    vk: 'ВКонтакте',
    discord: 'Discord',
}

const socials = computed(() =>
    Object.entries(props.contacts?.socials || {})
        .filter(([, url]) => !!url)
        .map(([key, url]) => ({ key, url, label: socialLabels[key] || key }))
)

const legal = computed(() => props.contacts?.legal || {})

const legalLinks = computed(() =>
    [
        { url: legal.value.offer_url || '/legal/offer', label: 'Публичная оферта' },
        { url: legal.value.privacy_url, label: 'Политика конфиденциальности' },
        { url: legal.value.rules_url, label: 'Правила клуба' },
    ].filter((item) => !!item.url)
)

const phoneHref = computed(() => {
    const phone = props.contacts?.phone || ''
    return phone ? `tel:${phone.replace(/[^\d+]/g, '')}` : ''
})

const year = new Date().getFullYear()
const clubName = useClubName()
</script>

<template>
    <footer class="w-full border-t border-white/10 mt-16">
        <div class="max-w-[1400px] mx-auto px-4 sm:px-6 py-10 grid gap-8 sm:grid-cols-2 lg:grid-cols-4 text-[13px] text-white/60">
            <div>
                <div class="text-white font-black italic uppercase tracking-widest text-sm mb-3">
                    {{ contacts?.club_name || clubName }}
                </div>
                <p v-if="contacts?.address" class="leading-relaxed">
                    <span v-if="contacts?.city">{{ contacts.city }}, </span>{{ contacts.address }}
                </p>
                <a v-if="contacts?.map_url" :href="contacts.map_url" target="_blank" rel="noopener"
                   class="inline-block mt-2 text-[#22c55e] hover:underline">Показать на карте</a>
            </div>

            <div v-if="contacts?.hours">
                <div class="label">Часы работы</div>
                <p class="leading-relaxed">{{ contacts.hours }}</p>
            </div>

            <div v-if="phoneHref || socials.length">
                <div class="label">Связь</div>
                <a v-if="phoneHref" :href="phoneHref" class="block text-white text-base font-bold hover:text-[#22c55e] transition-colors">
                    {{ contacts?.phone }}
                </a>
                <div v-if="socials.length" class="flex flex-wrap gap-3 mt-3">
                    <a v-for="s in socials" :key="s.key" :href="s.url" target="_blank" rel="noopener"
                       class="hover:text-[#22c55e] transition-colors">{{ s.label }}</a>
                </div>
            </div>

            <div v-if="legalLinks.length">
                <div class="label">Документы</div>
                <ul class="space-y-1.5">
                    <li v-for="link in legalLinks" :key="link.label">
                        <a :href="link.url" target="_blank" rel="noopener" class="hover:text-[#22c55e] transition-colors">
                            {{ link.label }}
                        </a>
                    </li>
                </ul>
            </div>
        </div>

        <div class="max-w-[1400px] mx-auto px-4 sm:px-6 pb-10 text-[11px] text-white/30 flex flex-wrap gap-x-6 gap-y-2">
            <span>© {{ year }} {{ contacts?.club_name || clubName }}</span>
            <span v-if="legal.entity">{{ legal.entity }}</span>
            <span v-if="legal.inn">ИНН {{ legal.inn }}</span>
        </div>
    </footer>
</template>

<style scoped>
@reference "../../css/app.css";

.label {
    @apply text-[10px] uppercase tracking-[0.25em] text-white/30 mb-3 font-black italic;
}
</style>
