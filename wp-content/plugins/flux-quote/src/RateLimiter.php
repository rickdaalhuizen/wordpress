<?php

/**
 * Caps how often the public endpoints can be hit, counting attempts in transients over a fixed window.
 *
 * @package Flux_Quote
 */

declare(strict_types=1);

namespace Flux\Quote;

use WP_Error;

final class RateLimiter
{
    private const PREFIX = 'flux_quote_rl_';

    /**
     * Counts one attempt for this subject and returns an error once the limit is reached within the window.
     * The read and the write are not atomic: a burst of parallel requests can slightly exceed the limit.
     */
    public function hit(string $bucket, string $subject, int $limit, int $window): ?WP_Error
    {
        // md5 keeps the key well under the 172 characters a transient name allows.
        $key = self::PREFIX . $bucket . '_' . md5($subject);

        $data = get_transient($key);
        if (!is_array($data) || ($data['reset'] ?? 0) <= time()) {
            $data = ['count' => 0, 'reset' => time() + $window];
        }

        if ($data['count'] >= $limit) {
            return new WP_Error(
                'rate_limited',
                __('Too many requests. Please try again later.', 'flux-quote'),
                ['status' => 429]
            );
        }

        $data['count']++;
        // The expiration follows the window's end, so each attempt does not push the reset further.
        set_transient($key, $data, max(1, $data['reset'] - time()));

        return null;
    }

    /**
     * For IPv6 this returns the packed /64 prefix (raw bytes), since a single connection gets a whole /64
     * and devices can rotate the lower 64 bits; limiting the prefix counts per household/office.
     * Caution: Behind a reverse proxy this is the proxy's address, so every visitor would share the same limit.
     */
    public function client_ip(): string
    {
        $ip = (string) filter_var($_SERVER['REMOTE_ADDR'] ?? '', FILTER_VALIDATE_IP);
        if ($ip === '') {
            return '';
        }

        $packed = inet_pton($ip);
        if ($packed === false || strlen($packed) !== 16) {
            return $ip;
        }

        // An IPv4-mapped address (::ffff:a.b.c.d) has an all-zero prefix, so it would put every IPv4 client in one bucket.
        if (strncmp($packed, str_repeat("\0", 10) . "\xff\xff", 12) === 0) {
            return (string) inet_ntop(substr($packed, 12));
        }

        return substr($packed, 0, 8);
    }
}
