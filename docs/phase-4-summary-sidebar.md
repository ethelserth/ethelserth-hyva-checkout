# Phase 4 — Summary Sidebar

> **Who this is for:** A developer who has already worked through
> Phases 0–3. Phase 4 plugs three new Magewire components — items,
> coupon, totals — into the existing event contract and renders them as
> a single visual unit that switches presentation between desktop sidebar
> and mobile collapsible bar.

---

## Table of Contents

1. [What Phase 4 Builds](#1-what-phase-4-builds)
2. [4.1 — Three Magewire Summary Components](#2-41--three-magewire-summary-components)
3. [4.2 — Templates](#3-42--templates)
4. [4.3 — Mobile Bar Without a Second Mount](#4-43--mobile-bar-without-a-second-mount)
5. [4.4 — Live Refresh Wiring](#5-44--live-refresh-wiring)
6. [Why Three Components, Not One](#6-why-three-components-not-one)
7. [Key Patterns to Remember](#7-key-patterns-to-remember)

---

## 1. What Phase 4 Builds

After Phase 3, the form column is functional but the right side of the
page was empty. Phase 4 adds the order summary: a sticky panel on
desktop, a collapsible top-of-viewport bar on mobile, both showing
items / coupon / live totals from the same DOM tree.

End result:

```
Desktop                          Mobile
┌────────────┬────────────┐     ┌──────────────────────────────┐
│ Steps      │ Summary    │     │ ▾ Show order summary  €118.45│  sticky
│ (col-7)    │ (col-5)    │     ├──────────────────────────────┤
│            │  ┌──────┐  │     │ Steps                        │
│            │  │Items │  │     │ ...                          │
│            │  │Coupon│  │     │                              │
│            │  │Totals│  │     │                              │
│            │  └──────┘  │     │                              │
└────────────┴────────────┘     └──────────────────────────────┘
```

The summary refreshes automatically when:

- the address is saved (VAT group flip → tax row may change),
- a shipping method is picked (shipping row appears),
- a coupon is applied or removed (discount row appears/disappears).

---

## 2. 4.1 — Three Magewire Summary Components

### `Magewire/Summary/Items.php`

Read-only. Items can't change inside the checkout (no add-to-cart, no
quantity editor), so the component has zero listeners and zero actions.
It just exposes a `getItems()` array shaped for the template:

```php
[
    'name'                => string,
    'sku'                 => string,
    'qty'                 => int,
    'options'             => [['label' => string, 'value' => string], …],
    'image_url'           => string,           // empty on missing image
    'row_total_formatted' => string,           // 'Free' for €0
]
```

Options are normalised through `Magento\Catalog\Helper\Product\Configuration::getOptions()`
— that helper handles configurable swatches, bundle selections, and
custom options uniformly so the template doesn't branch on product
type. Image goes through the standard `\Magento\Catalog\Helper\Image`
with `product_thumbnail_image` as the type.

### `Magewire/Summary/Coupon.php`

Owns the coupon input and apply/remove actions. Public state:

```php
public string $code           = '';   // input field, wire:model.defer
public string $appliedCode    = '';   // currently applied code (or '')
public string $errorMessage   = '';
public string $successMessage = '';
```

Listeners:

```php
protected $listeners = [
    'addressSaved' => 'syncFromQuote',
];
```

Why listen to `addressSaved`: cart price rules can be region-locked.
When the address country changes, Magento may invalidate the rule — and
when it does, it **blanks `coupon_code` on the quote** silently. Re-reading
from the quote on `addressSaved` keeps the UI honest: a code that no
longer applies disappears from the applied pill instead of lingering.

`applyCoupon()` is the same blanking pattern in reverse:

```php
$quote->setCouponCode($code);
$this->quoteService->collectAndSave($quote);

$applied = (string) ($this->checkoutSession->getQuote()->getCouponCode() ?? '');
if ($applied === '' || strcasecmp($applied, $code) !== 0) {
    // Magento blanks the coupon on the quote when the rule didn't match.
    $this->errorMessage = __('The discount code is not valid for this cart.');
    $this->code         = '';
    return;
}

$this->emit('couponApplied');
```

We don't need a separate Magento `CouponManagementInterface::set()` call
or a hand-rolled rule lookup — just `setCouponCode` + `collectAndSave`
and inspect the result.

### `Magewire/Summary/Totals.php`

Listeners:

```php
protected $listeners = [
    'addressSaved'           => 'refresh',
    'shippingMethodSelected' => 'refresh',
    'couponApplied'          => 'refresh',
    'couponRemoved'          => 'refresh',
];
```

`refresh()` is intentionally near-empty:

```php
public function refresh(): void
{
    $this->dispatchBrowserEvent('summary-totals-updated', [
        'grand_total_formatted' => $this->getGrandTotalFormatted(),
    ]);
}
```

The actual data refresh is implicit. Magewire re-renders the component
on every listener call; the template re-invokes `getRows()` and
`getGrandTotalFormatted()`, both of which read directly from the
checkout session quote. There's no in-memory cache to invalidate.

The `dispatchBrowserEvent` exists for the mobile collapsed bar — see §4.

`getRows()` is a thin formatter over the existing `TotalsService::getTotalsRows()`:

```php
return array_map(function (array $row): array {
    $free = (bool) ($row['free'] ?? false);
    return [
        'label'           => $row['label'],
        'value_formatted' => $free ? __('Free') : $this->priceCurrency->format($row['value'], false),
        'free'            => $free,
    ];
}, $rows);
```

`PriceCurrencyInterface::format($value, false)` — second arg `false` strips
the `<span class="price">` container Magento adds by default. We want
plain text, escaped at the template boundary.

---

## 3. 4.2 — Templates

Layout XML (children of `checkout.root`):

```xml
<block name="checkout.summary" template="…/summary/root.phtml">
    <block name="checkout.summary.items"
           template="…/summary/items.phtml">
        <arguments>
            <argument name="magewire" xsi:type="object">…\Items</argument>
        </arguments>
    </block>
    <block name="checkout.summary.coupon"
           template="…/summary/coupon.phtml">
        <arguments>
            <argument name="magewire" xsi:type="object">…\Coupon</argument>
        </arguments>
    </block>
    <block name="checkout.summary.totals"
           template="…/summary/totals.phtml">
        <arguments>
            <argument name="magewire" xsi:type="object">…\Totals</argument>
        </arguments>
    </block>
</block>
```

`checkout.summary` itself is a plain (non-Magewire) template block.
Its job is composition + the mobile-bar shell. Its three children are
each their own Magewire mount.

Each child template follows the same one-root-tag rule from Phase 3.
Coupon's two visual states (input row vs applied pill) are rendered
inside one root `<div class="checkout-coupon">` and gated by `<?php if ($applied !== ''): ?>` —
not by emitting two separate roots.

`Totals` template ends with the grand total tagged for the mobile bar
mirror:

```html
<span data-summary-grand><?= $escaper->escapeHtml($grandTotal) ?></span>
```

---

## 4. 4.3 — Mobile Bar Without a Second Mount

### The constraint

The Phase 3 lesson was direct: never mount the same Magewire component
class twice on the page. Two mounts of `Shipping` racing on `addressSaved`
caused silent double rate-collects and a non-advancing UI. Phase 4 has
the same temptation — render the summary in the right column AND in a
top-of-viewport mobile bar, both wired to the same listeners.

### The solution — one DOM, CSS-driven layout

`checkout.phtml`'s grid wrapper became responsive **flex on mobile, grid
on desktop**, with explicit ordering:

```html
<div class="flex flex-col lg:grid lg:grid-cols-12 lg:gap-x-12 xl:gap-x-16">
    <div class="order-2 lg:order-1 lg:col-span-7">…steps…</div>
    <div class="order-1 lg:order-2 lg:col-span-5 mb-4 lg:mb-0">
        <div class="sticky top-0 z-30 lg:top-6">
            <?= $block->getChildHtml('checkout.summary') ?>
        </div>
    </div>
</div>
```

On mobile the summary column is `order-1` (renders first → sticky to the
top of the viewport). On desktop it flips to `order-2` (renders right of
steps → sticky to its scroll container).

The summary itself flips its visual treatment via media-query CSS:

```css
.checkout-summary {
    background-color: #1e3a5f;       /* mobile dark navy */
    color: #ffffff;
    border-radius: var(--radius-lg);
}

@media (min-width: 1024px) {
    .checkout-summary {
        background-color: var(--color-surface);   /* desktop white card */
        color: var(--color-fg);
        border: 1px solid var(--color-gray-200);
    }
}
```

The compact toggle bar is `lg:hidden` — never shown on desktop. The
expanded body is `lg:block` — always visible on desktop, gated by an
Alpine `open` flag on mobile:

```html
<div id="checkout-summary-body"
     class="checkout-summary-body lg:block"
     :class="{ 'hidden': !open }"
     x-cloak>
    …items + coupon + totals…
</div>
```

Same DOM, same Magewire mounts, two visual treatments. No race.

### Mirroring the live total in the compact bar

The compact toggle text shows the grand total. That figure lives inside
the Totals child component's HTML — and that HTML re-renders on every
relevant event. The toggle button itself sits in `summary/root.phtml`,
outside any Magewire mount, so it doesn't auto-refresh.

We bridge with a server-dispatched browser event:

```php
// Magewire/Summary/Totals.php
public function refresh(): void
{
    $this->dispatchBrowserEvent('summary-totals-updated', [
        'grand_total_formatted' => $this->getGrandTotalFormatted(),
    ]);
}
```

```html
<!-- summary/root.phtml -->
<aside x-data="{ open: false, grandTotal: '<?= $escaper->escapeJs($initialGrand) ?>' }"
       @summary-totals-updated.window="grandTotal = $event.detail.grand_total_formatted ?? grandTotal">
    <button class="checkout-summary-bar lg:hidden" @click="open = !open">
        …
        <span x-text="grandTotal">…</span>
    </button>
    …
</aside>
```

The initial `grandTotal` is read in PHP from the `Totals` magewire
component instance via `$block->getLayout()->getBlock('checkout.summary.totals')->getData('magewire')->getGrandTotalFormatted()`. That call is
safe regardless of Magewire's lifecycle order because
`getGrandTotalFormatted()` reads the checkout session's quote directly,
not internal Magewire snapshot state.

After every `Totals::refresh()` call, the dispatched event arrives
client-side and Alpine updates the bar's text reactively. No second
component, no double XHR, no race.

---

## 5. 4.4 — Live Refresh Wiring

| Trigger                           | Emitter            | Listeners that re-render                |
|-----------------------------------|--------------------|-----------------------------------------|
| Address saved                     | `Address`          | Shipping (rates), **Totals (tax row)**, **Coupon (resync from quote)** |
| Shipping method selected          | `Shipping`         | **Totals (shipping row + grand)**       |
| Coupon applied                    | `Coupon`           | **Totals (discount row + grand)**       |
| Coupon removed                    | `Coupon`           | **Totals (discount row + grand)**       |

The three new listening paths (bold) are what Phase 4 added to the
existing event contract. Note `addressSaved` going to **both** Totals
and Coupon: the Totals path is for the VAT group flip (intra-EU B2B
0% kicks in immediately after the address save, before the user picks
shipping); the Coupon path is for the rule-invalidation case described
in §2.

`Items` listens to nothing. Items don't change during checkout —
adding the listeners would be cargo-cult.

---

## 6. Why Three Components, Not One

A single `Summary` component listening to all four events would be
fewer files and fewer DI graph nodes. We didn't take that path:

- **Coupon owns user input.** Folding it into a read-only Summary
  component muddies the responsibility. A user typing a code shouldn't
  trigger a re-render of the items list.
- **Items doesn't need any listeners.** A combined component would
  re-render items on every `couponApplied` even though the rendered
  HTML for items is identical. Wasted bandwidth.
- **PSP modules can override one piece independently.** A future Viva
  or Revolut PSP module might want to inject a "Pay later" badge into
  the items presentation without touching coupon or totals — that's
  trivial when each is its own block in layout XML, awkward when
  they're a single template.

Three components is the right granularity here.

---

## 7. Key Patterns to Remember

| Pattern | Rule |
|---------|------|
| One Magewire mount per logical role | Same as Phase 3: never mount the same component class twice. The mobile bar reuses the desktop sidebar's DOM via CSS ordering, not a duplicate mount. |
| Coupon validity is detected via `setCouponCode` + `collectAndSave` + re-read | Magento blanks the coupon on the quote when the rule doesn't match. Don't roll your own rule lookup or try to pre-validate — just check what's on the quote after saving. |
| `addressSaved` triggers Totals refresh | The VAT validator runs on `collectTotals`, which `Address::saveAddress` already calls. The customer group can flip from one save (e.g. valid intra-EU B2B → 0% VAT). Totals must reflect that without needing a shipping method first. |
| `dispatchBrowserEvent` from listeners for non-Magewire mirrors | When a server-side re-render needs to update something *outside* the component (the mobile compact bar's grand total, a third-party widget, etc.), call `$this->dispatchBrowserEvent('your-event', $payload)` in the listener handler. Alpine listens with `@your-event.window`. |
| Read-only components have empty listeners | If a component never mutates state and isn't affected by user events, don't add listeners just because other summary components have them. Items in this phase has zero listeners on purpose. |
| `PriceCurrencyInterface::format($amount, false)` for plain text | The second argument `false` strips the `<span class="price">` container Magento wraps prices with by default. We want plain text so the template controls escaping. |
| `wire:model.defer` for inputs that fire on submit, not keystroke | The coupon input uses `wire:model.defer="code"` — the value isn't synced over the wire until the user clicks Apply. No keystroke-XHR storm. |
| Dark-themed mobile presentation uses literal hex, not Hyvä tokens | The mobile bar's `#1e3a5f` is intentional: the dark navy is a visual identity choice independent of the host theme's primary color. Hosts that want their own mobile bar treatment can override `.checkout-summary` in their own theme CSS. |
| Reading magewire instance via layout block tree is safe | `$block->getLayout()->getBlock('…')->getData('magewire')` returns the DI-resolved component instance. Calling read-only methods on it (like `getGrandTotalFormatted()`) is safe at any rendering stage because they read the checkout session directly, not Magewire snapshot state. |

---

*Previous: [Phase 3 — Shipping Step](./phase-3-shipping-step.md)*
*Next: Phase 5 — Payment Step (see PROGRESS.md)*
