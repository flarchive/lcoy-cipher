<?php

/*
 * This file is part of lcoy/cipher.
 *
 * (c) Lcoy
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Lcoy\Cipher\Listener;

use Flarum\Extension\ExtensionManager;
use Flarum\Likes\Event\PostWasLiked;
use Flarum\Likes\Event\PostWasUnliked;
use Psr\Container\ContainerInterface;

/**
 * Pushes real-time updates over the forum's WebSocket channel (flarum-pusher)
 * so visitors see a locked card's checklist refresh without reloading.
 *
 * Deliberately scoped to avoid abusing the socket:
 *  - Only the `minlikes` condition depends on other users' actions, so only
 *    like events are broadcast — nothing fires for like/reply/follow, which
 *    are decided by the actor themselves and refresh via their own actions.
 *  - Events are only triggered for posts that actually contain a protected
 *    block with a minlikes condition, and only when flarum-pusher is enabled.
 */
class PushPostUpdate
{
    public function __construct(
        protected ExtensionManager $extensions,
        protected ContainerInterface $container
    ) {
    }

    public function handle(PostWasLiked|PostWasUnliked $event): void
    {
        if (! $this->extensions->isEnabled('flarum-pusher')) {
            return;
        }

        $post = $event->post;

        // Only protected posts that can flip a minlikes gate are worth
        // broadcasting. `content` is the stored (parsed) XML.
        $content = $post->content;

        if (! is_string($content) || ! str_contains($content, '<PROTECTED') || ! str_contains($content, 'minlikes')) {
            return;
        }

        if (! $this->container->has(\Pusher\Pusher::class)) {
            return;
        }

        try {
            $pusher = $this->container->get(\Pusher\Pusher::class);

            $pusher->trigger('public', 'cipherPostUpdate', [
                'postId' => $post->id,
                'discussionId' => $post->discussion_id,
            ]);
        } catch (\Throwable) {
            // Never let a push failure break the request that triggered it.
        }
    }
}
