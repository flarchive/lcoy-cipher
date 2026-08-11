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
            // Match the opening tag of a [protected ...] block. Quoted attribute
            // values may contain "]", so they are matched as whole quoted strings.
            '/\[protected\b((?:[^"\'\[\]]|"[^"]*"|\'[^\']*\')*)\]/i',
            function (array $m) use ($defaultPassword): string {
                $attrs = $m[1];

                // Password may be quoted or unquoted; normalize to a quoted value.
                // Case-insensitive to match s9e, which accepts attribute names in
                // any case (a PASSWORD="x" that slipped through here would leave
                // the plaintext in the <s> unparse marker).
                if (preg_match('/\bpassword\s*=\s*(?:"([^"]*)"|([^\s"\'\[\]]+))/i', $attrs, $pm)) {
                    $password = $pm[1] !== '' ? $pm[1] : ($pm[2] ?? '');

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
                    // No password attribute at all → apply the hashed default password.
                    $attrs = trim($attrs).' password="'.password_hash($defaultPassword, PASSWORD_DEFAULT).'"';
                }

                return '[protected'.$attrs.']';
            },
            $text
        ) ?? $text;
    }
}
