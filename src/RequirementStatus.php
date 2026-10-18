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

use Flarum\Locale\TranslatorInterface;
use Flarum\Post\Post;
use Flarum\User\User;

/**
 * Computes the satisfaction status and human-readable message of every
 * visibility condition configured on a [protected] block.
 *
 * Shared by RenderContent (server-rendered locked cards) and
 * StatusController (real-time status refreshes).
 */
class RequirementStatus
{
    public function __construct(
        protected TranslatorInterface $translator,
        protected Conditions $conditions
    ) {
    }

    /**
     * Status and message for every configured condition of a block, keyed by
     * requirement.
     *
     * @param  array<string,string> $attrs
     * @return array<string, array{met: bool, message: string}>
     */
    public function statuses(array $attrs, ?Post $post, ?User $actor): array
    {
        if ($post === null || $actor === null) {
            return [];
        }

        $unmet = $this->conditions->unmet($attrs, $post, $actor);

        // Every condition the author actually configured (an empty `like=""`
        // or `minlikes="0"` doesn't gate anything, so skip it).
        $configured = [];

        $timeTarget = $this->conditions->timeTarget($attrs);

        if ($timeTarget !== null) {
            $configured[] = 'time';
        }

        foreach (['like', 'reply', 'follow'] as $key) {
            if (! empty($attrs[$key])) {
                $configured[] = $key;
            }
        }

        // s9e lowercases attribute names; keep the display key camel-cased.
        if (! empty($attrs['followdiscussion'])) {
            $configured[] = 'followDiscussion';
        }

        if ((int) ($attrs['minlikes'] ?? 0) > 0) {
            $configured[] = 'minlikes';
        }

        $statuses = [];

        foreach ($configured as $key) {
            $met = ! in_array($key, $unmet, true);

            $statuses[$key] = [
                'met' => $met,
                'message' => $this->message($key, $attrs, $met, $timeTarget),
            ];
        }

        return $statuses;
    }

    /**
     * Human-readable message for a condition, depending on whether it is
     * already satisfied (met_*) or still required (need_*).
     */
    protected function message(string $key, array $attrs, bool $met, ?int $timeTarget): string
    {
        $prefix = $met ? 'met_' : 'need_';

        return match ($key) {
            'time' => $this->translator->trans('lcoy-cipher.forum.'.$prefix.'time', [
                'time' => date('Y-m-d H:i', $timeTarget),
            ]),
            'like' => $this->translator->trans('lcoy-cipher.forum.'.$prefix.'like'),
            'reply' => $this->translator->trans('lcoy-cipher.forum.'.$prefix.'reply'),
            'follow' => $this->translator->trans('lcoy-cipher.forum.'.$prefix.'follow'),
            'followDiscussion' => $this->translator->trans('lcoy-cipher.forum.'.$prefix.'follow_discussion'),
            'minlikes' => $this->translator->trans('lcoy-cipher.forum.'.$prefix.'minlikes', [
                'count' => (int) ($attrs['minlikes'] ?? 0),
            ]),
            default => '',
        };
    }
}
