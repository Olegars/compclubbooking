import { onMounted, onUnmounted, ref } from 'vue'
import axios from 'axios'
import { useToast } from '@/Composables/useToast'
import { charFromScanKey, normalizeScanLayout } from '@/utils/scanKeyboard'

/** Режим приёмки на склад (включается только на странице Склад). */
const receiveMode = ref(false)

/** Обработчик приёмки — регистрирует Inventory.vue / Store Warehouse */
let receiveHandler = null

/** Подписчики на успешное списание в заказ (Orders.vue обновляет прогресс). */
const fulfillListeners = new Set()

let barcodeBuffer = ''
let lastKeyTime = Date.now()
let scanBusy = false
let listenerAttached = false

/** Интервал между символами HID-скана обычно < 30–50 ms. */
const SCAN_GAP_MS = 80
const RAPID_MS = 45

const isTypingTarget = (target) =>
    target instanceof HTMLInputElement
    || target instanceof HTMLTextAreaElement
    || target instanceof HTMLSelectElement
    || (target instanceof HTMLElement && target.isContentEditable)

/** Поля штрихкода/S/N: data-scan-capture — всегда читаем физические клавиши. */
const isScanCaptureField = (target) =>
    target instanceof HTMLElement && target.dataset?.scanCapture !== undefined

const dispatchFulfill = (payload) => {
    fulfillListeners.forEach((fn) => {
        try { fn(payload) } catch (_) { /* ignore */ }
    })
}

const handleKeyDown = async (e) => {
    if (scanBusy) return

    const typing = isTypingTarget(e.target)
    const scanField = isScanCaptureField(e.target)
    const now = Date.now()
    const gap = now - lastKeyTime
    lastKeyTime = now

    const continuingBurst = barcodeBuffer.length > 0 && gap <= SCAN_GAP_MS
    const rapidKey = gap <= RAPID_MS

    // Поле data-scan-capture: быстрый HID — по e.code; медленный ручной ввод — через @input + normalize
    if (scanField) {
        if (!continuingBurst && gap > RAPID_MS) {
            barcodeBuffer = ''
            return
        }
        if (e.key === 'Enter' || e.code === 'Enter' || e.code === 'NumpadEnter') {
            e.preventDefault()
            e.stopPropagation()
            const code = normalizeScanLayout(barcodeBuffer)
            barcodeBuffer = ''
            if (code.length >= 3) await processScan(code)
            return
        }
        const chScan = charFromScanKey(e)
        if (chScan) {
            e.preventDefault()
            e.stopPropagation()
            barcodeBuffer += chScan
            try {
                if (e.target instanceof HTMLInputElement) {
                    e.target.value = barcodeBuffer
                    e.target.dispatchEvent(new Event('input', { bubbles: true }))
                }
            } catch (_) { /* ignore */ }
        }
        return
    }

    // Ручной ввод в обычные поля (имя, заметки…) — не трогаем.
    // Исключения: продолжение HID-пачки, старт быстрого скана в режиме приёмки.
    if (typing && !continuingBurst) {
        if (!receiveMode.value || !rapidKey) {
            barcodeBuffer = ''
            return
        }
        // receiveMode + очень быстрый ввод = скан попал в обычный input
        e.preventDefault()
        e.stopPropagation()
        try { e.target.blur?.() } catch (_) { /* ignore */ }
        barcodeBuffer = ''
        if (e.key === 'Enter' || e.code === 'Enter' || e.code === 'NumpadEnter') return
        const ch0 = charFromScanKey(e)
        if (ch0) barcodeBuffer += ch0
        return
    }

    if (gap > SCAN_GAP_MS) {
        barcodeBuffer = ''
    }

    if (e.key === 'Enter' || e.code === 'Enter' || e.code === 'NumpadEnter') {
        if (typing && !barcodeBuffer) return

        e.preventDefault()
        if (barcodeBuffer) e.stopPropagation()
        const code = normalizeScanLayout(barcodeBuffer)
        barcodeBuffer = ''
        if (code.length < 5) return
        await processScan(code)
        return
    }

    const ch = charFromScanKey(e)
    if (!ch) return

    e.preventDefault()
    barcodeBuffer += ch
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
        barcodeBuffer = ''
    }

    const onFulfillScan = (fn) => {
        fulfillListeners.add(fn)
        return () => fulfillListeners.delete(fn)
    }

    /** Один раз на AdminLayout — глобальный HID-сканер. */
    const attachGlobalListener = () => {
        onMounted(() => {
            if (listenerAttached) return
            // capture: true — до того, как символ попадёт в input по раскладке ОС
            window.addEventListener('keydown', handleKeyDown, true)
            listenerAttached = true
        })
        onUnmounted(() => {
            if (!listenerAttached) return
            window.removeEventListener('keydown', handleKeyDown, true)
            listenerAttached = false
        })
    }

    return {
        receiveMode,
        enableReceiveMode,
        disableReceiveMode,
        onFulfillScan,
        attachGlobalListener,
        normalizeScanLayout,
    }
}
