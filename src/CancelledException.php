<?php

declare(strict_types=1);

namespace IndianOpinion\GenQueue;

/**
 * Thrown by a handler that noticed a cancel request and stopped. The job ends cancelled.
 */
class CancelledException extends GenQueueException
{
}
