<?php
declare(strict_types=1);

namespace Ethelserth\Checkout\ViewModel;

use Ethelserth\Checkout\Model\OrderComments\Sanitizer;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Read-only config bridge for the order-comments feature.
 *
 * Templates and Magewire components consult this object to know:
 *   - Is the field enabled at all? (`isEnabled`)
 *   - Should it render in this particular checkout step? (`shouldRenderIn`)
 *   - What label / placeholder copy is configured? (`getLabel` / `getPlaceholder`)
 *
 * Centralising here keeps the rendering / persistence code free of
 * raw `scope_config` calls, and gives us one place to swap the source
 * if the toggle ever moves (e.g. become customer-group-specific).
 *
 * `ArgumentInterface` is the marker that lets layout XML inject this
 * via `<argument xsi:type="object">…</argument>`.
 */
class OrderCommentsConfig implements ArgumentInterface
{
    private const PATH_ENABLED     = 'ethelserth_checkout/order_comments/enabled';
    private const PATH_PLACEMENT   = 'ethelserth_checkout/order_comments/placement';
    private const PATH_LABEL       = 'ethelserth_checkout/order_comments/label';
    private const PATH_PLACEHOLDER = 'ethelserth_checkout/order_comments/placeholder';

    /** Must match `OrderCommentsPlacement::toOptionArray` and the gates below. */
    public const PLACEMENT_ADDRESS = 'address';
    public const PLACEMENT_PAYMENT = 'payment';

    /** Step names the partial accepts — same strings used by the Alpine store. */
    private const KNOWN_STEPS = [self::PLACEMENT_ADDRESS, self::PLACEMENT_PAYMENT];

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
    ) {}

    public function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::PATH_ENABLED, ScopeInterface::SCOPE_STORE);
    }

    public function getPlacement(): string
    {
        $value = (string) $this->scopeConfig->getValue(self::PATH_PLACEMENT, ScopeInterface::SCOPE_STORE);
        // Defensive default — if admin saved an unknown value (DB tamper,
        // upgrade leftover) fall back to the safer Payment placement
        // rather than silently disappearing.
        return in_array($value, self::KNOWN_STEPS, true) ? $value : self::PLACEMENT_PAYMENT;
    }

    /**
     * The single gate templates use to decide whether to emit the textarea.
     * Returns false when the feature is off OR when the requested step
     * doesn't match the configured placement — so a partial included in
     * BOTH `step/address.phtml` AND `step/payment.phtml` only renders in
     * the one the merchant chose.
     */
    public function shouldRenderIn(string $stepName): bool
    {
        return $this->isEnabled() && $this->getPlacement() === $stepName;
    }

    public function getLabel(): string
    {
        $configured = trim((string) $this->scopeConfig->getValue(self::PATH_LABEL, ScopeInterface::SCOPE_STORE));
        return $configured !== '' ? $configured : (string) __('Order notes (optional)');
    }

    public function getPlaceholder(): string
    {
        $configured = trim((string) $this->scopeConfig->getValue(self::PATH_PLACEHOLDER, ScopeInterface::SCOPE_STORE));
        return $configured !== '' ? $configured : (string) __('Delivery instructions, gift message, scheduling…');
    }

    public function getMaxLength(): int
    {
        return Sanitizer::MAX_LENGTH;
    }
}
