/**
 * Postcode lookup Alpine component.
 *
 * The postcode-lookup.phtml template already inlines this logic as x-data — this file
 * is the extracted standalone version for use when the template is loaded as a separate
 * Alpine component (e.g. registered as Alpine.data('postcodeLookup', ...)).
 *
 * Usage in a template that is NOT inside a Magewire component (no $wire available):
 *
 *   <div x-data="postcodeLookup('/checkout/postcode/lookup')" ...>
 *
 * Usage inside a Magewire component template (has $wire):
 *   Use the inline x-data in postcode-lookup.phtml instead — $wire is in scope there.
 */
document.addEventListener('alpine:init', () => {
    Alpine.data('postcodeLookup', (lookupUrl) => ({
        pcPostcode: '',
        pcNumber: '',
        pcLoading: false,
        pcError: null,
        pcSuccess: false,

        async pcLookup() {
            if (!this.pcPostcode || !this.pcNumber) return;

            this.pcLoading = true;
            this.pcError   = null;
            this.pcSuccess = false;

            try {
                const url = lookupUrl
                    + '?postcode=' + encodeURIComponent(this.pcPostcode.replace(/\s/g, ''))
                    + '&house_number=' + encodeURIComponent(this.pcNumber);

                const response = await fetch(url, {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                });

                const data = await response.json();

                if (data.success) {
                    const streetFull = [data.street, data.house_number, data.house_number_addition]
                        .filter(Boolean)
                        .join(' ');

                    this.$dispatch('postcode-resolved', {
                        street: streetFull,
                        city:   data.city,
                        region: data.region      || '',
                        regionCode: data.region_code || '',
                        postcode: this.pcPostcode.toUpperCase().replace(/\s/g, ''),
                    });

                    this.pcSuccess = true;
                } else {
                    this.pcError = data.error || 'Address not found.';
                }
            } catch {
                this.pcError = 'Lookup failed. Please enter your address manually.';
            } finally {
                this.pcLoading = false;
            }
        },
    }));
});
