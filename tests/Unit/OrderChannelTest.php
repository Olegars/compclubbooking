<?php

namespace Tests\Unit;

use App\Support\OrderChannel;
use Illuminate\Http\Request;
use Tests\TestCase;

class OrderChannelTest extends TestCase
{
    public function test_desktop_browser_is_site(): void
    {
        $request = Request::create('/api/shop/checkout', 'POST', ['order_type' => 'desktop']);

        $this->assertSame(OrderChannel::SITE, OrderChannel::fromShopRequest($request));
    }

    public function test_comp_club_client_ua_is_app(): void
    {
        $request = Request::create('/api/shop/checkout', 'POST', ['order_type' => 'desktop']);
        $request->headers->set('User-Agent', 'Mozilla/5.0 CompClubClient/1.0');

        $this->assertSame(OrderChannel::APP, OrderChannel::fromShopRequest($request));
    }

    public function test_kiosk_and_terminal_route_are_terminal(): void
    {
        $kiosk = Request::create('/api/shop/checkout', 'POST', ['order_type' => 'kiosk']);
        $this->assertSame(OrderChannel::TERMINAL, OrderChannel::fromShopRequest($kiosk));

        $terminal = Request::create('/api/terminal/shop/checkout', 'POST');
        $this->assertSame(OrderChannel::TERMINAL, OrderChannel::fromShopRequest($terminal));
    }

    public function test_labels(): void
    {
        $this->assertSame('Сайт', OrderChannel::label(OrderChannel::SITE));
        $this->assertSame('Приложение', OrderChannel::label(OrderChannel::APP));
        $this->assertSame('Терминал', OrderChannel::label(OrderChannel::TERMINAL));
        $this->assertSame('Шелл', OrderChannel::label(OrderChannel::SHELL));
        $this->assertSame('', OrderChannel::label(null));
    }
}
