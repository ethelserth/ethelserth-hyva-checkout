<?php
declare(strict_types=1);

namespace Ethelserth\Checkout\Magewire\Summary;

use Ethelserth\Checkout\Model\Quote\TotalsService;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magewirephp\Magewire\Component;

/**
 * Live totals block — re-renders on every event that mutates the quote
 * total: shipping method change, coupon apply/remove, address save (which
 * may flip the customer to an intra-EU VAT-exempt group).
 *
 * Read-only: never mutates the quote. Reads via TotalsService and formats
 * via PriceCurrencyInterface — same pattern the shipping decorator uses.
 *
 * Also dispatches a `summary-totals-updated` browser event after each
 * render so the (non-Magewire) mobile collapsed bar can mirror the new
 * grand total without needing its own mount.
 */
class Totals extends Component
{
    protected $listeners = [
        'addressSaved'           => 'refresh',
        'shippingMethodSelected' => 'refresh',
        'paymentMethodSelected'  => 'refresh',
        'couponApplied'          => 'refresh',
        'couponRemoved'          => 'refresh',
    ];

    public function __construct(
        private readonly CheckoutSession $checkoutSession,
        private readonly TotalsService $totalsService,
        private readonly PriceCurrencyInterface $priceCurrency,
    ) {}

    /**
     * Listener handler for every event that may have changed totals.
     * The work is implicit — Magewire re-renders the template, which calls
     * `getRows()` / `getGrandTotalFormatted()` against the current quote.
     * We just push the new grand total to any browser-side mirror.
     */
    public function refresh(): void
    {
        $this->dispatchBrowserEvent('summary-totals-updated', [
            'grand_total_formatted' => $this->getGrandTotalFormatted(),
        ]);
    }

    /**
     * @return array<int, array{label: string, value_formatted: string, free: bool}>
     */
    public function getRows(): array
    {
        $quote = $this->checkoutSession->getQuote();
        $rows  = $this->totalsService->getTotalsRows($quote);

        return array_map(function (array $row): array {
            $free = (bool) ($row['free'] ?? false);
            return [
                'label'           => (string) $row['label'],
                'value_formatted' => $free
                    ? (string) __('Free')
                    : $this->priceCurrency->format((float) $row['value'], false),
                'free'            => $free,
            ];
        }, $rows);
    }

    public function getGrandTotalFormatted(): string
    {
        $quote = $this->checkoutSession->getQuote();
        return $this->priceCurrency->format(
            $this->totalsService->getGrandTotal($quote),
            false
        );
    }

    public function hasShippingMethod(): bool
    {
        return (string) $this->checkoutSession->getQuote()
            ->getShippingAddress()
            ->getShippingMethod() !== '';
    }
}
