const SteamUser = require('steam-user');
const SteamTotp = require('steam-totp');

// Твои захардкоженные данные для теста
const LOGIN = "olehma";
const PASSWORD = "!elite12";
const SHARED_SECRET = "vqT8nrfsPbX0MQT2eaBOZLWtiJQ=";

console.log("[TEST] Инициализация клиента Steam...");
const client = new SteamUser();

// Генерируем 5-значный код Steam Guard прямо сейчас
const currentCode = SteamTotp.generateAuthCode(SHARED_SECRET);
console.log(`[TEST] Сгенерирован текущий код 2FA: ${currentCode}`);

console.log(`[TEST] Попытка подключения к Connection Manager Valve под аккаунтом ${LOGIN}...`);

client.logOn({
    accountName: LOGIN,
    password: PASSWORD,
    twoFactorCode: currentCode
});

// Событие успешного рукопожатия с сервером
client.on('loggedOn', (details) => {
    console.log("[TEST] УСПЕХ! Сервер Valve подтвердил логин, пароль и 2FA.");
    console.log("[TEST] Ожидаем генерации десктопного RefreshToken...");
});

// Ловим тот самый заветный JWT-токен обновления десктопной сессии
client.on('refreshToken', (token) => {
    console.log("\n=======================================================");
    console.log("🟢 ТОКЕН УСПЕШНО ПОЛУЧЕН ОТ VALVE!");
    console.log("=======================================================");
    console.log(`Твой RefreshToken:\n${token}`);
    console.log(`Твой SteamID64: ${client.steamID.getSteamID64()}`);
    console.log("=======================================================\n");

    client.logOff();
    process.exit(0);
});

// Перехват любых ошибок от Valve (неверный код, пароль, бан по IP и т.д.)
client.on('error', (err) => {
    console.error("\n❌ ОШИБКА АВТОРИЗАЦИИ VALVE:");
    console.error(`Сообщение от сервера: ${err.message}`);
    console.error(`Код ошибки: ${err.eresult}\n`);
    process.exit(1);
});
