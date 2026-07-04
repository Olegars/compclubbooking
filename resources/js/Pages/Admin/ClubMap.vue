<template>
    <div class="admin-map-container h-[800px]">
        <ReactorMap
            :computers="computers"
            :occupied-ids="occupiedGizmoIds"
            :selected-ids="selectedPcId"
            :map-config="mapConfig"
            @toggle-seat="handlePcSelect"
            @seat-error="handleOccupiedClick"
        />

        <div v-if="selectedPcId.length" class="pc-control-panel">
            <h3 class="text-cyan-500 font-black uppercase italic">
                Узел #{{ selectedPcData.name }}
            </h3>

            <div class="flex gap-4">
                <button @click="openGiftModal" class="bg-cyan-500 text-black px-6 py-2 rounded-xl font-black uppercase text-[10px]">
                    Добавить время (Limit: 20m)
                </button>

                <button @click="rebootPc" class="border border-red-500/50 text-red-500 px-6 py-2 rounded-xl font-black uppercase text-[10px]">
                    Перезагрузить
                </button>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue'
import ReactorMap from '@/Components/Map.vue' // Твой компонент

const computers = ref([...]) // Список ПК из БД
const occupiedGizmoIds = ref([]) // Сюда прилетают ID из Gizmo API
const selectedPcId = ref([]) // Здесь ID выбранного админом ПК

const selectedPcData = computed(() => {
    return computers.value.find(pc => pc.id.toString() === selectedPcId.value[0])
})

const handlePcSelect = (id) => {
    // В админке мы обычно выбираем один ПК для управления
    selectedPcId.value = [id.toString()]
}

const handleOccupiedClick = () => {
    // Если админ кликнул на занятый ПК, просто открываем его статус
    // Здесь можно выводить, кто сидит и сколько осталось
}
</script>
