<?php

namespace App\Modules\Improvement\Support;

use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Improvement\Models\ImprovementPost;
use DOMDocument;
use DOMElement;
use DOMText;
use DOMXPath;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * A post as a page, built in code from what the model wrote: the body's Markdown as HTML, the
 * first mention of each of the store's products linked to it, a block of the products with their
 * pictures, the questions and answers, and the structured data search engines read (FAQPage,
 * BlogPosting). The same document goes to the store's own blog and to the guide we serve.
 */
final class PostDocument
{
    /**
     * @param  Collection<int, CatalogProduct>  $products  the post's products, in its order
     */
    public function __construct(
        private readonly ImprovementPost $post,
        private readonly Collection $products,
        private readonly string $shopName,
    ) {}

    public function rtl(): bool
    {
        return preg_match('/\p{Hebrew}/u', $this->post->title.$this->post->body) === 1;
    }

    /** The body with its products linked: the guide we serve adds its own products and questions around it. */
    public function body(): string
    {
        return $this->linked(Str::markdown($this->post->body, ['html_input' => 'strip', 'allow_unsafe_links' => false]));
    }

    /** The whole post for a store's blog: the body, its products with pictures, and its questions. */
    public function html(): string
    {
        return $this->body().$this->productsBlock().$this->faqBlock();
    }

    /** @return list<array{q: string, a: string}> */
    public function faq(): array
    {
        return array_values(array_filter((array) ($this->post->seo['faq'] ?? []), fn ($i): bool => is_array($i) && ($i['q'] ?? '') !== '' && ($i['a'] ?? '') !== ''));
    }

    public function seoTitle(): string
    {
        return trim((string) ($this->post->seo['seo_title'] ?? '')) ?: $this->post->title;
    }

    public function metaDescription(): string
    {
        $written = trim((string) ($this->post->seo['meta_description'] ?? ''));

        return $written !== '' ? $written : mb_substr(trim((string) preg_replace('/\s+/u', ' ', strip_tags($this->body()))), 0, 155);
    }

    public function excerpt(): string
    {
        return trim((string) ($this->post->seo['excerpt'] ?? '')) ?: $this->metaDescription();
    }

    /** The picture the post is shown with: its first product's. */
    public function image(): ?array
    {
        $first = $this->products->first(fn (CatalogProduct $p): bool => is_string($p->image_url) && $p->image_url !== '');

        return $first === null ? null : ['url' => (string) $first->image_url, 'alt' => (string) $first->title];
    }

    /**
     * Search engines' reading of the questions: FAQPage, when there are any.
     *
     * @return array<string, mixed>|null
     */
    public function faqSchema(): ?array
    {
        $faq = $this->faq();

        return $faq === [] ? null : [
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => array_map(fn (array $i): array => ['@type' => 'Question', 'name' => $i['q'], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $i['a']]], $faq),
        ];
    }

    /** @return array<string, mixed> */
    public function articleSchema(?string $url): array
    {
        $image = $this->image();

        return array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'BlogPosting',
            'headline' => mb_substr($this->post->title, 0, 110),
            'description' => $this->metaDescription(),
            'image' => $image['url'] ?? null,
            'datePublished' => $this->post->published_at?->toAtomString(),
            'dateModified' => $this->post->updated_at?->toAtomString(),
            'author' => ['@type' => 'Organization', 'name' => $this->shopName],
            'publisher' => ['@type' => 'Organization', 'name' => $this->shopName],
            'mainEntityOfPage' => $url,
            'keywords' => implode(', ', array_filter([(string) ($this->post->seo['focus_keyword'] ?? ''), ...(array) ($this->post->seo['tags'] ?? [])])),
        ], fn ($v): bool => $v !== null && $v !== '');
    }

    /**
     * What a store's blog is sent: the platform's publisher maps it onto its own fields.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            'title' => $this->post->title,
            'html' => $this->html(),
            'excerpt' => $this->excerpt(),
            'seo_title' => $this->seoTitle(),
            'meta_description' => $this->metaDescription(),
            'focus_keyword' => (string) ($this->post->seo['focus_keyword'] ?? ''),
            'tags' => array_values((array) ($this->post->seo['tags'] ?? [])),
            'faq' => $this->faq(),
            'image' => $this->image(),
            'author' => $this->shopName,
            'post_id' => $this->post->id,
        ];
    }

    /** The first mention of each product in the text, a link to it; never inside a heading or a link. */
    private function linked(string $html): string
    {
        $named = $this->products->filter(fn (CatalogProduct $p): bool => is_string($p->url) && $p->url !== '' && mb_strlen(trim((string) $p->title)) >= 3);

        if ($named->isEmpty() || trim($html) === '') {
            return $html;
        }

        $doc = new DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"><div id="la-root">'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        $xpath = new DOMXPath($doc);

        foreach ($named as $product) {
            $title = trim((string) $product->title);
            foreach ($xpath->query('//*[self::p or self::li]//text()[not(ancestor::a) and not(ancestor::h1) and not(ancestor::h2) and not(ancestor::h3)]') ?: [] as $text) {
                /** @var DOMText $text */
                $at = mb_strpos($text->data, $title);
                if ($at === false) {
                    continue;
                }
                $after = $text->splitText($at);
                $after->splitText(mb_strlen($title));
                $link = $doc->createElement('a');
                $link->setAttribute('href', (string) $product->url);
                $link->appendChild($doc->createTextNode($title));
                $after->parentNode->replaceChild($link, $after);
                break;
            }
        }

        $root = $doc->getElementById('la-root') ?? $xpath->query('//div[@id="la-root"]')->item(0);
        $out = '';
        if ($root instanceof DOMElement) {
            foreach ($root->childNodes as $child) {
                $out .= $doc->saveHTML($child);
            }
        }

        return $out !== '' ? $out : $html;
    }

    private function productsBlock(): string
    {
        $shown = $this->products->filter(fn (CatalogProduct $p): bool => is_string($p->url) && $p->url !== '');
        if ($shown->isEmpty()) {
            return '';
        }

        $e = fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $cards = $shown->map(function (CatalogProduct $p) use ($e): string {
            $picture = is_string($p->image_url) && $p->image_url !== ''
                ? '<img src="'.$e($p->image_url).'" alt="'.$e((string) $p->title).'" loading="lazy" width="300" height="300" style="width:100%;height:auto;aspect-ratio:1/1;object-fit:cover;border-radius:8px">'
                : '';

            return '<li style="list-style:none;margin:0"><a href="'.$e((string) $p->url).'" style="text-decoration:none;color:inherit;display:block">'.$picture
                .'<span style="display:block;margin-top:8px;font-weight:600;line-height:1.4">'.$e((string) $p->title).'</span></a></li>';
        })->implode('');

        return '<h2>'.$e($this->rtl() ? 'המוצרים במדריך' : 'Products in this guide').'</h2>'
            .'<ul class="la-products" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:16px;padding:0;margin:0 0 24px">'.$cards.'</ul>';
    }

    private function faqBlock(): string
    {
        $faq = $this->faq();
        if ($faq === []) {
            return '';
        }

        $e = fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return '<h2>'.$e($this->rtl() ? 'שאלות נפוצות' : 'Frequently asked questions').'</h2>'
            .'<div class="la-faq">'.implode('', array_map(fn (array $i): string => '<h3>'.$e($i['q']).'</h3><p>'.$e($i['a']).'</p>', $faq)).'</div>';
    }
}
