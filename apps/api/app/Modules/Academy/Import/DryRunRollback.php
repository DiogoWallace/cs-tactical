<?php

namespace App\Modules\Academy\Import;

use RuntimeException;

/** Lançada no fim da simulação (`--dry-run`) para desfazer a transação. */
final class DryRunRollback extends RuntimeException {}
