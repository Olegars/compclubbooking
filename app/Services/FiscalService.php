<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FiscalService {
    protected string $url;
    protected string $user;
    protected string $password;

    public function __construct() {
        $this->url = env('KKM_SERVER_URL', 'http://localhost:5893/Execute');
        $this->user = env('KKM_SERVER_USER', 'Admin');
        $this->password = env('KKM_SERVER_PASS', '');
    }

    /**
     * Регистрация чека (Приход)
     */
    public function registerReceipt($transaction) {
        $user = $transaction->user;

        $data = [
            "Command" => "RegisterCheck",
            "NumDevice" => 0, // Номер устройства в KkmServer
            "InnKassa" => "", // Если несколько касс, можно фильтровать по ИНН
            "IsFiscalCheck" => true,
            "TypeCheck" => 0, // 0 - Приход, 1 - Возврат прихода
            "NotPrint" => false, // Печатать бумажный чек или только электронный?
            "CashierName" => "REACTOR System",
            "ClientAddress" => $user->email ?? $user->phone, // Куда слать эл. чек
            "CheckStrings" => [
                [
                    "Register" => [
                        "Name" => $transaction->description,
                        "Quantity" => 1,
                        "Price" => abs($transaction->amount),
                        "Amount" => abs($transaction->amount),
                        "Tax" => -1, // -1 - Без НДС (для патента/УСН)
                        "EAN13" => ""
                    ]
                ]
            ],
            "Payments" => [
                [
                    "Type" => $transaction->source === 'cash' ? 0 : 1, // 0 - Нал, 1 - Безнал
                    "Amount" => abs($transaction->amount)
                ]
            ],
            "User" => $this->user,
            "Password" => $this->password
        ];

        try {
            $response = Http::timeout(10)->post($this->url, $data);
            $result = $response->json();

            if (isset($result['Status']) && $result['Status'] == 0) {
                return [
                    'success' => true,
                    'url' => $result['URL'] ?? null
                ];
            }

            return ['success' => false, 'error' => $result['Error'] ?? 'Unknown KKM Error'];

        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
