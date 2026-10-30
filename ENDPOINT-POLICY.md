# API endpoint policy

Per-site API Base URL settings, including old saved values, are ignored. The public Shashura URL serves sandbox and production via the environment header. Operators may set SHASMOMO_BASE_URL in wp-config.php or use the shasmomo_base_url filter in trusted deployment code. Keep multisite credentials and filters scoped to the intended site.

All endpoints require HTTPS, with no userinfo, query, fragment or nonstandard port. Requests use WordPress safe HTTP validation and never follow redirects. Local HTTP gateways and the previous insecure-URL filter are no longer supported. For development use mocked HTTP responses; no production credentials or live transactions are needed. Invalid trusted configuration fails closed. WordPress safe HTTP rejects unsafe destinations; deployment code must not override its safety filters to allow private networks.

Run `php tests/endpoint.php`. These are transport-contract mocks, not a DNS-rebinding integration test. Verify actual WordPress safe HTTP and multisite behavior before release. This hardening does not claim to eliminate DNS-rebinding risks in underlying DNS/HTTP implementations.
