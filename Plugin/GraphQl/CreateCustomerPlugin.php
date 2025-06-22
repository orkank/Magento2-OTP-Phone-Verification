<?php

namespace IDangerous\PhoneOtpVerification\Plugin\GraphQl;

use Magento\CustomerGraphQl\Model\Customer\CreateCustomerAccount;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Customer\Model\Session;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Framework\App\CacheInterface;
use Psr\Log\LoggerInterface;

class CreateCustomerPlugin
{
    /**
     * @var Session
     */
    private $customerSession;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var CacheInterface
     */
    private $cache;

    /**
     * @param Session $customerSession
     * @param LoggerInterface $logger
     * @param CacheInterface $cache
     */
    public function __construct(
        Session $customerSession,
        LoggerInterface $logger,
        CacheInterface $cache
    ) {
        $this->customerSession = $customerSession;
        $this->logger = $logger;
        $this->cache = $cache;
    }

    /**
     * Before customer creation, validate phone verification if phone number is provided
     *
     * @param CreateCustomerAccount $subject
     * @param array $customerData
     * @param StoreInterface $store
     * @return array
     * @throws GraphQlInputException
     */
    public function beforeExecute(
        CreateCustomerAccount $subject,
        array $customerData,
        StoreInterface $store
    ): array {
        $this->logger->info('GraphQL CreateCustomer: Customer data received: ' . json_encode($customerData));

        $phoneNumber = null;

                // Check if phone_number is provided in customer data (direct field - our extension)
        if (isset($customerData['phone_number']) && !empty($customerData['phone_number'])) {
            $phoneNumber = $customerData['phone_number'];
        }

        // Also check if phone_number is provided in custom_attributes (fallback)
        if (!$phoneNumber && isset($customerData['custom_attributes'])) {
            foreach ($customerData['custom_attributes'] as $attribute) {
                if (isset($attribute['attribute_code']) && $attribute['attribute_code'] === 'phone_number') {
                    $phoneNumber = $attribute['value'];
                    break;
                }
            }
        }

                if ($phoneNumber) {
            $normalizedPhoneNumber = $this->normalizePhoneNumber($phoneNumber);
            $verifiedPhone = $this->customerSession->getRegistrationVerifiedPhone();

            $this->logger->info('GraphQL CreateCustomer: Phone number provided: ' . $phoneNumber);
            $this->logger->info('GraphQL CreateCustomer: Normalized phone: ' . $normalizedPhoneNumber);
            $this->logger->info('GraphQL CreateCustomer: Verified phone in session: ' . ($verifiedPhone ?: 'none'));

            // Check phone verification with multiple methods
            $phoneVerified = false;

            // Method 1: Check session
            if ($verifiedPhone && $this->normalizePhoneNumber($verifiedPhone) === $normalizedPhoneNumber) {
                $phoneVerified = true;
                $this->logger->info('GraphQL CreateCustomer: Phone verified via session');
            }

            // Method 2: Check cache with original phone number
            if (!$phoneVerified) {
                $cacheKey1 = 'verified_phone_' . md5($phoneNumber);
                $cachedData = $this->cache->load($cacheKey1);
                if ($cachedData) {
                    $verificationData = json_decode($cachedData, true);
                    if (isset($verificationData['verified']) && $verificationData['verified'] &&
                        isset($verificationData['timestamp']) && (time() - $verificationData['timestamp']) < 600) {
                        $phoneVerified = true;
                        $this->logger->info('GraphQL CreateCustomer: Phone verified via cache (original): ' . $cacheKey1);
                    }
                }
            }

            // Method 3: Check cache with normalized phone number
            if (!$phoneVerified) {
                $cacheKey2 = 'verified_phone_' . md5($normalizedPhoneNumber);
                $cachedData = $this->cache->load($cacheKey2);
                if ($cachedData) {
                    $verificationData = json_decode($cachedData, true);
                    if (isset($verificationData['verified']) && $verificationData['verified'] &&
                        isset($verificationData['timestamp']) && (time() - $verificationData['timestamp']) < 600) {
                        $phoneVerified = true;
                        $this->logger->info('GraphQL CreateCustomer: Phone verified via cache (normalized): ' . $cacheKey2);
                    }
                }
            }

            if (!$phoneVerified) {
                $this->logger->error('GraphQL CreateCustomer: Phone verification failed - not found in session or cache');
                $this->logger->error('GraphQL CreateCustomer: Checked session: ' . ($verifiedPhone ?: 'none'));
                $this->logger->error('GraphQL CreateCustomer: Checked cache keys: verified_phone_' . md5($phoneNumber) . ', verified_phone_' . md5($normalizedPhoneNumber));
                throw new GraphQlInputException(
                    __('Phone number must be verified before registration. Please send and verify OTP first.')
                );
            }

            // Ensure phone attributes are set correctly
            $customerData['phone_number'] = $phoneNumber;
            $customerData['phone_verified'] = 1;

            $this->logger->info('GraphQL CreateCustomer: Setting phone_number: ' . $phoneNumber);
            $this->logger->info('GraphQL CreateCustomer: Setting phone_verified: 1');

            // Also add to custom_attributes if not already there
            if (!isset($customerData['custom_attributes'])) {
                $customerData['custom_attributes'] = [];
            }

            // Add phone_number custom attribute
            $phoneAttrExists = false;
            foreach ($customerData['custom_attributes'] as &$attribute) {
                if (isset($attribute['attribute_code']) && $attribute['attribute_code'] === 'phone_number') {
                    $attribute['value'] = $phoneNumber;
                    $phoneAttrExists = true;
                    $this->logger->info('GraphQL CreateCustomer: Updated existing phone_number custom attribute');
                    break;
                }
            }
            if (!$phoneAttrExists) {
                $customerData['custom_attributes'][] = [
                    'attribute_code' => 'phone_number',
                    'value' => $phoneNumber
                ];
                $this->logger->info('GraphQL CreateCustomer: Added new phone_number custom attribute');
            }

            // Add phone_verified custom attribute
            $verifiedAttrExists = false;
            foreach ($customerData['custom_attributes'] as &$attribute) {
                if (isset($attribute['attribute_code']) && $attribute['attribute_code'] === 'phone_verified') {
                    $attribute['value'] = 1;
                    $verifiedAttrExists = true;
                    $this->logger->info('GraphQL CreateCustomer: Updated existing phone_verified custom attribute to 1');
                    break;
                }
            }
            if (!$verifiedAttrExists) {
                $customerData['custom_attributes'][] = [
                    'attribute_code' => 'phone_verified',
                    'value' => 1
                ];
                $this->logger->info('GraphQL CreateCustomer: Added new phone_verified custom attribute set to 1');
            }

            $this->logger->info('GraphQL CreateCustomer: Phone verification validated for registration');
            $this->logger->info('GraphQL CreateCustomer: Final customer data: ' . json_encode($customerData));
        } else {
            // Check if we have a verified phone in session (for cases where phone_number isn't passed)
            $verifiedPhone = $this->customerSession->getRegistrationVerifiedPhone();
            if ($verifiedPhone) {
                $customerData['phone_number'] = $verifiedPhone;
                $customerData['phone_verified'] = 1;

                if (!isset($customerData['custom_attributes'])) {
                    $customerData['custom_attributes'] = [];
                }

                $customerData['custom_attributes'][] = [
                    'attribute_code' => 'phone_number',
                    'value' => $verifiedPhone
                ];
                $customerData['custom_attributes'][] = [
                    'attribute_code' => 'phone_verified',
                    'value' => 1
                ];

                $this->logger->info('GraphQL CreateCustomer: Adding verified phone from session: ' . $verifiedPhone);
            }
        }

        return [$customerData, $store];
    }

