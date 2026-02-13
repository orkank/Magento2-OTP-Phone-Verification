<?php
declare(strict_types=1);

namespace IDangerous\PhoneOtpVerification\Model;

use Magento\Framework\App\CacheInterface;

/**
 * Issues and validates short-lived tokens proving phone OTP verification.
 *
 * Designed for mobile apps where GraphQL (OTP) and REST (checkout) calls
 * do not share a browser session.
 */
class PhoneVerificationTokenManager
{
    private const CACHE_PREFIX = 'idg_phone_verify_token_';
    private const DEFAULT_TTL_SECONDS = 300; // 5 minutes

    /**
     * @var CacheInterface
     */
    private $cache;

    public function __construct(CacheInterface $cache)
    {
        $this->cache = $cache;
    }

    /**
     * Issue a token bound to customer + normalized phone.
     *
     * @return array{token:string,expires_in:int}
     */
    public function issueToken(int $customerId, string $normalizedPhone, ?int $ttlSeconds = null): array
    {
        $ttl = $ttlSeconds ?? self::DEFAULT_TTL_SECONDS;
        if ($ttl <= 0) {
            $ttl = self::DEFAULT_TTL_SECONDS;
        }

        $token = $this->generateToken();
        $payload = [
            'customer_id' => $customerId,
            'phone' => $normalizedPhone,
            'issued_at' => time(),
            'expires_at' => time() + $ttl
        ];

        $this->cache->save(
            json_encode($payload),
            $this->getCacheKey($token),
            [],
            $ttl
        );

        return ['token' => $token, 'expires_in' => $ttl];
    }

    /**
     * Validate token against customer + normalized phone.
     */
    public function validateToken(string $token, int $customerId, string $normalizedPhone): bool
    {
        $token = trim($token);
        // allow guest context (customerId = 0) for stateless checkout flows
        if ($token === '' || $customerId < 0 || $normalizedPhone === '') {
            return false;
        }

        $raw = $this->cache->load($this->getCacheKey($token));
        if (!$raw) {
            return false;
        }

        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            return false;
        }

        $expiresAt = (int)($payload['expires_at'] ?? 0);
        if ($expiresAt > 0 && time() > $expiresAt) {
            return false;
        }

        return (int)($payload['customer_id'] ?? 0) === $customerId
            && (string)($payload['phone'] ?? '') === $normalizedPhone;
    }

    private function getCacheKey(string $token): string
    {
        return self::CACHE_PREFIX . $token;
    }

    private function generateToken(): string
    {
        // 32 bytes => 43 chars base64url (no padding)
        $bytes = random_bytes(32);
        $b64 = rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
        return $b64;
    }
}

