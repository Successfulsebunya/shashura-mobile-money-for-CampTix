# Collected amount mismatches

A mismatch remains stored as a final attempt with the collected and expected amounts. Settlement reports `reconciliation`, not failure, and does not invoke unpaid cancellation callbacks. The phone, confirmation and return screens warn the buyer not to pay again. Prompt creation checks again under the order lock. Polling redirects to the warning. Alerts use a durable per-attempt marker before email to avoid replay duplicates. This is at-most-once notification dispatch: mail delivery itself is not guaranteed; organisers must monitor CampTix logs too.

An exact matching payment can still settle through a webhook even if a different attempt mismatched; the mismatch evidence and alert remain for manual reconciliation. No automatic refund or reconciliation-clear action is provided. WordPress multisite uses the existing site-scoped order lock and metadata. CampTix 1.x polling now acquires that lock, but its broader background webhook termination behavior is still under investigation (#41).

Run `php tests/mismatch.php`. In-memory WordPress/CampTix doubles verify under/overpayment, replay, direct prompt protection and ordinary settlement. They do not prove WordCamp.org deployment compatibility or real concurrent database correctness. Require integration review before release.
