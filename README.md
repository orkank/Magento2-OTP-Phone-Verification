# Phone OTP Verification for Magento 2

This module provides phone number verification functionality using OTP (One-Time Password) for Magento 2. It supports both customer registration and account management, ensuring phone numbers are verified before being saved.

## Important Note

This module is specifically designed to work with NetGsm SMS services. It requires a valid NetGsm account and credentials. If you're using a different SMS provider, you'll need to modify the SMS sending logic.

## Requirements

* Magento 2.3.x or higher
* PHP 7.4 or higher
* Valid NetGsm Account
* NetGsm API Credentials
* NetGsm IYS (îleti Yönetim Sistemi) Access

## Features

* Phone number verification using OTP
* Support for both registration and account management
* **Address phone verification (My Account Address Book + Checkout)**
* **GraphQL API support for headless/PWA applications**
* Automatic phone number formatting (removes +90 and leading 0)
* Prevention of duplicate verified phone numbers (registration/account flows)
* Session-based OTP codes with 3-minute expiry
* Visual countdown timer for OTP expiration
* Multi-language support (en_US, tr_TR)
* Secure storage of verified phone numbers
* Integration with NetGsm SMS gateway
* Verification indicator ("Verified / Telefon Doğrulandı") on address views (Address Book, Dashboard, Checkout)

## Required Dependencies

This module requires the following NetGsm modules to be installed first:

