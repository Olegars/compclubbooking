<script setup lang="ts">
import { Head, useForm, usePage } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'

type MatchRow = { match_id: string; status: string; map: string | null; finished_at: string | null }
type TournamentRow = { id: number; name: string; faceit_championship_id: string | null }

const props = defineProps<{
    mode: string
    enabled: boolean
    api_key_set: boolean
    oauth_set: boolean
    webhook_set: boolean
    hub_id: string
    hub_url: string | null
    identities: number
    rate_limited_at: string | null
    matches: MatchRow[]
    tournaments: TournamentRow[]
    championships: Array<Record<string, unknown>>
}>()

const page = usePage()
const form = useForm({
    tournament_id: props.tournaments[0]?.id ?? '',
    championship_id: '',
})

function saveChampionship() {
    form.post('/admin/faceit/championship', { preserveScroll: true })
}
</script>

<template>
    <Head title="FACEIT" />
    <AdminLayout>
        <div class="max-w-4xl space-y-6 text-white">
            <div>
                <div class="text-[10px] uppercase tracking-[0.28em] text-orange-300 font-black">Киберспорт</div>
                <h1 class="text-3xl font-black italic uppercase">FACEIT в клубе</h1>
                <p class="mt-2 text-sm text-white/50">Режим {{ mode }}. Тумблер {{ enabled ? 'включён' : 'выключен' }}. Призы хаба касса клуба не платит.</p>
            </div>

            <p v-if="(page.props as any).flash?.success" class="text-emerald-300 text-sm">{{ (page.props as any).flash.success }}</p>

            <div class="grid grid-cols-2 md:grid-cols-4 gap-3 text-xs">
                <div class="border border-white/10 rounded-xl p-3">Ключ Data API<br><b>{{ api_key_set ? 'задан' : 'нет' }}</b></div>
                <div class="border border-white/10 rounded-xl p-3">OAuth<br><b>{{ oauth_set ? 'задан' : 'нет' }}</b></div>
                <div class="border border-white/10 rounded-xl p-3">Webhook<br><b>{{ webhook_set ? 'секрет задан' : 'не принимать' }}</b></div>
                <div class="border border-white/10 rounded-xl p-3">Привязки<br><b>{{ identities }}</b></div>
            </div>

            <div v-if="rate_limited_at" class="text-amber-300 text-sm">Последний 429: {{ rate_limited_at }}. Кэш Elo не затирали.</div>

            <div v-if="hub_id" class="text-sm text-white/70">
                Хаб {{ hub_id }}
                <a v-if="hub_url" :href="hub_url" class="text-orange-300 ml-2" target="_blank" rel="noopener">вступить</a>
            </div>

            <div class="border border-white/10 rounded-2xl p-4 space-y-3">
                <div class="text-[10px] uppercase tracking-widest text-white/40">Чемпионат на карточке ивента</div>
                <form class="flex flex-wrap gap-2" @submit.prevent="saveChampionship">
                    <select v-model="form.tournament_id" class="bg-black border border-white/10 rounded-xl px-3 py-2 text-sm">
                        <option v-for="row in tournaments" :key="row.id" :value="row.id">
                            {{ row.name }} {{ row.faceit_championship_id ? '· ' + row.faceit_championship_id : '' }}
                        </option>
                    </select>
                    <input v-model="form.championship_id" placeholder="UUID чемпионата FACEIT" class="bg-black border border-white/10 rounded-xl px-3 py-2 text-sm flex-1 min-w-[12rem]" />
                    <button class="px-4 py-2 rounded-xl bg-orange-500 text-black text-xs font-black uppercase" :disabled="form.processing">Сохранить</button>
                </form>
                <p class="text-[11px] text-white/40">Пустой UUID снимает привязку. Сетка читается Data API, «Завершить + призы» для такого ивента закрыто.</p>
            </div>

            <div class="border border-white/10 rounded-2xl overflow-hidden">
                <div class="px-4 py-3 text-[10px] uppercase tracking-widest text-white/40">Матчи хаба</div>
                <div v-if="matches.length === 0" class="px-4 pb-4 text-sm text-white/40">Пока пусто</div>
                <div v-for="row in matches" :key="row.match_id" class="px-4 py-2 border-t border-white/5 text-sm flex justify-between">
                    <span>{{ row.map || 'карта' }} · {{ row.status }}</span>
                    <span class="text-white/40 font-mono text-xs">{{ row.match_id }}</span>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
