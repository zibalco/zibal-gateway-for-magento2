<?php

namespace ChalakSoft\Zibal\Model;

use Magento\Framework\Encryption\Encryptor;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\UrlInterface;
use Magento\Sales\Model\Order;

/**
 * Opaque, encrypted token carried in the callback URL sent to Zibal.
 *
 * The order reference is encrypted with the shop's crypt/key (ChaCha20-Poly1305,
 * authenticated), so it can be neither read nor altered. Each token carries a
 * random nonce; PaymentService stores its hash per trackId and accepts it once.
 */
class CallbackToken
{
    /** URL path parameter: .../zibal/index/callback/t/<token>/ */
    const PARAM = 't';

    const VERSION = 1;

    /** Longest token we will try to decrypt */
    const MAX_LENGTH = 1024;

    /** @var EncryptorInterface */
    private $encryptor;

    /** @var UrlInterface */
    private $urlBuilder;

    public function __construct(EncryptorInterface $encryptor, UrlInterface $urlBuilder)
    {
        $this->encryptor = $encryptor;
        $this->urlBuilder = $urlBuilder;
    }

    /**
     * @param Order $order
     * @return array ['token' => string for the URL, 'nonce' => one-time secret]
     */
    public function generate(Order $order)
    {
        $nonce = bin2hex(random_bytes(16));

        $payload = json_encode([
            'v' => self::VERSION,
            'o' => (string)$order->getIncrementId(),
            's' => (int)$order->getStoreId(),
            'c' => (string)$order->getCreatedAt(),
            'n' => $nonce,
        ]);

        // Encryptor output is "<keyVersion>:<cipher>:<base64>". Only the key version
        // and ciphertext travel; the cipher is fixed so parse() never falls back to
        // Magento's legacy, unauthenticated ciphers.
        list($keyVersion, , $cipherText) = explode(':', $this->encryptor->encrypt($payload), 3);

        return [
            'token' => $keyVersion . '.' . $this->base64UrlEncode(base64_decode($cipherText)),
            'nonce' => $nonce,
        ];
    }

    /**
     * @param string $token
     * @return string
     */
    public function getCallbackUrl($token)
    {
        return $this->urlBuilder->getUrl('zibal/index/callback', [
            '_secure' => true,
            self::PARAM => $token,
        ]);
    }

    /**
     * @param mixed $token
     * @return array|null ['o' => incrementId, 's' => storeId, 'c' => createdAt, 'n' => nonce],
     *                    or null when the token is missing, malformed or tampered with
     */
    public function parse($token)
    {
        if (!is_string($token)
            || strlen($token) > self::MAX_LENGTH
            || !preg_match('/^(\d{1,3})\.([A-Za-z0-9_-]+)$/', $token, $parts)
        ) {
            return null;
        }

        $cipherText = $this->base64UrlDecode($parts[2]);

        if ($cipherText === false || $cipherText === '') {
            return null;
        }

        try {
            $json = $this->encryptor->decrypt(
                $parts[1] . ':' . Encryptor::CIPHER_AEAD_CHACHA20POLY1305 . ':' . base64_encode($cipherText)
            );
        } catch (\Throwable $e) {
            return null;
        }

        $data = json_decode((string)$json, true);

        if (!is_array($data)
            || ($data['v'] ?? null) !== self::VERSION
            || !isset($data['o'], $data['s'], $data['c'], $data['n'])
            || !is_string($data['o']) || $data['o'] === ''
            || !is_int($data['s'])
            || !is_string($data['c'])
            || !is_string($data['n']) || !preg_match('/^[0-9a-f]{32}$/', $data['n'])
        ) {
            return null;
        }

        return $data;
    }

    /**
     * Only this hash is stored, so the database alone cannot be used to forge a callback.
     *
     * @param string $nonce
     * @return string
     */
    public static function hashNonce($nonce)
    {
        return hash('sha256', (string)$nonce);
    }

    private function base64UrlEncode($data)
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function base64UrlDecode($data)
    {
        return base64_decode(strtr($data, '-_', '+/'), true);
    }
}
