<?php

return [
    /*
    | Запасные числа, если из sector0451.apk не прочитался манифест.
    | Живой /app.json берёт version_code и version_name из самого файла.
    | Объявлять версию выше файла нельзя: телефон скачает тот же APK
    | и будет ставить его по кругу, потому что номер сборки не вырастет.
    */
    'version_code' => (int) env('CLIENT_APP_VERSION_CODE', 4),
    'version_name' => (string) env('CLIENT_APP_VERSION_NAME', '1.1.2'),
];
