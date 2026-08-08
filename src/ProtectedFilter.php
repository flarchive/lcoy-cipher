<?php

/*
 * This file is part of lcoy/cipher.
 *
 * (c) Lcoy
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Lcoy\Cipher;

use s9e\TextFormatter\Parser\Tag;

class ProtectedFilter
{
    /**
     * Parse-time tag filter for [protected].
     *
     * Safety net on top of ParseProtected: guarantees the password attribute is
     * always stored as a one-way hash and assigns a unique id used by the
     * unlock flow.
     *
     * Relative `time` offsets ("+1h", "2d", "30m") are normalized to absolute
     * timestamps here so the visibility gate is anchored to the moment the post
     * was written instead of being re-evaluated relative to "now" on every
     * page view.
     */
    public static function filter(Tag $tag): void
    {
        $password = $tag->getAttribute('password');

        // ParseProtected already hashes the raw text; this also covers input
        // that slipped past the pre-parse pass. Never re-hash an existing hash.
        if (is_string($password) && $password !== '' && ! preg_match('/^\$(?:2[ayb]\$\d{2}|argon2)/', $password)) {
            $tag->setAttribute('password', password_hash($password, PASSWORD_DEFAULT));
        }

        if (! $tag->hasAttribute('id')) {
            $tag->setAttribute('id', 'cipher_'.bin2hex(random_bytes(8)));
        }

        $time = $tag->hasAttribute('time') ? $tag->getAttribute('time') : null;

        if (is_string($time) && $time !== '' && preg_match('/^\+?(\d+)\s*(d|h|m|i|s)\b/i', $time, $m)) {
            $seconds = ['d' => 86400, 'h' => 3600, 'm' => 60, 'i' => 60, 's' => 1][strtolower($m[2])];
            $tag->setAttribute('time', (string) (time() + (int) $m[1] * $seconds));
        }
    }
}
