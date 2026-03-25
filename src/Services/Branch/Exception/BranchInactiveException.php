<?php

declare(strict_types=1);

namespace App\Services\Branch\Exception;

final class BranchInactiveException extends \RuntimeException
{
    public function __construct(string $name)
    {
        parent::__construct("Branch \"{$name}\" is currently inactive. Contact your administrator.");
    }
}
