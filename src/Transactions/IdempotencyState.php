<?php

declare(strict_types=1);

namespace RoayaPay\PlatformCore\Transactions;

enum IdempotencyState: string
{
    /** This caller owns the key and must execute the operation. */
    case ACQUIRED = 'acquired';

    /** The operation already completed; replay the stored result. */
    case REPLAY = 'replay';

    /** The key was used with a different payload. */
    case CONFLICT = 'conflict';

    /** Another request holding this key is still running. */
    case IN_PROGRESS = 'in_progress';

    /** A previous attempt crashed or its lock expired: it MAY have had side effects. Verify before retrying. */
    case OUTCOME_UNKNOWN = 'outcome_unknown';
}
