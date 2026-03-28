<?php

declare(strict_types=1);

namespace App\Services\Loyalty\DTO;

/**
 * A resolved loyalty account with program branding and tier included.
 * Returned by LoyaltyService — never constructed outside the service.
 */
final class LoyaltyAccount
{
    public function __construct(
        public readonly int                 $id,
        public readonly int                 $companyId,
        public readonly int                 $customerId,
        public readonly string              $msisdn,
        public readonly int                 $pointsBalance,
        public readonly int                 $totalPointsEarned,
        public readonly int                 $totalPointsRedeemed,

        // Program branding
        public readonly string              $programName,
        public readonly string              $pointsName,
        public readonly ?string             $pointsSymbol,

        // Current tier (null if no tiers defined or not yet qualified)
        public readonly ?int                $tierId,
        public readonly ?string             $tierName,
        public readonly ?string             $tierColor,

        // Next tier (null if already at highest)
        public readonly ?string             $nextTierName,
        public readonly ?int                $nextTierMinPoints,

        public readonly \DateTimeImmutable  $enrolledAt,
    ) {}

    /** Points needed to reach next tier. Null if already at highest. */
    public function pointsToNextTier(): ?int
    {
        if ($this->nextTierMinPoints === null) {
            return null;
        }

        return max(0, $this->nextTierMinPoints - $this->pointsBalance);
    }

    /** Formatted balance with symbol, e.g. "250 pts" or "250 Stars" */
    public function formattedBalance(): string
    {
        $symbol = $this->pointsSymbol ?? $this->pointsName;
        return number_format($this->pointsBalance) . ' ' . $symbol;
    }

    public static function fromRow(array $row): self
    {
        return new self(
            id:                  (int) $row['id'],
            companyId:           (int) $row['company_id'],
            customerId:          (int) $row['customer_id'],
            msisdn:              (string) $row['msisdn'],
            pointsBalance:       (int) $row['points_balance'],
            totalPointsEarned:   (int) $row['total_points_earned'],
            totalPointsRedeemed: (int) $row['total_points_redeemed'],
            programName:         (string) ($row['program_name'] ?? 'Loyalty Program'),
            pointsName:          (string) ($row['points_name'] ?? 'Points'),
            pointsSymbol:        $row['points_symbol'] ?? null,
            tierId:              isset($row['tier_id']) ? (int) $row['tier_id'] : null,
            tierName:            $row['tier_name'] ?? null,
            tierColor:           $row['tier_color'] ?? null,
            nextTierName:        $row['next_tier_name'] ?? null,
            nextTierMinPoints:   isset($row['next_tier_min_points']) ? (int) $row['next_tier_min_points'] : null,
            enrolledAt:          new \DateTimeImmutable($row['enrolled_at']),
        );
    }
}
