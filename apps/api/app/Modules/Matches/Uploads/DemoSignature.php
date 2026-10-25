<?php

namespace App\Modules\Matches\Uploads;

/**
 * Assinatura das demos do Source 2 (CS2): os 8 primeiros bytes são `PBDEMS2\0` ("protobuf demo,
 * Source 2"; o `demo_file_stamp` que o awpy e o parser Boon conferem). A M3 não interpreta mais
 * nada do arquivo (decisão U4): o cabeçalho é lido pelo analisador isolado da M4.
 */
final class DemoSignature
{
    public const MAGIC = "PBDEMS2\0";

    public static function matches(string $head): bool
    {
        return strlen($head) >= strlen(self::MAGIC) && str_starts_with($head, self::MAGIC);
    }
}
