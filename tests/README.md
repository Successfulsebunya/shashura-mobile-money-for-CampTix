# Public test coverage

Run `php bin/test.php` (or `composer test`) from the repository root. Each
top-level test runs in its own PHP process; `bootstrap.php` is a shared fixture.
New top-level regressions are discovered automatically. Integration scripts
belong in a subdirectory and require their own environment.

| Script | Evidence provided |
| --- | --- |
| `endpoint.php` | Saved endpoint ignored, safe-HTTP transport arguments, malformed trusted URLs, endpoint-bound token cache |
| `mismatch.php` | Under/overpayments retain evidence, block another charge and unpaid callbacks, deduplicated alert, ordinary settlement |
| `refund.php` | Failed/pending/unknown payout, transport ambiguity, persisted holds, full/partial retries, lock contention |
| `refund_confirmation.php` | Full and partial asynchronous confirmation handling, missing hold/transaction, unconfirmed payout |

These are in-memory WordPress/CampTix doubles and mocked gateway responses.
They do not contact Shashura, use production credentials, or move money.
The workflow runs them on PHP 7.4, 8.2 and 8.4, and checks PHP syntax.

They are **not** the historical 92-check end-to-end suite. That suite and the
independent security-review report have not been published in this repository.
Do not describe these regressions as proof of that historical claim or of a
complete security audit.

Remaining release verification includes real WordPress HTTP/DNS behaviour,
WordPress/CampTix integration, database concurrency, multisite isolation,
privacy export/erasure, and the gateway's actual refund/webhook contract.
Track these gaps in #29, #31, #36, #41 and #53. A green mocked suite alone does
not establish end-to-end compatibility or close those issues.
