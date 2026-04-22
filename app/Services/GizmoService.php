<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

class GizmoService
{
    protected string $baseUrl;
    protected string $apiKey;

    public function __construct()
    {
        // Эти данные нужно будет добавить в твой файл .env
        // GIZMO_API_URL=http://192.168.1.100:8080/api
        // GIZMO_API_KEY=твой_секретный_ключ
        $this->baseUrl = config('services.gizmo.url', 'http://localhost/api');
        $this->apiKey = config('services.gizmo.key', 'secret');
    }

    /**
     * Дать команду Gizmo разблокировать компьютер на заданное время
     */
    public function startSession(int $gizmoUserId, int $hostId, int $minutes): bool
    {
        try {
            // Отправляем POST запрос к локальному серверу Gizmo
            $response = Http::timeout(5)
                ->withHeaders([
                    'X-Api-Key' => $this->apiKey,
                    'Accept' => 'application/json',
                ])
                ->post("{$this->baseUrl}/sessions/start", [
                    'userId' => $gizmoUserId,
                    'hostId' => $hostId,
                    'minutes' => $minutes
                ]);

            if ($response->successful()) {
                Log::info("REACTOR: Успешный запуск сессии. ПК: {$hostId}, Юзер: {$gizmoUserId}, Минут: {$minutes}");
                return true;
            }

            // Если Gizmo ответил ошибкой (например 400 Bad Request)
            Log::error("REACTOR: Ошибка Gizmo API при запуске сессии: " . $response->body());
            return false;

        } catch (Exception $e) {
            // Если сервер Gizmo вообще недоступен (упал инет в клубе)
            Log::critical("REACTOR: Потеряна связь с узлом Gizmo: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Экстренно завершить сессию (выключить комп)
     */
    public function stopSession(int $hostId): bool
    {
        try {
            $response = Http::timeout(5)
                ->withHeaders(['X-Api-Key' => $this->apiKey])
                ->post("{$this->baseUrl}/sessions/stop", [
                    'hostId' => $hostId
                ]);

            return $response->successful();
        } catch (Exception $e) {
            Log::error("REACTOR: Ошибка принудительной остановки узла {$hostId}: " . $e->getMessage());
            return false;
        }
    }
    // Добавь это внутрь класса GizmoService

    /**
     * Создать пользователя в Gizmo
     * @param array $userData [username, phone]
     * @return int|null Возвращает ID созданного пользователя в Gizmo
     */
    public function createUser(array $userData): ?int
    {
        try {
            $response = Http::withHeaders(['X-Api-Key' => $this->apiKey])
                ->post("{$this->baseUrl}/users", [
                    'username' => $userData['username'],
                    'mobilePhone' => $userData['phone'],
                    // Можно добавить дефолтный пароль или ПИН
                    'password' => '0451',
                ]);

            if ($response->successful()) {
                // Gizmo обычно возвращает ID созданной сущности
                return $response->json('id');
            }

            Log::error("Gizmo: Ошибка создания юзера: " . $response->body());
            return null;

        } catch (\Exception $e) {
            Log::critical("Gizmo: Сервис недоступен при регистрации: " . $e->getMessage());
            return null;
        }
    }
}
