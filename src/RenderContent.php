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

use DOMDocument;
use DOMXPath;
use Flarum\Http\RequestUtil;
use Flarum\Locale\TranslatorInterface;
use Flarum\Post\Post;
use Flarum\User\User;
use Psr\Http\Message\ServerRequestInterface;
use s9e\TextFormatter\Renderer;

class RenderContent
{
    public function __construct(
        protected TranslatorInterface $translator,
        protected Conditions $conditions,
        protected RequirementStatus $requirements
    ) {
    }

    /**
     * Render-time hook.
     *
     * The parsed XML always keeps the inner content of [protected] blocks (they
     * are re-rendered server-side on unlock), so here we make sure the content
     * never reaches the browser for anyone who hasn't unlocked it:
     *
     * - Authors and moderators/admins see the raw content.
     * - Blocks whose `time` requirement is already reached are visible to
     *   everyone (scheduled visibility).
     * - Everyone else gets a locked placeholder; the inner content is stripped
     *   from the XML and the unmet visibility requirements are embedded as
     *   data attributes before the templates are applied.
     */
    public function __invoke(Renderer $renderer, mixed $context, string $xml, ?ServerRequestInterface $request = null): string
    {
        if (!str_contains($xml, '<PROTECTED')) {
            return $xml;
        }

        $renderer->setParameter('CIPHER_LOCKED_TEXT', $this->translator->trans('lcoy-cipher.forum.locked_text'));
        $renderer->setParameter('CIPHER_UNLOCK', $this->translator->trans('lcoy-cipher.forum.unlock'));

        $actor = $request ? RequestUtil::getActor($request) : null;

        // No request context (e-mails, CLI, queues): conservatively lock
        // everything rather than leaking gated content.
        if ($actor === null) {
            return $this->lockAll($xml, null, null);
        }

        $allowed = $actor->isAdmin();

        $post = $context instanceof Post ? $context : null;

        if ($post !== null && ! $allowed) {
            $allowed = (int) $post->user_id === (int) $actor->id
                || $actor->hasPermission('discussion.moderate');
        }

        if ($allowed) {
            return $xml;
        }

        return $this->lockAll($xml, $post, $actor);
    }

    /**
     * Replace every locked <PROTECTED> block with a locked placeholder, keeping
     * only the attributes the locked card needs and dropping the inner content.
     */
    protected function lockAll(string $xml, ?Post $post, ?User $actor): string
    {
        $dom = new DOMDocument;
        $dom->loadXML('<cipher-root>'.$xml.'</cipher-root>', LIBXML_NONET | LIBXML_COMPACT);

        $xpath = new DOMXPath($dom);
        $nodes = $xpath->query('//PROTECTED');

        // Process bottom-up so nested blocks are removed before their parents.
        for ($i = $nodes->length - 1; $i >= 0; $i--) {
            $node = $nodes->item($i);

            $attrs = $this->nodeAttributes($node);
            $id = $attrs['id'] ?? '';
            $title = $attrs['title'] ?? null;

            // Scheduled visibility: once the time is reached the block is
            // visible to everyone, no password required.
            $target = $post !== null && $actor !== null ? $this->conditions->timeTarget($attrs) : null;

            if ($target !== null && time() >= $target) {
                continue;
            }

            $statuses = $this->requirements->statuses($attrs, $post, $actor);

            // Drop every attribute (including the password hash — defense in
            // depth, the locked card never needs it).
            foreach (iterator_to_array($node->attributes) as $attribute) {
                $node->removeAttributeNode($attribute);
            }

            $node->setAttribute('id', $id);
            $node->setAttribute('cipher-locked', '1');

            if ($title !== null) {
                $node->setAttribute('title', $title);
            }

            if ($post !== null) {
                $node->setAttribute('data-post-id', (string) $post->id);
            }

            // Embed every configured visibility condition with its current
            // satisfaction status (1 = met, 0 = unmet) plus a human-readable
            // message, so the locked card and the unlock modal can show a
            // live ✓/✗ checklist.
            foreach ($statuses as $key => $status) {
                $node->setAttribute('data-cipher-req-'.$key, $status['met'] ? '1' : '0');
                $node->setAttribute('data-cipher-msg-'.$key, $status['message']);
            }

            // Expose the unlock timestamp of a time-gated block so the
            // frontend can schedule a one-shot refresh instead of polling.
            if ($target !== null && time() < $target) {
                $node->setAttribute('data-cipher-target', (string) $target);
            }

            // Strip the inner content.
            while ($node->firstChild) {
                $node->removeChild($node->firstChild);
            }
        }

        $result = '';
        foreach ($dom->documentElement->childNodes as $child) {
            $result .= $dom->saveXML($child);
        }

        return $result;
    }

    /**
     * @return array<string,string>
     */
    protected function nodeAttributes(\DOMElement $node): array
    {
        $attrs = [];

        foreach ($node->attributes as $attribute) {
            $attrs[$attribute->nodeName] = $attribute->nodeValue;
        }

        return $attrs;
    }
}
