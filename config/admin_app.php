<?php

return [
    /*
    | Админское Android-приложение (0451 Ctrl).
    | После выкладки нового admin0451.apk увеличьте ADMIN_APP_VERSION_CODE.
    */
    'version_code' => (int) env('ADMIN_APP_VERSION_CODE', 2),
    'version_name' => (string) env('ADMIN_APP_VERSION_NAME', '1.1.0'),
];
