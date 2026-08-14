<?php
declare(strict_types=1);

namespace Scr1be\ContentTransfer\Api\Data;

/**
 * One captured piece of content, on its way to or from a bundle file.
 *
 * The payload is a plain array on purpose. A porter owns the shape of its own payload and nothing
 * else in the engine reads it — the engine sorts entries, writes them and hands them back. Typing
 * the payload would mean the engine knowing about CMS pages, which is the one thing this design is
 * built to avoid.
 */
interface EntryInterface
{
    /**
     * Code of the porter that produced this entry and must consume it again.
     */
    public function getPorterCode(): string;

    /**
     * Install-independent name of the entity — the key import matches on.
     */
    public function getIdentifier(): string;

    /**
     * @return array<string, mixed>
     */
    public function getPayload(): array;

    /**
     * Human-readable notes about references the porter rewrote while capturing.
     *
     * @return string[]
     */
    public function getTransforms(): array;

    /**
     * Human-readable notes about references the porter could **not** rewrite.
     *
     * A warning never blocks the capture: a bundle with one dangling reference is still worth
     * having, and the operator is the only one who can decide what the reference should have been.
     *
     * @return string[]
     */
    public function getWarnings(): array;
}
