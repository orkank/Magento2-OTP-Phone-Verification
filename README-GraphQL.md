# GraphQL API for Phone OTP Verification

This document describes the GraphQL API endpoints added to the Phone OTP Verification module.

## Available Mutations

### 1. Send Phone OTP

Send an OTP code to a phone number for verification.

**Mutation:**
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

**Input:**
- `phone_number` (String, required): The phone number to send OTP to

**Response:**
- `success` (Boolean): Whether the OTP was sent successfully
- `message` (String): Response message

### 2. Verify Phone OTP

Verify an OTP code for phone number verification.

**Mutation:**
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

**Input:**
- `otp_code` (String, required): The OTP code to verify

**Response:**
- `success` (Boolean): Whether the verification was successful
- `message` (String): Response message
- `phone_verified` (Boolean): Whether the phone number is now verified
- `customer_updated` (Boolean): Whether customer data was updated (for logged users)

### 3. Create Customer with Phone Verification

Create a customer account with phone verification support.

**Mutation:**
```graphql
mutation {
  createCustomerWithPhone(input: {
    firstname: "John"
    lastname: "Doe"
    email: "john.doe@example.com"
    password: "MyPassword123!"
    phone_number: "5551234567"
  }) {
    success
    message
    customer {
      id
      firstname
      lastname
      email
      phone_number
      phone_verified
    }
  }
}
```

**Input:**
- `firstname` (String, required): Customer's first name
- `lastname` (String, required): Customer's last name
- `email` (String, required): Customer's email address
- `password` (String, required): Customer's password
- `phone_number` (String, optional): Phone number (must be verified first if provided)

**Response:**
- `success` (Boolean): Whether customer creation was successful
- `message` (String): Response message
- `customer` (Object): Created customer data with phone attributes

## Available Queries

### 1. Phone OTP Status

Get the current phone OTP verification status.

**Query:**
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

**Response:**
- `has_pending_otp` (Boolean): Whether there's a pending OTP verification
- `phone_number` (String): Phone number for pending verification
- `time_remaining` (Int): Time remaining for OTP in seconds
- `is_expired` (Boolean): Whether the current OTP has expired

### 2. Customer with Phone Attributes

Get customer information including phone number and verification status.

**Query:**
```graphql
query {
  customer {
    id
    firstname
    lastname
    email
    phone_number
    phone_verified
  }
}
```

**Response:**
- `phone_number` (String): Customer's phone number
- `phone_verified` (Boolean): Whether the phone number is verified

## Usage Scenarios

### For Registration Flow

1. **Send OTP before registration:**
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

2. **Verify OTP:**
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

3. **Create customer account with verified phone using standard createCustomer mutation:**
```graphql
mutation {
  createCustomer(input: {
    firstname: "John"
    lastname: "Doe"
    email: "john.doe@example.com"
    password: "MyPassword123!"
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

**Alternative (verified phone from session):**
```graphql
mutation {
  createCustomer(input: {
    firstname: "John"
    lastname: "Doe"
    email: "john.doe@example.com"
    password: "MyPassword123!"
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

### For Logged User Flow

1. **Send OTP to update phone number:**
```graphql
# User must be logged in (provide Authorization Bearer token)
mutation {
  sendPhoneOtp(input: {
    phone_number: "5559876543"
  }) {
    success
    message
  }
}
```

2. **Verify OTP and update customer:**
```graphql
# User must be logged in (provide Authorization Bearer token)
mutation {
  verifyPhoneOtp(input: {
    otp_code: "654321"
  }) {
    success
    message
    phone_verified
    customer_updated
  }
}
```

## Error Handling

The API will return appropriate error messages for various scenarios:

- Invalid phone number format
- Phone number already verified by another user
- Invalid or expired OTP code
- Missing required parameters
- SMS service errors

## Authentication

- **For Registration Flow**: No authentication required
- **For Logged User Flow**: Customer authentication token required in the Authorization header

## Phone Number Format

The API automatically normalizes phone numbers by:
- Removing non-numeric characters (except +)
- Removing +90 country code if present
- Removing leading 0 if present

Examples:
- `+905551234567` → `5551234567`
- `05551234567` → `5551234567`
- `555-123-4567` → `5551234567`

## Rate Limiting

- OTP codes expire after 5 minutes (300 seconds)
- Only one active OTP per session
- New OTP requests will override previous ones

## Dependencies

This GraphQL API requires:
- `IDangerous_Sms` module (for SMS sending)
- `IDangerous_NetgsmIYS` module (for IYS compliance)
- `Magento_CustomerGraphQl` module
- `Magento_GraphQl` module

## Logging

All GraphQL operations are logged for debugging purposes:
- OTP send attempts
- OTP verification attempts
- Error conditions

Check `var/log/system.log` for detailed logs.