# Selcom payment integration foundation

## Official SDK review (2026-11-16)

Composer now requires `selcom/selcom-apigw-client:^1.0.5` (v1.0.5). Its package metadata has no PHP version constraint or runtime dependencies, so it does not declare a supported PHP range. The source uses typed `String` properties and PHP syntax that works with CHIMBO's PHP 8.2 requirement.

The SDK implements the legacy HMAC profile: `Authorization: SELCOM <base64(api key)>`, `Digest-Method: HS256`, `Digest`, `Timestamp` and `Signed-Fields`. It computes HMAC-SHA256 and exposes `postFunc`, `getFunc`, and `deleteFunc`. This matches the authentication described by the legacy `developers.selcommobile.com` checkout docs. It does **not** implement the RSA-SHA256 profile in the current Selcom Business portal, which uses an API key, private key, UTC timestamp and `Origin` header. Do not enable this client until the merchant account is confirmed to use the legacy checkout profile.

SDK transport limitations also need to be resolved before sending production requests: its methods hard-code a 90-second timeout, enable cURL verbose mode, do not check cURL or HTTP errors, and return only decoded JSON; `computeHeader()` changes PHP's process-wide timezone to `Africa/Dar_es_Salaam`. The SDK offers no configurable timeout or quiet transport option. It is therefore not safe to call directly from a request path without a narrowly scoped transport strategy that still uses the official SDK's signing/API behavior and has been validated against Selcom's contract. No live request has been made.

The legacy docs describe `POST /v1/checkout/create-order-minimal` and generic `POST /v1/checkout/wallet-payment`; they separately describe `POST /v1/checkout/selcompesa-payment` for Selcom Pesa registered users. They do not establish that the wallet endpoint supports CHIMBO's M-Pesa, Airtel Money, Mixx by Yas or HaloPesa merchant profile. Treat all those network mappings as awaiting written merchant confirmation. The current Business portal documents a different product/API, so its requirements cannot be combined with legacy checkout assumptions.

Legacy callback docs show the SELCOM authorization and digest headers and example payload, but the documentation reviewed does not specify enough callback-signature canonicalization and trust requirements to verify events safely. Callbacks remain rejected and cannot update payments.

## Current state

CHIMBO retains staff-verified mobile-money and bank claims. This foundation adds a provider contract, a Selcom adapter boundary, customer payment processing, owner-scoped status lookup and a callback entry point. It does not send requests to Selcom and cannot mark payments successful.

Payment storage already exists. Migration 018 adds provider attempt and idempotency columns plus `payment_events`; migration 014 makes method codes open text values, so HaloPesa is already supported without a new migration.

## Architecture and workflow

`PaymentGatewayInterface` defines readiness, initiation, status inquiry, response normalization, provider reference extraction and webhook verification. `SelcomGateway` implements the interface but rejects provider actions until the official Selcom contract is supplied.

Authenticated customers can call `POST /api/v1/payments/initiate` with an active mobile-money method matching their order, a Tanzanian phone number and a reusable `Idempotency-Key` header. `PaymentProcessor` checks order ownership and eligibility, calculates the remaining amount from confirmed payments minus refunds, and blocks a second pending Selcom attempt. It does not accept a client-supplied amount. The adapter's readiness guard currently fails before an attempt is recorded.

`GET /api/v1/payments/{id}/status` returns a limited status view to the payment owner. `POST /api/webhooks/selcom.php` accepts JSON POST requests, but rejects them until Selcom authentication is implemented. The webhook handler performs no database updates, so forged callbacks cannot change payment or order status.

Existing manual-payment verification remains in `Payment`; staff confirmation and the `confirmed` payment accounting are unchanged. Partial-payment balance calculation is supported by the processor, but automatic partial-payment confirmation awaits verified provider event mapping.

## Environment configuration

`PAYMENT_FLOW` selects the customer flow for both API clients: `manual` (default) or `selcom`. In `selcom` mode, checkout and order responses expose the selected flow, manual proof submission is rejected, and bank methods are hidden; cash on delivery remains available. The Selcom attempt endpoint is enabled only in `selcom` mode, and still fails closed until gateway readiness is implemented. Set this to `selcom` only after the merchant profile, supported networks, callbacks and sandbox operation are confirmed.

Set these values in the server's private `.env` only after Selcom supplies credentials and documentation. Do not put real values in source control or Flutter configuration.

```dotenv
SELCOM_VENDOR_ID=
SELCOM_API_KEY=
SELCOM_API_SECRET=
SELCOM_BASE_URL=
SELCOM_ENVIRONMENT=sandbox
SELCOM_CURRENCY=TZS
SELCOM_TIMEOUT_SECONDS=15
```

`SELCOM_ENVIRONMENT` accepts `sandbox` or `production`. Set the base URL only to the official URL for that environment. Credentials alone do not enable the integration: `SelcomGateway::assertReady()` continues to reject calls until the protocol and callback are implemented.

## Pending official Selcom details

- API base URLs and request paths for initiating and querying payments.
- Required request fields, phone formatting, accepted wallet codes and amount units.
- Authentication/signing algorithm, canonical string rules, timestamp/nonce requirements and headers.
- Selcom idempotency behavior and transaction reference formats.
- Response fields and definitive success, pending, failure, cancellation and expiry states.
- Callback payload, signature rules, event ID, retry behavior and acknowledgement requirements.
- Whether the merchant account supports partial payments.

Do not infer these values from another integration. Use official merchant documentation and confirm the sandbox contract before implementation.

## Before sandbox or production

1. Obtain the official documentation and merchant credentials through a secure channel.
2. Implement and review request signing, URL construction, timeouts, status mapping and inquiry in `SelcomGateway`; test with fake responses only.
3. Implement callback signature verification and payload mapping in `PaymentWebhookHandler`. Verify reference, amount, currency, provider status and event uniqueness under row locks before changing payment or order state. Record verified events in `payment_events`.
4. Map verified provider success to existing confirmed-payment accounting exactly once. HTTP success and browser returns must never confirm a payment.
5. Configure sandbox values in a non-production `.env` and cover retries, partial balances, wrong amount/currency, invalid signatures, duplicate events and late notifications.
6. Configure the HTTPS callback URL and confirm server egress, callback reachability and operational logs before a low-value production transaction.

## Testing

Use a fake `PaymentGatewayInterface`; tests must not make network calls. The repository PHPUnit bootstrap recreates the separate `chimbo_test` database, never production `chimbo`.
