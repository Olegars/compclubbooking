<?php

return [
    // 1 XP = столько рублей премии. Управляющий может переопределить в /admin/staff.
    'xp_to_rub_rate' => (float) env('STAFF_XP_TO_RUB', 10),

    // План выручки бара за смену, ₽. Считаются неотменённые заказы за окно смены.
    'bar_target_rub' => (float) env('STAFF_BAR_TARGET_RUB', 15000),

    'monthly_share' => 0.70,

    'awards' => [
        'shift_base' => 100,
        'bar_perfect' => 30,
        'hardware_green' => 20,
        'bar_plan' => 50,
        'shortage' => -50,
    ],

    // Инциденты зала за время смены, которые снимают «зелёный аудит» станций.
    'hardware_incident_types' => [
        'golden_image_drift',
        'golden_image_crash',
        'nic_link_flap',
        'hardware_switch_fault',
    ],
];
