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

use Flarum\Extend;

return [
    (new Extend\Frontend('forum'))
        ->js(__DIR__.'/js/dist/forum.js')
        ->css(__DIR__.'/resources/less/forum/extension.less'),

    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js'),

    new Extend\Locales(__DIR__.'/resources/locale'),

    // Register the [protected] BBCode and redact protected content at render time
    // for everyone except the author and moderators/admins.
    (new Extend\Formatter)
        ->configure(Configure::class)
        ->parse(ParseProtected::class)
        ->render(RenderContent::class),

    // POST /api/resource/unlock — verify the submitted password and return the
    // rendered content of a single protected block.
    (new Extend\Routes('api'))
        ->post('/resource/unlock', 'cipher.unlock', Api\Controller\UnlockController::class)
        // GET /api/cipher/status?postId=N — current condition status of every
        // protected block in a post, used for real-time checklist refreshes.
        ->get('/cipher/status', 'cipher.status', Api\Controller\StatusController::class),

    // Real-time updates: broadcast a lightweight event when a minlikes-gated
    // post is liked, so visitors see the checklist flip without reloading.
    (new Extend\Event())
        ->listen(Flarum\Likes\Event\PostWasLiked::class, Listener\PushPostUpdate::class)
        ->listen(Flarum\Likes\Event\PostWasUnliked::class, Listener\PushPostUpdate::class),

    (new Extend\Settings())
        ->default('lcoy-cipher.allow_guest_unlock', true)
        // Default password used when an author leaves the password empty. Kept
        // server-side only: it must never reach the forum frontend.
        ->default('lcoy-cipher.default_password', 'cipher'),
];
