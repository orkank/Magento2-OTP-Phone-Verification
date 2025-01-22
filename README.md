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
* Automatic phone number formatting (removes +90 and leading 0)
* Prevention of duplicate verified phone numbers
* Session-based OTP codes with 3-minute expiry
* Visual countdown timer for OTP expiration
* Multi-language support (en_US, tr_TR)
* Secure storage of verified phone numbers
* Integration with NetGsm SMS gateway

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