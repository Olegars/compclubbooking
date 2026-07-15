// Путь: steam_generator.cjs
const SteamUser = require('steam-user');
const SteamTotp = require('steam-totp');

const LOGIN = process.argv[2];
const PASSWORD = process.argv[3];
const SHARED_SECRET = process.argv[4] && process.argv[4] !== 'null' ? process.argv[4] : null;

if (!LOGIN || !PASSWORD) {
    console.log(JSON.stringify({ status: "error", message: "Не переданы логин или пароль для входа" }));
    process.exit(1);
}

const client = new SteamUser();
const logOnDetails = {
    accountName: LOGIN,
    password: PASSWORD,
    // SteamClient (1) — токен для десктопного ConnectCache / silent login.
    // WebBrowser (2) даёт JWT, но клиент Steam его часто игнорирует → окно входа.
    platformType: 1,
    clientAppId: 7
};

if (SHARED_SECRET) {
    try {
        logOnDetails.twoFactorCode = SteamTotp.generateAuthCode(SHARED_SECRET);
    } catch (err) {
        console.log(JSON.stringify({ status: "error", message: "Ошибка TOTP: " + err.message }));
        process.exit(1);
    }
}

// Запускаем авторизацию
client.logOn(logOnDetails);

// Глобальные переменные для хранения данных сессии
let receivedToken = null;
let receivedSteamId = null;

// Флаг, гарантирующий, что мы ответим только один раз
let isResponded = false;

function tryRespond() {
    if (receivedToken && receivedSteamId && !isResponded) {
        isResponded = true;

        console.log(JSON.stringify({
            status: "success",
            token: receivedToken,
            steamid: receivedSteamId
        }));

        // КРИТИЧЕСКОЕ ИСПРАВЛЕНИЕ: НИКАКИХ client.logOff()!
        // Если вызвать logOff(), Valve мгновенно сделает токен невалидным.
        // Мы просто уничтожаем процесс Node.js, оставляя токен активным на серверах.
        setTimeout(() => {
            process.exit(0);
        }, 100);
    }
}

// 1. Ловим десктопный JWT-токен
client.on('refreshToken', (token) => {
    receivedToken = token;
    tryRespond();
});

// 2. Ждём полной авторизации профиля
client.on('loggedOn', () => {
    if (client.steamID) {
        receivedSteamId = client.steamID.getSteamID64();
        tryRespond();
    }
});

// Ловим ошибки входа
client.on('error', (err) => {
    let msg = err.message;
    if (err.eresult === 85) {
        msg = "Требуется Steam Guard 2FA, но shared_secret в базе пустой (NULL)";
    }
    console.log(JSON.stringify({ status: "error", message: msg, eresult: err.eresult }));
    process.exit(1);
});
