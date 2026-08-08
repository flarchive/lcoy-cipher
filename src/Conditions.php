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
    protected ?bool $hasLikesTable = null;

    /**
     * Request-scoped query results.
     *
     * A single render can evaluate the same condition for several protected
     * blocks of the same post (and the same actor), so cache each result per
     * post+actor key to avoid re-issuing the same SQL over and over. The
     * container gives every consumer its own instance per request, so these
     * never leak across requests.
     *
     * @var array<string,bool|int>
     */
    protected array $memo = [];

    /**
     * Request-scoped time parse results, keyed by the raw attribute value.
     *
     * The same block's time gate is evaluated multiple times per request
     * (statuses(), met/unmet messages, unlock checks, render) — all of them
     * must agree on a single instant, and re-running the regex/strtotime for
     * each is pure waste.
     *
     * @var array<string,int|null>
     */
    protected array $timeMemo = [];

    protected function remember(string $key, callable $callback): bool|int
    {
        if (! array_key_exists($key, $this->memo)) {
            $this->memo[$key] = $callback();
        }

        return $this->memo[$key];
    }

    /**
     * Parse a time attribute into a unix timestamp.
     */
    public function parseTime(string $value): ?int
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (array_key_exists($value, $this->timeMemo)) {
            return $this->timeMemo[$value];
        }

        // Bare unix timestamps (as stored by ProtectedFilter for relative times).
        if (preg_match('/^\d{9,10}$/', $value)) {
            return $this->timeMemo[$value] = (int) $value;
        }

        // Relative offsets such as "+1h", "2d", "30m", "45s" — computed
        // explicitly because strtotime() misparses the ambiguous "h"/"d"
        // shorthands.
        if (($seconds = self::relativeSeconds($value)) !== null) {
            return $this->timeMemo[$value] = time() + $seconds;
        }

        $timestamp = strtotime($value);

        return $this->timeMemo[$value] = $timestamp === false ? null : $timestamp;
    }

    /**
     * Seconds represented by a relative offset such as "+1h", "2d", "30m",
     * "45s", or null if the value isn't one.
     *
     * Shared with ProtectedFilter: parse time normalizes relative offsets to
     * absolute timestamps anchored to the moment the post was written, and the
     * render-time fallback (legacy blocks) must interpret them identically.
     */
    public static function relativeSeconds(string $value): ?int
    {
        if (preg_match('/^\+?(\d+)\s*(d|h|m|i|s)\b/i', $value, $m)) {
            $seconds = ['d' => 86400, 'h' => 3600, 'm' => 60, 'i' => 60, 's' => 1][strtolower($m[2])];

            return (int) $m[1] * $seconds;
        }

        return null;
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
        if (! $this->hasLikesTable()) {
            return false;
        }

        return $this->remember('like.'.$post->id.'.'.$actor->id, fn () => $this->db->table('post_likes')
            ->where('post_id', $post->id)
            ->where('user_id', $actor->id)
            ->exists());
    }

    /**
     * @return bool whether the actor has replied in the discussion
     *
     * Hidden/deleted replies don't count — only visible comments.
     */
    public function hasReplied(Post $post, User $actor): bool
    {
        return $this->remember('reply.'.$post->id.'.'.$actor->id, fn () => $this->db->table('posts')
            ->where('discussion_id', $post->discussion_id)
            ->where('user_id', $actor->id)
            ->where('type', 'comment')
            ->where('id', '!=', $post->id)
            ->whereNull('hidden_at')
            ->exists());
    }

    /**
     * @return bool whether the actor follows the post author
     */
    public function followsAuthor(Post $post, User $actor): bool
    {
        if (! $post->user_id || ! $this->hasFollowersTable()) {
            return false;
        }

        return $this->remember('follow.'.$post->id.'.'.$actor->id, fn () => $this->db->table('user_followers')
            ->where('user_id', $actor->id)
            ->where('followed_user_id', $post->user_id)
            ->exists());
    }

    /**
     * @return bool whether the actor follows the post's discussion
     *
     * Requires the flarum-subscriptions extension, which stores the state in
     * discussion_user.subscription ('follow' | 'ignore').
     */
    public function hasFollowedDiscussion(Post $post, User $actor): bool
    {
        return $this->remember('discussion.'.$post->id.'.'.$actor->id, fn () => $this->db->table('discussion_user')
            ->where('user_id', $actor->id)
            ->where('discussion_id', $post->discussion_id)
            ->where('subscription', 'follow')
            ->exists());
    }

    /**
     * @return int total number of likes on the post
     */
    public function likeCount(Post $post): int
    {
        if (! $this->hasLikesTable()) {
            return 0;
        }

        return $this->remember('likes.'.$post->id, fn () => (int) $this->db->table('post_likes')->where('post_id', $post->id)->count());
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

    protected function hasLikesTable(): bool
    {
        return $this->hasLikesTable ??= $this->db->getSchemaBuilder()->hasTable('post_likes');
    }
}
