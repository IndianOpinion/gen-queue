<?php

declare(strict_types=1);

namespace IndianOpinion\GenQueue;

/**
 * The database refused a read or a write. Fail closed: the caller knows nothing was stored.
 */
class StorageException extends GenQueueException
{
}
