<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Domain;

/**
 * The SEO fields of a product — the manager's, and only the manager's.
 *
 * Master Spec §6 puts SEO on the review step with «SEO فقط مدیر», and the
 * reason is not politeness: the slug is the public URL and the meta title is
 * what the marketplace shows in search results, so both are the bazaar's
 * voice rather than the shop's. The vendor's form never renders these fields
 * and the vendor's save path never carries them.
 */
final class ProductSeo
{
    public function __construct(
        public readonly string $slug = '',
        public readonly string $title = '',
        public readonly string $description = ''
    ) {
    }

    public function isEmpty(): bool
    {
        return trim($this->slug) === '' && trim($this->title) === '' && trim($this->description) === '';
    }

    /**
     * A slug safe to put in a URL: lowercase, dashes, and Persian left alone.
     *
     * Persian characters survive on purpose — WordPress has handled UTF-8
     * slugs for years and «دستکش-لاتکس» is a better address for this shop's
     * readers than a transliteration nobody types.
     */
    public static function normaliseSlug(string $raw): string
    {
        $slug = trim(mb_strtolower($raw));
        $slug = (string) preg_replace('/[\s_]+/u', '-', $slug);
        $slug = (string) preg_replace('/[^\p{L}\p{N}\-]+/u', '', $slug);
        $slug = (string) preg_replace('/-+/', '-', $slug);
        return trim(mb_substr($slug, 0, 180), '-');
    }
}
