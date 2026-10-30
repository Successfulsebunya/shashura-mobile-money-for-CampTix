=== Shashura Mobile Money for CampTix ===
Contributors: cipherwave, ssebuwufumoses
Tags: camptix, shashura, mobile money, mtn, airtel, uganda
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept MTN and Airtel Mobile Money for CampTix ticket sales in Uganda, settled through the Shashura payment gateway. Not affiliated with or endorsed by CampTix.

== Description ==

Shashura Mobile Money for CampTix adds MTN and Airtel Mobile Money as a payment option for CampTix, the WordPress event ticketing plugin used by WordCamp and community events. It is built for organisers selling tickets in Uganda who need a payment method their attendees already use day to day. This plugin is developed and maintained by Techdom Digital Solutions and is not affiliated with, or endorsed by, the CampTix project.

A Shashura merchant account is required to use this plugin. Sign up or log in at [https://shashura.com](https://shashura.com).

The plugin connects CampTix to the Shashura Mobile Money API and handles the full collection lifecycle: number verification, the PIN push to the buyer's phone, settlement confirmation, and reconciliation. It runs against either Shashura's Sandbox or Production environment, and endpoint overrides are controlled by trusted deployment code, never per-site settings. See ENDPOINT-POLICY.md for migration and development testing.

= How a payment works =

1. The buyer selects Mobile Money at checkout and enters their MTN or Airtel number.
2. The number is verified against the network before anything is charged, and the registered account name is shown to the buyer for confirmation.
3. A PIN prompt is pushed to the buyer's phone for the exact order total.
4. The plugin confirms the payment with Shashura directly rather than trusting the browser or an incoming webhook on their own. Both only trigger a check; only a status enquiry whose reference and amount match the order can mark it paid.
5. Once confirmed, the buyer is taken to their CampTix ticket page and receives CampTix's standard confirmation email.

= Reliability and safety =

* Every payment prompt on an order is tracked and reconciled, including ones approved after a buyer tried more than once.
* A duplicate payment on the same order is detected and flagged to the site administrator for a manual refund, rather than accepted or dropped silently.
* Incoming webhooks are verified against an HMAC-SHA256 signature. Unsigned, forged, oversized or mismatched-environment events are rejected.
* A per-order lock stops the webhook, the buyer's browser, an order cancellation and CampTix's own timeout sweep from recording conflicting outcomes for the same order at the same time.
* A payment approved after an order was cancelled or timed out is still recognised and completes the order.
* Works with both current CampTix (2.x) and CampTix 1.4.x, using the reconciliation strategy appropriate to each.

= Requirements =

* CampTix, with the ticket currency set to UGX.
* A Shashura merchant account with an app configured for Mobile Money collections, and its Client ID, Client Secret and Webhook Secret.
* A publicly reachable site, with the server's outbound IP whitelisted on the Shashura app, so callbacks and settlement can reach the plugin.

== Installation ==

1. Install and activate CampTix. Under Tickets → Setup → General, set the ticket currency to UGX.
2. Upload the plugin zip file under Plugins → Add New → Upload Plugin, then activate it.
3. Go to Tickets → Setup → Payment and open the Mobile Money (MTN / Airtel) section:
   * Set Enabled to Yes.
   * Enter the Client ID and Client Secret from your Shashura app (see "Getting Your Shashura API Credentials" below).
   * Choose Sandbox while testing. Switch to Production only when ready to take real payments.
   * Enter the Webhook Secret shown on your Shashura app. Mobile Money is not offered at checkout until this is set.
   * API endpoints are controlled by trusted deployment code; see ENDPOINT-POLICY.md.
4. Copy the webhook URL shown under Webhook Secret and set it as the Callback URL on the matching app in your Shashura dashboard.
5. Ask Shashura to whitelist your server's outbound IP address for that app. Requests from an IP that is not whitelisted are rejected.
6. Save your settings. The Connection line confirms whether the credentials authenticate successfully.

== Getting Your Shashura API Credentials ==

This plugin needs three values from your Shashura merchant account: a Client ID, a Client Secret, and a Webhook Secret. All three are issued from the Apps page of your Shashura merchant dashboard.

1. Log in to your Shashura merchant dashboard at [https://shashura.com](https://shashura.com) and open the Apps page. If you don't have an account yet, sign up there first.
2. Click Create App and give it a name, such as your event site's name. Shashura generates a Sandbox credential pair and a Production credential pair for the app at the same time, along with a Webhook Signing Secret, so there is no separate step to request production access later.
3. The Apps page lists each Client ID against its environment:
   * Client ID: SANDBOX begins with `TID_`. Use this, with its matching Client Secret, while Environment is set to Sandbox in this plugin.
   * Client ID: PRODUCTION begins with `PID_`. Use this, with its matching Client Secret, once Environment is switched to Production.
   * Client Secret is hidden by default. Use the reveal icon to display it on screen, or the regenerate icon to issue a new one. Regenerating immediately invalidates the previous secret, so update this plugin's settings straight after.
4. Copy the Client ID and Client Secret for the environment you are configuring into this plugin's Payment settings.
5. Reveal the Webhook Signing Secret (begins with `whsec_`) on the same Apps page and copy it into this plugin's Webhook Secret field.
6. Copy the webhook URL this plugin displays under its Webhook Secret field and paste it into the Callback URL field for the same app on the Apps page.
7. Ask Shashura to whitelist your server's outbound IP address for this app, so callbacks and settlement checks are accepted.

Because each app holds both a Sandbox and a Production credential pair, going live does not require creating a new app; only switching this plugin's Environment setting to Production and swapping in the Production Client ID and Secret from the same Apps page.

== Frequently Asked Questions ==

= Which currencies are supported? =

Uganda Shillings (UGX) only, matching Shashura's Mobile Money coverage.

= Does this plugin process refunds? =

Yes. Use CampTix's own "Refund" button on the attendee screen — full, partial, and refund-all are all supported. Only the amount the organizer actually received is reversed; Shashura's processing fee is not refunded. The attendee gets the refunded amount back on the same phone number that paid.

= What happens if a buyer closes the browser tab after approving the PIN prompt? =

The payment still settles. Shashura's webhook, and CampTix's own timeout check on CampTix 2.x, both ask Shashura directly whether the order was paid, independently of whether the buyer's browser is still open.

= Can I switch between Sandbox and Production while orders are still open? =

Avoid it. A prompt sent under one environment cannot be checked while the plugin is configured for the other. It stays in a waiting state, never shown as failed, until the environment is switched back.

= Where do I see what happened on a specific order? =

Open the attendee's edit screen under Tickets → Attendees. The CampTix log on that screen records every check, error, amount mismatch or duplicate-payment warning for that order.

= Can this connect to a Shashura deployment other than the public default? =

Yes. A trusted deployment operator can set SHASMOMO_BASE_URL or use shasmomo_base_url. Per-site URL settings are ignored. See ENDPOINT-POLICY.md.

== External services ==

This plugin connects to the Shashura Mobile Money API to collect ticket payments. This is required functionality: without it, the plugin cannot verify mobile numbers, push PIN prompts, or confirm payment, since Mobile Money collection can only happen through a licensed Mobile Money aggregator.

What is sent, and when:

* Number verification: when a buyer enters a mobile number at checkout, that number is sent to Shashura to look up the registered account name on the MTN/Airtel network, shown to the buyer for confirmation before anything is charged.
* Payment request: once the buyer confirms, the mobile number, the order total, and an order reference are sent to Shashura to push a PIN prompt to the buyer's phone.
* Status confirmation: after a prompt is sent, this plugin asks Shashura directly whether the referenced payment succeeded (and Shashura also notifies this plugin via a signed webhook). No card numbers, mobile money PINs, or other site data are sent.
* These calls happen only when a buyer actually attempts a Mobile Money payment at checkout, never on a page load with no checkout activity.

This service is provided by Shashura (operated by Techdom Digital Solutions): [Terms of Service](https://shashura.com/terms), [Privacy Policy](https://shashura.com/privacy).

A Shashura merchant account, with an app's Client ID and Client Secret, is required to use this plugin. See "Getting Your Shashura API Credentials" above.

== Screenshots ==

1. Payment configuration under CampTix → Setup → Payment: credentials, environment selection, and live connection status.
2. The buyer-facing checkout flow, from entering a mobile number through to the CampTix ticket page.
3. An optional "Powered by Shashura" footer, shown here on the PIN-approval step. Off by default; the site administrator can turn it on from the payment settings.

== Changelog ==

= 1.0.3 =
* The buyer-facing payment pages now show the actual MTN MoMo and Airtel Money logos instead of a coloured badge with the network name. A site without permission to use the official logos can restore the plain badge, or supply its own image, through the `shasmomo_network_logo` filter.
* Updated plugin credits: now developed and maintained by Techdom Digital Solutions. Added Cipherwave Software Limited and Moses Ssebuwufu as contributors.

= 1.0.2 =
* Added refund support: CampTix's "Refund" button (single, partial, and refund-all) now works through the Shashura API. The attendee's money is sent back to the phone that paid; Shashura's processing fee is not refunded.
* The "Powered by Shashura" credit link on buyer-facing payment pages is now off by default and only shown if the site administrator explicitly turns it on in the payment settings.
* Renamed internal functions, classes, defines and filters from the generic "ctx_shashura"/"CTX_Shashura" prefix to a more distinctive "shasmomo"/"Shasmomo" prefix, to avoid clashing with other plugins.
* Fixed the plugin header's License field to a format reliably recognised by the Plugin Check tool.

= 1.0.1 =
* Renamed from "CampTix Shashura Mobile Money" to "Shashura Mobile Money for CampTix" to avoid implying official affiliation with the CampTix project.
* Documented the plugin's use of the Shashura Mobile Money API under "External services".

= 1.0.0 =
* Initial release. MTN and Airtel Mobile Money collection for CampTix via the Shashura API, with number verification, webhook and status-enquiry reconciliation, duplicate-payment detection, and support for both CampTix 2.x and 1.4.x.

== Upgrade Notice ==

= 1.0.3 =
Payment pages now show the real MTN/Airtel logos by default (filter-overridable). Plugin credits also updated: Techdom Digital Solutions, Cipherwave Software Limited.

= 1.0.2 =
"Powered by Shashura" is now opt-in (off by default); internal naming made more distinctive. No action needed unless you want the credit link, which you can now turn on in the payment settings.

= 1.0.1 =
Plugin renamed to Shashura Mobile Money for CampTix; no functional changes.

= 1.0.0 =
Initial release.

== Additional Information ==

= About Shashura =

Shashura is a Mobile Money payment gateway for Uganda, settling payments through MTN and Airtel Mobile Money. A Shashura merchant account is required to use this plugin. Sign up or log in at [https://shashura.com](https://shashura.com).

= Privacy =

Against each paid order, this plugin stores the payer's registered name, a masked mobile number, the mobile network, and the Shashura transaction reference, for reconciliation and support. Full, unmasked phone numbers and names are included in WordPress's own Tools → Export Personal Data and Erase Personal Data tools, so data-subject requests are handled through core WordPress, not a separate process. This plugin never handles or stores card numbers, mobile money PINs, or Shashura account credentials.

= Support =

For help with a Shashura account, API credentials, or a specific payment, contact Shashura support through the merchant dashboard at [https://shashura.com](https://shashura.com). For issues with this plugin itself, use this plugin's support forum on WordPress.org.

= Testing =

An automated end-to-end suite (92 checks) has been run against WordPress with both current CampTix (2.x) and CampTix 1.4.x, covering the full payment lifecycle: KYC errors, underpayment, wrong reference, forged or replayed webhooks, double approval, telco rejection, token expiry, cancel races, concurrent webhook and browser settlement, the timeout sweep, rate limits, and privacy export/erase. An independent security review of the code and a live test deployment was also carried out, with every finding fixed and covered by the test suite.

= Credits =

* **Developed and maintained by:** Techdom Digital Solutions ([https://techdom.io](https://techdom.io))
* **Contributors:** Cipherwave Software Limited ([https://cipherwavesolutions.com](https://cipherwavesolutions.com)), Moses Ssebuwufu (ssebuwufumoses on WordPress.org and GitHub)
* **Trademarks:** The MTN MoMo and Airtel Money logos in `assets/images` are trademarks of MTN and Airtel.

= Developer Filters =

The following filters are available to adjust plugin behaviour from a theme or another plugin:

* `shasmomo_lookups_per_ip` (default 20): number verification checks allowed per visitor IP per hour.
* `shasmomo_lookups_alert` (default 300): site-wide number verification checks per hour that trigger an admin email.
* `shasmomo_client_ip`: overrides the IP address used for rate limiting.
* `shasmomo_accent_color` (default #1d4ed8): accent colour used on the payment pages.
* `shasmomo_http_timeout` (default 25): timeout, in seconds, for calls to the Shashura API.
* `shasmomo_base_url` (default https://shashura.com/api/v1): trusted deployment override requiring HTTPS and WordPress safe HTTP validation. Per-site endpoint settings are ignored.
