<?php
namespace App\Services;

class GizmoService
{
    // Переключатель: true - работаем на моках, false - идем в реальное API
    protected $useMocks = true;

    public function getUserInfo($userIdOrPhone)
    {
        if ($this->useMocks) {
            return [
                'balance' => 1250,
                'bonus' => 300,
                'status' => 'Commander',
                'current_pc' => 'PRO-01',
                'spent_time' => 5400 // секунд
            ];
        }

        // Здесь будет реальный запрос: return $this->apiCall("GET", "/users/$userId");
    }

    public function getHosts()
    {
        if ($this->useMocks) {
            return [
                ['name' => 'PC-01'], ['name' => 'PC-02'], ['name' => 'PRO-01']
                // ... остальные моки
            ];
        }

        // Реальный запрос к Gizmo API
    }
}
