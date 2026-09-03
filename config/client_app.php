<?php

return [
    /*
    | Клиентское Android-приложение. version_code должен быть больше,
    | чем у установленного APK, иначе приложение не начнёт самообновление.
    | После выкладки нового sector0451.apk увеличьте CLIENT_APP_VERSION_CODE.
    */
    'version_code' => (int) env('CLIENT_APP_VERSION_CODE', 2),
    'version_name' => (string) env('CLIENT_APP_VERSION_NAME', '1.1.0'),
];
