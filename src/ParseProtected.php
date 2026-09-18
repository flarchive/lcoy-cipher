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

use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use s9e\TextFormatter\Parser;

class ParseProtected
{
    /**
     * Opening tag of a [protected ...] block, mirroring s9e's own BBCode
     * attribute grammar so the pre-parse pass and the parser always agree on
     * where the tag ends and what its attribute values are:
     *
     *  - attribute names are [-\w]+ and must be followed immediately by "="
     *    (s9e ignores names without one, and allows no whitespace there);
     *  - a value may be double- or single-quoted (and may then contain "]"),
     *    or unquoted, in which case s9e consumes everything up to the next
     *    whitespace-then-attribute or "]" — quotes included. That unquoted
     *    branch is copied verbatim from s9e's parser regex, so a hand-written
     *    [protected password=ab"c] is matched here and its password hashed,
     *    instead of leaking into the stored <s> unparse marker.
     *
     * The (?![\w-]) after the tag name keeps [protected-like] (a different
     * BBCode for s9e) untouched.
     */
    protected const TAG_OPEN = '~\[protected(?![\w-])((?:\s*[-\w]+(?:=(?:"[^"]*"|\'[^\']*\'|(?:[^\s\]]|[ \t](?!\s*(?:[-\w]+=|/?\))))*))?)*)\s*\]~i';

    /**
     * The password attribute inside an opening tag, with the same value
     * grammar as TAG_OPEN. The lookarounds require "password" to be a whole
     * attribute name (not a fragment such as "my-password").
     */
    protected const PASSWORD = '~(?<![\w-])password(?![\w-])=(?:"([^"]*)"|\'([^\']*)\'|((?:[^\s\]]|[ \t](?!\s*(?:[-\w]+=|/?\))))*))~i';

    public function __construct(
        protected SettingsRepositoryInterface $settings
    ) {
    }

    /**
     * Pre-parse callback: replace the plaintext password inside every
     * [protected ...] opening tag with a one-way hash before s9e parses the
     * text.
     *
     * s9e keeps the raw BBCode source in <s>/<e> unparse markers that end up in
     * the stored XML; by hashing the password here first, neither the database
     * content nor the unparse markers ever contain the plaintext password.
     * ProtectedFilter runs again at parse time as a safety net.
     *
     * If the author left the password empty or omitted it, the configured
     * default password is applied so the block can still be unlocked.
     */
    public function __invoke(Parser $parser, mixed $context, string $text, ?User $user = null): string
    {
        // BBCode tags are case-insensitive, so authors may write [PROTECTED]
        // or [Protected]. Match case-insensitively here too, otherwise the
        // plaintext password would bypass the hash below and end up in the
        // stored <s> unparse markers.
        if (stripos($text, 'protected') === false) {
            return $text;
        }

        $defaultPassword = ProtectedFilter::defaultPassword($this->settings);

        return preg_replace_callback(
            self::TAG_OPEN,
            function (array $m) use ($defaultPassword): string {
                $attrs = $m[1];

                // Password may be quoted (double or single) or unquoted;
                // normalize to a quoted value. Case-insensitive to match s9e,
                // which lowercases attribute names. PREG_UNMATCHED_AS_NULL
                // distinguishes the three capture groups so an empty value
                // (a bare password= or an empty quoted password="") is
                // preserved.
                if (preg_match(self::PASSWORD, $attrs, $pm, PREG_UNMATCHED_AS_NULL)) {
                    $password = $pm[1] ?? $pm[2] ?? $pm[3] ?? '';

                    if ($password === '') {
                        // Author left the password empty → apply the hashed default
                        // password so it never appears in plaintext anywhere.
                        $attrs = str_replace($pm[0], 'password="'.password_hash($defaultPassword, PASSWORD_DEFAULT).'"', $attrs);
                    } elseif (! ProtectedFilter::isHashed($password)) {
                        // Don't re-hash values that already look like bcrypt/argon2
                        // hashes (e.g. the raw text reconstructed by unparse() when a
                        // post is edited).
                        $attrs = str_replace($pm[0], 'password="'.password_hash($password, PASSWORD_DEFAULT).'"', $attrs);
                    }
                } else {
                    // No password attribute at all → apply the hashed default
                    // password. rtrim (not trim) keeps the leading space that
                    // separates the tag name from its attributes — trim() would
                    // produce "[protectedlike=...]", which s9e can't parse and
                    // would leak the content as plain text.
                    $attrs = rtrim($attrs).' password="'.password_hash($defaultPassword, PASSWORD_DEFAULT).'"';
                }

                return '[protected'.$attrs.']';
            },
            $text
        ) ?? $text;
    }
}
