# Feature — Order Comments

> **What this is:** A free-text "order notes" textarea the shopper can fill
> in during checkout. The value persists on the quote, copies onto the
> placed order, displays in admin, and is exposed to REST consumers as
> an extension attribute on both the cart and the order. The whole
> thing is admin-toggleable, with a separate admin select that decides
> whether the textarea appears in the **Address** step or the **Payment**
> step.
>
> **Who this is for:** A developer reading this *before* changing any
> file under the order-comments feature, or *after* a regression — the
> doc is structured around the failure modes that almost bit us in
> design (XSS, header injection, length DoS, double-mount sync
> problems, the admin-toggle race, the placement-config-vs-template-
> include mismatch, etc.) and explains why the chosen mitigations are
> what they are.

---

## Table of Contents

1. [Why This Feature Exists](#1-why-this-feature-exists)
2. [Architecture at a Glance](#2-architecture-at-a-glance)
3. [The Sanitizer Is Non-Negotiable](#3-the-sanitizer-is-non-negotiable)
4. [Storage: Two Columns, One Truth](#4-storage-two-columns-one-truth)
5. [The Trait Pattern (and Why Not a Standalone Magewire Component)](#5-the-trait-pattern-and-why-not-a-standalone-magewire-component)
6. [Placement Gate: One Partial, Two Includes, One Render](#6-placement-gate-one-partial-two-includes-one-render)
7. [Quote → Order Copy](#7-quote--order-copy)
8. [REST API Round-Trip via Extension Attributes](#8-rest-api-round-trip-via-extension-attributes)
9. [Admin Order View](#9-admin-order-view)
10. [Defense-in-Depth Map](#10-defense-in-depth-map)
11. [How To Test Locally](#11-how-to-test-locally)
12. [How To Extend](#12-how-to-extend)
13. [Things This Feature Did NOT Do](#13-things-this-feature-did-not-do)
14. [Key Patterns to Remember](#14-key-patterns-to-remember)

---

## 1. Why This Feature Exists

Most stores need a way for shoppers to add a free-text note to their
order: delivery instructions ("leave at door"), gift messages, B2B
purchase-order references, accessibility requests, scheduling notes.
Magento's native checkout doesn't ship one — there used to be a
`Magento_Comment` module but it's gone. A drop-in field that's
admin-toggleable and admin-placement-able fills the gap without
forcing every store to copy/paste the same custom code.

Why **two** placements? Both have legitimate use cases:
- **Address step** is right for stores where shoppers think of the
  note in the same mental context as the address ("delivery
  instructions" feels addressy).
- **Payment step** is right for stores where the note is more of a
  last-mile thought ("oh, also, it's a gift") — putting it next to
  the place-order CTA catches more.

Letting the merchant choose at runtime avoids forking the module per
store.

---

## 2. Architecture at a Glance

```
┌─ Storage ────────────────────────────────────────────────────────┐
│ etc/db_schema.xml                                                │
│   quote.order_comments        VARCHAR(1000) NULL                 │
│   sales_order.order_comments  VARCHAR(1000) NULL                 │
│ etc/db_schema_whitelist.json                                     │
└──────────────────────────────────────────────────────────────────┘

┌─ Domain / API ───────────────────────────────────────────────────┐
│ etc/extension_attributes.xml                                     │
│   Magento\Quote\Api\Data\CartInterface  ← order_comments         │
│   Magento\Sales\Api\Data\OrderInterface ← order_comments         │
└──────────────────────────────────────────────────────────────────┘

┌─ Sanitisation (single-source-of-truth) ──────────────────────────┐
│ Model/OrderComments/Sanitizer.php                                │
│   Strip control bytes • strip_tags • normalise CRLF              │
│   • collapse blank-line walls • trim • mb_substr cap (1000)      │
└──────────────────────────────────────────────────────────────────┘

┌─ Write paths (every one calls Sanitizer) ────────────────────────┐
│ ── Frontend ──                                                   │
│ Magewire/Concern/HasOrderComments::saveOrderComments()           │
│   ↪ used by Magewire/Step/Address AND Magewire/Step/Payment      │
│                                                                  │
│ ── REST consumers ──                                             │
│ Plugin/Quote/CartExtensionPlugin::beforeSave (cart)              │
│ Plugin/Sales/OrderExtensionPlugin::beforeSave (order)            │
│                                                                  │
│ ── Quote → Order on submit ──                                    │
│ Observer/CopyQuoteOrderComments                                  │
│   on `sales_model_service_quote_submit_before`                   │
└──────────────────────────────────────────────────────────────────┘

┌─ Read paths (also re-sanitise — historical rows distrust) ───────┐
│ Plugin/Quote/CartExtensionPlugin::afterGet*  → ext attr          │
│ Plugin/Sales/OrderExtensionPlugin::afterGet* → ext attr          │
└──────────────────────────────────────────────────────────────────┘

┌─ Admin config ───────────────────────────────────────────────────┐
│ etc/adminhtml/system.xml — group `order_comments` under          │
│ Stores > Config > Ethelserth > Checkout                          │
│   enabled       (yes/no)                                         │
│   placement     (address | payment)                              │
│   label         (free-text)                                      │
│   placeholder   (free-text)                                      │
│ etc/config.xml — defaults: disabled, placement=payment           │
└──────────────────────────────────────────────────────────────────┘

┌─ Admin display ──────────────────────────────────────────────────┐
│ view/adminhtml/layout/sales_order_view.xml                       │
│ view/adminhtml/templates/order/view/comments.phtml               │
│   Always renders if `order_comments` non-empty (toggle gates     │
│   collection, NOT display — historical orders stay readable)     │
└──────────────────────────────────────────────────────────────────┘

┌─ Frontend rendering ─────────────────────────────────────────────┐
│ ViewModel/OrderCommentsConfig.php                                │
│   shouldRenderIn($step) gates the partial                        │
│ view/frontend/templates/checkout/shared/order-comments.phtml     │
│   Included from BOTH step/address.phtml AND step/payment.phtml — │
│   the gate makes sure only the configured one actually emits.    │
└──────────────────────────────────────────────────────────────────┘
```

---

## 3. The Sanitizer Is Non-Negotiable

`Model/OrderComments/Sanitizer.php` is the entry door for every byte
that lands on `quote.order_comments` or `sales_order.order_comments`.
Six steps, each defending against a specific failure mode:

| Step | Defends against |
|---|---|
| Strip `\x00-\x08\x0B\x0C\x0E-\x1F\x7F` | NULL-byte injection, terminal escape sequences, log-shipper confusion |
| `strip_tags()` | XSS via `<script>`, `<img onerror=…>`, `<svg>` payloads, broken-out attribute contexts |
| Normalise `\r\n` / `\r` → `\n` | Mail-header injection if the value ever ends up in an email body that re-derives headers from blank-line splits |
| Collapse 3+ `\n` to 2 | DoS-via-vertical-flooding of the admin order view |
| `trim()` | Whitespace noise |
| `mb_substr(…, 0, MAX_LENGTH)` | DB column overflow (column is `varchar(1000)`; emoji-heavy strings can exceed byte count without the multi-byte cap) |

**MAX_LENGTH (1000) MUST stay in sync with the column width in
`etc/db_schema.xml`.** Bumping one without the other risks silent
truncation at the DB layer (MySQL truncates without warning by
default). The Sanitizer's docblock calls this out at the top of the
file.

The output is **plain text**. We deliberately do NOT pre-encode HTML
entities — that's the renderer's job (`escapeHtml` at the output
site). Pre-encoding would corrupt the value if it round-trips through
the API.

### Why sanitise on every write site, not just one

The temptation is to sanitise once at the Magewire boundary and trust
the column. That fails for any of:

1. A **REST consumer** writes via `/V1/carts/...` extension attribute.
   Bypass Magewire entirely.
2. A **third-party plugin** sets `quote.order_comments` directly via
   `setData()`. Bypass even our extension-attribute plugin.
3. A **historical row** existed before the sanitiser was deployed.

Defense in depth means **every write path sanitises** — Magewire's
`saveOrderComments`, the cart-repository `beforeSave` plugin, the
order-repository `beforeSave` plugin, and the quote→order observer.
Plus reads sanitise too, because we never trust DB content. This is
~4 cheap function calls per round-trip in exchange for an invariant
the next maintainer doesn't have to verify mentally.

---

## 4. Storage: Two Columns, One Truth

```xml
<!-- etc/db_schema.xml -->
<table name="quote">
    <column xsi:type="varchar" name="order_comments" length="1000" nullable="true"/>
</table>
<table name="sales_order">
    <column xsi:type="varchar" name="order_comments" length="1000" nullable="true"/>
</table>
```

Two columns because Magento's quote and order are separate
aggregates with separate persistence. The quote column is
write/read-active during the entire checkout session; the order
column is the historical record after submit.

`varchar(1000)` is generous for an order note — long enough for
gift messages and detailed delivery instructions, bounded enough
that a single field can't grow into an unintended log.

`nullable=true` is required because most rows (orders placed
before the field was enabled, orders placed while the toggle was
off) won't have a value. A `NOT NULL` would break those upgrades.

The whitelist file (`etc/db_schema_whitelist.json`) is required by
Magento's declarative-schema apparatus — `bin/magento
setup:db-declaration:generate-whitelist` creates it; we ship it
hand-written to keep the file in `git`.

---

## 5. The Trait Pattern (and Why Not a Standalone Magewire Component)

The textarea can render in either the Address step or the Payment
step. The Magewire property that backs it has to live somewhere. We
considered three options:

### Option A — Duplicate the property in both components

```php
class Address extends Component {
    public string $orderComments = '';
    public function saveOrderComments(): void { /* …12 lines… */ }
}
class Payment extends Component {
    public string $orderComments = '';
    public function saveOrderComments(): void { /* …12 lines, identical… */ }
}
```

Easy to grep, dead-simple to reason about. But a third placement
(say, Summary) would mean a third copy. Drift risk.

### Option B — Trait `HasOrderComments` (chosen)

```php
trait HasOrderComments {
    public string $orderComments = '';
    protected function bootOrderComments(): void { /* … */ }
    public function saveOrderComments(): void   { /* … */ }
    abstract protected function getOrderCommentsCheckoutSession(): CheckoutSession;
    abstract protected function getOrderCommentsQuoteService(): QuoteService;
    abstract protected function getOrderCommentsSanitizer(): Sanitizer;
    abstract protected function getOrderCommentsConfig(): OrderCommentsConfig;
}
```

The using component (`Address`, `Payment`) provides the four
dependency accessors; everything else lives in the trait.

Why **abstract accessors** rather than the trait reading
`$this->checkoutSession` directly: PHP traits CAN access private
properties of the using class, but constructor-promoted `private
readonly` parameters live in the using class's scope, and reading
them from the trait would couple the trait to a specific dependency
ordering. Accessors keep the trait portable across components that
might inject the same dep with a different parameter name.

Why **public properties from a trait work for Magewire**: Magewire
enumerates public properties via reflection; trait public properties
are visible to reflection on the using class. Confirmed.

### Option C — Standalone `Magewire/OrderComments` component

A dedicated component, mounted once in the layout, teleported into
either step's body via Alpine. **Rejected** because Magewire mounts
are positionally bound to the layout XML — moving the mount around
based on runtime config breaks the Magewire snapshot model in subtle
ways (the morph algorithm needs a stable parent/sibling map).

---

## 6. Placement Gate: One Partial, Two Includes, One Render

`view/frontend/templates/checkout/shared/order-comments.phtml` is
included from BOTH `step/address.phtml` and `step/payment.phtml`. The
partial's first action is to consult `OrderCommentsConfig::shouldRenderIn($currentStep)`:

```php
if (!$orderCommentsConfig->shouldRenderIn($currentStep)) {
    return;
}
```

The partial is given `$currentStep` (literal `'address'` or
`'payment'`) by the parent template, and `$orderCommentsConfig` (the
ViewModel) via `$block->getData('order_comments_config')` set by the
layout XML.

The gate combines two things:
1. Is the feature enabled at all? (`isEnabled()`)
2. Does the configured placement match this step? (`getPlacement() === $stepName`)

So a partial included in both templates renders in only one step — the
one matching the admin config — and silently no-ops in the other.

This avoids the alternative: branching at the parent-template level
("if address-placement, include the partial here; if payment-
placement, include it there"). That alternative is brittle because it
duplicates the placement logic in two templates instead of one
ViewModel call. The partial-with-internal-gate pattern keeps the
single source of truth.

### Defensive default in `getPlacement()`

```php
public function getPlacement(): string {
    $value = (string) $this->scopeConfig->getValue(self::PATH_PLACEMENT, ScopeInterface::SCOPE_STORE);
    return in_array($value, self::KNOWN_STEPS, true) ? $value : self::PLACEMENT_PAYMENT;
}
```

If the config returns an unknown value (DB tampering, stale upgrade
leftover, source model returning something neither template knows
about) the field falls back to **Payment** placement. Returning
`null`/`''` would mean the partial never renders in EITHER step — the
field would silently disappear, which is the worst possible
failure mode for a customer-facing feature. Defaulting to Payment
keeps the field visible until an admin notices and corrects.

---

## 7. Quote → Order Copy

`Observer/CopyQuoteOrderComments` listens on
`sales_model_service_quote_submit_before` and copies
`quote.order_comments` → `order.order_comments` before the order is
persisted.

Why that specific event, not `sales_order_place_after`:

- `sales_model_service_quote_submit_before` fires inside
  `Magento\Quote\Model\QuoteManagement::submit` BETWEEN data
  assembly and `$this->orderRepository->save($order)`. A `setData`
  here lands in the same INSERT — no second UPDATE, no half-written
  rows.
- `sales_order_place_after` fires AFTER the order is persisted. We'd
  need a second save, which is wasted work and introduces a window
  where the row exists without the comment.

The observer re-sanitises the value defensively (see §3 — every write
path sanitises).

---

## 8. REST API Round-Trip via Extension Attributes

REST consumers see `order_comments` as a top-level extension
attribute on both `Cart` and `Order`:

```http
GET /rest/V1/carts/mine
{
    "id": 42,
    ...
    "extension_attributes": {
        "order_comments": "Please leave at the side door"
    }
}
```

```http
POST /rest/V1/carts/mine
{
    "cart": {
        "extension_attributes": {
            "order_comments": "Gift wrap please"
        }
    }
}
```

This works because of two `etc/di.xml` plugins:

| Plugin | Direction | Purpose |
|---|---|---|
| `CartExtensionPlugin::afterGet*` | Read | After the repository loads a cart, copy `quote.order_comments` → extension attribute so REST consumers see the value |
| `CartExtensionPlugin::beforeSave` | Write | Before save, pull the extension attribute → `quote.order_comments` (sanitised) |
| `OrderExtensionPlugin::afterGet/afterGetList` | Read | Same, for orders (including search results) |
| `OrderExtensionPlugin::beforeSave` | Write | Same, for orders (rare but supported) |

Each method runs the value through `Sanitizer` — defense in depth in
case a REST consumer or extension wrote raw bytes.

**Important nuance about extension attributes**: the Magento codegen
creates `getOrderComments()` / `setOrderComments()` on the
`CartExtension` and `OrderExtension` classes only AFTER `bin/magento
setup:upgrade` runs. The plugin's `method_exists($ext,
'getOrderComments')` check is paranoia for the moment between
deploying the module and running setup:upgrade — it lets the plugin
load without fataling on a fresh install.

---

## 9. Admin Order View

`view/adminhtml/layout/sales_order_view.xml` adds a child block to
the `order_additional_info` container; the template renders the value
inside an `admin__page-section` panel that visually matches Magento's
own "Account Information" / "Order Totals" cards.

Two important rules:

1. **Display is NOT toggle-gated.** The admin "Enable Order Comments
   Field" toggle gates COLLECTION (whether the frontend shows the
   textarea), not DISPLAY. If a merchant disables the field after
   collecting comments on historical orders, those orders should
   still show their comments in admin. Hiding them would hide
   shopper requests that may still need to be honoured.

2. **`escapeHtml` BEFORE `nl2br`, never the other way around.** If
   you `nl2br()` first then `escapeHtml()` you'll escape the
   `<br/>` tags into literal text. The template has a comment
   making this explicit:

   ```php
   $escaped     = $escaper->escapeHtml($value);
   $asMultiline = nl2br($escaped, false);
   ```

   The `false` second argument to `nl2br` produces `<br>` (not
   `<br/>`), which is what the rest of Magento admin uses.

---

## 10. Defense-in-Depth Map

| Attack vector | Layer 1 (entry) | Layer 2 (storage) | Layer 3 (output) |
|---|---|---|---|
| XSS via `<script>` | `strip_tags` in Sanitizer | Re-sanitise on read | `escapeHtml` in admin template |
| Mail-header injection | CRLF normalised in Sanitizer | n/a | n/a (we don't render to email) |
| NULL-byte injection / control char | Control-byte regex strip in Sanitizer | n/a | n/a |
| Length DoS | `mb_substr` cap in Sanitizer | DB `varchar(1000)` truncation as last resort | n/a |
| SQL injection via the value | Magento ORM parameterises | Magento ORM parameterises | n/a |
| CSRF on `saveOrderComments` | Magewire round-trip uses Magento form_key | n/a | n/a |
| Authz (other-user comment write) | Magewire uses CheckoutSession quote — only the session owner can mutate | n/a | n/a |
| Bypass via REST | `CartExtensionPlugin::beforeSave` sanitises | n/a | n/a |
| Bypass via direct setData | `Observer::execute` re-sanitises on submit | n/a | n/a |

---

## 11. How To Test Locally

### Frontend, address-placement

1. `Stores > Config > Ethelserth > Checkout > Order Comments`:
   - Enable Order Comments Field = **Yes**
   - Field Placement = **Address step**
2. Add product to cart, go to `/checkout`.
3. Fill the address fields. Confirm a textarea labelled "Order notes
   (optional)" appears between the billing toggle and the "Continue
   to shipping" button.
4. Type something. Tab out (blur) — the character counter should NOT
   change (it shows characters left), and the field should persist
   after a refresh.

### Frontend, payment-placement

1. Same config but Field Placement = **Payment step**.
2. Confirm the textarea now appears between the method list and the
   place-order button (NOT in the address step).

### Sanitiser

In an active checkout, paste this into the textarea:
```
<script>alert(1)</script>
Hello world

(deliberately many

blank


lines)
```
Tab out. Refresh. Confirm:
- The `<script>` tag is gone (only the literal text "alert(1)" if
  any remained — that's fine, it's plain text now).
- 3+ blank lines are collapsed to 2.

### Quote → Order copy

Place an order. Open the placed order in admin
(`Sales > Orders > [your order]`). Confirm an "Order Comments" panel
shows the (sanitised) value.

### REST round-trip

```bash
# Read
curl -H "Authorization: Bearer $ADMIN_TOKEN" \
     https://your-store/rest/V1/orders/<id> \
     | jq '.extension_attributes.order_comments'

# Write to a cart (guest cart example — adjust auth as needed)
curl -X PUT -H "Content-Type: application/json" \
     -H "Authorization: Bearer $TOKEN" \
     https://your-store/rest/V1/carts/mine \
     -d '{"cart":{"extension_attributes":{"order_comments":"<b>HTML</b> stays gone"}}}'
```

Confirm the value comes back without the `<b>` tags.

### Disable while orders exist

1. Disable the toggle.
2. Confirm the frontend textarea is gone everywhere.
3. Confirm pre-existing orders' comments STILL display in admin.

---

## 12. How To Extend

### Add a third placement (e.g. `summary` for the order summary sidebar)

1. Add `'summary'` to `Model/Config/Source/OrderCommentsPlacement::toOptionArray()`.
2. Add `'summary'` to `OrderCommentsConfig::KNOWN_STEPS` constant.
3. Decide which Magewire component owns the field in summary
   placement (probably a new `Magewire/Summary/Notes` component, or
   re-use `Magewire/Summary/Items`). Add `use HasOrderComments;` and
   the four trait accessors.
4. Include the partial from the chosen template, passing
   `$currentStep = 'summary'`.
5. Wire `order_comments_config` argument into the chosen block via
   layout XML.

### Add a per-customer-group toggle

`OrderCommentsConfig::isEnabled` reads a single store-scope flag.
Make it customer-group-aware by injecting `CustomerSession` and
checking `$session->getCustomerGroupId()` against a comma-separated
config like `ethelserth_checkout/order_comments/groups_enabled`.

### Render the comment in a transactional email

Add a template variable in the order email template referencing
`{{var order.getOrderComments()|escape|nl2br}}`. The value is plain
text (Sanitizer-cleaned) so escaping in the template is sufficient.

---

## 13. Things This Feature Did NOT Do

- **A WYSIWYG / rich-text editor.** Order notes are plain text; the
  Sanitizer assumes plain text. A WYSIWYG would require a different
  sanitiser (HTML allow-list via DOMPurify or similar) and a
  different rendering path. Out of scope.
- **Per-line-item comments.** This is order-level only. Per-item
  comments would be a separate column on `quote_item` /
  `sales_order_item` and a separate UI per row. Different feature.
- **A hard requirement toggle.** The field is always optional. We
  could add `ethelserth_checkout/order_comments/required` but the
  use-cases for a required free-text field on every order are thin
  — usually that's better done as a structured field (e.g. PO
  number) under a different feature.
- **Notification when the comment is non-empty.** Some merchants
  want a Slack ping or admin email when an order has a non-empty
  comment. Out of scope here; easy to layer on top via an observer
  on `sales_order_place_after` reading `$order->getOrderComments()`.

---

## 14. Key Patterns to Remember

| Pattern | Rule |
|---|---|
| Single Sanitizer, called from every write path | Strip tags, control bytes, normalise CRLF, length cap. ~4 cheap calls in exchange for "no untrusted bytes ever in the orders table" as an enforced invariant. |
| Sanitise on read too | Historical rows pre-date the sanitiser. Plugins re-clean on `afterGet*` so the API never returns dirty data. |
| `escapeHtml` BEFORE `nl2br`, never the other way | Reverse order escapes the `<br/>` tags into literal text. |
| `MAX_LENGTH` constant in the Sanitizer must equal the column width | Bumping one without the other risks silent MySQL truncation. |
| Trait > duplicated property when 2+ components share | Single source of truth; abstract accessors keep the trait portable. |
| One partial included from N parents, gate inside the partial | Don't branch placement logic in N parent templates; let the partial decide. |
| Defensive default placement | Unknown config value → fall back to Payment, never disappear silently. |
| Display gate ≠ collection gate | Admin toggle gates the frontend textarea; admin display is unconditional so legacy comments stay readable. |
| Quote → Order copy on `sales_model_service_quote_submit_before` | Lands in the same INSERT as the rest of the order — no second save, no race. |
| `method_exists($ext, 'getOrderComments')` paranoia | The Magento codegen only creates the accessor after `setup:upgrade`. The check lets the plugin load on a fresh install. |
| REST extension-attribute round-trip needs FOUR plugins | `afterGet`, `afterGetActive`, `afterGetForCustomer`, `afterGetActiveForCustomer` for cart; `afterGet` + `afterGetList` for order. Plus `beforeSave` on each. |
| `wire:model.defer` + Alpine `@blur="$wire.save…"` + server-side `apply…ToQuote()` from the parent action | **Magewire 1.x has NO `wire:blur` directive — silent no-op.** Use Alpine `@blur` for save-while-typing UX, AND have the parent step's own action (`saveAddress` / `placeOrder`) call `applyOrderCommentsToQuote()` so the deferred value always reaches the column even if the shopper never blurred before clicking the CTA. Belt + braces — see §15. |
| The textarea reflects the sanitised value back | After save, `$this->orderComments = $cleaned` so the user sees what was actually stored. Otherwise tags they typed appear "still there" client-side while the DB has a different value. |

---

---

## 15. Bug We Hit on First Integration (and the Fix)

**Symptom:** Admin enabled the field, placed it in the Payment step,
shopper typed a comment, placed an order. Empty column on the quote.
Empty column on `sales_order`. Admin order view shows nothing.

**Root cause:** `wire:blur="saveOrderComments"` on the textarea was a
silent no-op. **Magewire 1.x does not implement a `wire:blur`
directive** — only `wire:click`, `wire:keydown`, `wire:keyup`,
`wire:loading.*`, `wire:model[.defer]`. Without the blur trigger,
`saveOrderComments` never fired. The deferred property value was
correctly bundled into the next wire round-trip (the `placeOrder`
call), but `placeOrder` never read or persisted it. Quote column
stayed empty → observer copied an empty string to the order → admin
template's "render only when non-empty" gate hid the panel.

**Fix (two-pronged):**

1. **Server-side belt + braces (the real fix):** the trait gained a
   new `applyOrderCommentsToQuote()` method that sanitises and stamps
   the property onto the in-memory quote without persisting. Both
   `Address::saveAddress()` and `Payment::placeOrder()` call it
   BEFORE their own `collectAndSave` / `placeOrder` runs. This
   guarantees the deferred value reaches the column on the same wire
   round-trip the shopper triggers, regardless of whether they ever
   blurred the textarea.

2. **UX improvement (the nice-to-have):** template uses Alpine
   `@blur="$wire.saveOrderComments"` (note the syntax: Alpine
   directive calling the Magewire action via the `$wire` proxy).
   This gives "save while typing" behaviour for the refresh-survives
   case where a shopper types, switches tabs, comes back and reloads.

The trait splits responsibilities:
- `applyOrderCommentsToQuote()`  — protected; sanitise + setData on quote, NO save.
- `saveOrderComments()`          — public Magewire action; calls `applyOrderCommentsToQuote()` then `cartRepository->save()`.

Calling `saveOrderComments()` from inside `placeOrder()` would issue
a redundant `cartRepository->save()` before the order is placed —
fine for correctness, wasteful at runtime. The split lets `placeOrder`
only set the in-memory value and let its own existing save handle
persistence.

**Lesson:** Never assume a wire directive exists because Livewire
has it. Magewire is a port and intentionally implements a subset.
Greppable signal: `grep -oE 'wire:[a-z.]+' vendor/magewirephp/magewire/src/view/frontend/web/js/livewire.js | sort -u` returns the entire supported surface in one line. Run it before reaching for any `wire:` directive that isn't already in our codebase.

---

## 16. Second Bug We Hit (Same Feature, Different Layer)

After fixing the `wire:blur` no-op, the comment STILL didn't reach the
column. Trace:

1. Page load → `cartRepository->getActive()` → **`afterGetActive`
   plugin populates `$ext->orderComments` with the current column
   value** (`''` for a fresh quote). The extension attribute is now
   attached to the in-memory cart object for the rest of the request.
2. Shopper types "leave at door" — `wire:model.defer` keeps it local.
3. Shopper clicks Place Order. Wire round-trip carries the deferred
   value into `$this->orderComments`.
4. `Payment::placeOrder` runs. `applyOrderCommentsToQuote` calls
   `$quote->setData('order_comments', 'leave at door')` ✓
5. `quoteService->save($quote)` → `cartRepository->save($quote)` →
   **`CartExtensionPlugin::beforeSave` fires**.
6. The plugin reads `$ext->getOrderComments()` — but `$ext` is the
   SAME object from step 1, **still holding the auto-populated `''`**.
   The mid-request `setData` didn't update the extension attribute.
7. The plugin's old `if ($value !== null)` guard treats `''` as "REST
   consumer wrote a value" and runs `$cart->setData('order_comments',
   '')` → **overwrites our "leave at door" with `''`** a quarter-
   second before the actual save SQL.
8. Empty saved to DB. Observer copies empty to order. Admin shows
   nothing.

**The fix (in both `CartExtensionPlugin` and `OrderExtensionPlugin`):**
distinguish "ext was just auto-populated by afterGet" from "REST
consumer wrote a new value."

Both plugins now compare the cleaned ext value against the model's
`getOrigData('order_comments')` — i.e. the column value at load time:

```php
if ($extCleaned === $origData) {
    // Ext is just the auto-populated copy from afterGet*; a setData()
    // elsewhere in the request may have changed the column data
    // already. Trust the column, leave it.
    return [$cart];
}

$cart->setData('order_comments', $extCleaned);
return [$cart];
```

Match → ext is stale, leave the column alone (so our own setData
calls survive).
Differ → REST consumer modified the ext attribute, mirror it to the
column.

**Why this works for every flow:**

| Flow | `origData` | `ext` | Compare | Action |
|---|---|---|---|---|
| Magewire `setData('leave at door')` then save | `''` (from load) | `''` (auto-populated, stale) | match | skip — `'leave at door'` survives ✓ |
| REST consumer changes ext from `'old'` to `'new'` | `'old'` | `'new'` | differ | apply `'new'` to column ✓ |
| Sanitizer normalizes `'<b>x</b>'` → `'x'` on read | `'<b>x</b>'` (legacy) | `'x'` (cleaned) | differ | applies `'x'` (legacy upgrade as a side-effect) ✓ |
| No order_comments at all | `null` cast to `''` | `''` | match | skip ✓ |

**Lessons (the deeper ones):**

- **Auto-populating an extension attribute on `afterGet` is a
  trap.** The Magento manual loves the pattern, but it creates a
  stale snapshot the moment any other code mutates the underlying
  column. If you do this, your `beforeSave` MUST distinguish "I
  populated this from the load" from "the API consumer wrote a new
  value here." `getOrigData` is the cheapest way to make that
  distinction.

- **`null` vs `''` is not the same** in the ext-attribute world.
  Our first fix attempt was tempted to skip on `$value === ''`, but
  that breaks the legitimate REST flow where a consumer wants to
  CLEAR a previously-set comment by setting it to empty. The
  `origData` comparison is correct; the empty-string short-circuit
  was wrong.

- **One bug masked another.** The `wire:blur` no-op (§15) was
  fixed first; the fix routed the deferred value through
  `applyOrderCommentsToQuote` → `setData`. That `setData` exposed
  the auto-populate-vs-mid-request-mutation race the plugin had
  always had — but never triggered when no other layer was setting
  the column. Two-layer bugs are common when you add a feature on
  top of an extension-attribute scaffold; check the round-trip path
  end to end, not just the input layer.

---

*Previous: [Phase 6 — Hardening and UX](./phase-6-hardening.md)*
*Next: Phase 7 — A/B Testing Hooks (see PROGRESS.md)*
