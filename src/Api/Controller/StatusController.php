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

use Flarum\Formatter\Formatter;
use Flarum\Http\RequestUtil;
use Flarum\Locale\TranslatorInterface;
use Flarum\Post\CommentPost;
use Flarum\User\User;
use Illuminate\Contracts\Cache\Repository as Cache;
use Laminas\Diactoros\Response\JsonResponse;
use Lcoy\Cipher\Conditions;
use Lcoy\Cipher\ProtectedXml;
use Lcoy\Cipher\RequirementStatus;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/cipher/status?postId=N
 *
 * Returns the current satisfaction status of every visibility condition of
 * every protected block in a post, for the requesting actor. Used by the
 * forum frontend to refresh locked-card checklists in real time (e.g. when a
 * Pusher event reports that a post was liked or replied to).
 *
 * Blocks whose `time` gate has already been reached are included with their
 * rendered HTML — at that point the content is public, so the client can
 * replace the locked card without any password.
 */
class StatusController implements RequestHandlerInterface
{
    /**
     * Guest responses are cached for this long so anonymous visitors
     * hammering the endpoint share the same work instead of re-parsing the
     * XML and re-running the condition queries for every request. Logged-in
     * visitors are never cached (their own actions flip conditions), and
     * posts gated by `minlikes` or `time` bypass the cache entirely: their
     * status changes with other users' likes or the clock, so the real-time
     * refresh must always see fresh data.
     */
    private const CACHE_TTL_SECONDS = 10;

    public function __construct(
        protected Formatter $formatter,
        protected TranslatorInterface $translator,
        protected Conditions $conditions,
        protected RequirementStatus $requirements,
        protected Cache $cache
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $postId = (int) ($request->getQueryParams()['postId'] ?? 0);

        if ($postId <= 0) {
            return $this->error(400, $this->translator->trans('lcoy-cipher.forum.invalid_request'));
        }

        $post = CommentPost::find($postId);

        if (! $post || ! $post->isVisibleTo($actor)) {
            return $this->error(404, $this->translator->trans('lcoy-cipher.forum.post_not_found'));
        }

        $xml = $post->getParsedContentAttribute();

        if (! $xml || ! str_contains($xml, '<PROTECTED')) {
            return $this->success([]);
        }

        // minlikes and time conditions are time-sensitive: minlikes changes the
        // moment someone likes the post, and a cached response without the
        // rendered html could keep the client's scheduled unlock from firing.
        // Bypass the cache for those posts so real-time behavior always sees
        // fresh data; the cache still absorbs repeated polls of the actor-scoped
        // conditions (like/reply/follow/followDiscussion).
        $uncached = str_contains($xml, 'minlikes') || str_contains($xml, 'time=');

        $blocks = $this->blocksFor($post, $xml, $actor, $request, $uncached);

        return $this->success($blocks);
    }

    /**
     * Build the status payload for every protected block, caching the result
     * for guests only.
     *
     * A guest's conditions never change (they cannot like, reply or follow),
     * so the cache can safely absorb repeated polls for them. Logged-in
     * visitors, however, can flip like/reply/follow conditions with their own
     * actions at any moment — serving them a cached snapshot written before
     * the action would show a stale ✗ checklist that disagrees with the
     * server's live unlock verdict. Posts gated by minlikes or time bypass
     * the cache for everyone (see handle()).
     *
     * @return array<int,array<string,mixed>>
     */
    protected function blocksFor(CommentPost $post, string $xml, ?User $actor, ServerRequestInterface $request, bool $uncached): array
    {
        if (! $uncached && $actor?->isGuest()) {
            return $this->cache->remember('lcoy-cipher.status.'.$post->id.'.guest', self::CACHE_TTL_SECONDS, fn () => $this->computeBlocks($post, $xml, $actor, $request));
        }

        return $this->computeBlocks($post, $xml, $actor, $request);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    protected function computeBlocks(CommentPost $post, string $xml, ?User $actor, ServerRequestInterface $request): array
    {
        $dom = ProtectedXml::load($xml);

        $blocks = [];

        foreach (ProtectedXml::protectedNodes($dom) as $node) {
            $attrs = ProtectedXml::attributes($node);

            $id = $attrs['id'] ?? '';

            if ($id === '' || ! isset($attrs['password'])) {
                continue;
            }

            $statuses = $this->requirements->statuses($attrs, $post, $actor);

            // Reuse the same structure the locked card renders so the frontend
            // can patch the checklist in place.
            $reqs = [];

            foreach ($statuses as $key => $status) {
                $reqs[$key] = [
                    'met' => $status['met'],
                    'message' => $status['message'],
                ];
            }

            $block = ['id' => $id, 'reqs' => $reqs];

            // Scheduled visibility: once the time is reached the content is
            // public, so hand over the rendered HTML to auto-unlock the card.
            // Nested [protected] blocks inside the fragment are re-gated by the
            // RenderContent render hook that runs on this Formatter render.
            $target = $this->conditions->timeTarget($attrs);

            if ($target !== null && time() >= $target) {
                $block['html'] = $this->formatter->render('<r>'.ProtectedXml::innerXml($dom, $node).'</r>', $post, $request);
            }

            $blocks[] = $block;
        }

        return $blocks;
    }

    protected function success(array $blocks): JsonResponse
    {
        return new JsonResponse(['success' => true, 'blocks' => $blocks]);
    }

    protected function error(int $status, string $message): JsonResponse
    {
        return new JsonResponse(['success' => false, 'error' => $message], $status);
    }
}
