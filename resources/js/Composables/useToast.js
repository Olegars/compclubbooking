import { ref } from 'vue'

// Общий стек уведомлений на всё приложение (модульное состояние — один на страницу)
const toasts = ref([])
let seq = 0

const remove = (id) => {
    toasts.value = toasts.value.filter(t => t.id !== id)
}

const push = (message, type = 'info', timeout = 4500) => {
    if (!message) return null

    const id = ++seq
    toasts.value = [...toasts.value, { id, message: String(message), type }]

    if (timeout > 0) {
        setTimeout(() => remove(id), timeout)
    }

    return id
}

export function useToast() {
    return {
        toasts,
        toast: push,
        success: (message, timeout) => push(message, 'success', timeout),
        error: (message, timeout) => push(message, 'error', timeout ?? 6000),
        warning: (message, timeout) => push(message, 'warning', timeout),
        info: (message, timeout) => push(message, 'info', timeout),
        dismiss: remove,
    }
}
