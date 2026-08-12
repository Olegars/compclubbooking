/**
 * HID-сканер эмулирует клавиатуру: при русской раскладке e.key даёт «йцукен».
 * Берём физическую клавишу (e.code) — раскладка ОС не влияет.
 */
const SHIFT_DIGITS: Record<string, string> = {
    '1': '!',
    '2': '@',
    '3': '#',
    '4': '$',
    '5': '%',
    '6': '^',
    '7': '&',
    '8': '*',
    '9': '(',
    '0': ')',
}

const CODE_PUNCT: Record<string, [string, string]> = {
    Minus: ['-', '_'],
    Equal: ['=', '+'],
    BracketLeft: ['[', '{'],
    BracketRight: [']', '}'],
    Backslash: ['\\', '|'],
    Semicolon: [';', ':'],
    Quote: ["'", '"'],
    Comma: [',', '<'],
    Period: ['.', '>'],
    Slash: ['/', '?'],
    Backquote: ['`', '~'],
    Space: [' ', ' '],
}

/** ЙЦУКЕН → QWERTY по позиции клавиши (fallback, если нет e.code). */
const RU_TO_EN: Record<string, string> = {
    й: 'q', ц: 'w', у: 'e', к: 'r', е: 't', н: 'y', г: 'u', ш: 'i', щ: 'o', з: 'p', х: '[', ъ: ']',
    ф: 'a', ы: 's', в: 'd', а: 'f', п: 'g', р: 'h', о: 'j', л: 'k', д: 'l', ж: ';', э: "'",
    я: 'z', ч: 'x', с: 'c', м: 'v', и: 'b', т: 'n', ь: 'm', б: ',', ю: '.', ё: '`',
    Й: 'Q', Ц: 'W', У: 'E', К: 'R', Е: 'T', Н: 'Y', Г: 'U', Ш: 'I', Щ: 'O', З: 'P', Х: '{', Ъ: '}',
    Ф: 'A', Ы: 'S', В: 'D', А: 'F', П: 'G', Р: 'H', О: 'J', Л: 'K', Д: 'L', Ж: ':', Э: '"',
    Я: 'Z', Ч: 'X', С: 'C', М: 'V', И: 'B', Т: 'N', Ь: 'M', Б: '<', Ю: '>', Ё: '~',
}

export function charFromScanKey(e: KeyboardEvent): string | null {
    if (e.key === 'Enter' || e.code === 'Enter' || e.code === 'NumpadEnter') {
        return null
    }

    const code = e.code || ''

    if (code.startsWith('Digit')) {
        const d = code.slice(5)
        return e.shiftKey ? (SHIFT_DIGITS[d] || d) : d
    }
    if (code.startsWith('Numpad') && code !== 'NumpadEnter') {
        const n = code.replace('Numpad', '')
        if (/^\d$/.test(n)) return n
        const numpadMap: Record<string, string> = {
            Decimal: '.',
            Subtract: '-',
            Add: '+',
            Multiply: '*',
            Divide: '/',
        }
        return numpadMap[n] || null
    }
    if (code.startsWith('Key') && code.length === 4) {
        const letter = code.slice(3)
        return e.shiftKey ? letter.toUpperCase() : letter.toLowerCase()
    }
    if (CODE_PUNCT[code]) {
        return e.shiftKey ? CODE_PUNCT[code][1] : CODE_PUNCT[code][0]
    }

    if (e.key && e.key.length === 1) {
        return RU_TO_EN[e.key] || e.key
    }

    return null
}

/** Нормализация уже набранной строки (кириллица → латиница по раскладке). */
export function normalizeScanLayout(raw: string): string {
    if (!raw) return ''
    let out = ''
    for (const ch of raw) {
        out += RU_TO_EN[ch] || ch
    }
    return out
}

/** true, если в строке есть кириллица (скан «уехал» в русскую раскладку). */
export function looksLikeRuLayoutScan(raw: string): boolean {
    return /[а-яёА-ЯЁ]/.test(raw || '')
}
