<?php

namespace App\Modules\Matches\Enums;

/**
 * Estado de um envio de demo (etapa M3). `uploading` → `completed` | `rejected` | `cancelled` |
 * `expired`; `completed` → `cancelled` | `expired` (e, na M4, os estados da análise). Nos três
 * finais o arquivo não existe mais.
 */
enum MatchUploadStatus: string
{
    case Uploading = 'uploading';
    case Completed = 'completed';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
}
