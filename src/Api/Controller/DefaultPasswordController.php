<?php

/*
 * This file is part of lcoy/cipher.
 *
 * (c) Lcoy
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Lcoy\Cipher\Api\Controller;

use Flarum\Settings\SettingsRepositoryInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Lcoy\Cipher\ProtectedFilter;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/cipher/default-password
 *
 * Returns the default password — the one that unlocks every [protected] block
 * whose author did not set a password of its own — so authors can see what an
 * empty password field actually resolves to instead of having to remember it.
 *
 * Deliberately open to every visitor: the default is a forum-wide fallback that
 * is meant to be handed out, and the point of showing it is that nobody has to
 * ask for it. Note the consequence — a block that uses it is only as private as
 * the default itself. Authors who need real protection set their own password,
 * which is stored as a bcrypt hash and is never revealed by this endpoint or
 * any other.
 */
class DefaultPasswordController implements RequestHandlerInterface
{
    public function __construct(
        protected SettingsRepositoryInterface $settings
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return new JsonResponse([
            'success' => true,
            'password' => ProtectedFilter::defaultPassword($this->settings),
        ]);
    }
}
