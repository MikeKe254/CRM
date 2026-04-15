<?php

declare(strict_types=1);

namespace App\Services\Revenue;

use Doctrine\DBAL\Connection;

/**
 * Manages branch-scoped events.
 *
 * Events are revenue context — the occasion under which a transaction occurred.
 * ONE record per recurring event — forever.
 *
 * Recurrence model:
 *   none      — one-off, uses starts_at / ends_at
 *   daily     — every day within recurrence_time_start–end window
 *   weekly    — specific days of week within time window
 *   biweekly  — every other occurrence of specific days (anchored to valid_from)
 *   monthly   — Mode A: specific day of month (recurrence_monthly_day 1-31)
 *               Mode B: nth weekday of month (recurrence_days=[dow], recurrence_monthly_day 1-5)
 *                        where 5 = "last"
 *
 * Overnight windows are fully supported. An event set to run 22:00–03:00 on Fridays
 * will correctly appear at 01:00 Saturday because isRunningNow() detects the after-
 * midnight portion and evaluates recurrence rules against "yesterday" (Friday).
 */
final class EventService
{
    public function __construct(
        private readonly Connection $db,
    ) {}

    // =========================================================================
    // READS
    // =========================================================================

    /**
     * @return array<int, array<string, mixed>>
     */
    public function list(int $companyId, int $branchId, bool $includeAll = false): array
    {
        $filter = $includeAll ? '' : "AND (
            e.status != 'cancelled'
            AND (
                e.recurrence_type != 'none'
                OR e.ends_at IS NULL
                OR e.ends_at >= NOW()
            )
        )";

