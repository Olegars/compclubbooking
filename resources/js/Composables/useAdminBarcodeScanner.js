import { onMounted, onUnmounted, ref } from 'vue'
import axios from 'axios'
import { useToast } from '@/Composables/useToast'
import { charFromScanKey, normalizeScanLayout } from '@/utils/scanKeyboard'

/** Режим приёмки на склад (включается только на странице Склад). */
const receiveMode = ref(false)

/** Обработчик приёмки — регистрирует Inventory.vue */
let receiveHandler = null

/** Подписчики на успешное списание в заказ (Orders.vue обновляет прогресс). */
const fulfillListeners = new Set()

let barcodeBuffer = ''
let lastKeyTime = Date.now()
let scanBusy = false
let listenerAttached = false

const isTypingTarget = (target) =>
    target instanceof HTMLInputElement
    || target instanceof HTMLTextAreaElement
    || target instanceof HTMLSelectElement
    || (target instanceof HTMLElement && target.isContentEditable)

const dispatchFulfill = (payload) => {
    fulfillListeners.forEach((fn) => {
        try { fn(payload) } catch (_) { /* ignore */ }
    })
}

const handleKeyDown = async (e) => {
    if (isTypingTarget(e.target)) return
    if (scanBusy) return

    const now = Date.now()
    if (now - lastKeyTime > 80) barcodeBuffer = ''
    lastKeyTime = now

    if (e.key === 'Enter' || e.code === 'Enter' || e.code === 'NumpadEnter') {
        e.preventDefault()
        const code = normalizeScanLayout(barcodeBuffer)
        barcodeBuffer = ''
        if (code.length <= 5) return
        await processScan(code)
        return
    }

    const ch = charFromScanKey(e)
    if (ch) {
        e.preventDefault()
        barcodeBuffer += ch
    }
}

const processScan = async (code) => {
    scanBusy = true
    const { success, error, info } = useToast()
    const normalized = normalizeScanLayout(code)

    try {
        if (receiveMode.value && typeof receiveHandler === 'function') {
            await receiveHandler(normalized)
            return
        }

        const { data } = await axios.post('/admin/orders/fulfill-scan', { code: normalized })
        const orderId = data.order_id
        success(`#${orderId} · ${data.product_name || 'Позиция'} · КМ списан`)
        if (data.marking_complete) {
            info(`Заказ #${orderId}: все коды готовы — нажмите «Выполнен»`)
        }
        dispatchFulfill(data)
    } catch (e) {
        // Ошибки приёмки показывает Inventory; здесь — только списание в заказ
        if (receiveMode.value) return
        error(e?.response?.data?.message || 'Скан не принят')
    } finally {
        scanBusy = false
    }
}

export function useAdminBarcodeScanner() {
    const enableReceiveMode = (handler) => {
        receiveMode.value = true
        receiveHandler = handler
    }

    const disableReceiveMode = () => {
        receiveMode.value = false
        receiveHandler = null
    }

    const onFulfillScan = (fn) => {
        fulfillListeners.add(fn)
        return () => fulfillListeners.delete(fn)
    }

    /** Один раз на AdminLayout — глобальный HID-сканер. */
    const attachGlobalListener = () => {
        onMounted(() => {
            if (listenerAttached) return
            window.addEventListener('keydown', handleKeyDown)
            listenerAttached = true
        })
        onUnmounted(() => {
            if (!listenerAttached) return
            window.removeEventListener('keydown', handleKeyDown)
            listenerAttached = false
        })
    }

    return {
        receiveMode,
        enableReceiveMode,
        disableReceiveMode,
        onFulfillScan,
        attachGlobalListener,
    }
}
