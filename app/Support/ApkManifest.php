<?php

namespace App\Support;

/**
 * versionCode / versionName из бинарного AndroidManifest внутри APK.
 * /app.json должен отдавать эти числа, а не отдельную настройку:
 * если объявить версию выше файла, клиент скачивает тот же APK и ставит его по кругу.
 */
final class ApkManifest
{
    /**
     * @return array{version_code:int,version_name:string}|null
     */
    public static function read(string $path): ?array
    {
        if (! is_file($path) || ! class_exists(\ZipArchive::class)) {
            return null;
        }

        $zip = new \ZipArchive;
        if ($zip->open($path) !== true) {
            return null;
        }
        $xml = $zip->getFromName('AndroidManifest.xml');
        $zip->close();
        if (! is_string($xml) || $xml === '') {
            return null;
        }

        return self::parseBinaryXml($xml);
    }

    /**
     * @return array{version_code:int,version_name:string}|null
     */
    private static function parseBinaryXml(string $xml): ?array
    {
        if (strlen($xml) < 16 || self::u16($xml, 0) !== 0x0003) {
            return null;
        }

        $pool = 8;
        if (self::u16($xml, $pool) !== 0x0001) {
            return null;
        }
        $headerSize = self::u16($xml, $pool + 2);
        $poolSize = self::u32($xml, $pool + 4);
        $stringCount = self::u32($xml, $pool + 8);
        $flags = self::u32($xml, $pool + 16);
        $stringsStart = self::u32($xml, $pool + 20);
        if ($headerSize < 28 || $pool + $poolSize > strlen($xml) || $stringCount > 100000) {
            return null;
        }

        $utf8 = ($flags & 0x100) !== 0;
        $strings = [];
        $base = $pool + $stringsStart;
        $offsets = $pool + $headerSize;
        for ($i = 0; $i < $stringCount; $i++) {
            $at = $offsets + ($i * 4);
            if ($at + 4 > strlen($xml)) {
                return null;
            }
            $strings[] = self::poolString($xml, $base + self::u32($xml, $at), $utf8);
        }

        $codeIndex = array_search('versionCode', $strings, true);
        $nameIndex = array_search('versionName', $strings, true);
        if ($codeIndex === false || $nameIndex === false) {
            return null;
        }

        $versionCode = null;
        $versionName = null;
        $pos = $pool + $poolSize;
        $end = strlen($xml);
        while ($pos + 8 <= $end) {
            $type = self::u16($xml, $pos);
            $nodeHeader = self::u16($xml, $pos + 2);
            $size = self::u32($xml, $pos + 4);
            if ($size < 8 || $pos + $size > $end) {
                break;
            }
            if ($type === 0x0102 && $nodeHeader >= 16) {
                $ext = $pos + $nodeHeader;
                if ($ext + 16 <= $end) {
                    $attrStart = self::u16($xml, $ext + 8);
                    $attrSize = self::u16($xml, $ext + 10);
                    $attrCount = self::u16($xml, $ext + 12);
                    if ($attrSize >= 20) {
                        $attrBase = $ext + $attrStart;
                        for ($a = 0; $a < $attrCount; $a++) {
                            $ap = $attrBase + ($a * $attrSize);
                            if ($ap + 20 > $end) {
                                break;
                            }
                            $name = self::u32($xml, $ap + 4);
                            $dataType = ord($xml[$ap + 15]);
                            $data = self::u32($xml, $ap + 16);
                            if ($name === $codeIndex && $dataType === 0x10) {
                                $versionCode = $data;
                            }
                            if ($name === $nameIndex && $dataType === 0x03 && isset($strings[$data])) {
                                $versionName = $strings[$data];
                            }
                        }
                    }
                }
            }
            $pos += $size;
        }

        if ($versionCode === null || $versionName === null || $versionName === '') {
            return null;
        }

        return [
            'version_code' => $versionCode,
            'version_name' => $versionName,
        ];
    }

    private static function poolString(string $xml, int $off, bool $utf8): string
    {
        $len = strlen($xml);
        if ($off < 0 || $off >= $len) {
            return '';
        }
        if ($utf8) {
            $charLen = ord($xml[$off]);
            $p = $off + 1;
            if ($charLen & 0x80) {
                if ($p >= $len) {
                    return '';
                }
                $charLen = (($charLen & 0x7F) << 8) | ord($xml[$p]);
                $p++;
            }
            if ($p >= $len) {
                return '';
            }
            $byteLen = ord($xml[$p]);
            $p++;
            if ($byteLen & 0x80) {
                if ($p >= $len) {
                    return '';
                }
                $byteLen = (($byteLen & 0x7F) << 8) | ord($xml[$p]);
                $p++;
            }
            if ($p + $byteLen > $len) {
                return '';
            }

            return substr($xml, $p, $byteLen);
        }

        if ($off + 2 > $len) {
            return '';
        }
        $charLen = self::u16($xml, $off);
        $p = $off + 2;
        if ($charLen & 0x8000) {
            if ($p + 2 > $len) {
                return '';
            }
            $charLen = (($charLen & 0x7FFF) << 16) | self::u16($xml, $p);
            $p += 2;
        }
        $byteLen = $charLen * 2;
        if ($charLen < 0 || $p + $byteLen > $len) {
            return '';
        }
        return self::utf16leToUtf8(substr($xml, $p, $byteLen));
    }

    private static function utf16leToUtf8(string $raw): string
    {
        $out = '';
        $n = strlen($raw);
        for ($i = 0; $i + 1 < $n; $i += 2) {
            $cu = unpack('v', substr($raw, $i, 2))[1];
            if ($cu === 0) {
                break;
            }
            if ($cu < 0x80) {
                $out .= chr($cu);
            } elseif ($cu < 0x800) {
                $out .= chr(0xC0 | ($cu >> 6)).chr(0x80 | ($cu & 0x3F));
            } else {
                $out .= chr(0xE0 | ($cu >> 12))
                    .chr(0x80 | (($cu >> 6) & 0x3F))
                    .chr(0x80 | ($cu & 0x3F));
            }
        }

        return $out;
    }

    private static function u16(string $b, int $o): int
    {
        if ($o < 0 || $o + 2 > strlen($b)) {
            return 0;
        }

        return unpack('v', substr($b, $o, 2))[1];
    }

    private static function u32(string $b, int $o): int
    {
        if ($o < 0 || $o + 4 > strlen($b)) {
            return 0;
        }

        return unpack('V', substr($b, $o, 4))[1];
    }
}