        return $this->db->fetchAllAssociative(
            "SELECT e.id, e.name, e.entry_type, e.description, e.starts_at, e.ends_at, e.status,
                    e.recurrence_type, e.recurrence_days, e.recurrence_time_start,
                    e.recurrence_time_end, e.recurrence_valid_from, e.recurrence_valid_until,
                    e.recurrence_monthly_day,
                    e.offer_discount_type, e.offer_discount_value, e.offer_applies_to,
                    e.created_at
               FROM events e
              WHERE e.company_id = :company_id
                AND e.branch_id  = :branch_id
                AND e.deleted_at IS NULL
                {$filter}
              ORDER BY e.recurrence_type DESC, e.starts_at DESC, e.created_at DESC",
            ['company_id' => $companyId, 'branch_id' => $branchId],
        );
    }

    /**
     * Returns all events currently running at this branch.
     * Recurrence logic is evaluated in PHP — SQL cannot cleanly express it.
     *
     * This is what the terminal calls to auto-populate the event chip row.
     * If today is Tuesday and "Tuesday Jazz Night" is weekly-Tuesday 18:00-23:00
     * and the clock is 20:15, this method returns it automatically.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findAllActive(int $branchId): array
    {
        $candidates = $this->db->fetchAllAssociative(
            "SELECT id, name, entry_type, starts_at, ends_at, status,
                    recurrence_type, recurrence_days, recurrence_time_start,
                    recurrence_time_end, recurrence_valid_from, recurrence_valid_until,
                    recurrence_monthly_day,
                    offer_discount_type, offer_discount_value, offer_applies_to,
                    created_at
               FROM events
              WHERE branch_id  = :branch_id
                AND status    != 'cancelled'
                AND deleted_at IS NULL",
            ['branch_id' => $branchId],
        );

        $now = new \DateTimeImmutable('now');

        return array_values(array_filter(
            $candidates,
            fn(array $event) => $this->isRunningNow($event, $now),
        ));
    }

    public function findById(int $id, int $companyId, int $branchId): ?array
    {
        $row = $this->db->fetchAssociative(
            'SELECT * FROM events
              WHERE id = :id AND company_id = :company_id AND branch_id = :branch_id AND deleted_at IS NULL',
            ['id' => $id, 'company_id' => $companyId, 'branch_id' => $branchId],
        );

        return $row ?: null;
    }

    // =========================================================================
    // RECURRENCE LOGIC
    // =========================================================================

    /**
     * Determines whether an event is running at the given moment.
     *
     * Overnight windows (e.g. 22:00–03:00) are fully supported. When the clock
     * is in the after-midnight portion (e.g. 01:30) the method evaluates the day-
     * of-week / monthly rules against "yesterday" because that is the day the
     * event actually started.
     */
    public function isRunningNow(array $event, ?\DateTimeImmutable $at = null): bool
    {
        $at ??= new \DateTimeImmutable('now');

        if (($event['status'] ?? '') === 'cancelled') {
            return false;
        }

        $type = $event['recurrence_type'] ?? 'none';

        // ── One-off ────────────────────────────────────────────────────────
        if ($type === 'none') {
            $starts = $event['starts_at'] ?? null;
            $ends   = $event['ends_at']   ?? null;

            if ($starts && $ends) {
                return $at->format('Y-m-d H:i:s') >= $starts
                    && $at->format('Y-m-d H:i:s') <= $ends;
            }

            // No dates → open-ended, only runs when status = active
            return ($event['status'] ?? '') === 'active';
        }

        // ── Shared: validity date range ────────────────────────────────────
        $today      = $at->format('Y-m-d');
        $validFrom  = $event['recurrence_valid_from']  ?? null;
        $validUntil = $event['recurrence_valid_until'] ?? null;

        if ($validFrom  && $today < $validFrom)  return false;
        if ($validUntil && $today > $validUntil) return false;

        // ── Shared: time-of-day window ─────────────────────────────────────
        $timeStart   = $event['recurrence_time_start'] ?? null;
        $timeEnd     = $event['recurrence_time_end']   ?? null;
        $nowTime     = $at->format('H:i:s');
        $isOvernight = $timeStart && $timeEnd && $timeEnd < $timeStart;

        if ($timeStart && $timeEnd) {
            if ($isOvernight) {
                // Outside window: after end but before start (the daytime gap)
                if ($nowTime > $timeEnd && $nowTime < $timeStart) return false;
            } else {
                if ($nowTime < $timeStart || $nowTime > $timeEnd) return false;
            }
        }

        // ── Overnight adjustment ───────────────────────────────────────────
        // If we're in the after-midnight portion of an overnight window
        // (i.e. the event started "yesterday"), evaluate recurrence rules
        // against yesterday's date so day-of-week / monthly checks are correct.
        $afterMidnight = $isOvernight && $timeEnd && $nowTime <= $timeEnd;
        $eventDay = $afterMidnight
            ? new \DateTimeImmutable((new \DateTimeImmutable($at->format('Y-m-d')))->modify('-1 day')->format('Y-m-d'))
            : new \DateTimeImmutable($at->format('Y-m-d'));

        // ── Daily ──────────────────────────────────────────────────────────
        if ($type === 'daily') {
            return true;
        }

        // ── Weekly ────────────────────────────────────────────────────────
        $days    = json_decode((string) ($event['recurrence_days'] ?? '[]'), true) ?: [];
        $eventDow = (int) $eventDay->format('w'); // 0=Sun…6=Sat

        if ($type === 'weekly') {
            return in_array($eventDow, $days, true);
        }

        // ── Biweekly ──────────────────────────────────────────────────────
        if ($type === 'biweekly') {
            if (!in_array($eventDow, $days, true)) {
                return false;
            }

            $anchorStr = $event['recurrence_valid_from'] ?? null;
            if (!$anchorStr) {
                $anchorStr = substr((string) ($event['created_at'] ?? $eventDay->format('Y-m-d')), 0, 10);
            }

            try {
                $anchor = new \DateTimeImmutable($anchorStr);
            } catch (\Throwable) {
                $anchor = $eventDay;
            }

            $anchorDate = new \DateTimeImmutable($anchor->format('Y-m-d'));
            $daysDiff   = (int) $anchorDate->diff($eventDay)->days;
            $weeksDiff  = (int) floor($daysDiff / 7);

            return $weeksDiff % 2 === 0;
        }

        // ── Monthly ───────────────────────────────────────────────────────
        if ($type === 'monthly') {
            $monthlyDay = isset($event['recurrence_monthly_day']) ? (int) $event['recurrence_monthly_day'] : 0;

            if (!empty($days)) {
                // Mode B: nth weekday of month
                return $this->isNthWeekdayOfMonth($eventDay, (int) $days[0], $monthlyDay);
            }

            // Mode A: specific day of month
            return $monthlyDay > 0 && (int) $eventDay->format('j') === $monthlyDay;
        }

        return false;
    }

    /**
     * Returns true if $date is the $n-th occurrence of $weekday in its month.
     * $n = 1–4: first through fourth. $n = 5: last.
     */
    private function isNthWeekdayOfMonth(\DateTimeImmutable $date, int $weekday, int $n): bool
    {
        if ((int) $date->format('w') !== $weekday) {
            return false;
        }

        $day = (int) $date->format('j');

        if ($n === 5) {
            // "Last" occurrence: no more same-weekday dates left in this month
            return ($day + 7) > (int) $date->format('t');
        }

        // Occurrence number: ceil(day / 7)
        return (int) ceil($day / 7) === $n;
    }

    /**
     * Returns a human-readable recurrence description.
     */
    public static function describeRecurrence(array $event): string
    {
        $type = $event['recurrence_type'] ?? 'none';

        if ($type === 'none') {
            return 'One-off';
        }

        $dayNames  = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
        $days      = json_decode((string) ($event['recurrence_days'] ?? '[]'), true) ?: [];
        sort($days);
        $dayLabels = implode(', ', array_map(fn($d) => $dayNames[$d] ?? "Day{$d}", $days));

        $timeStr = '';
        if (!empty($event['recurrence_time_start']) && !empty($event['recurrence_time_end'])) {
            $timeStr = ' · ' . substr((string) $event['recurrence_time_start'], 0, 5)
                     . '–' . substr((string) $event['recurrence_time_end'], 0, 5);
        }

        if ($type === 'monthly') {
            $monthlyDay = isset($event['recurrence_monthly_day']) ? (int) $event['recurrence_monthly_day'] : 0;

            if (!empty($days)) {
                // Mode B: nth weekday
                $ordinal = match ($monthlyDay) {
                    1       => 'First',
                    2       => 'Second',
                    3       => 'Third',
                    4       => 'Fourth',
                    5       => 'Last',
                    default => "#{$monthlyDay}",
                };
                return "{$ordinal} {$dayLabels} of every month" . $timeStr;
            }

            // Mode A: specific date
            if ($monthlyDay > 0) {
                $suffix = match (true) {
                    in_array($monthlyDay, [1, 21, 31]) => 'st',
                    in_array($monthlyDay, [2, 22])     => 'nd',
                    in_array($monthlyDay, [3, 23])     => 'rd',
                    default                             => 'th',
                };
                return "Every {$monthlyDay}{$suffix} of the month" . $timeStr;
            }

            return 'Monthly' . $timeStr;
        }

        return match ($type) {
            'daily'    => 'Every day' . $timeStr,
            'weekly'   => 'Every ' . ($dayLabels ?: '?') . $timeStr,
            'biweekly' => 'Every other ' . ($dayLabels ?: '?') . $timeStr,
            default    => ucfirst($type),
        };
    }

    // =========================================================================
    // WRITES
    // =========================================================================

    public function create(
        int     $companyId,
        int     $branchId,
        string  $name,
        ?string $description,
        ?string $startsAt,
        ?string $endsAt,
        string  $recurrenceType = 'none',
        ?array  $recurrenceDays = null,
        ?string $recurrenceTimeStart = null,
        ?string $recurrenceTimeEnd = null,
        ?string $recurrenceValidFrom = null,
        ?string $recurrenceValidUntil = null,
        ?int    $recurrenceMonthlyDay = null,
        string  $entryType = 'event',
        ?string $offerDiscountType = null,
        ?float  $offerDiscountValue = null,
        string  $offerAppliesTo = 'all',
    ): int {
        $this->db->insert('events', [
            'company_id'               => $companyId,
            'branch_id'                => $branchId,
            'name'                     => $name,
            'entry_type'               => $entryType,
            'description'              => $description,
            'starts_at'                => $recurrenceType === 'none' ? $startsAt : null,
            'ends_at'                  => $recurrenceType === 'none' ? $endsAt   : null,
            'status'                   => 'draft',
            'recurrence_type'          => $recurrenceType,
            'recurrence_days'          => $recurrenceDays ? json_encode(array_values($recurrenceDays)) : null,
            'recurrence_time_start'    => $recurrenceTimeStart,
            'recurrence_time_end'      => $recurrenceTimeEnd,
            'recurrence_valid_from'    => $recurrenceValidFrom,
            'recurrence_valid_until'   => $recurrenceValidUntil,
            'recurrence_monthly_day'   => $recurrenceMonthlyDay,
            'offer_discount_type'      => $entryType === 'offer' ? $offerDiscountType  : null,
            'offer_discount_value'     => $entryType === 'offer' ? $offerDiscountValue : null,
            'offer_applies_to'         => $entryType === 'offer' ? $offerAppliesTo     : null,
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function update(
        int     $id,
        int     $companyId,
        int     $branchId,
        string  $name,
        ?string $description,
        ?string $startsAt,
        ?string $endsAt,
        string  $recurrenceType = 'none',
        ?array  $recurrenceDays = null,
        ?string $recurrenceTimeStart = null,
        ?string $recurrenceTimeEnd = null,
        ?string $recurrenceValidFrom = null,
        ?string $recurrenceValidUntil = null,
        ?int    $recurrenceMonthlyDay = null,
        string  $entryType = 'event',
        ?string $offerDiscountType = null,
        ?float  $offerDiscountValue = null,
        string  $offerAppliesTo = 'all',
    ): void {
        $this->db->executeStatement(
            'UPDATE events
                SET name                   = :name,
                    entry_type             = :entry_type,
                    description            = :description,
                    starts_at              = :starts_at,
                    ends_at                = :ends_at,
                    recurrence_type        = :recurrence_type,
                    recurrence_days        = :recurrence_days,
                    recurrence_time_start  = :recurrence_time_start,
                    recurrence_time_end    = :recurrence_time_end,
                    recurrence_valid_from  = :recurrence_valid_from,
                    recurrence_valid_until = :recurrence_valid_until,
                    recurrence_monthly_day = :recurrence_monthly_day,
                    offer_discount_type    = :offer_discount_type,
                    offer_discount_value   = :offer_discount_value,
                    offer_applies_to       = :offer_applies_to
              WHERE id = :id AND company_id = :company_id AND branch_id = :branch_id',
            [
                'name'                   => $name,
                'entry_type'             => $entryType,
                'description'            => $description,
                'starts_at'              => $recurrenceType === 'none' ? $startsAt : null,
                'ends_at'                => $recurrenceType === 'none' ? $endsAt   : null,
                'recurrence_type'        => $recurrenceType,
                'recurrence_days'        => $recurrenceDays ? json_encode(array_values($recurrenceDays)) : null,
                'recurrence_time_start'  => $recurrenceTimeStart,
                'recurrence_time_end'    => $recurrenceTimeEnd,
                'recurrence_valid_from'  => $recurrenceValidFrom,
                'recurrence_valid_until' => $recurrenceValidUntil,
                'recurrence_monthly_day' => $recurrenceMonthlyDay,
                'offer_discount_type'    => $entryType === 'offer' ? $offerDiscountType  : null,
                'offer_discount_value'   => $entryType === 'offer' ? $offerDiscountValue : null,
                'offer_applies_to'       => $entryType === 'offer' ? $offerAppliesTo     : null,
                'id'                     => $id,
                'company_id'             => $companyId,
                'branch_id'              => $branchId,
            ],
        );
    }

    public function setStatus(int $id, int $companyId, int $branchId, string $status): void
    {
        $this->db->executeStatement(
            'UPDATE events
                SET status = :status
              WHERE id = :id AND company_id = :company_id AND branch_id = :branch_id AND deleted_at IS NULL',
            ['status' => $status, 'id' => $id, 'company_id' => $companyId, 'branch_id' => $branchId],
        );
    }

    public function delete(int $id, int $companyId, int $branchId): void
    {
        $this->db->executeStatement(
            'UPDATE events SET deleted_at = NOW()
              WHERE id = :id AND company_id = :company_id AND branch_id = :branch_id',
            ['id' => $id, 'company_id' => $companyId, 'branch_id' => $branchId],
        );
    }
}
