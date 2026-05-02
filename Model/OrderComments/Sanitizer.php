<?php
declare(strict_types=1);

namespace Ethelserth\Checkout\Model\OrderComments;

/**
 * Single source of truth for cleaning user-supplied order comments.
 *
 * Applied at THREE layers — defense in depth:
 *   1. Frontend Magewire `saveOrderComments` (before persisting to quote)
 *   2. Quote→Order observer on `sales_model_service_quote_submit_before`
 *      (in case a REST consumer or extension wrote to the quote without
 *      going through Magewire)
 *   3. Repository save plugins on Cart + Order (in case REST consumers
 *      write via the extension attribute)
 *
 * Output is plain text, safe to store and safe to render IF the renderer
 * still applies `escapeHtml`. We do NOT pre-encode entities — that's the
 * renderer's job; encoding here would corrupt the value if it's ever
 * round-tripped through the API.
 *
 * The hard length cap MUST stay in sync with the `varchar(1000)` column
 * width in `etc/db_schema.xml`. Bumping one without the other risks data
 * truncation at the DB layer (silent on MySQL).
 */
class Sanitizer
{
    /** Matches the `varchar(1000)` column. Multi-byte safe via mb_substr. */
    public const MAX_LENGTH = 1000;

    public function sanitize(?string $raw): string
    {
        if ($raw === null || $raw === '') {
            return '';
        }

        // 1. Strip control bytes (NULL, BEL, BS, VT, FF, etc.) but keep
        //    \t (\x09) and \n (\x0A). Defends against null-byte injection
        //    and against terminals/log shippers misinterpreting control
        //    characters that might surface in an admin tail of order data.
        $cleaned = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $raw) ?? '';

        // 2. Strip ALL HTML/XML tags. The field is a plain-text comment;
        //    rendering happens through `escapeHtml` so this is belt-AND-
        //    braces (defense in depth). Strips e.g. `<script>`, `<img onerror>`,
        //    SVG payloads, and stray `<` markers that could break out of
        //    attribute contexts if a future renderer forgets to escape.
        $cleaned = strip_tags($cleaned);

        // 3. Normalise CRLF / CR → LF. Helps prevent header-injection
        //    style attacks if the value is ever placed into an email
        //    body that re-derives header lines from blank-line splits.
        $cleaned = str_replace(["\r\n", "\r"], "\n", $cleaned);

        // 4. Collapse 3+ consecutive newlines to 2. Stops shoppers from
        //    pasting a wall of blank lines that would visually flood
        //    the admin order view.
        $cleaned = preg_replace("/\n{3,}/", "\n\n", $cleaned) ?? '';

        // 5. Trim leading/trailing whitespace.
        $cleaned = trim($cleaned);

        // 6. Hard length cap. Multi-byte-aware so an emoji-heavy comment
        //    doesn't slip past the byte-count and overflow the DB column.
        if (mb_strlen($cleaned) > self::MAX_LENGTH) {
            $cleaned = mb_substr($cleaned, 0, self::MAX_LENGTH);
        }

        return $cleaned;
    }
}
