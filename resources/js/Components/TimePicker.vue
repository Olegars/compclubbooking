<script setup lang="ts">
import { ref, watch, onMounted } from 'vue'

const props = defineProps<{
  label: string
}>()

const emit = defineEmits(['update:time'])

// Генерируем массив часов от 00:00 до 23:00
const hours = Array.from({ length: 24 }, (_, i) => i.toString().padStart(2, '0') + ':00')

const scrollRef = ref<HTMLElement | null>(null)
const selectedIndex = ref(0)

const handleScroll = () => {
  if (!scrollRef.value) return
  const itemHeight = 40 // Высота одного элемента
  const index = Math.round(scrollRef.value.scrollTop / itemHeight)
  if (selectedIndex.value !== index) {
    selectedIndex.value = index
    emit('update:time', hours[index])
  }
}

// Установка начального значения
onMounted(() => {
  if (scrollRef.value) {
    scrollRef.value.scrollTop = 0
  }
})
</script>

<template>
  <div class="flex flex-col items-center">
    <span class="text-[9px] text-slate-500 uppercase tracking-widest mb-2">{{ props.label }}</span>

    <div class="relative h-[120px] w-20 overflow-hidden bg-white/5 border border-white/10 rounded-lg">
      <div class="absolute top-10 left-0 w-full h-10 border-y border-reactor-neon/30 pointer-events-none z-10 bg-reactor-neon/5"></div>

      <div
        ref="scrollRef"
        @scroll="handleScroll"
        class="h-full overflow-y-scroll scrollbar-hide snap-y snap-mandatory py-10"
      >
        <div
          v-for="(hour, index) in hours"
          :key="hour"
          class="h-10 flex items-center justify-center snap-center transition-all duration-300"
          :class="selectedIndex === index ? 'text-reactor-neon text-lg font-bomber' : 'text-slate-600 text-sm'"
        >
          {{ hour }}
        </div>
        <div class="h-10"></div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.scrollbar-hide {
  /* Скрываем стандартный скроллбар */
  -ms-overflow-style: none;  /* IE и Edge */
  scrollbar-width: none;     /* Firefox */

  /* Включаем плавную прокрутку и инерцию для iOS/Android */
  -webkit-overflow-scrolling: touch;
}

.scrollbar-hide::-webkit-scrollbar {
  display: none; /* Chrome, Safari, Opera */
}

/* Убедимся, что контейнер реагирует на жесты */
div[ref="scrollRef"] {
  touch-action: pan-y; /* Разрешаем только вертикальные жесты внутри колесика */
}
</style>
