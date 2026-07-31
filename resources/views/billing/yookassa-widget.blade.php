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
            min-height: 100%;
            background: #050505;
            color: #e5e5e5;
            font-family: Inter, Segoe UI, system-ui, sans-serif;
        }
        .wrap {
            max-width: 480px;
            margin: 0 auto;
            padding: 20px 16px 28px;
        }
        .head {
            margin-bottom: 16px;
        }
        .head h1 {
            margin: 0 0 6px;
            font-size: 18px;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: #22c55e;
        }
        .head p {
            margin: 0;
            font-size: 13px;
            color: #a3a3a3;
        }
        .amount {
            margin: 0 0 16px;
            font-size: 28px;
            font-weight: 800;
            color: #fff;
        }
        #payment-form {
            min-height: 320px;
            background: #0a0a0a;
            border: 1px solid rgba(34, 197, 94, 0.25);
            border-radius: 12px;
            padding: 8px;
        }
        .banner {
            display: none;
            margin-top: 16px;
            padding: 14px 16px;
            border-radius: 10px;
            font-size: 14px;
            line-height: 1.4;
        }
        .banner.show { display: block; }
        .banner.ok {
            background: rgba(34, 197, 94, 0.12);
            border: 1px solid rgba(34, 197, 94, 0.45);
            color: #86efac;
        }
        .banner.err {
            background: rgba(248, 113, 113, 0.12);
            border: 1px solid rgba(248, 113, 113, 0.4);
            color: #fecaca;
        }
        .banner.info {
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid rgba(255, 255, 255, 0.1);
            color: #d4d4d4;
        }
    </style>
</head>
<body>
<div class="wrap">
    <div class="head">
        <h1>Reactor Pay</h1>
        <p>Оплата картой через ЮKassa · тестовый режим</p>
    </div>
    <div class="amount">{{ number_format((float) $payment->amount, 0, '.', ' ') }} ₽</div>

    @if ($payment->isSucceeded())
        <div class="banner ok show">Оплата уже прошла. Можно закрыть окно — баланс обновится на ПК.</div>
    @elseif ($payment->status === 'canceled')
        <div class="banner err show">Платёж отменён. Закройте окно и попробуйте снова.</div>
    @elseif (!$confirmationToken)
        <div class="banner err show">Нет токена виджета. Создайте платёж заново.</div>
    @else
        <div id="payment-form"></div>
        <div id="status" class="banner info"></div>
    @endif
</div>

@if (!$payment->isFinal() && $confirmationToken)
<script src="https://yookassa.ru/checkout-widget/v1/checkout-widget.js"></script>
<script>
(function () {
    const syncUrl = @json($syncUrl);
    const statusEl = document.getElementById('status');

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

    // Ошибку виджета отдаём читаемой строкой: шелл снимает текст этого блока
    // через runJavaScript, а '' + object превращался в "[object Object]".
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

    const checkout = new window.YooMoneyCheckoutWidget({
        confirmation_token: @json($confirmationToken),
        return_url: @json($returnUrl),
        error_callback: function (error) {
            show('err', 'Ошибка виджета: ' + describe(error));
        }
    });

    if (typeof checkout.on === 'function') {
        checkout.on('success', function () {
            show('ok', 'Оплата принята. Синхронизируем баланс…');
            syncPayment().then(function (data) {
                if (data && data.paid) {
                    show('ok', 'Готово. Можно закрыть окно — баланс обновится на ПК.');
                } else {
                    show('ok', 'Платёж принят. Баланс обновится в течение минуты.');
                }
            });
        });
        checkout.on('fail', function () {
            show('err', 'Оплата не прошла. Можно закрыть окно и попробовать снова.');
            syncPayment();
        });
        checkout.on('complete', function () {
            syncPayment();
        });
    }

    checkout.render('payment-form').then(function () {
        show('info', 'Введите данные карты тестового магазина ЮKassa.');
    }).catch(function (e) {
        show('err', 'Не удалось показать форму: ' + describe(e));
    });
})();
</script>
@endif
</body>
</html>
