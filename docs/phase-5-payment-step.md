# Phase 5 — Payment Step (Offline)

> **Who this is for:** A developer who has worked through Phases 0–4.
> Phase 5 closes the loop: an extensible adapter contract for any payment
> method, an `Offline` adapter that covers all five native Magento
> offline methods (Bank Transfer, COD, Check / Money Order, Free,
> Purchase Order), the place-order pipeline through adapter
> `before/after` hooks, and a confirmation page. PSP modules
> (`Ethelserth_CheckoutViva`, `Ethelserth_CheckoutRevolut`) build on the
> contract this phase establishes.

---

## Table of Contents

1. [What Phase 5 Builds](#1-what-phase-5-builds)
2. [5.1 — Adapter Interface and MethodPool](#2-51--adapter-interface-and-methodpool)
3. [5.2 — Offline Adapter (No Parallel Config Tree)](#3-52--offline-adapter-no-parallel-config-tree)
4. [5.3 — Payment Magewire Component](#4-53--payment-magewire-component)
5. [5.4 — Templates](#5-54--templates)
6. [5.5 — Place-Order Pipeline + Confirmation](#6-55--place-order-pipeline--confirmation)
7. [Browser-Channel vs Magewire-Channel: When You Need Both](#7-browser-channel-vs-magewire-channel-when-you-need-both)
8. [Why No `Magewire/Payment/Offline.php`](#8-why-no-magewirepaymentofflinephp)
9. [Bugs Fixed in This Phase](#9-bugs-fixed-in-this-phase)
10. [Key Patterns to Remember](#10-key-patterns-to-remember)

---

## 1. What Phase 5 Builds

After Phase 4 the form column has address + shipping working and the
sidebar is live. Phase 5 adds the third step — payment — and the
post-success confirmation page. Crucially, it doesn't just wire offline
methods: it establishes the adapter contract that all future PSP
modules implement.

End result:

```
Shipping section          ← already built
   ↓ shippingMethodSelected
Payment section           ← THIS PHASE
   [express rail]                          ← per-step PSP wallet slot
   [method list — radio cards]
       ▾ selected
       [adapter form template OR instructions paragraph]
   [Place order · €118.45]
   [terms note]
   ↓ orderPlaced
Confirmation page         ← /checkout/success
   [order #, email, total, CTAs]
```

The flow respects four hard constraints from the design doc:

- **Any admin-enabled offline method must work** — no per-method
  hardcoding. New offline methods (e.g. a custom `voucher`) light up
  automatically when their `payment/<code>/active` config is on.
- **PSP-specific code lives in PSP modules.** The core module ships the
  `AdapterInterface` and the `Offline` adapter; nothing else.
- **VAT / surcharges propagate live** — picking COD adds `+€2.50` to
  the sidebar before the user clicks Place Order.
- **Order placement uses Magento's standard pipeline.** No bespoke
  order creation, no observer kludges. `CartManagementInterface::placeOrder`
  is the only path.

---

## 2. 5.1 — Adapter Interface and MethodPool

### `Model/Payment/Adapter/AdapterInterface.php`

The contract every payment method exposes to the checkout step:

```php
interface AdapterInterface
{
    public function getMethodCode(): string;
    public function getTitle(): string;
    public function getIconUrl(): string;
    public function isAvailable(Quote $quote): bool;
    public function getInstructions(): string;
    public function getSurcharge(Quote $quote): float;
    public function getFormTemplate(): ?string;
    public function beforePlaceOrder(Quote $quote): void;
    public function afterPlaceOrder(Quote $quote): ?string;
    public function getJsAssets(): array;
}
```

Five of these are presentation/availability and four are lifecycle:

| Method | Purpose | Used by |
|---|---|---|
| `getMethodCode()` | The Magento method code, e.g. `checkmo`, `revolut_pay` | MethodPool, QuoteService |
| `getTitle()` | Label in the radio card | method-item template |
| `getIconUrl()` | Logo URL or empty string | method-item template |
| `isAvailable(Quote)` | Filter at list time (e.g. country restrictions) | MethodPool |
| `getInstructions()` | Body text shown when method is selected | method-item / form-template |
| `getSurcharge(Quote)` | Display-only surcharge (e.g. COD fee) | MethodPool, format inline |
| `getFormTemplate()` | Optional `Vendor_Module::path/to.phtml` for inline form | method-item |
| `beforePlaceOrder(Quote)` | Last-chance veto via `LocalizedException` | Magewire/Step/Payment |
| `afterPlaceOrder(Quote)` | Returns optional redirect URL (PSP hosted page) | Magewire/Step/Payment |
| `getJsAssets()` | Lazy-loaded JS URLs for the selected method | step/payment.phtml |

`AbstractAdapter` provides no-op defaults for everything except
`getMethodCode()` / `getTitle()`, so PSP adapters override only the
parts they care about.

### `Model/Payment/MethodPool.php`

Two responsibilities:

1. **Auto-discover offline adapters.** A static map of the five native
   offline method codes is iterated; each enabled method gets one
   `Offline` instance constructed via `ObjectManager::create` with the
   code + admin-config title injected.
2. **Accept PSP adapters via DI.** PSP modules append themselves through
   the `adapters` array argument:

   ```xml
   <type name="Ethelserth\Checkout\Model\Payment\MethodPool">
       <arguments>
           <argument name="adapters" xsi:type="array">
               <item name="viva_smart"
                     xsi:type="object">Ethelserth\CheckoutViva\Adapter\SmartCheckout</item>
           </argument>
       </arguments>
   </type>
   ```

   Phase 5's `etc/di.xml` declares the `adapters` argument as an empty
   array on the core module — that's the extension point PSPs target.

The pool also materialises adapters into a per-quote dict that Magewire
can hold in a public property:

```php
[
    'code'                => 'cashondelivery',
    'title'               => 'Cash On Delivery',
    'icon'                => '',
    'instructions'        => 'Pay in cash when your order is delivered.',
    'form_template'       => 'Ethelserth_Checkout::checkout/payment/offline/form.phtml',
    'surcharge'           => 2.50,
    'surcharge_formatted' => '+€2.50',
]
```

Magewire serialises public arrays into the wire snapshot, so the dict
must be plain PHP scalars. Adapter objects themselves never cross the
wire — only the materialised facts.

---

## 3. 5.2 — Offline Adapter (No Parallel Config Tree)

### `Model/Payment/Adapter/Offline.php`

One class, five instances at runtime. Key design rule (per the standing
"don't duplicate native config" feedback): every knob is read straight
from the existing `payment/<code>/*` admin paths.

| Adapter exposes | Reads from |
|---|---|
| `isAvailable()` | `payment/<code>/active` |
| `getTitle()` (default) | `payment/<code>/title` |
| `getInstructions()` | `payment/<code>/instructions` |
| `getSurcharge()` | `payment/cashondelivery/fee_amount` (only for COD; 0 for the rest) |
| `getFormTemplate()` | Hardcoded — `Ethelserth_Checkout::checkout/payment/offline/form.phtml` |

We do **not** introduce an `ethelserth_checkout/payment/...` config
tree. If the merchant wants to change the COD instructions, they edit
`Stores > Configuration > Sales > Payment Methods > Cash On Delivery >
New Order Status & Instructions` — the same place they'd edit it
without our module.

### Why a single class for five methods

The five offline methods (`checkmo`, `banktransfer`, `cashondelivery`,
`free`, `purchaseorder`) differ only in their config keys, not in their
behaviour. There's no Magewire form to fill, no JS to load, no token
exchange. A single class parameterised by `$methodCode` is enough.
PSP-specific adapters in their own modules will subclass
`AbstractAdapter` directly because their behaviour diverges materially
(token capture, 3DS challenges, redirect flows).

### COD surcharge as a display value, not a fee collector

`Offline::getSurcharge()` returns the value in `payment/cashondelivery/fee_amount`
purely so the radio card can show `+€2.50`. The actual fee on the
quote is collected by Magento's `Magento\OfflinePayments\Model\Cashondelivery`
fee-collector — that's part of the platform, not something we
re-implement. Selecting the method on the quote (which Phase 5 does in
`QuoteService::setPaymentMethod` + `collectAndSave`) triggers the
collector, the surcharge appears as a totals row, and the sidebar's
`Totals` listener re-renders.

---

## 4. 5.3 — Payment Magewire Component

### `Magewire/Step/Payment.php`

Public properties:

```php
public string $selectedMethod = '';
public bool   $placing        = false;
public array  $methods        = []; // dicts from MethodPool
public array  $jsAssets       = [];
```

Listeners:

```php
protected $listeners = [
    'shippingMethodSelected' => 'refresh',
    'couponApplied'          => 'refresh',
    'couponRemoved'          => 'refresh',
];
```

`refresh()` re-pulls the available methods from MethodPool and drops a
selection if the previously chosen method is no longer in the list
(e.g. the cart total fell below the COD threshold). It does NOT touch
the quote — it's a read-only re-hydration.

### `selectMethod(string $code)`

Two things have to happen:

1. **Apply the method to the quote and re-collect totals.** This is
   what makes the COD surcharge appear in the sidebar live, because
   `collectAndSave` triggers Magento's offline-payments fee-collector
   which adds a `payment_fee` line, which `Totals::refresh()` then
   re-reads:

   ```php
   $this->quoteService->setPaymentMethod($quote, $methodCode);
   $this->quoteService->collectAndSave($quote);
   ```

2. **Emit + dispatch.** The dual-channel rule from Phase 3 applies
   again (see §7) — `paymentMethodSelected` goes to both:

   ```php
   $this->emit('paymentMethodSelected', $methodCode);
   $this->dispatchBrowserEvent('payment-method-selected', ['code' => $methodCode]);
   ```

   The `emit` is what wakes the `Totals` listener (which has
   `'paymentMethodSelected' => 'refresh'` — Phase 4's table now has a
   fifth row for this). The `dispatchBrowserEvent` is for symmetry
   with address/shipping and gives PSP adapters a Browser-side hook
   they can use to lazy-mount their JS SDK without touching our store.

### `placeOrder()`

The order in which things run matters. Get it wrong and the user sees a
generic error instead of the adapter's specific failure, or the redirect
URL gets dropped:

```
1. Validate selectedMethod is non-empty           → user-facing flash
2. Resolve adapter from MethodPool                → defensive, "method gone"
3. Set $placing = true                            → button shows spinner
4. adapter->beforePlaceOrder($quote)              → MAY throw LocalizedException
5. orderId = quoteService->placeOrder($quote)     → Magento's standard call
6. redirectUrl = adapter->afterPlaceOrder($quote) → optional PSP hosted page
7. Build payload {orderId, redirectUrl?}
8. emit('orderPlaced', $orderId)                  → Magewire listeners (none today)
9. dispatchBrowserEvent('order-placed', payload)  → root template's @order-placed
```

The root template's `@order-placed.window` redirects to
`/checkout/success` — that's the only place the navigation happens.
Magewire never triggers a redirect itself; the browser does.

Two error paths:

- `LocalizedException` → user-facing message via
  `dispatchErrorMessage`, `placing` reset to false, page state
  preserved. The user can fix what's wrong (e.g. terms checkbox in a
  custom adapter) and click again.
- Any other `\Throwable` → critical-logged, generic "we couldn't place
  your order" flash. We never expose internal errors to the customer.

### `getGrandTotalFormatted()`

The place-order button's inline total. Reads
`TotalsService::getGrandTotal($quote)` and formats via
`PriceCurrencyInterface::format($amount, false)` — same format helper
the rest of the checkout uses (the `false` strips Magento's default
`<span class="price">` wrapper so we get plain text).

---

## 5. 5.4 — Templates

### `view/frontend/templates/checkout/step/payment.phtml`

The orchestrator, four blocks:

1. Per-step express rail slot — `checkout.express.rail.payment` — for
   PSP modules that prefer to inject a wallet button inside the payment
   step instead of at the top of the page.
2. Method list include.
3. Lazy `<script defer>` tags for the selected method's JS assets
   (`$jsAssets` from the component). Empty for offline methods, used
   by Revolut's `embed.js` etc.
4. Place-order CTA.

### `view/frontend/templates/checkout/payment/method-list.phtml`

Iterates `$methods` and includes `method-item.phtml` per row. Empty
state: "No payment methods are available right now. Please contact us
if this persists." (rare — usually means every offline method was
disabled and no PSP module is installed).

`role="radiogroup"` on the wrapper, `role="radio"` /
`aria-checked="true|false"` on each item — keyboard navigation works
out of the box.

### `view/frontend/templates/checkout/payment/method-item.phtml`

The card. Layout:

```
┌─────────────────────────────────────────────────────────┐
│ ○  [LOGO]  Cash On Delivery                  +€2.50     │  ← head (button)
├─────────────────────────────────────────────────────────┤
│ Pay in cash when your order is delivered.               │  ← body, when selected
└─────────────────────────────────────────────────────────┘
```

Structural choices worth knowing:

- **The head is a `<button>`, not a `<div>`.** Keyboard accessible by
  default (Enter / Space activate it), screen readers announce it as
  an interactive control. The wire:click lives on the button, NOT on
  the outer card — that way a click inside the open body never
  re-fires `selectMethod`.
- **The radio is a styled `<span>`, not an `<input type="radio">`.**
  We're already inside a `<button>`; nesting form controls inside
  buttons is invalid HTML. The visual radio is purely cosmetic; the
  state is driven by `is-selected` on the outer card.
- **The body branches on `getFormTemplate()`.** When the adapter
  returns a template path, we render it as a sub-block; when it
  returns null we render the instructions inline. PSP adapters that
  need an inline form (Revolut card, iDEAL bank picker) just return
  their template path — no extra plumbing needed.
- **Surcharge label is opt-in.** Empty `surcharge_formatted` → no
  label. COD shows `+€2.50`; the rest show nothing.

### `view/frontend/templates/checkout/payment/offline/form.phtml`

Just renders the admin-configured instructions paragraph with
`strip_tags()` on a small allow-list (`<br><strong><em><a>`) so admins
can format their instructions without us trusting arbitrary HTML.

### Place-order button

Mockup pattern: `[Place order] | [€118.45]` — a vertical divider
between the action label and the live total. Achieved with a
`.checkout-place-order-divider` `<span>`:

```html
<button class="checkout-place-order">
    <span class="checkout-place-order-label">Place order</span>
    <span class="checkout-place-order-divider"></span>
    <span class="checkout-place-order-total">€118.45</span>
</button>
```

Disabled state: button is `disabled` while `$selectedMethod === ''` OR
`$placing === true`. A loading state is also shown via
`wire:loading wire:target="placeOrder"` — spinner SVG + "Placing
order…" replaces the action label during the wire round-trip.

---

## 6. 5.5 — Place-Order Pipeline + Confirmation

### Order placement

The place-order pipeline is short by design:

```
Magewire Payment::placeOrder()
  → adapter->beforePlaceOrder()        (PSP veto via LocalizedException)
  → CartManagementInterface::placeOrder($quoteId)   (Magento)
  → adapter->afterPlaceOrder()         (PSP redirect URL or null)
  → dispatchBrowserEvent('order-placed', {orderId, redirectUrl?})
  → root template @order-placed → window.location.href = '/checkout/success'
```

`CartManagementInterface::placeOrder` is **the** method that creates
the order. It runs the standard Magento pipeline:

- Validates the quote (stock, addresses, payment, shipping all set)
- Creates a `Magento\Sales\Model\Order` from the quote
- Marks the quote inactive
- Fires `sales_order_place_after` — which triggers the standard
  new-order email observer, the inventory reservation, etc.

We don't subscribe to any of those; the platform does the work.

### Browser-side redirect, not server-side

The Magewire component never returns a redirect response — it only
dispatches a browser event with the order ID. The root template
listens:

```html
@order-placed.window="window.location.href = '/checkout/success'"
```

If a PSP adapter's `afterPlaceOrder()` returns a redirect URL (e.g.
Viva Smart Checkout), the future PSP module will override the
`@order-placed` handler in its own template to redirect to the URL in
`$event.detail.redirectUrl` instead of `/checkout/success`. The core
contract (event name, payload shape) doesn't change.

### Confirmation page

`Controller/Success/Index.php`:

- Reads `getLastOrderId()` from the checkout session.
- If empty (direct hit, expired session) → redirect to cart.
- Otherwise renders the `checkout_success_index` layout.

`view/frontend/templates/checkout/confirmation/index.phtml`:

- Big checkmark icon (green-100 bg, green-600 stroke).
- Order number heading.
- A `<dl>` panel with "Confirmation sent to" + "Total paid" — pulled
  from the `getLastRealOrder()` instance.
- Two CTAs: "View my orders" (to `sales/order/history`) and "Continue
  shopping" (to `getUrl()`, i.e. the homepage).

The new-order email is sent by Magento's standard observer. We don't
trigger it ourselves — and we don't suppress it either. If the merchant
disables `sales_email/order/enabled`, no email goes out. If they enable
it, the customer gets the receipt regardless of guest vs. logged-in.

---

## 7. Browser-Channel vs Magewire-Channel: When You Need Both

Same lesson as Phase 3 (and the Phase 3.x bug log entry that
documented it). Magewire components have two outbound event channels:

| Channel | API | Listened by |
|---|---|---|
| Magewire (server) | `$this->emit('eventName', $payload)` | Other Magewire components' `$listeners` array |
| Browser (DOM) | `$this->dispatchBrowserEvent('event-name', $payload)` | Alpine `@event-name.window`, third-party JS |

Cross-step transitions (`address-saved`, `shipping-method-selected`,
`payment-method-selected`, `order-placed`) need **both**:

- The Magewire emit so any sibling Magewire component (Totals,
  Coupon, etc.) can listen and re-render server-side.
- The browser dispatch so the Alpine store can advance the visible
  step and the root template's redirect handler fires.

Calling only one of them is a silent bug — the page either won't
advance or the totals won't refresh, and there's no error. The
bug-log entries from Phases 3 and 5 both stem from forgetting one of
the two.

Naming convention: the Magewire channel uses **camelCase**
(`paymentMethodSelected`), the browser channel uses **kebab-case**
(`payment-method-selected`). Same root, two casings, by design — it
makes it obvious in code which channel a string is referring to.

---

## 8. Why No `Magewire/Payment/Offline.php`

PROGRESS.md's original 5.2 task list included a
`Magewire/Payment/Offline.php` Magewire component. We deliberately
didn't build it. Reasons:

- Offline methods have **no JS** (no SDK, no token exchange, no 3DS).
- They have **no inline form** (no card fields, no bank picker).
- The body content is the admin-configured instructions string,
  rendered statically by `payment/offline/form.phtml`.

A Magewire mount adds a lifecycle (boot, listeners, snapshot
serialisation) that nothing on this side of the contract uses. It
would be dead code that future maintainers would have to read and
delete.

PSP adapters that DO need a Magewire mount get their own — Revolut's
inline card form is a Magewire component (token exchange, 3DS
callback handling), Stripe Elements would be too. Those live in their
own modules. The core module ships exactly one Magewire payment
component: `Magewire/Step/Payment` (the orchestrator).

---

## 9. Bugs Fixed in This Phase

Documented for posterity so future phases don't repeat them.

### `setData('foo', $bar)` does NOT extract `$foo` in the template

`payment/offline/form.phtml` initially blew up with `Undefined variable
$instructions`. The parent `method-item.phtml` rendered it with:

```php
$block->getLayout()
    ->createBlock(...)
    ->setTemplate('Ethelserth_Checkout::checkout/payment/offline/form.phtml')
    ->setData('instructions', $instructions)
    ->toHtml();
```

Magento's `setData('instructions', $value)` puts the value on the
**block** instance — read it with `$block->getData('instructions')`.
It does NOT extract the data array into local PHP variables in the
template scope (despite a wishful `@var string $instructions` docblock).

Fix: `$instructions = (string) $block->getData('instructions');` at
the top of the template.

### Carrier logo box rendered garbled letters

`MethodDecorator` returned non-null `logo_url` for `flatrate`,
`freeshipping`, `tablerate`, `ups`, `dhl` etc. — but no SVG assets ever
shipped with the module. The browser 404'd the `<img src=…>` and fell
back to rendering the `alt` text wrapped inside the 36×24 box. Two
items rendered as "ee xippir" / "uu Date" — mangled "Free Shipping" /
"Flat Rate".

Fix: `CarrierLogo\Resolver::getLogoUrl()` now always returns null in
the base resolver. The template's neutral truck glyph is the honest
default. Stores or PSP modules ship their own resolver via DI when
they have actual brand artwork.

### Done-summary leaked into the active method list

Both shipping and address steps render their collapsed summary
server-side whenever Magewire's `complete=true` (which `boot()` sets
from existing quote data on a page refresh). The summary div was
hidden by `x-show="isDone(...)"` + `x-cloak`, but a Magewire DOM-morph
on partial render could swap the node into the page before Alpine
re-evaluated x-show, leaving its class default `display: flex`
showing alongside the active list.

Fix: added inline `style="display: none"` to both
`.checkout-done-summary` divs in `step/shipping.phtml` and
`step/address.phtml`. Alpine's x-show still flips it visible when the
step actually becomes done — the inline style is a safety net for the
morph-window race.

---

## 10. Key Patterns to Remember

| Pattern | Rule |
|---|---|
| Adapter contract is the only PSP extension surface | New payment methods implement `AdapterInterface`, register via `MethodPool.adapters` in their own `etc/di.xml`. They never touch `Magewire/Step/Payment` or any core template. |
| Don't duplicate Magento's payment config | `Offline` adapter reads `payment/<code>/active`, `/title`, `/instructions`, `/fee_amount` directly. We never bolt on an `ethelserth_checkout/payment/...` parallel tree. |
| Materialise adapters into dicts before serialising over the wire | Magewire snapshots are JSON. Adapter objects don't serialise; `MethodPool::getAvailableMethods()` returns `code/title/icon/instructions/form_template/surcharge/surcharge_formatted` arrays. |
| `selectMethod()` always calls `collectAndSave` | Without it the COD surcharge never lands on the quote and the sidebar never updates. The pattern is `setPaymentMethod` → `collectAndSave` → `emit('paymentMethodSelected')` → `dispatchBrowserEvent`. |
| `placeOrder` runs `before` → place → `after` | The before/after hooks are the PSP integration points. Don't inline PSP logic in `Magewire/Step/Payment::placeOrder` — keep that method generic and let adapters do PSP-specific work. |
| Browser channel + Magewire channel for cross-step events | Same rule as Phase 3. `emit('orderPlaced')` is for Magewire listeners (currently none), `dispatchBrowserEvent('order-placed', payload)` is what the root template's `@order-placed.window` listens to. Forgetting one breaks the redirect silently. |
| The place-order button is the only redirect trigger | The Magewire component never returns a redirect response. `window.location.href = '/checkout/success'` happens in the Alpine handler. PSP adapters that redirect to a hosted page override the handler in their own template, not in core. |
| Method head is a `<button>`, the radio is a styled `<span>` | Keyboard accessible without nesting interactive controls. The radio's visual state is driven by the `.is-selected` class on the outer card, not by an `<input>`. |
| Adapter form-template branch is opt-in | `getFormTemplate()` returning null means "render instructions inline". Returning a path means "render this sub-template". Keep both paths working in `method-item.phtml` so PSP adapters can pick whichever they need. |
| New-order email is Magento's job | We don't dispatch the email ourselves and we don't suppress it. `CartManagementInterface::placeOrder` fires `sales_order_place_after`, which Magento's standard observer handles. |
| Don't ship empty Magewire mounts | `Magewire/Payment/Offline.php` was deliberately not created — offline methods have no JS / no inline form, so a mount would just be a lifecycle that nothing uses. PSP modules add their own mount only when the method genuinely needs one. |
| Inline `style="display: none"` defends against Alpine/Magewire morph races | When a server-rendered element's visibility depends on Alpine state, give it an inline `display: none` default. Alpine's `x-show` overrides on init; the inline style protects the brief window after a Magewire DOM swap before x-show re-evaluates. |

---

*Previous: [Phase 4 — Summary Sidebar](./phase-4-summary-sidebar.md)*
*Next: [Phase 6 — Hardening and UX](./phase-6-hardening.md)*
