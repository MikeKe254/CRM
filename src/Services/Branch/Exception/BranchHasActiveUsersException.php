<?php

declare(strict_types=1);

namespace App\Services\Branch\Exception;

final class BranchHasActiveUsersException extends \RuntimeException
{
    public function __construct(string $name)
    {
        parent::__construct("Branch \"{$name}\" still has active user assignments. Reassign them before deleting.");
    }
}
