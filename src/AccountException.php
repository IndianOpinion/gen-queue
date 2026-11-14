<?php

declare(strict_types=1);

namespace IndianOpinion\GenQueue;

/**
 * Thrown by a handler for an authentication, permission or credit failure. The job is requeued without using an attempt and its whole group is held.
 */
class AccountException extends GenQueueException
{
}
