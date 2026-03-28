<?php

declare(strict_types=1);

namespace App\Services\Encryption;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Encrypts and decrypts API credentials stored in the database.
 *
 * Uses libsodium secretbox (XSalsa20-Poly1305) with a random 24-byte nonce.
 * Ciphertext is stored as: base64(nonce + ciphertext).
 *
 * The key is read from APP_CREDENTIALS_KEY in .env (base64-encoded 32 bytes).
 * Existing rows with credentials_encrypted=0 are returned as-is (plaintext).
 */
final class CredentialEncryptionService
{
    private string $key;

    public function __construct(
        #[Autowire('%env(APP_CREDENTIALS_KEY)%')]
        string $rawKey,
    ) {
        $decoded = base64_decode($rawKey, strict: true);

        if ($decoded === false || strlen($decoded) !== \SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \RuntimeException(
                'APP_CREDENTIALS_KEY must be a base64-encoded 32-byte key. '
                . 'Generate with: php -r "echo base64_encode(random_bytes(32));"'
            );
        }

        $this->key = $decoded;
    }

    /**
     * Encrypt a plaintext string.
     * Returns base64(nonce + ciphertext) — safe to store in TEXT columns.
     */
    public function encrypt(string $plaintext): string
    {
        $nonce      = random_bytes(\SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $this->key);

        return base64_encode($nonce . $ciphertext);
    }

    /**
     * Decrypt a value produced by encrypt().
     * Returns the original plaintext string.
     */
    public function decrypt(string $encoded): string
    {
        $raw = base64_decode($encoded, strict: true);

        if ($raw === false) {
            throw new \RuntimeException('Credential value is not valid base64.');
        }

        if (strlen($raw) < \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + \SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
            throw new \RuntimeException('Credential value is too short to be a valid ciphertext.');
        }

        $nonce      = substr($raw, 0, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($raw, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $this->key);

        if ($plaintext === false) {
            throw new \RuntimeException('Credential decryption failed — wrong key or corrupted data.');
        }

        return $plaintext;
    }

    /**
     * Read a credential field transparently:
     * - If the row is encrypted (credentials_encrypted=1), decrypt.
     * - Otherwise return as-is (legacy plaintext row).
     */
    public function read(string $value, bool $isEncrypted): string
    {
        if ($value === '') {
            return '';
        }

        return $isEncrypted ? $this->decrypt($value) : $value;
    }

    /**
     * Encrypt an array of credential fields and return [field => encrypted_value].
     * Non-empty values are encrypted; empty values pass through as-is.
     */
    public function encryptFields(array $fields): array
    {
        $result = [];
        foreach ($fields as $key => $value) {
            $result[$key] = ($value !== '' && $value !== null) ? $this->encrypt((string) $value) : $value;
        }
        return $result;
    }
}
