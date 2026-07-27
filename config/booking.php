<?php

return [
    /*
    |--------------------------------------------------------------------------
    | PS5 surcharge (RUB per hour)
    |--------------------------------------------------------------------------
    | Added on top of the regular seat tariff when booking a ps5 marker.
    */
    'ps5_surcharge_per_hour' => (float) env('PS5_SURCHARGE_PER_HOUR', 100),
];
