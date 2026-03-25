<?php

declare(strict_types=1);

namespace App\Services\Branch\DTO;

final class BranchPickerResult
{
    /** @param BranchNode[] $availableBranches */
    public function __construct(
        public readonly bool       $requiresPicker,
        public readonly ?BranchNode $directBranch,
        public readonly array      $availableBranches,
    ) {}

    public static function direct(BranchNode $branch): self
    {
        return new self(false, $branch, []);
    }

    public static function picker(array $branches): self
    {
        return new self(true, null, $branches);
    }
}
