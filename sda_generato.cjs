const { LoginSession } = require('steam-session');
const readlineSync = require('readline-sync');

// Получаем параметры из командной строки: node sda_generator.cjs <логин> <пароль>
const accountName = process.argv[2];
const password = process.argv[3];

if (!accountName || !password) {
    console.log("\n\x1b[31m[REACTOR-SDA] Ошибка: Не указаны учетные данные!\x1b[0m");
    console.log("Использование: \x1b[36mnode sda_generator.cjs <логин> <пароль>\x1b[0m\n");
    process.exit(1);
}

// Инициализируем сессию мобильного приложения Steam
let session = new LoginSession(2);

console.log(`\n[REACTOR-SDA] Запуск сессии сопряжения для аккаунта: ${accountName}...`);

// Перехват критических ошибок Node.js
process.on('unhandledRejection', (reason) => {
    console.error('\n\x1b[31m[CRITICAL-REJECTION] Необработанная ошибка:\x1b[0m', reason);
    process.exit(1);
});

// Запускаем авторизацию в Steam
session.startWithCredentials({
    accountName: accountName,
    password: password
});

session.on('timeout', () => {
    console.log("\n\x1b[31m[REACTOR-SDA] Время ожидания сессии сопряжения истекло (Timeout).\x1b[0m");
    process.exit(1);
});

// Событие успешной генерации и привязки виртуального SDA
session.on('authenticated', () => {
    console.log("\n\x1b[32m[REACTOR-SUCCESS] Успешный вход и сопряжение устройства!\x1b[0m");
    console.log("\n============================================================================");
    console.log(`\x1b[32mТВОЙ SHARED_SECRET:\x1b[0m  \x1b[33m${session.steamGuardSecret}\x1b[0m`);
    console.log("============================================================================");
    console.log("\x1b[36m-> Скопируйте это значение и вставьте в колонку `shared_secret` вашей БД.\x1b[0m\n");
    process.exit(0);
});

// Ожидание ввода кода подтверждения (Email или SMS, если привязан телефон)
session.on('steamGuard', (domain, callback) => {
    console.log(`\n[REACTOR-SDA] Требуется первичное подтверждение входа.`);
    const code = readlineSync.question(`[STEAM-GUARD] Введите код (отправлен на ${domain || 'Email/SMS'}): `);
    callback(code);
});

session.on('error', (err) => {
    console.error("\n\x1b[31m[REACTOR-ERROR] Ошибка привязки аутентификатора:\x1b[0m", err.message);
    process.exit(1);
});

// Предохранитель на 45 секунд
setTimeout(() => {
    console.log("\n\x1b[31m[REACTOR-TIMEOUT] Скрипт принудительно завершен по таймауту.\x1b[0m");
    process.exit(1);
}, 45000);
