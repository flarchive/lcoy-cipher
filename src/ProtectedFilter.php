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
        if (is_string($password) && $password !== '' && ! self::isHashed($password)) {
            $tag->setAttribute('password', password_hash($password, PASSWORD_DEFAULT));
        }

        if (! $tag->hasAttribute('id')) {
            $tag->setAttribute('id', 'cipher_'.bin2hex(random_bytes(8)));
        }

        $time = $tag->hasAttribute('time') ? $tag->getAttribute('time') : null;

        if (is_string($time) && $time !== '' && ($seconds = Conditions::relativeSeconds($time)) !== null) {
            $tag->setAttribute('time', (string) (time() + $seconds));
        }
    }

    /**
     * Whether a password value already looks like a bcrypt/argon2 hash.
     *
     * Shared with ParseProtected so the parse-time pre-hash and the safety-net
     * filter agree on what counts as "already hashed" (e.g. the raw text s9e
     * reconstructs via unparse() when a post is edited).
     */
    public static function isHashed(string $password): bool
    {
        return (bool) preg_match('/^\$(?:2[ayb]\$\d{2}|argon2)/', $password);
    }
}