    /**
     * After customer creation, clean up the registration verified phone from session
     *
     * @param CreateCustomerAccount $subject
     * @param \Magento\Customer\Api\Data\CustomerInterface $result
     * @return \Magento\Customer\Api\Data\CustomerInterface
     */
    public function afterExecute(
        CreateCustomerAccount $subject,
        \Magento\Customer\Api\Data\CustomerInterface $result
    ) {
        // Clean up the registration verified phone from session after successful registration
        $this->customerSession->unsRegistrationVerifiedPhone();
        $this->logger->info('GraphQL CreateCustomer: Registration verified phone cleaned from session');

        return $result;
    }

    /**
     * Normalize phone number by removing country code and leading zero
     *
     * @param string $phoneNumber
     * @return string
     */
    private function normalizePhoneNumber($phoneNumber)
    {
        // Remove all non-numeric characters except +
        $phoneNumber = preg_replace('/[^0-9+]/', '', $phoneNumber);

        // Remove +90 country code if present
        if (strpos($phoneNumber, '+90') === 0) {
            $phoneNumber = substr($phoneNumber, 3);
        }

        // Remove leading 0 if present
        if (strpos($phoneNumber, '0') === 0) {
            $phoneNumber = substr($phoneNumber, 1);
        }

        return $phoneNumber;
    }
}