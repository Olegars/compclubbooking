<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Оплата · REACTOR</title>
    <style>
        :root { color-scheme: dark; }
        * { box-sizing: border-box; }
        html, body {
            margin: 0;
            height: 100%;
            /* Совпадает с фоном попапов шелла (Theme.bgPanel). */
            background: #0a0a0a;
            color: #f5f5f5;
            font-family: Inter, Segoe UI, system-ui, sans-serif;
            overflow: hidden;
        }
        .shell {
            display: flex;
            flex-direction: column;
            height: 100%;
        }
        .body {
            flex: 1 1 auto;
            overflow-y: auto;
            padding: 12px;
        }
        .foot {
            flex: 0 0 auto;
            border-top: 1px solid rgba(255, 255, 255, 0.05);
            padding: 10px 16px;
            text-align: center;
            font-size: 8px;
            font-weight: 700;
            letter-spacing: 0.18em;
            text-transform: uppercase;
            color: rgba(255, 255, 255, 0.25);
        }
        #payment-form { min-height: 320px; }

        /* Фон под блоками формы виджет не красит — по документации это фон
           контейнера, то есть наш. Просвечивать он будет только через
           прозрачный iframe, а Chromium делает встроенный документ
           непрозрачно-белым, когда у страницы color-scheme: dark, а у
           документа ЮKassa схема не задана. Возвращаем фрейму normal. */
        #payment-form iframe {
            color-scheme: normal;
            background-color: transparent !important;
        }

        .loader {
            display: flex;
            min-height: 340px;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }
        .loader.hide { display: none; }
        .spin {
            height: 40px;
            width: 40px;
            border-radius: 9999px;
            border: 2px solid rgba(34, 197, 94, 0.2);
            border-top-color: #22c55e;
            animation: spin 0.8s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
        .loader-text {
            margin-top: 20px;
            font-size: 10px;
            font-weight: 900;
            font-style: italic;
            letter-spacing: 0.2em;
            text-transform: uppercase;
            color: #22c55e;
        }

        .banner {
            display: none;
            margin-top: 12px;
            border-radius: 12px;
            padding: 16px;
            text-align: center;
            font-size: 10px;
            font-weight: 900;
            font-style: italic;
            letter-spacing: 0.1em;
            text-transform: uppercase;
        }
        .banner.show { display: block; }
        .banner.ok {
            border: 1px solid rgba(34, 197, 94, 0.3);
            background: rgba(34, 197, 94, 0.1);
            color: #22c55e;
        }
        .banner.err {
            border: 1px solid rgba(239, 68, 68, 0.3);
            background: rgba(239, 68, 68, 0.1);
            color: #fca5a5;
            font-size: 12px;
            font-style: normal;
            letter-spacing: normal;
            text-transform: none;
        }
    </style>
</head>
<body>
<div class="shell">
    <div class="body">
        @if ($payment->isSucceeded())
            <div class="banner ok show">Оплата прошла · баланс обновится на ПК</div>
        @elseif ($payment->status === 'canceled')
            <div class="banner err show">Платёж отменён. Закройте окно и попробуйте снова.</div>
        @elseif (!$confirmationToken)
            <div class="banner err show">Нет токена виджета. Создайте платёж заново.</div>
        @else
            <div id="loader" class="loader">
                <div class="spin"></div>
                <div class="loader-text">Загрузка формы</div>
            </div>
            <div id="payment-form"></div>
            <div id="status" class="banner"></div>
        @endif
    </div>
    <div class="foot">Защищённая форма ЮKassa · данные карты не передаются REACTOR</div>
</div>

@if (!$payment->isFinal() && $confirmationToken)
<script src="https://yookassa.ru/checkout-widget/v1/checkout-widget.js"></script>
<script>
(function () {
    const syncUrl = @json($syncUrl);
    const statusEl = document.getElementById('status');
    const loaderEl = document.getElementById('loader');

    // Флаги для шелла: он опрашивает их и сам закрывает нативное окно.
    window.__payDone = false;
    window.__payClose = false;

    // Накопитель ошибок: статус-строку перетирает успешный render(),
    // а шелл читает именно этот список через runJavaScript.
    window.__payErrors = [];
    function logError(where, text) {
        window.__payErrors.push(where + ': ' + text);
    }

    window.addEventListener('error', function (e) {
        logError('window.error', (e && e.message) || 'unknown');
    });
    window.addEventListener('unhandledrejection', function (e) {
        logError('unhandledrejection', describe(e && e.reason));
    });

    function show(type, text) {
        if (type === 'err') logError('widget', text);
        if (!statusEl) return;
        statusEl.className = 'banner show ' + type;
        statusEl.textContent = text;
    }

    function hideLoader() {
        if (loaderEl) loaderEl.className = 'loader hide';
    }

    // Ошибку виджета отдаём читаемой строкой: '' + object превращался
    // в "[object Object]" и скрывал причину.
    function describe(value) {
        if (value === null || value === undefined) return 'unknown';
        if (typeof value === 'string') return value;
        try {
            var seen = [];
            var json = JSON.stringify(value, function (k, v) {
                if (typeof v === 'object' && v !== null) {
                    if (seen.indexOf(v) !== -1) return '[circular]';
                    seen.push(v);
                }
                return v;
            });
            if (json && json !== '{}') return json;
        } catch (e) { /* ниже фолбэк */ }
        var parts = [];
        for (var k in value) {
            try { parts.push(k + '=' + value[k]); } catch (e) { /* пропускаем */ }
        }
        return parts.length ? parts.join(', ') : Object.prototype.toString.call(value);
    }

    function syncPayment() {
        return fetch(syncUrl, {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: '{}'
        }).then(function (r) { return r.json(); }).catch(function () { return null; });
    }

    // Статус в ЮKassa проставляется с задержкой — опрашиваем, как в ЛК.
    function syncUntilPaid(attempt) {
        attempt = attempt || 0;
        show('ok', 'Платёж принят · обновляем баланс');

        return syncPayment().then(function (data) {
            if (data && data.paid) {
                window.__payDone = true;
                return;
            }
            if (data && data.payment_status === 'canceled') {
                show('err', 'Платёж отменён. Закройте окно и попробуйте снова.');
                return;
            }
            if (attempt >= 11) {
                // Дальше добьёт вебхук: шеллу разрешаем закрыться.
                window.__payDone = true;
                return;
            }
            return new Promise(function (resolve) {
                setTimeout(function () { resolve(syncUntilPaid(attempt + 1)); }, 1000);
            });
        });
    }

    const checkout = new window.YooMoneyCheckoutWidget({
        confirmation_token: @json($confirmationToken),
        return_url: @json($returnUrl),
        // Тёмная тема под интерфейс REACTOR (как в личном кабинете).
        // payment_methods не задаём: тестовый магазин отвечает на него
        // customization_of_payment_methods_not_allowed и форма не грузится.
        customization: {
            colors: {
                background: '#0A0A0A',
                control_primary: '#22C55E',
                control_primary_content: '#020202',
                control_secondary: '#64748B',
                border: '#24332A',
                text: '#F5F5F5'
            }
        },
        error_callback: function (error) {
            hideLoader();
            show('err', 'Ошибка ЮKassa: ' + describe(error));
        }
    });

    if (typeof checkout.on === 'function') {
        checkout.on('success', function () { syncUntilPaid(0); });
        checkout.on('complete', function () { syncUntilPaid(0); });
        checkout.on('fail', function () {
            show('err', 'Оплата не прошла. Проверьте данные карты и попробуйте снова.');
        });
    }

    checkout.render('payment-form').then(function () {
        hideLoader();
    }).catch(function (e) {
        hideLoader();
        show('err', 'Не удалось показать форму: ' + describe(e));
    });
})();
</script>
@endif
</body>
</html>
