# Collected amount mismatches

A mismatch remains stored as a final attempt with the collected and expected amounts. Settlement reports `reconciliation`, not failure, and does not invoke unpaid cancellation callbacks. The phone, confirmation and return screens warn the buyer not to pay again. Prompt creation checks again under the order lock. Polling redirects to the warning. Alerts use a durable per-attempt marker before email to avoid replay duplicates. This is at-most-once notification dispatch: mail delivery itself is not guaranteed; organisers must monitor CampTix logs too.

An exact matching payment can still settle through a webhook even if a different attempt mismatched; the mismatch evidence and alert remain for manual reconciliation. No automatic refund or reconciliation-clear action is provided. WordPress multisite uses the existing site-scoped order lock and metadata. CampTix 1.x polling now acquires that lock, but its broader background webhook termination behavior is still under investigation (#41).

Run `php tests/mismatch.php`. In-memory WordPress/CampTix doubles verify under/overpayment, replay, direct prompt protection and ordinary settlement. They do not prove WordCamp.org deployment compatibility or real concurrent database correctness. Require integration review before release.

## Late collection after a reservation was released

A paid attempt can carry `fulfilment_hold` and `fulfilment_alerted` timestamps.
Its original `tid`, reference and collected amount remain in `_shashura_attempts`
on the order attendees. This is a collected-but-unfulfilled payment, not an
unpaid cancellation. The order is not automatically reopened, even if capacity
is available later. The organiser receives a reconciliation email once per
attempt; the persisted marker prevents duplicate dispatch, but does not guarantee
mail delivery. The same evidence remains in the attendee log if email fails.

Verify the collection in Shashura. If the order cannot be fulfilled, refund that
collection through the dashboard and record the outcome on the order. If it can
be fulfilled, reserve capacity using CampTix's supported administrative process
and reconcile the order manually. Do not merely clear the hold or reset the order
to draft: that does not reserve a seat. No automatic refund or inventory
reallocation is performed.

The conservative guard avoids a count-then-publish inventory race. Database
concurrency with upstream timeout/checkout and WordCamp multisite still needs
integration verification before #31 can be closed.
