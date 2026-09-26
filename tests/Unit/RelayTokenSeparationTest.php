<?php

namespace Tests\Unit;

use Tests\TestCase;

class RelayTokenSeparationTest extends TestCase
{
    public function test_fan_wifi_and_kitchen_configs_do_not_read_wol_token(): void
    {
        foreach (['fan.php', 'wifi_access.php', 'kitchen_print.php'] as $file) {
            $source = (string) file_get_contents(config_path($file));

            $this->assertDoesNotMatchRegularExpression(
                "/env\(\s*'CLUB_WOL_RELAY_TOKEN'/",
                $source,
                $file.' must not fall back to CLUB_WOL_RELAY_TOKEN',
            );
        }
    }
}
