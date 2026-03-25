<?php

declare(strict_types=1);

namespace App\Services\Branch\Exception;

final class BranchSlugTakenException extends \RuntimeException
{
    public function __construct(string $slug)
    {
        parent::__construct("Branch slug \"{$slug}\" is already taken in this company.");
    }
}
