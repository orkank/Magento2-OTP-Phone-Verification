# App Developer Guide: Address Phone OTP Verification (GraphQL + REST Checkout)

This guide explains how the mobile app should handle **address phone verification** when:
- **Address Book** uses **GraphQL**
- **Checkout** uses **REST** (Magento checkout APIs)

The goal is to satisfy backend requirements **without relying on browser session**.

---

## 1) Concepts

### When verification is required
If `telephone` is present and none of the skip rules apply, backend requires OTP verification before:
- saving an address (create/update)
- continuing checkout (shipping-information REST call)

### Skip rules (backend auto-allows)
Backend will NOT require OTP if:

1) **Phone matches customer's already verified profile phone**
- Customer has:
  - `customer_entity.phone_number`
  - `customer_entity.phone_verified = 1`
- And the submitted `telephone` matches it (numeric-normalized compare)

2) **Checkout REST payload does not include `customer_address_id`**
Even if the app sends a raw shipping address object (street/city/region_id/etc), backend will skip OTP if:
- customer already has **any verified address** with the same normalized phone

### Stateless bridge token (critical)
OTP verification happens via **GraphQL**, but checkout continues via **REST**.
To “prove verification” across these calls, backend supports a short-lived token:

- Header: `X-Phone-Verification-Token: <token>`
- TTL: ~5 minutes

---

## 2) GraphQL operations (OTP for address/checkout)

### 2.1 Send OTP (address/checkout)

```graphql
mutation SendAddressPhoneOtp($phone: String!) {
  sendAddressPhoneOtp(input: { phone_number: $phone }) {
    success
    message
  }
}
```

Notes:
- This mutation is designed for **address/checkout verification**.
- It **does not block** if the phone number exists/verified on another customer.

### 2.2 Verify OTP and receive a token

```graphql
mutation VerifyAddressPhoneOtp($otp: String!) {
  verifyAddressPhoneOtp(input: { otp_code: $otp }) {
    success
    message
    verification_token
    expires_in
  }
}
```

On success, app receives:
- `verification_token` (string)
- `expires_in` (seconds)

---

## 3) Address Book (GraphQL) integration

### Recommended flow

1) Try address create/update normally (if `telephone` exists).
2) If backend responds with “phone verification required” (LocalizedException message):
   - `sendAddressPhoneOtp(phone)`
   - prompt user for OTP
   - `verifyAddressPhoneOtp(otp)` → get `verification_token`
   - retry the original address create/update mutation with header:
     - `X-Phone-Verification-Token: <verification_token>`

### Header usage (GraphQL)
When retrying the address save GraphQL request, add:

```
X-Phone-Verification-Token: <token>
```

---

## 4) Checkout (REST) integration

Checkout commonly calls Magento REST:
- Logged-in: `POST /rest/<storeCode>/V1/carts/mine/shipping-information`
- Guest: similar guest endpoint based on cartId

### Recommended flow

1) Call REST shipping-information normally.
2) If backend responds with “phone verification required”:
   - run OTP via GraphQL:
     - `sendAddressPhoneOtp(phone)`
     - `verifyAddressPhoneOtp(otp)` → `verification_token`
   - retry the REST shipping-information call with header:

```
X-Phone-Verification-Token: <verification_token>
```

### Important note: no `customer_address_id` in payload
If your app does **not** send `customer_address_id` (only sends city/street/region_id/etc), backend will still allow without OTP **if** the customer already has any verified address with the same normalized phone.

---

## 5) Phone normalization (recommended)

Backend compares phone numbers by removing non-digits.
App should normalize similarly for consistency:
- remove spaces, `+`, `-`, `(`, `)`, etc.
- keep only digits

Example:
- `+90 (555) 123-45-67` → `905551234567` or `5551234567` depending on your app’s storage

---

## 6) Retry strategy

- Treat OTP verification as a **step-up auth**:
  - on verification-required error → run OTP → retry original call once with token
- Token is short-lived; do not cache long-term.

---

## 7) What NOT to do

- Do not rely on browser session cookies between GraphQL and REST in mobile apps.
- Do not block “phone exists on another customer” for address verification (backend already bypasses for address/checkout OTP sends).

