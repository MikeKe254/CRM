<?php

declare(strict_types=1);

namespace App\Services\Customer;

use Doctrine\DBAL\Connection;

/**
 * Manages the canonical `customers` table.
 *
 * A customer is identified solely by msisdn per company.
 * findOrCreate() is the primary entry point — safe to call on every transaction.
 */
final class CustomerService
{
    public function __construct(
        private readonly Connection $db,
    ) {}

    // =========================================================================
    // FIND / CREATE
    // =========================================================================

    /**
     * Find an existing customer or create a new one.
     * Returns the full customers row as an array.
     *
     * @param string|null $gender     'male' | 'female' — only applied if not already set
     * @param int|null    $birthMonth 1–12
     * @param int|null    $birthDay   1–31
     */
    public function findOrCreate(
        int     $companyId,
        string  $msisdn,
        ?string $firstName  = null,
        ?string $gender     = null,
        ?int    $birthMonth = null,
        ?int    $birthDay   = null,
    ): array {
        $msisdn = $this->normalizePhone($msisdn);

        if ($msisdn === null) {
            throw new \InvalidArgumentException("Invalid phone number: {$msisdn}");
        }

        $existing = $this->findByMsisdn($companyId, $msisdn);

        if ($existing !== null) {
            $updates = [];
            $params  = ['id' => $existing['id']];

            if ($firstName !== null && ($existing['first_name'] === null || $existing['first_name'] === '')) {
                $updates[] = 'first_name = :first_name';
                $params['first_name'] = $firstName;
            }
            if ($gender !== null && in_array($gender, ['male', 'female'], true)
                && ($existing['gender'] === 'unknown' || $existing['gender'] === null)) {
                $updates[] = 'gender = :gender';
                $params['gender'] = $gender;
            }
            if ($birthMonth !== null && $existing['birth_month'] === null) {
                $updates[] = 'birth_month = :birth_month';
                $params['birth_month'] = $birthMonth;
            }
            if ($birthDay !== null && $existing['birth_day'] === null) {
                $updates[] = 'birth_day = :birth_day';
                $params['birth_day'] = $birthDay;
            }

            if ($updates !== []) {
                $this->db->executeStatement(
                    'UPDATE customers SET ' . implode(', ', $updates) . ', updated_at = NOW() WHERE id = :id',
                    $params,
                );
            }

            return $this->findByMsisdn($companyId, $msisdn) ?? $existing;
        }

        // Create new
        $this->db->insert('customers', [
            'company_id'  => $companyId,
            'msisdn'      => $msisdn,
            'first_name'  => $firstName,
            'gender'      => in_array($gender, ['male', 'female'], true) ? $gender : 'unknown',
            'birth_month' => $birthMonth,
            'birth_day'   => $birthDay,
            'enrolled_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        return $this->findByMsisdn($companyId, $msisdn) ?? [];
    }

    /**
     * Find a customer by msisdn. Returns null if not found.
     */
    public function findByMsisdn(int $companyId, string $msisdn): ?array
    {
        $msisdn = $this->normalizePhone($msisdn);

        if ($msisdn === null) {
            return null;
        }

        $row = $this->db->fetchAssociative(
            'SELECT * FROM customers
              WHERE company_id = :company_id AND msisdn = :msisdn
              LIMIT 1',
            ['company_id' => $companyId, 'msisdn' => $msisdn],
        );

        return $row ?: null;
    }

    /**
     * Find a customer by id.
     */
    public function findById(int $companyId, int $customerId): ?array
    {
        $row = $this->db->fetchAssociative(
            'SELECT * FROM customers WHERE id = :id AND company_id = :company_id LIMIT 1',
            ['id' => $customerId, 'company_id' => $companyId],
        );

        return $row ?: null;
    }

    /**
     * Update last_seen_at on every transaction.
     */
    public function touchLastSeen(int $customerId): void
    {
        $this->db->executeStatement(
            'UPDATE customers SET last_seen_at = NOW(), updated_at = NOW() WHERE id = :id',
            ['id' => $customerId],
        );
    }

    // =========================================================================
    // PHONE NORMALISATION
    // =========================================================================

    /**
     * Normalize to E.164 Kenya format (254XXXXXXXXX).
     * Returns null if the number cannot be normalised.
     */
    public function normalizePhone(string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', $phone);

        if ($digits === null || $digits === '') {
            return null;
        }

        // 07XXXXXXXX or 01XXXXXXXX → 2547 / 2541
        if (strlen($digits) === 10 && in_array($digits[0], ['0'], true)) {
            $digits = '254' . substr($digits, 1);
        }

        // 7XXXXXXXX (9 digits starting with 7)
        if (strlen($digits) === 9 && $digits[0] === '7') {
            $digits = '254' . $digits;
        }

        // 1XXXXXXXX (9 digits starting with 1 — Safaricom 0111)
        if (strlen($digits) === 9 && $digits[0] === '1') {
            $digits = '254' . $digits;
        }

        // Must now be 254 + 9 digits = 12 digits
        if (strlen($digits) !== 12 || !str_starts_with($digits, '254')) {
            return null;
        }

        return $digits;
    }

    /**
     * Returns true if the string looks like a valid Kenyan mobile number.
     */
    public function isValidPhone(string $phone): bool
    {
        return $this->normalizePhone($phone) !== null;
    }
}
