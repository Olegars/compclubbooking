<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Illuminate\Support\Facades\Auth;

class HomeController extends Controller
{
    public function index()
    {
        return Inertia::render('Home/Index', [
            'userBalance' => 1450.00, // Позже заменим на Auth::user()->balance
            'zones' => [
                [
                    'id' => '01',
                    'name' => 'PRO ZONE',
                    'desc' => 'Профессиональное оборудование для киберспорта и стриминга.',
                    'specs' => [
                        ['label' => 'CPU', 'value' => 'i9-13900K'],
                        ['label' => 'GPU', 'value' => 'RTX 4090 24GB'],
                        ['label' => 'RAM', 'value' => '32GB DDR5 6000MHz'],
                        ['label' => 'DISP', 'value' => 'ZOWIE 360Hz 24.5"']
                    ],
                    'price' => 250,
                    'status' => 'AVAILABLE'
                ],
                [
                    'id' => '02',
                    'name' => 'BOOTCAMP',
                    'desc' => 'Изолированная комната для командных тренировок (5 ПК).',
                    'specs' => [
                        ['label' => 'CPU', 'value' => 'i7-12700K'],
                        ['label' => 'GPU', 'value' => 'RTX 3080 10GB'],
                        ['label' => 'RAM', 'value' => '16GB DDR4 3600MHz'],
                        ['label' => 'DISP', 'value' => 'AOC 240Hz 24.5"']
                    ],
                    'price' => 180,
                    'status' => 'LIMITED'
                ]
            ]
        ]);
    }
}
