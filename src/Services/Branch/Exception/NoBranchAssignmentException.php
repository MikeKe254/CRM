<?php

declare(strict_types=1);

namespace App\Services\Branch\Exception;

final class NoBranchAssignmentException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Your account has no branch assignment. Contact your administrator.');
    }
}
