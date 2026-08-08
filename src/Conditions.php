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

use Flarum\Post\Post;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;

/**
 * Evaluates the visibility conditions attached to a [protected] block.
 *
 * Conditions (all optional, combined with the password):
 *  - like="1"     visitor must have liked the post
 *  - reply="1"    visitor must have replied in the discussion
 *  - follow="1"   visitor must follow the post author
 *  - minlikes="N" the post must have at least N likes
 *  - time="..."   content is only visible after this time (absolute date or
 *                 relative offset such as "2d", "12h", "30m")
 */
class Conditions
{
    public function __construct(
        protected ConnectionInterface $db
    ) {
    }

    protected ?bool $hasFollowersTable = null;

    /**
     * Parse a time attribute into a unix timestamp.
     */
    public function parseTime(string $value): ?int
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        // Bare unix timestamps (as stored by ProtectedFilter for relative times).
        if (preg_match('/^\d{9,10}$/', $value)) {
            return (int) $value;
        }

        // Relative offsets such as "+1h", "2d", "30m", "45s" — computed
        // explicitly because strtotime() misparses the ambiguous "h"/"d"
        // shorthands.
        if (preg_match('/^\+?(\d+)\s*(d|h|m|i|s)\b/i', $value, $m)) {
            $seconds = ['d' => 86400, 'h' => 3600, 'm' => 60, 'i' => 60, 's' => 1][strtolower($m[2])];

            return time() + (int) $m[1] * $seconds;
        }

        $timestamp = strtotime($value);

        return $timestamp === false ? null : $timestamp;
    }

    /**
     * Resolve the visibility timestamp of a block's attributes.
     */
    public function timeTarget(array $attrs): ?int
    {
        return $this->parseTime((string) ($attrs['time'] ?? ''));
    }

    /**
     * @return bool whether the actor has liked the post
     */
    public function isLikedBy(Post $post, User $actor): bool
    {
        return $this->db->table('post_likes')
            ->where('post_id', $post->id)
            ->where('user_id', $actor->id)
            ->exists();
    }

    /**
     * @return bool whether the actor has replied in the discussion
     *
     * Hidden/deleted replies don't count — only visible comments.
     */
    public function hasReplied(Post $post, User $actor): bool
    {
        return $this->db->table('posts')
            ->where('discussion_id', $post->discussion_id)
            ->where('user_id', $actor->id)
            ->where('type', 'comment')
            ->where('id', '!=', $post->id)
            ->whereNull('hidden_at')
            ->exists();
    }

    /**
     * @return bool whether the actor follows the post author
     */
    public function followsAuthor(Post $post, User $actor): bool
    {
        if (! $post->user_id || ! $this->hasFollowersTable()) {
            return false;
        }

        return $this->db->table('user_followers')
            ->where('user_id', $actor->id)
            ->where('followed_user_id', $post->user_id)
            ->exists();
    }

    /**
     * @return bool whether the actor follows the post's discussion
     *
     * Requires the flarum-subscriptions extension, which stores the state in
     * discussion_user.subscription ('follow' | 'ignore').
     */
    public function hasFollowedDiscussion(Post $post, User $actor): bool
    {
        return $this->db->table('discussion_user')
            ->where('user_id', $actor->id)
            ->where('discussion_id', $post->discussion_id)
            ->where('subscription', 'follow')
            ->exists();
    }

    /**
     * @return int total number of likes on the post
     */
    public function likeCount(Post $post): int
    {
        return (int) $this->db->table('post_likes')->where('post_id', $post->id)->count();
    }

    /**
     * The list of condition keys currently not satisfied for this actor.
     *
     * @param  array<string,string> $attrs
     * @return string[] e.g. ['time', 'like', 'reply', 'follow', 'followDiscussion', 'minlikes']
     */
    public function unmet(array $attrs, Post $post, User $actor): array
    {
        $unmet = [];

        if (($target = $this->timeTarget($attrs)) !== null && time() < $target) {
            $unmet[] = 'time';
        }

        if (($attrs['like'] ?? '') && ! $this->isLikedBy($post, $actor)) {
            $unmet[] = 'like';
        }

        if (($attrs['reply'] ?? '') && ! $this->hasReplied($post, $actor)) {
            $unmet[] = 'reply';
        }

        if (($attrs['follow'] ?? '') && ! $this->followsAuthor($post, $actor)) {
            $unmet[] = 'follow';
        }

        // s9e lowercases attribute names, so read the discussion-follow flag
        // with its lowercase key.
        if (($attrs['followdiscussion'] ?? '') && ! $this->hasFollowedDiscussion($post, $actor)) {
            $unmet[] = 'followDiscussion';
        }

        $minlikes = (int) ($attrs['minlikes'] ?? 0);

        if ($minlikes > 0 && $this->likeCount($post) < $minlikes) {
            $unmet[] = 'minlikes';
        }

        return $unmet;
    }

    protected function hasFollowersTable(): bool
    {
        return $this->hasFollowersTable ??= $this->db->getSchemaBuilder()->hasTable('user_followers');
    }
}
