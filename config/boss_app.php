<?php

return [
    /*
    | Android-приложение владельца (0451 Boss).
    | После выкладки нового boss0451.apk увеличьте BOSS_APP_VERSION_CODE.
    */
    'version_code' => (int) env('BOSS_APP_VERSION_CODE', 1),
    'version_name' => (string) env('BOSS_APP_VERSION_NAME', '1.0.0'),
];
