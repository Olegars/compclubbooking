<script setup lang="ts">
/**
 * Единый блок согласия у кнопки оплаты (план 1.1).
 * Галочка «Отправить чек» по умолчанию снята.
 */
import { computed } from 'vue'

const props = withDefaults(defineProps<{
    modelValue?: boolean
    payLabel?: string
}>(), {
    modelValue: false,
    payLabel: 'Оплатить',
})

const emit = defineEmits<{
    'update:modelValue': [value: boolean]
}>()

const checked = computed({
    get: () => Boolean(props.modelValue),
    set: (v: boolean) => emit('update:modelValue', v),
})
</script>

<template>
    <div class="space-y-3 text-left">
        <label class="flex items-start gap-3 cursor-pointer select-none group">
            <input
                v-model="checked"
                type="checkbox"
                class="mt-1 accent-[#22c55e] w-4 h-4 shrink-0"
            />
            <span class="text-[11px] leading-snug text-white/55 group-hover:text-white/75 transition-colors">
                Отправить чек на Email/SMS
                <span class="block text-white/30 text-[10px] mt-1">
                    На контакт, привязанный к аккаунту. Без галочки чек только на экране (QR).
                </span>
            </span>
        </label>

        <p class="text-[10px] leading-relaxed text-white/30 border border-white/5 rounded-xl px-3 py-2.5 bg-black/40">
            Нажимая «{{ payLabel }}», вы соглашаетесь получить чек в виде QR-кода на экране
        </p>
    </div>
</template>
