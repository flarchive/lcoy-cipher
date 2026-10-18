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
use DOMElement;
use DOMXPath;

/**
 * Shared DOM helpers for the stored parsed XML of posts containing
 * [protected] blocks.
 *
 * Every consumer (RenderContent, the unlock and status controllers) operates
 * on the same shape: the post's parsed XML, wrapped in a <cipher-root>
 * document because s9e's stored XML is a fragment (multiple top-level
 * nodes), with every <PROTECTED> block addressed by its unique id.
 */
final class ProtectedXml
{
    /**
     * Load a post's stored parsed XML into a document wrapped in
     * <cipher-root>.
     *
     * The wrapped document always has a root element, even when the stored XML
     * is malformed (corrupted content, a fragment written by a different
     * formatter, an undeclared namespace prefix). In that case the fragment is
     * unparseable, so no protected region can be identified: callers see zero
     * protected nodes and save() yields an empty string, i.e. the content is
     * dropped rather than passed through. Failing closed is deliberate — the
     * alternative (returning the original XML) would leak gated content.
     *
     * libxml reports malformed input as warnings plus a false return; they are
     * collected here instead of reaching the error log, since an unparseable
     * post is an expected (if rare) condition that every caller already
     * handles.
     */
    public static function load(string $xml): DOMDocument
    {
        $dom = new DOMDocument;

        $internalErrors = libxml_use_internal_errors(true);

        try {
            $loaded = $dom->loadXML('<cipher-root>'.$xml.'</cipher-root>', LIBXML_NONET | LIBXML_COMPACT);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($internalErrors);
        }

        if ($loaded) {
            return $dom;
        }

        $empty = new DOMDocument;
        $empty->loadXML('<cipher-root/>');

        return $empty;
    }

    /**
     * Every <PROTECTED> node of a document, in document order.
     *
     * @return DOMElement[]
     */
    public static function protectedNodes(DOMDocument $dom): array
    {
        $nodes = [];

        foreach ((new DOMXPath($dom))->query('//PROTECTED') ?? [] as $node) {
            $nodes[] = $node;
        }

        return $nodes;
    }

    /**
     * The first <PROTECTED> node carrying the given id, or null.
     *
     * The id is interpolated into the XPath query, so callers must validate
     * it (UnlockController only accepts ids matching ProtectedFilter's
     * `cipher_[a-f0-9]{16}` format).
     */
    public static function findNode(DOMDocument $dom, string $cipherId): ?DOMElement
    {
        $nodes = (new DOMXPath($dom))->query('//PROTECTED[@id = "'.$cipherId.'"]');

        return ($nodes && $nodes->length) ? $nodes->item(0) : null;
    }

    /**
     * A node's attributes as a name => value map.
     *
     * s9e lowercases attribute names at parse time, so the map is keyed by
     * the lowercase names stored in the XML.
     *
     * @return array<string,string>
     */
    public static function attributes(DOMElement $node): array
    {
        $attrs = [];

        foreach ($node->attributes as $attribute) {
            $attrs[$attribute->nodeName] = $attribute->nodeValue;
        }

        return $attrs;
    }

    /**
     * The XML serialization of a node's children (its inner content).
     */
    public static function innerXml(DOMDocument $dom, DOMElement $node): string
    {
        $inner = '';

        foreach ($node->childNodes as $child) {
            $inner .= $dom->saveXML($child);
        }

        return $inner;
    }

    /**
     * Serialize the document's wrapped fragment back to XML.
     *
     * Expects a document from load(), which guarantees a root element.
     */
    public static function save(DOMDocument $dom): string
    {
        $result = '';

        foreach ($dom->documentElement->childNodes as $child) {
            $result .= $dom->saveXML($child);
        }

        return $result;
    }
}
