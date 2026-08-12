/**
 * Однозначные автозаполнения полей конструктора комплектующих.
 * Меняем только поля, которые однозначно следуют из введённого значения.
 */

const norm = (v) => String(v || '').trim()
const upper = (v) => norm(v).toUpperCase().replace(/\s+/g, '')

const SOCKET_CPU_BRAND = {
    LGA1851: 'Intel',
    LGA1700: 'Intel',
    LGA1200: 'Intel',
    LGA1151: 'Intel',
    LGA1150: 'Intel',
    LGA1155: 'Intel',
    AM4: 'AMD',
    AM5: 'AMD',
    STR5: 'AMD',
    TR4: 'AMD',
}

const SERIES_INFER = [
    { re: /^core\s*ultra/i, brand: 'Intel', socket: 'LGA1851' },
    { re: /^(core\s*i[3579]|pentium|celeron)/i, brand: 'Intel', socket: null },
    { re: /^ryzen\s*threadripper/i, brand: 'AMD', socket: null },
    { re: /^ryzen/i, brand: 'AMD', socket: null },
]

const CHIPSET_SOCKET = [
    { re: /^(B860|Z890|H810)/i, socket: 'LGA1851' },
    { re: /^(H610|B660|Z690|B760|Z790|H670|B760M)/i, socket: 'LGA1700' },
    { re: /^(B650|B650E|X670|X670E|A620|X870|B850)/i, socket: 'AM5' },
    { re: /^(A520|B450|B550|X570|X470|B350)/i, socket: 'AM4' },
]

function socketBrand(socket) {
    const compact = upper(socket).replace(/LGA\s*/, 'LGA')
    return SOCKET_CPU_BRAND[compact] || null
}

function seriesInfer(series) {
    const s = norm(series)
    for (const row of SERIES_INFER) {
        if (row.re.test(s)) {
            return { brand: row.brand, socket: row.socket }
        }
    }
    return { brand: null, socket: null }
}

function chipsetSocket(chipset) {
    const c = norm(chipset)
    for (const row of CHIPSET_SOCKET) {
        if (row.re.test(c)) return row.socket
    }
    return null
}

/** Intel Core model → series + socket */
function inferIntelModel(model) {
    const m = upper(model)
    // 12xxx–14xxx → LGA1700; 15xxx / 2xx (Ultra 200) loosely LGA1851
    let socket = null
    if (/^1[234]\d{3}/.test(m)) socket = 'LGA1700'
    else if (/^15\d{3}/.test(m) || /^2[0-9]{2}K?F?$/.test(m) || /^24[05]/.test(m) || /^28[05]/.test(m)) {
        socket = 'LGA1851'
    }

    let series = null
    // 12400 → digit at pos 2: 1=i3, 4/5/6=i5, 7=i7, 9=i9
    const five = m.match(/^(\d)(\d)(\d)\d{2}/)
    if (five) {
        const tier = five[3]
        if (tier === '1') series = 'Core i3'
        else if ('456'.includes(tier)) series = 'Core i5'
        else if (tier === '7') series = 'Core i7'
        else if (tier === '9') series = 'Core i9'
    }

    return { brand: 'Intel', socket, series }
}

/** AMD Ryzen model → series + socket */
function inferAmdModel(model) {
    const m = upper(model)
    const num = parseInt(m.replace(/[^0-9].*$/, ''), 10)
    if (!Number.isFinite(num)) return null

    let socket = null
    if (num >= 9000) socket = 'AM5'
    else if (num >= 7000 && num < 8000) socket = 'AM5'
    else if (num >= 5000 && num < 6000) socket = 'AM4'
    else if (num >= 3000 && num < 4000) socket = 'AM4'
    else return null

    let series = null
    const family = Math.floor(num / 100) % 10
    // 5600 → 5, 7600 → 6, 9600 → 6 — hundreds digit of last 3: 5600 → 6? 
    // Better: 5x00 → Ryzen 5, 7x00 → Ryzen 7: second digit of 4-digit
    // 5600 → digits 5,6,0,0 → second digit 6 → Ryzen 5? Actually Ryzen naming: 5=Ryzen 5, 7=Ryzen 7 in first digit for 5000/7000
    // 5600 first digit 5 → Ryzen 5; 7600 → Ryzen 7; 9600 → Ryzen 9
    const first = Math.floor(num / 1000)
    if (first === 3) series = 'Ryzen 3'
    else if (first === 5) series = 'Ryzen 5'
    else if (first === 7) series = 'Ryzen 7'
    else if (first === 9) series = 'Ryzen 9'

    // Threadripper often 19xx/29xx/79xx — skip unless TR in name
    if (/TR|THREAD/.test(m)) {
        return { brand: 'AMD', socket: num >= 7000 ? 'sTR5' : 'TR4', series: 'Ryzen Threadripper' }
    }

    return { brand: 'AMD', socket, series }
}

function inferCpuModel(model) {
    const m = norm(model)
    if (!m) return {}

    // Prefer AMD if looks like Ryzen SKU (4 digits starting 3/5/7/9) without being Intel 13xxx
    const digits = upper(m).replace(/[^0-9A-Z]/g, '')
    if (/^1[2-5]\d{3}/.test(digits) || /^2\d{2}/.test(digits)) {
        return inferIntelModel(digits)
    }
    if (/^[3579]\d{3}/.test(digits)) {
        return inferAmdModel(digits) || {}
    }
    // fallback try both
    return inferIntelModel(digits).socket ? inferIntelModel(digits) : (inferAmdModel(digits) || {})
}

/**
 * @param {string} type
 * @param {string} changedKey
 * @param {Record<string,string>} specs
 * @returns {Record<string,string>} fields to set
 */
export function inferSpecFills(type, changedKey, specs) {
    const out = {}
    const value = norm(specs[changedKey])
    if (!value) return out

    if (type === 'cpu') {
        if (changedKey === 'socket') {
            const brand = socketBrand(value)
            if (brand) out.brand = brand
        }
        if (changedKey === 'series') {
            const info = seriesInfer(value)
            if (info.brand) out.brand = info.brand
            if (info.socket) out.socket = info.socket
        }
        if (changedKey === 'model') {
            const info = inferCpuModel(value)
            if (info.brand) out.brand = info.brand
            if (info.socket) out.socket = info.socket
            if (info.series) out.series = info.series
        }
        if (changedKey === 'brand') {
            // no forced fills
        }
    }

    if (type === 'motherboard') {
        if (changedKey === 'socket') {
            // бренд платы не Intel/AMD — не трогаем
        }
        if (changedKey === 'chipset') {
            const socket = chipsetSocket(value)
            if (socket) out.socket = socket
        }
    }

    return out
}
