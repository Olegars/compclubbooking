<?php
namespace App\Http\Controllers;

use App\Services\GizmoService;
use Inertia\Inertia;
use Illuminate\Support\Facades\Auth;

class ProfileController extends Controller
{
    protected $gizmoService; // 1. Объявляем свойство

    public function __construct(GizmoService $gizmoService) // 2. Внедряем сервис
    {
        $this->gizmoService = $gizmoService;
    }

    // app/Http/Controllers/ProfileController.php
    public function index()
    {
        return Inertia::render('Profile/Dashboard', [
            'user' => [
                'id' => Auth::id(),
                'name' => Auth::user()->name,
                'phone' => Auth::user()->phone,
                'gizmo_pin' => Auth::user()->gizmo_pin, // Передаем ПИН
            ],
            // ... gizmo data
        ]);
    }

}
