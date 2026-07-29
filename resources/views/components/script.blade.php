<script
    src="{{ $url }}"
    @if ($siteKey) data-site-key="{{ $siteKey }}" @endif
    @if ($captureEndpoint) data-capture-endpoint="{{ $captureEndpoint }}" @endif
    data-storage="{{ $storage }}"
    data-attribution="{{ $attribution }}"
    @if ($stripe) data-stripe="auto" @endif
    @if ($cookieDays !== null) data-cookie-days="{{ $cookieDays }}" @endif
    @if ($nonce) nonce="{{ $nonce }}" @endif
    defer
></script>
@if ($consentPayload !== null)
    {{-- Deferred snippet, so window.lnkflow does not exist yet. Poll briefly
         rather than blocking the parser on a synchronous script tag. --}}
    <script @if ($nonce) nonce="{{ $nonce }}" @endif>
        (function () {
            var attempts = 0;
            (function apply() {
                if (window.lnkflow && window.lnkflow.setConsent) {
                    window.lnkflow.setConsent(@json($consentPayload));
                } else if (attempts++ < 100) {
                    setTimeout(apply, 50);
                }
            })();
        })();
    </script>
@endif
