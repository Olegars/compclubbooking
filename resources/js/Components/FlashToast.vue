<script setup lang="ts">
import { computed, ref, watch, onUnmounted } from 'vue'
import { usePage } from '@inertiajs/vue3'

type ToastKind = 'success' | 'error' | 'info'

const page = usePage()

const visible = ref(false)
const kind = ref<ToastKind>('info')
const message = ref('')
let hideTimer: ReturnType<typeof setTimeout> | null = null

const flash = computed(() => (page.props as any).flash || {})

const show = (nextKind: ToastKind, text: string) => {
    kind.value = nextKind
    message.value = text
    visible.value = true

    if (hideTimer) clearTimeout(hideTimer)
    hideTimer = setTimeout(() => { visible.value = false }, 6000)
}

watch(flash, (value) => {
    if (!value) return
    if (value.success) show('success', String(value.success))
    else if (value.error) show('error', String(value.error))
    else if (value.info) show('info', String(value.info))
}, { immediate: true, deep: true })

onUnmounted(() => {
    if (hideTimer) clearTimeout(hideTimer)
})

const palette = computed(() => {
    if (kind.value === 'success') {
        return { accent: '#22c55e', title: 'Операция выполнена' }
    }
    if (kind.value === 'error') {
        return { accent: '#ef4444', title: 'Ошибка операции' }
    }
    return { accent: '#eab308', title: 'Платёж в обработке' }
})
</script>

<template>
    <Teleport to="body">
        <Transition name="toast">
            <div
                v-if="visible"
                class="flash-toast"
                :style="{ '--accent': palette.accent }"
                role="status"
            >
                <span class="flash-toast-bar" aria-hidden="true"></span>

                <div class="flash-toast-icon" aria-hidden="true">
                    <svg v-if="kind === 'success'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                    </svg>
                    <svg v-else-if="kind === 'error'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 6l12 12M18 6L6 18" />
                    </svg>
                    <svg v-else viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                        <circle cx="12" cy="12" r="9" />
                        <path stroke-linecap="round" d="M12 7v6" />
                        <circle cx="12" cy="16.5" r="0.8" fill="currentColor" stroke="none" />
                    </svg>
                </div>

                <div class="min-w-0">
                    <div class="flash-toast-title">{{ palette.title }}</div>
                    <p class="flash-toast-text">{{ message }}</p>
                </div>

                <button type="button" class="flash-toast-close" @click="visible = false" aria-label="Закрыть">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                        <path stroke-linecap="round" d="M6 6l12 12M18 6L6 18" />
                    </svg>
                </button>
            </div>
        </Transition>
    </Teleport>
</template>

<style scoped>
@reference "../../css/app.css";

.flash-toast {
    position: fixed;
    left: 50%;
    bottom: 28px;
    transform: translateX(-50%);
    z-index: 9999995;
    width: min(420px, calc(100vw - 32px));
    display: flex;
    align-items: flex-start;
    gap: 14px;
    padding: 18px 18px 18px 26px;
    background: #070707;
    border: 1px solid color-mix(in srgb, var(--accent) 35%, transparent);
    border-radius: 12px;
    box-shadow: 0 0 40px color-mix(in srgb, var(--accent) 18%, transparent),
                0 20px 60px rgba(0, 0, 0, 0.8);
    overflow: hidden;
}

.flash-toast-bar {
    position: absolute;
    left: 0;
    top: 0;
    bottom: 0;
    width: 4px;
    background: var(--accent);
    box-shadow: 0 0 14px var(--accent);
}

.flash-toast-icon {
    flex-shrink: 0;
    width: 38px;
    height: 38px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    color: var(--accent);
    background: color-mix(in srgb, var(--accent) 10%, transparent);
    border: 1px solid color-mix(in srgb, var(--accent) 30%, transparent);
}

.flash-toast-icon svg { width: 20px; height: 20px; }

.flash-toast-title {
    font-size: 9px;
    font-weight: 900;
    font-style: italic;
    text-transform: uppercase;
    letter-spacing: 0.2em;
    color: var(--accent);
    margin-bottom: 6px;
}

.flash-toast-text {
    font-size: 13px;
    font-weight: 700;
    line-height: 1.35;
    color: #fff;
    font-style: italic;
}

.flash-toast-close {
    margin-left: auto;
    flex-shrink: 0;
    width: 26px;
    height: 26px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    color: rgba(255, 255, 255, 0.3);
    transition: color 0.2s, background 0.2s;
}

.flash-toast-close:hover {
    color: #fff;
    background: rgba(255, 255, 255, 0.08);
}

.flash-toast-close svg { width: 14px; height: 14px; }

.toast-enter-active { transition: opacity 0.35s ease, transform 0.35s cubic-bezier(0.16, 1, 0.3, 1); }
.toast-leave-active { transition: opacity 0.25s ease, transform 0.25s ease; }
.toast-enter-from,
.toast-leave-to {
    opacity: 0;
    transform: translateX(-50%) translateY(24px);
}
</style>