### 1. NetGsm SMS Module
* Repository: [Netgsm-SMS-module](https://github.com/orkank/Netgsm-SMS-module)
* Features:
  * SMS sending functionality
  * Bulk SMS support
  * SMS status tracking
  * Detailed reporting

### 2. NetGsm IYS Module
* Repository: [Netgsm-IYS-module](https://github.com/orkank/Netgsm-IYS-module)
* Features:
  * IYS (İleti Yönetim Sistemi) integration
  * Permission management
  * Customer consent tracking
  * Regulatory compliance

## Installation

1. First, install the required NetGsm modules:

```bash
# Install NetGsm IYS Module
mkdir -p app/code/IDangerous/NetgsmIYS
# Clone Netgsm-IYS-module from GitHub

# Install NetGsm SMS Module
mdkir -p app/code/IDangerous/Sms
# Clone Netgsm-SMS-module from GitHub
```

2. Install the Phone OTP Verification module:

```bash
mkdir -p app/code/IDangerous/PhoneOtpVerification
# Copy module files

php bin/magento module:enable IDangerous_NetgsmIYS
php bin/magento module:enable IDangerous_Sms
php bin/magento module:enable IDangerous_PhoneOtpVerification
php bin/magento setup:upgrade
php bin/magento setup:di:compile
php bin/magento setup:static-content:deploy -f
php bin/magento cache:clean
```

## Configuration

1. Configure NetGsm credentials in:
   * Admin > Stores > Configuration > IDangerous > SMS Settings
   * Admin > Stores > Configuration > IDangerous > IYS Settings

2. Phone verification settings:
   * Optional/Required verification
   * OTP message template
   * Phone number format settings

3. Address phone verification settings:
   * Admin > Stores > Configuration > IDangerous > Phone OTP Verification > Address
     * **Require Phone Verification for New Addresses**
     * **Require Verification for Unverified Existing Addresses**

## Address Phone Verification (My Account + Checkout)

When enabled, customers must verify phone numbers used on addresses (if telephone is present):

* **My Account > Address Book**:
  * On Save, if verification is required, the form submission is paused and an OTP modal opens.
  * OTP is **auto-sent** when the modal opens.
  * After successful verification, the address is saved and marked as verified.
* **Checkout**:
  * If the selected shipping/billing address has an unverified phone, an OTP modal opens automatically.
  * After successful verification, the checkout request is retried and the user can continue.

**Important rules:**
* If the address telephone matches the customer's already verified profile phone (`customer_entity.phone_number` and `customer_entity.phone_verified=1`), no additional verification is required.
* Existing addresses created before enabling this feature are supported; unverified ones can be forced to verify via config.
* Address telephone numbers may be used by multiple customers; the "phone already used by another verified customer" restriction is bypassed for **address/checkout** OTP sends.

## Database / Storage

Customer profile phone verification uses:
* `customer_entity.phone_number`
* `customer_entity.phone_verified`

Address phone verification status is stored in a separate table to avoid Quote/Checkout interface conflicts:
* `idangerous_address_phone_verification`
  * `address_id` (PK)
  * `is_verified`
  * `verified_at`
  * `verified_ip`

## Proxy / Cloudflare real IP

If your store is behind Cloudflare or a reverse proxy, the module is configured to prefer real client IP headers when storing `verified_ip`:
* `CF-Connecting-IP` (`HTTP_CF_CONNECTING_IP`)
* `X-Forwarded-For` (`HTTP_X_FORWARDED_FOR`)
* `X-Real-IP` (`HTTP_X_REAL_IP`)

For security, ensure your origin is not directly reachable from the public internet (only via your proxy/CDN), otherwise these headers can be spoofed.

## GraphQL API

This module now includes comprehensive GraphQL API support for headless/PWA applications.

### Available GraphQL Operations

* **Mutations:**
  * `sendPhoneOtp` - Send OTP to phone number
  * `verifyPhoneOtp` - Verify OTP code
  * `sendAddressPhoneOtp` - Send OTP for address/checkout verification (skips duplicate-phone restriction)
  * `verifyAddressPhoneOtp` - Verify OTP and receive `verification_token` to bridge to REST/GraphQL address save

* **Queries:**
  * `phoneOtpStatus` - Get current OTP verification status

### Mobile App Integration (GraphQL Address Book + REST Checkout)

This module supports apps that use **GraphQL for address book** and **REST for checkout**.

**Key points:**
* Address verification is **per phone** and stored on addresses separately (`idangerous_address_phone_verification`).
* If the phone matches the customer's already verified profile phone (`customer_entity.phone_number` + `phone_verified=1`), verification is not required.
* If checkout REST payload does not include `customer_address_id`, the backend will skip verification when the customer already has **any verified address with the same phone**.
* To bridge stateless OTP verification to REST checkout, use `X-Phone-Verification-Token` header (short-lived token).

#### App flow: verify then retry with token

1) When backend requires verification (address save or `/V1/carts/mine/shipping-information` fails with our \"verification required\" message):\n
- GraphQL: `sendAddressPhoneOtp(phone_number)`\n
- User enters OTP\n
- GraphQL: `verifyAddressPhoneOtp(otp_code)` → returns `verification_token` + `expires_in`\n
\n
2) Retry the original request with header:\n
- `X-Phone-Verification-Token: <verification_token>`\n
\n
**Where to send the header:**\n
- GraphQL address create/update mutation request\n
- REST checkout shipping-information request\n

### Usage Examples

**Send OTP:**
```graphql
mutation {
  sendPhoneOtp(input: {
    phone_number: "5551234567"
  }) {
    success
    message
  }
}
```

**Verify OTP:**
```graphql
mutation {
  verifyPhoneOtp(input: {
    otp_code: "123456"
  }) {
    success
    message
    phone_verified
    customer_updated
  }
}
```

**Create Customer with Verified Phone:**
```graphql
mutation {
  createCustomer(input: {
    firstname: "John"
    lastname: "Doe"
    email: "john@example.com"
    password: "Pass123!"
    custom_attributes: [
      {
        attribute_code: "phone_number"
        value: "5551234567"
      }
    ]
  }) {
    customer {
      id
      firstname
      lastname
      email
      custom_attributes {
        attribute_code
        value
      }
    }
  }
}
```

**Check OTP Status:**
```graphql
query {
  phoneOtpStatus {
    has_pending_otp
    phone_number
    time_remaining
    is_expired
  }
}
```

For detailed GraphQL API documentation, see [README-GraphQL.md](README-GraphQL.md).

## Features

### Registration Flow
* Phone verification before account creation
* Prevention of duplicate verified numbers
* Automatic formatting of phone numbers
* 3-minute OTP expiration
* Visual countdown timer

### Account Management
* Phone verification for existing customers
* Update phone number with verification
* Maintain verification status

### Address Book & Checkout (Address Phone Verification)
* Address phone verification during address create/edit (modal-based flow)
* Checkout auto modal + auto OTP send for unverified selected addresses
* No re-verification if address phone matches customer's already verified profile phone
* Address verification status stored with `verified_at` and `verified_ip`

### Security Features
* Session-based OTP storage
* Timed expiration of verification codes
* Prevention of duplicate verified numbers
* Rate limiting for OTP requests

## Translations

The module includes translations for:
* English (en_US)
* Turkish (tr_TR)

Generate translation files:
```bash
php bin/magento i18n:collect-phrases -o "app/code/IDangerous/PhoneOtpVerification/i18n/dictionary.csv" app/code/IDangerous/PhoneOtpVerification
```

## Support

For technical support:
1. Check the logs at `var/log/system.log`
2. Verify NetGsm credentials
3. Ensure proper module configuration
4. Check phone number formatting
5. Verify NetGsm service status

## License

This software is licensed under a Custom License.

### Non-Commercial Use
- This software is free for non-commercial use
- You may copy, distribute and modify the software as long as you track changes/dates in source files
- Any modifications must be made available under the same license terms

### Commercial Use
- Commercial use of this software requires explicit permission from the author
- Please contact [Orkan Köylü](orkan.koylu@gmail.com) for commercial licensing inquiries
- Usage without proper licensing agreement is strictly prohibited

Copyright (c) 2024 Orkan Köylü. All Rights Reserved.

[Developer: Orkan Köylü](orkan.koylu@gmail.com)