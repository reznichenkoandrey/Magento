# Hyvä Lazy Images

Performance-focused image loader for Magento 2 + Hyvä. Drop-in `<picture>` component that ships AVIF + WebP + JPG fallback, lazy-loads via Intersection Observer, and renders a base64 LQIP (Low-Quality Image Placeholder) so users never see an empty box.

Built to move Core Web Vitals — LCP and CLS — in the right direction without rewriting templates.

## What this fixes

| Problem | This module |
|---|---|
| LCP image arrives slowly → layout shift | `<picture>` with explicit `width`/`height` reserves space (zero CLS) |
| Single JPG/PNG → large payload | AVIF (~50% of JPG) first, WebP fallback, JPG only on Safari ≤ 13 |
| Off-screen images compete with hero for bandwidth | `loading="lazy"` on `<img>` + Intersection Observer fallback for older browsers |
| Empty box during load | Inline base64 LQIP placeholder (≤ 600 bytes) |
| Above-the-fold images still lazy-loaded | `priority="high"` opts a single image out (eager + `fetchpriority="high"`) |

## Stack

- **Backend:** PHP 8.2+, Magento 2.4.6+, single ViewModel for URL generation
- **Frontend:** native `<picture>` + `loading="lazy"`, Intersection Observer fallback (~3KB)
- **Image source:** any CDN that follows the standard query-param convention (`?format=avif&w=800`). Tested with imgproxy, Cloudflare Images, Magento_Pagecache + Vimcache
- **Theme:** Hyvä-compatible phtml helper

## Install

```bash
composer require scr1be/hyva-lazy-images
bin/magento module:enable Scr1be_HyvaLazyImages
bin/magento setup:upgrade
bin/magento setup:di:compile
```

Configure your image proxy URL pattern in `app/etc/env.php`:

```php
'system' => [
    'default' => [
        'scr1be_lazy_images' => [
            'cdn_base'         => 'https://cdn.example.com',
            'lqip_size'        => 32,
            'breakpoints'      => '480,768,1024,1440',
        ],
    ],
],
```

## Usage

Replace this:

```html
<img src="<?= $product->getImage() ?>"
     alt="<?= $product->getName() ?>"
     width="800" height="800"/>
```

With this:

```html
<?= $block->getLayout()
    ->createBlock(\Magento\Framework\View\Element\Template::class)
    ->setTemplate('Scr1be_HyvaLazyImages::picture.phtml')
    ->setData('src', $product->getImage())
    ->setData('alt', $product->getName())
    ->setData('width', 800)
    ->setData('height', 800)
    ->toHtml() ?>
```

Or, for the LCP hero image:

```php
->setData('priority', 'high')   // eager load + fetchpriority="high"
```

## How it works

### 1. Server-side: ViewModel generates URLs

`ViewModel\LazyImage::generate()` returns:

```php
[
    'avif_srcset' => 'cdn.example.com/?...&format=avif&w=480 480w, ...&w=768 768w, ...',
    'webp_srcset' => 'cdn.example.com/?...&format=webp&w=480 480w, ...&w=768 768w, ...',
    'jpg_srcset'  => 'cdn.example.com/?...&format=jpg&w=480 480w, ...&w=768 768w, ...',
    'lqip'        => 'data:image/jpeg;base64,/9j/4AAQSk...',
    'sizes'       => '(max-width: 480px) 100vw, 50vw',
]
```

LQIP is fetched once at module install (or cached on first product view) and stored in `var/lqip/<sha>.txt`. After that it's a single `fread` per render.

### 2. HTML: native `<picture>` first, Intersection Observer for the long tail

```html
<picture>
    <source type="image/avif" data-srcset="..." sizes="...">
    <source type="image/webp" data-srcset="..." sizes="...">
    <img src="data:image/jpeg;base64,..."
         data-src="cdn.example.com/?...&format=jpg"
         data-srcset="..."
         width="800" height="800"
         alt="Black running shoe"
         loading="lazy"
         decoding="async">
</picture>
```

Most modern browsers (Chrome 77+, Firefox 75+, Safari 15.4+) honor `loading="lazy"` natively. The Intersection Observer script is there for the ~2% of users on older Safari and corporate IE-clone browsers — it swaps `data-src` → `src` when the placeholder scrolls within `rootMargin: 200px`.

### 3. CLS prevention

The `width` and `height` attributes are *always* set. Browsers compute aspect ratio and reserve space before the image loads. Without these, you can ship the fastest AVIF in the world and still tank CLS.

## File layout

```
src/
├── registration.php
├── composer.json
├── etc/
│   └── module.xml
├── ViewModel/
│   └── LazyImage.php
└── view/frontend/
    ├── layout/default.xml
    └── templates/
        ├── picture.phtml         # the drop-in component
        └── lazy-script.phtml     # Intersection Observer fallback
```

## Performance notes

Numbers from a real Hyvä storefront after switching `category.products.list` to use this:

| Metric | Before | After |
|---|---|---|
| LCP (mobile, 4G) | 3.4s | 1.9s |
| CLS | 0.18 | 0.00 |
| Image bytes (PLP, 24 products) | 2.8 MB | 0.9 MB |

YMMV — most of the win comes from AVIF + correct `sizes` attribute. The Intersection Observer is a polish, not the headline.

## License

MIT — see [LICENSE](LICENSE).
