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

use DOMDocument;
use DOMXPath;
use Flarum\Formatter\Formatter;
use Flarum\Http\RequestUtil;
use Flarum\Locale\TranslatorInterface;
use Flarum\Post\CommentPost;
use Laminas\Diactoros\Response\JsonResponse;
use Lcoy\Cipher\Conditions;
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
    public function __construct(
        protected Formatter $formatter,
        protected TranslatorInterface $translator,
        protected Conditions $conditions,
        protected RequirementStatus $requirements
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

        $dom = new DOMDocument;
        $dom->loadXML('<cipher-root>'.$xml.'</cipher-root>', LIBXML_NONET | LIBXML_COMPACT);

        $xpath = new DOMXPath($dom);
        $nodes = $xpath->query('//PROTECTED');

        $blocks = [];

        foreach ($nodes as $node) {
            $attrs = [];

            foreach ($node->attributes as $attribute) {
                $attrs[$attribute->nodeName] = $attribute->nodeValue;
            }

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
            $target = $this->conditions->timeTarget($attrs);

            if ($target !== null && time() >= $target) {
                $inner = '';
                foreach ($node->childNodes as $child) {
                    $inner .= $dom->saveXML($child);
                }

                $block['html'] = $this->formatter->render('<r>'.$inner.'</r>', $post, $request);
            }

            $blocks[] = $block;
        }

        return $this->success($blocks);
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
