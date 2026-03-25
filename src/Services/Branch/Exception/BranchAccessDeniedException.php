<?php

declare(strict_types=1);

namespace App\Services\Branch\Exception;

final class BranchAccessDeniedException extends \RuntimeException
{
    public function __construct(string $slug)
    {
        parent::__construct("You do not have access to branch: {$slug}.");
    }
}
