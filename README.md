# Shashura Mobile Money for CampTix

Accept MTN and Airtel Mobile Money for [CampTix](https://wordpress.org/plugins/camptix/) ticket sales in Uganda, settled through the [Shashura](https://shashura.com) payment gateway.

> Not affiliated with, or endorsed by, the CampTix project.

## What it does

Shashura Mobile Money for CampTix adds MTN and Airtel Mobile Money as a payment option for CampTix, the WordPress event ticketing plugin used by WordCamp and community events. It's built for organisers selling tickets in Uganda who need a payment method their attendees already use day to day.

A Shashura merchant account is required to use this plugin. Sign up or log in at [shashura.com](https://shashura.com).

The plugin connects CampTix to the Shashura Mobile Money API and handles the full collection lifecycle: number verification, the PIN push to the buyer's phone, settlement confirmation, and reconciliation. It runs against either Shashura's Sandbox or Production environment, and the API endpoint is configurable, so it can be pointed at a specific Shashura deployment rather than the public default.

## How a payment works

1. The buyer selects Mobile Money at checkout and enters their MTN or Airtel number.
2. The number is verified against the network before anything is charged, and the registered account name is shown to the buyer for confirmation.
3. A PIN prompt is pushed to the buyer's phone for the exact order total.
4. The plugin confirms the payment with Shashura directly rather than trusting the browser or an incoming webhook on their own. Both only trigger a check; only a status enquiry whose reference and amount match the order can mark it paid.
5. Once confirmed, the buyer is taken to their CampTix ticket page and receives CampTix's standard confirmation email.

## Screenshots

**Payment configuration**: Tickets → Setup → Payment: credentials, environment, webhook URL, buyer name display, rate-limit settings, the optional credit link, and live connection status.

![Payment configuration screen](screenshot-1.png)

**Buyer-facing checkout flow**: entering a mobile number, confirming the verified account, approving on the phone, and the payment receipt.

![Checkout flow](screenshot-2.png)

**PIN-approval step**: the countdown matches the time before a new prompt can be sent. An optional "Powered by Shashura" credit can be shown below the payment pages; it is off by default.

![PIN-approval step](screenshot-3.png)

## Reliability and safety

- Every payment prompt on an order is tracked and reconciled, including ones approved after a buyer tried more than once.
- A duplicate payment on the same order is detected and flagged to the site administrator for a manual refund, rather than accepted or dropped silently.
- Incoming webhooks are verified against an HMAC-SHA256 signature. Unsigned, forged, oversized or mismatched-environment events are rejected.
- A per-order lock stops the webhook, the buyer's browser, an order cancellation and CampTix's own timeout sweep from recording conflicting outcomes for the same order at the same time.
- A payment approved after an order was cancelled or timed out is still recognised and completes the order.
- Works with both current CampTix (2.x) and CampTix 1.4.x, using the reconciliation strategy appropriate to each.

An automated end-to-end suite (92 checks) has been run against WordPress with both current CampTix (2.x) and CampTix 1.4.x, covering the full payment lifecycle: KYC errors, underpayment, wrong reference, forged or replayed webhooks, double approval, telco rejection, token expiry, cancel races, concurrent webhook and browser settlement, the timeout sweep, rate limits, and privacy export/erase. An independent security review of the code and a live test deployment was also carried out, with every finding fixed and covered by the test suite.

## Requirements

- CampTix, with the ticket currency set to UGX.
- A Shashura merchant account with an app configured for Mobile Money collections, and its Client ID, Client Secret and Webhook Secret.
- A publicly reachable site, with the server's outbound IP whitelisted on the Shashura app, so callbacks and settlement can reach the plugin.

## Installation

1. Install and activate CampTix. Under **Tickets → Setup → General**, set the ticket currency to UGX.
2. Upload the plugin zip under **Plugins → Add New → Upload Plugin**, then activate it.
3. Go to **Tickets → Setup → Payment** and open the **Mobile Money (MTN / Airtel)** section:
   - Set **Enabled** to Yes.
   - Enter the **Client ID** and **Client Secret** from your Shashura app (see [Getting your Shashura API credentials](#getting-your-shashura-api-credentials) below).
   - Choose **Sandbox** while testing. Switch to **Production** only when ready to take real payments.
   - Enter the **Webhook Secret** shown on your Shashura app. Mobile Money is not offered at checkout until this is set.
   - Leave **API Base URL** blank to use Shashura's default endpoint, or set it to point at a specific Shashura deployment.
   - If the site is behind a proxy or load balancer, set **Visitor IP from** to the header it sends, and **Trusted proxies** to the number of proxies in front of the site (usually 1).
   - **Show "Powered by Shashura"** is off by default. Tick it to show a small credit link below the payment pages.
4. Copy the webhook URL shown under Webhook Secret and set it as the **Callback URL** on the matching app in your Shashura dashboard.
5. Ask Shashura to whitelist your server's outbound IP address for that app. Requests from an IP that is not whitelisted are rejected.
6. Save your settings. The **Connection** line confirms whether the credentials authenticate successfully.

To keep the credentials out of the database, define them in `wp-config.php` instead. The matching fields then show "Set in wp-config.php":

```php
define( 'SHASMOMO_CLIENT_ID', 'PID_...' );
define( 'SHASMOMO_CLIENT_SECRET', '...' );
define( 'SHASMOMO_WEBHOOK_SECRET', '...' );
```

## Getting your Shashura API credentials

This plugin needs three values from your Shashura merchant account: a **Client ID**, a **Client Secret**, and a **Webhook Secret**. All three are issued from the Apps page of your Shashura merchant dashboard.

1. Log in to your Shashura merchant dashboard at [shashura.com](https://shashura.com) and open the **Apps** page. If you don't have an account yet, sign up there first.
2. Click **Create App** and give it a name, such as your event site's name. Shashura generates a Sandbox credential pair and a Production credential pair for the app at the same time, along with a Webhook Signing Secret, so there's no separate step to request production access later.
3. The Apps page lists each Client ID against its environment:
   - Client ID: **SANDBOX** begins with `TID_`. Use this, with its matching Client Secret, while Environment is set to Sandbox in this plugin.
   - Client ID: **PRODUCTION** begins with `PID_`. Use this, with its matching Client Secret, once Environment is switched to Production.
   - Client Secret is hidden by default. Use the reveal icon to display it on screen, or the regenerate icon to issue a new one. Regenerating immediately invalidates the previous secret, so update this plugin's settings straight after.
4. Copy the Client ID and Client Secret for the environment you're configuring into this plugin's Payment settings.
5. Reveal the **Webhook Signing Secret** (begins with `whsec_`) on the same Apps page and copy it into this plugin's Webhook Secret field.
6. Copy the webhook URL this plugin displays under its Webhook Secret field and paste it into the **Callback URL** field for the same app on the Apps page.
7. Ask Shashura to whitelist your server's outbound IP address for this app, so callbacks and settlement checks are accepted.

Because each app holds both a Sandbox and a Production credential pair, going live does not require creating a new app; only switching this plugin's Environment setting to Production and swapping in the Production Client ID and Secret from the same Apps page.

## FAQ

**Which currencies are supported?**
Uganda Shillings (UGX) only, matching Shashura's Mobile Money coverage.

**Does this plugin process refunds?**
Yes. Use CampTix's own "Refund" button on the attendee screen — full, partial, and refund-all are all supported. Only the amount the organizer actually received is reversed; Shashura's processing fee is not refunded. The attendee gets the refunded amount back on the same phone number that paid.

**What happens if a buyer closes the browser tab after approving the PIN prompt?**
The payment still settles. Shashura's webhook, and CampTix's own timeout check on CampTix 2.x, both ask Shashura directly whether the order was paid, independently of whether the buyer's browser is still open.

**Can I switch between Sandbox and Production while orders are still open?**
Avoid it. A prompt sent under one environment cannot be checked while the plugin is configured for the other. It stays in a waiting state, never shown as failed, until the environment is switched back.

**Where do I see what happened on a specific order?**
Open the attendee's edit screen under **Tickets → Attendees**. The CampTix log on that screen records every check, error, amount mismatch or duplicate-payment warning for that order.

**Can this connect to a Shashura deployment other than the public default?**
Yes. The API Base URL field under the payment settings accepts a full base URL, such as a sandbox or a dedicated gateway instance, in place of the default.

## External services

This plugin connects to the **Shashura Mobile Money API** to collect ticket payments. This is required functionality: without it, the plugin cannot verify mobile numbers, push PIN prompts, or confirm payment, since Mobile Money collection can only happen through a licensed Mobile Money aggregator.

What is sent, and when:

- **Number verification**: when a buyer enters a mobile number at checkout, that number is sent to Shashura to look up the registered account name on the MTN/Airtel network, shown to the buyer for confirmation before anything is charged.
- **Payment request**: once the buyer confirms, the mobile number, the order total, and an order reference are sent to Shashura to push a PIN prompt to the buyer's phone.
- **Status confirmation**: after a prompt is sent, this plugin asks Shashura directly whether the referenced payment succeeded (and Shashura also notifies this plugin via a signed webhook). No card numbers, mobile money PINs, or other site data are sent.
- These calls happen only when a buyer actually attempts a Mobile Money payment at checkout, never on a page load with no checkout activity.

This service is provided by Shashura (operated by Techdom Digital Solutions): [Terms of Service](https://shashura.com/terms) · [Privacy Policy](https://shashura.com/privacy).

## Privacy

While a payment request is open, the plugin keeps the buyer's full mobile number. Once the request is settled (paid, failed or rejected), only a masked number (+256 77* *** 456) is kept.

Against each paid order, this plugin stores the payer's registered name, a masked mobile number, the mobile network, and the Shashura transaction reference, for reconciliation and support. Stored phone numbers and names are included in WordPress's own **Tools → Export Personal Data** and **Erase Personal Data** tools, so data-subject requests are handled through core WordPress, not a separate process. This plugin never handles or stores card numbers, mobile money PINs, or Shashura account credentials.

## Developer filters

| Filter | Default | Description |
|---|---|---|
| `shasmomo_lookups_per_ip` | `20` | Number verification checks allowed per visitor IP per hour. |
| `shasmomo_lookups_per_number` | `3` | Number verification checks allowed per phone number per hour, across all orders. |
| `shasmomo_lookups_alert` | `300` | Site-wide number verification checks per hour that trigger an admin email. |
| `shasmomo_client_ip` | n/a | Overrides the IP address used for rate limiting. |
| `shasmomo_accent_color` | `#1d4ed8` | Accent colour used on the payment pages. Button text switches between white and near-black to stay readable. |
| `shasmomo_merchant_logo` | Site Icon, then Custom Logo | Image shown in the payment page header. |
| `shasmomo_network_logo` | bundled MTN MoMo / Airtel Money logo | Image URL for a network logo (`mtn` or `airtel`). Return an empty string to show the coloured badge with the network name instead. |
| `shasmomo_template` | plugin's `templates/` | Path of a payment page template. Themes can also override one by copying it to `your-theme/shashura-mobile-money-for-camptix/`. |
| `shasmomo_http_timeout` | `25` | Timeout, in seconds, for calls to the Shashura API. |
| `shasmomo_base_url` | `https://shashura.com/api/v1` | Overrides the API base URL on top of the settings field. HTTPS is enforced except for local or loopback addresses. |

## Support

For help with a Shashura account, API credentials, or a specific payment, contact Shashura support through the merchant dashboard at [shashura.com](https://shashura.com). For issues with this plugin itself, open an issue in this repository or use the plugin's support forum on WordPress.org.

## Credits

Developed and maintained by [Cipherwave Software Limited](https://shashura.com).

Contributors: Moses Ssebuwufu ([ssebuwufumoses](https://profiles.wordpress.org/ssebuwufumoses/) on WordPress.org, [@ssebuwufumoses](https://github.com/ssebuwufumoses) on GitHub).

The MTN MoMo and Airtel Money logos in `assets/images` are trademarks of MTN and Airtel.

## License

GPLv2 or later. See [LICENSE](https://www.gnu.org/licenses/gpl-2.0.html).
