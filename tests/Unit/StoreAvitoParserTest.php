<?php

namespace Tests\Unit;

use App\Services\StoreAvito\StoreAvitoCatalogAttrParser;
use App\Services\StoreAvito\StoreAvitoCopywriter;
use Tests\TestCase;

class StoreAvitoParserTest extends TestCase
{
    private StoreAvitoCatalogAttrParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new StoreAvitoCatalogAttrParser;
    }

    public function test_parses_intel_cpu_for_avito_xml(): void
    {
        $a = $this->parser->parse('cpu', 'Процессор Intel Core i5-12400F OEM', '12400F', 'Intel');

        $this->assertSame('Intel', $a['avito_brand']);
        $this->assertSame('Core i5', $a['avito_model']);
        $this->assertSame('12400F', $a['avito_code']);
        $this->assertSame('12400F', $a['standard']);
        $this->assertSame('cpu', $a['type']);
        $this->assertSame('LGA1700', $a['socket']);
    }

    public function test_parses_ryzen_5_7500f_from_catalog_name(): void
    {
        $a = $this->parser->parse(
            'cpu',
            'Процессор AMD Ryzen 5 7500F Soc-AM5 3.7GHz OEM',
            '100-000000597',
            'AMD',
        );

        $this->assertSame('AMD', $a['avito_brand']);
        $this->assertSame('Ryzen 5', $a['avito_model']);
        $this->assertSame('7500F', $a['avito_code']);
        $this->assertSame('7500F', $a['standard']);
        $this->assertSame('cpu', $a['type']);
        $this->assertSame('AM5', $a['socket']);
    }

    public function test_parses_ryzen_and_am5(): void
    {
        $a = $this->parser->parse('cpu', 'AMD Ryzen 7 7800X3D', '', 'AMD');

        $this->assertSame('AMD', $a['avito_brand']);
        $this->assertSame('Ryzen 7', $a['avito_model']);
        $this->assertSame('7800X3D', $a['avito_code']);
        $this->assertSame('AM5', $a['socket']);
    }

    public function test_parses_card_maker_gpu_not_nvidia_chip(): void
    {
        $a = $this->parser->parse('gpu', 'ZOTAC GAMING GEFORCE RTX 4060 Ti 16GB AMP', '', 'ZOTAC');

        $this->assertSame('ZOTAC', $a['avito_brand']);
        $this->assertStringContainsString('4060 Ti', $a['avito_model']);
        $this->assertStringContainsString('AMP', $a['avito_model']);
        $this->assertSame('RTX 4060 Ti', $a['avito_code']);
        $this->assertSame('RTX 4060 Ti', $a['standard']);
        $this->assertSame('gpu', $a['type']);
    }

    public function test_parses_rtx_4060_from_compact_and_hyphen_names(): void
    {
        $this->assertSame('RTX 4060', $this->parser->parse('gpu', 'Palit RTX4060 Dual 8GB', '', 'Palit')['avito_code']);
        $this->assertSame('RTX 4060', $this->parser->parse('gpu', 'VGA Palit RTX-4060 Dual', '', 'Palit')['avito_code']);
        $this->assertSame('RTX 4060', $this->parser->allowedAvitoGpuChip('ZOTAC GAMING GEFORCE RTX 4060 8GB'));
        $this->assertTrue($this->parser->isAllowedAvitoGpu('Видеокарта Palit GeForce RTX 4060 StormX 8GB'));
        $this->assertSame('RTX 4060', $this->parser->allowedAvitoGpuChip('Видеокарта Palit Dual 4060 8GB'));
        $this->assertSame('RTX 4060', $this->parser->allowedAvitoGpuChip('VGA Palit Dual 4060 8G'));
        $this->assertSame('RTX 4060', $this->parser->allowedAvitoGpuChip('Видеокарта Palit РТХ 4060 Dual 8GB'));
        $this->assertNull($this->parser->allowedAvitoGpuChip('Palit Dual NE64060019P1-1060F'));
        $this->assertSame('RTX 4060', $this->parser->canonicalizeAllowedGpuChip('4060'));
        $this->assertSame('RTX 4060 Ti', $this->parser->canonicalizeAllowedGpuChip('4060ti'));
        $this->assertSame('RTX 4060 Ti', $this->parser->canonicalizeAllowedGpuChip('RTX 4060 Ti'));
    }

    public function test_parses_palit_gpu_brand(): void
    {
        $a = $this->parser->parse('gpu', 'Palit GeForce RTX 4060 Dual 8GB', '', 'Palit');

        $this->assertSame('Palit', $a['avito_brand']);
        $this->assertStringContainsString('RTX 4060', $a['avito_model']);
        $this->assertSame('RTX 4060', $a['avito_code']);
    }

    public function test_printer_drum_p4060_is_not_rtx_4060(): void
    {
        $name = 'Блок фотобарабана NVPrint совместимый NV-DK-8550 DU для Kyocera ECOSYS P4060/P8060';
        $this->assertNull($this->parser->allowedAvitoGpuChip($name));
        $this->assertFalse($this->parser->isAllowedAvitoGpu($name));
    }

    public function test_does_not_parse_workstation_a400_as_rtx_4060(): void
    {
        $a = $this->parser->parse('gpu', 'Видеокарта NVIDIA RTX A400, 4 GB GDDR6, 64 bit, 4xDisplayPort, GPU 727 MHz', '', 'NVIDIA');

        $this->assertTrue($this->parser->isSkippedAvitoGpu('NVIDIA RTX A400'));
        $this->assertFalse($this->parser->isAllowedAvitoGpu('NVIDIA RTX A400'));
        $this->assertSame('SKIP', $a['avito_code']);
    }

    public function test_does_not_parse_l40s_as_geforce(): void
    {
        $a = $this->parser->parse('gpu', 'Видеокарта NVIDIA L40S, 48 GB GDDR6, 384 bit, 4xDisplayPort, GPU 1110 MHz', '', 'NVIDIA');

        $this->assertTrue($this->parser->isSkippedAvitoGpu($a['avito_model'] ?? 'L40S'));
        $this->assertSame('SKIP', $a['avito_code']);
    }

    public function test_skips_rtx_3060_and_rx_6600(): void
    {
        $old = $this->parser->parse('gpu', 'MSI GeForce RTX 3060 Ventus 12G', '', 'MSI');
        $this->assertSame('SKIP', $old['avito_code']);
        $this->assertTrue($this->parser->isSkippedAvitoGpu('RTX 3060'));

        $rdna2 = $this->parser->parse('gpu', 'Sapphire Pulse RX 6600 8GB', '', 'Sapphire');
        $this->assertSame('SKIP', $rdna2['avito_code']);
    }

    public function test_allows_rx_9070_xt(): void
    {
        $a = $this->parser->parse('gpu', 'Gigabyte Radeon RX 9070 XT Gaming OC', '', 'Gigabyte');
        $this->assertSame('RX 9070 XT', $a['avito_code']);
        $this->assertTrue($this->parser->isAllowedAvitoGpu($a['avito_model'] ?? ''));
    }

    public function test_parses_ram_kit_and_ddr5(): void
    {
        $a = $this->parser->parse('ram', 'Kingston Fury Beast DDR5 32GB (2x16GB) 6000', '', 'Kingston');

        $this->assertSame('DDR5', $a['ddr']);
        $this->assertSame(32, $a['ram_gb']);
        $this->assertSame('32 ГБ', $a['avito_code']);
        $this->assertSame('DDR5 32', $a['standard']);
        $this->assertSame('ram', $a['type']);
        $this->assertSame('DDR5 32', $this->parser->ramStandard('DDR5', 32));
        $this->assertSame('DDR4 32', $this->parser->parseRamStandard('ddr4-32')['standard'] ?? null);
        $this->assertSame('DDR5 16', $this->parser->parseRamStandard('DDR5 16 ГБ')['standard'] ?? null);
        $this->assertNull($this->parser->parseRamStandard('32'));
    }

    public function test_parses_ssd_256_not_m2(): void
    {
        $a = $this->parser->parse('ssd', 'Kingston NV2 256GB', '', 'Kingston');
        $this->assertSame(256, $a['ram_gb']);
        $this->assertSame('256', $a['standard']);
        $this->assertSame('ssd', $a['type']);
        $this->assertSame('256', $this->parser->parseSsdStandard('SSD M.2 256 ГБ'));
        $this->assertSame('256', $this->parser->parseSsdStandard('M.2 250GB'));
        $this->assertSame('512', $this->parser->parseSsdStandard('Samsung 980 512 ГБ'));
        $this->assertSame('1024', $this->parser->parseSsdStandard('Kingston NV2 1TB'));
        $this->assertNull($this->parser->parseSsdStandard('M.2'));
    }

    public function test_parses_full_motherboard_name_not_chipset(): void
    {
        $a = $this->parser->parse('motherboard', 'GIGABYTE B760M GAMING X DDR4 (rev. 1.0)', '', 'GIGABYTE');

        $this->assertSame('Gigabyte', $a['avito_brand']);
        $this->assertStringContainsString('B760M GAMING X DDR4', $a['avito_model']);
        $this->assertSame('LGA1700', $a['socket']);
        $this->assertSame('DDR4', $a['ddr']);
        $this->assertSame('B760', $a['avito_code']);
    }

    public function test_parses_b650_board(): void
    {
        $a = $this->parser->parse('motherboard', 'GIGABYTE B650 AORUS ELITE AX', '', 'Gigabyte');

        $this->assertSame('Gigabyte', $a['avito_brand']);
        $this->assertStringContainsString('B650', $a['avito_model']);
        $this->assertSame('AM5', $a['socket']);
        $this->assertSame('DDR5', $a['ddr']);
        $this->assertSame('B650', $a['avito_code']);
    }

    public function test_parses_b650e_not_as_b650(): void
    {
        $a = $this->parser->parse('motherboard', 'ASUS TUF GAMING B650E-PLUS WIFI', '', 'ASUS');

        $this->assertSame('AM5', $a['socket']);
        $this->assertSame('B650E', $a['avito_code']);
    }

    public function test_parses_psu_watts_from_model_and_vt(): void
    {
        $a = $this->parser->parse('psu', 'Блок питания Chieftec GPS-500A8', '', 'Chieftec');
        $this->assertSame(500, $a['wattage']);

        $b = $this->parser->parse('psu', 'AeroCool VX PLUS 500Вт', '', 'AeroCool');
        $this->assertSame(500, $b['wattage']);

        $c = $this->parser->parse('psu', 'Deepcool PK650D 80 PLUS Bronze', '', 'Deepcool');
        $this->assertSame(650, $c['wattage']);
    }

    public function test_title_keeps_config_id_within_50_chars(): void
    {
        $copy = new StoreAvitoCopywriter;
        $title = $copy->clampTitle('Игровой компьютер на топовом железе для киберспорта 2026 супер', 'DZK48190');

        $this->assertLessThanOrEqual(50, mb_strlen($title));
        $this->assertStringContainsString('DZK48190', $title);
    }

    public function test_copywriter_builds_title_and_bom_from_catalog_names(): void
    {
        $copy = new StoreAvitoCopywriter;
        $out = $copy->write('DZK48190', [
            ['type' => 'cpu', 'name' => 'Процессор Intel Core i5-12400F OEM'],
            ['type' => 'motherboard', 'name' => 'GIGABYTE B760M GAMING X DDR4'],
            ['type' => 'ram', 'name' => 'Kingston DDR4 32GB'],
            ['type' => 'gpu', 'name' => 'ZOTAC GAMING GEFORCE RTX 4060 Ti 16GB AMP'],
            ['type' => 'ssd', 'name' => 'Kingston NV2 256GB'],
            ['type' => 'psu', 'name' => 'Chieftec 650W'],
            ['type' => 'cooler', 'name' => 'Вентилятор для ноутбука Dell Latitude 2100'],
        ], 89900, [
            'CodeProcessor' => '12400F',
            'CodeVideocard' => 'RTX 4060 Ti',
            'RamSize' => '32 ГБ',
        ]);

        $this->assertStringStartsWith('ПК 12400F RTX 4060 Ti 32 ГБ DZK48190', $out['title']);
        $this->assertLessThanOrEqual(50, mb_strlen($out['title']));
        $this->assertStringContainsString("Комплектация:\n• Процессор Intel Core i5-12400F OEM", $out['description']);
        $this->assertStringContainsString('• ZOTAC GAMING GEFORCE RTX 4060 Ti 16GB AMP', $out['description']);
        $this->assertStringNotContainsString('Latitude', $out['description']);
        $this->assertStringContainsString('ID:DZK48190', $out['description']);
    }
}
