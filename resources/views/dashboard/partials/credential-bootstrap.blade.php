@php
    $bootstrapCredentials = session('dashboard_api_credentials_bootstrap');
@endphp

@if (is_array($bootstrapCredentials)
    && isset($bootstrapCredentials['api_key'], $bootstrapCredentials['api_secret'])
    && $bootstrapCredentials['api_key'] !== ''
    && $bootstrapCredentials['api_secret'] !== '')
    <script>
        (() => {
            try {
                localStorage.setItem('gateway_dashboard_credentials_v1', JSON.stringify({
                    api_key: @json($bootstrapCredentials['api_key']),
                    api_secret: @json($bootstrapCredentials['api_secret'])
                }));
            } catch (_) {
                // Ignore bootstrap write failures in restrictive browser contexts.
            }
        })();
    </script>
@endif
