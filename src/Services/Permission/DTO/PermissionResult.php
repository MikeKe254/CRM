<?php

declare(strict_types=1);

namespace App\Services\Permission\DTO;

/**
 * Returned by every mutating method in PermissionService.
 * Immutable — inspect it, don't mutate it.
 *
 * Usage:
 *   $result = $this->permissions->assignPermission(...);
 *   if (!$result->success) {
 *       return $this->json(['error' => $result->reason], $result->httpStatus);
 *   }
 */
final class PermissionResult
{
    private function __construct(
        public readonly bool   $success,
        public readonly string $reason,
        public readonly int    $httpStatus,
        public readonly array  $data = [],
    ) {}

    public static function ok(string $reason = 'Success.', array $data = []): self
    {
        return new self(true, $reason, 200, $data);
    }

    public static function fail(string $reason, int $httpStatus = 400): self
    {
        return new self(false, $reason, $httpStatus, []);
    }

    public function toArray(): array
    {
        return [
            'success'    => $this->success,
            'reason'     => $this->reason,
            'data'       => $this->data,
        ];
    }
}
