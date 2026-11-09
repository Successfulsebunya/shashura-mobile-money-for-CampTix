# API endpoint policy

Per-site API Base URL settings, including old saved values, are ignored. The public Shashura URL serves sandbox and production via the environment header. Operators may set SHASMOMO_BASE_URL in wp-config.php or use the shasmomo_base_url filter in trusted deployment code. Keep multisite credentials and filters scoped to the intended site.

All endpoints require HTTPS, with no userinfo, query, fragment or nonstandard port. Requests use WordPress safe HTTP validation and never follow redirects. Local HTTP gateways and the previous insecure-URL filter are no longer supported. For development use mocked HTTP responses; no production credentials or live transactions are needed. Invalid trusted configuration fails closed. WordPress safe HTTP rejects unsafe destinations; deployment code must not override its safety filters to allow private networks.

Run `php tests/endpoint.php`. These are transport-contract mocks, not a DNS-rebinding integration test. Verify actual WordPress safe HTTP and multisite behavior before release. This hardening does not claim to eliminate DNS-rebinding risks in underlying DNS/HTTP implementations.
# Integration verification

`.github/workflows/wordpress-http.yml` installs disposable WordPress 5.8 and
current WordPress multisites. `tests/integration/endpoint.php` exercises real
WordPress URL validation, safe HTTP, Requests redirect handling, and site-scoped
transients. Only the final socket transport is replaced with invented responses;
there are no Shashura calls or real credentials. Controlled host-file entries
cover public and private DNS results, including a hostname becoming private
between requests. The test deliberately does not use `pre_http_request`, which
would bypass WordPress URL validation.

This does not prove protection against DNS changing between WordPress validation
and a real socket connection. WordPress safe HTTP does not pin that connection
to the validated address. Production WordCamp egress restrictions and its
deployment configuration still need review; do not close #29 on mocked evidence
alone.
