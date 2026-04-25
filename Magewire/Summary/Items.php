<?php
declare(strict_types=1);

namespace Ethelserth\Checkout\Magewire\Summary;

use Magento\Catalog\Helper\Image as ImageHelper;
use Magento\Catalog\Helper\Product\Configuration as ConfigurationHelper;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Quote\Model\Quote\Item as QuoteItem;
use Magewirephp\Magewire\Component;

/**
 * Read-only summary of the quote items.
 *
 * Items don't change during checkout (no add-to-cart / qty editor here),
 * so this component has no listeners and no actions — it just renders
 * what's on the quote at boot.
 */
class Items extends Component
{
    public function __construct(
        private readonly CheckoutSession $checkoutSession,
        private readonly ImageHelper $imageHelper,
        private readonly ConfigurationHelper $configurationHelper,
        private readonly PriceCurrencyInterface $priceCurrency,
    ) {}

    /**
     * @return array<int, array{
     *     name: string,
     *     sku: string,
     *     qty: int,
     *     options: array<int, array{label: string, value: string}>,
     *     image_url: string,
     *     row_total_formatted: string
     * }>
     */
    public function getItems(): array
    {
        $items = [];
        foreach ($this->checkoutSession->getQuote()->getAllVisibleItems() as $item) {
            /** @var QuoteItem $item */
            $rowTotal = (float) ($item->getRowTotalInclTax() ?: $item->getRowTotal());

            $items[] = [
                'name'                => (string) $item->getName(),
                'sku'                 => (string) $item->getSku(),
                'qty'                 => (int) $item->getQty(),
                'options'             => $this->buildOptions($item),
                'image_url'           => $this->resolveImageUrl($item),
                'row_total_formatted' => $rowTotal > 0
                    ? $this->priceCurrency->format($rowTotal, false)
                    : (string) __('Free'),
            ];
        }
        return $items;
    }

    public function getItemCount(): int
    {
        $count = 0;
        foreach ($this->checkoutSession->getQuote()->getAllVisibleItems() as $item) {
            $count += (int) $item->getQty();
        }
        return $count;
    }

    /**
     * Normalised option list (configurable swatches, bundle selections,
     * custom options). Empty for plain simple products.
     *
     * @return array<int, array{label: string, value: string}>
     */
    private function buildOptions(QuoteItem $item): array
    {
        $options = [];
        foreach ($this->configurationHelper->getOptions($item) as $option) {
            $label = (string) ($option['label'] ?? '');
            $value = is_array($option['value'] ?? null)
                ? implode(', ', array_map('strval', $option['value']))
                : (string) ($option['value'] ?? '');

            if ($label === '' && $value === '') {
                continue;
            }
            $options[] = ['label' => $label, 'value' => $value];
        }
        return $options;
    }

    private function resolveImageUrl(QuoteItem $item): string
    {
        $product = $item->getProduct();
        if (!$product) {
            return '';
        }
        try {
            return (string) $this->imageHelper
                ->init($product, 'product_thumbnail_image')
                ->getUrl();
        } catch (\Throwable) {
            return '';
        }
    }
}
