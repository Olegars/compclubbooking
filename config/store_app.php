<?php

return [
    /*
    | Магазинное Android-приложение (0451 Store).
    | После выкладки нового store0451.apk увеличьте STORE_APP_VERSION_CODE.
    */
    'version_code' => (int) env('STORE_APP_VERSION_CODE', 2),
    'version_name' => (string) env('STORE_APP_VERSION_NAME', '1.1.0'),
];
