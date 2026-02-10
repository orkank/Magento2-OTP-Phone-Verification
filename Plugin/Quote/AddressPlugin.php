<?php
declare(strict_types=1);

namespace IDangerous\PhoneOtpVerification\Plugin\Quote;

use Magento\Quote\Model\Quote\Address;
use Magento\Customer\Api\Data\AddressInterface;
use Psr\Log\LoggerInterface;

/**
 * Plugin to prevent phoneVerified from being added to Quote Address
 */
class AddressPlugin
{
    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param LoggerInterface $logger
     */
    public function __construct(
        LoggerInterface $logger
    ) {
        $this->logger = $logger;
    }
    /**
     * Remove phoneVerified from Quote Address after importing customer address data
     *
     * @param Address $subject
     * @param Address $result
     * @param AddressInterface $address
     * @return Address
     */
    public function afterImportCustomerAddressData(
        Address $subject,
        Address $result,
        AddressInterface $address
    ): Address {
        $this->logger->info('QuoteAddressPlugin::afterImportCustomerAddressData - START', [
            'address_id' => $address->getId(),
            'has_phone_verified' => $result->hasData('phone_verified'),
            'has_phoneVerified' => $result->hasData('phoneVerified'),
            'has_PhoneVerified' => $result->hasData('PhoneVerified'),
        ]);
        
        // Remove phoneVerified if it was copied from customer address
        // Quote Address Interface doesn't have this property
        if ($result->hasData('phone_verified')) {
            $result->unsetData('phone_verified');
            $this->logger->info('QuoteAddressPlugin: Removed phone_verified');
        }
        if ($result->hasData('phoneVerified')) {
            $result->unsetData('phoneVerified');
            $this->logger->info('QuoteAddressPlugin: Removed phoneVerified');
        }
        if ($result->hasData('PhoneVerified')) {
            $result->unsetData('PhoneVerified');
            $this->logger->info('QuoteAddressPlugin: Removed PhoneVerified');
        }
        
        return $result;
    }

    /**
     * Remove phoneVerified before converting to array
     *
     * @param Address $subject
     * @param array $result
     * @return array
     */
    public function afterToArray(
        Address $subject,
        array $result
    ): array {
        $hasPhoneVerified = isset($result['phone_verified']) || isset($result['phoneVerified']) || isset($result['PhoneVerified']);
        
        if ($hasPhoneVerified) {
            $this->logger->info('QuoteAddressPlugin::afterToArray - Found phoneVerified in array', [
                'keys' => array_keys($result),
                'phone_verified' => $result['phone_verified'] ?? null,
                'phoneVerified' => $result['phoneVerified'] ?? null,
                'PhoneVerified' => $result['PhoneVerified'] ?? null,
            ]);
        }
        
        // Remove phoneVerified from array to prevent WebAPI errors
        unset($result['phone_verified'], $result['phoneVerified'], $result['PhoneVerified']);
        
        return $result;
    }

    /**
     * Remove phoneVerified before setting data
     *
     * @param Address $subject
     * @param string|array $key
     * @param mixed $value
     * @return array
     */
    public function beforeSetData(
        Address $subject,
        $key,
        $value = null
    ): array {
        // If setting phone_verified or phoneVerified, return empty key to skip
        if (is_string($key) && (strtolower($key) === 'phone_verified' || strtolower($key) === 'phoneverified')) {
            // Return a dummy key that won't cause issues
            return ['_skip_phone_verified', null];
        }
        
        // If setting data as array, remove phone_verified
        if (is_array($key)) {
            unset($key['phone_verified'], $key['phoneVerified'], $key['PhoneVerified']);
        }
        
        return [$key, $value];
    }

    /**
     * Remove phoneVerified after getData to prevent WebAPI serialization issues
     *
     * @param Address $subject
     * @param mixed $result
     * @param string|null $key
     * @return mixed
     */
    public function afterGetData(
        Address $subject,
        $result,
        $key = null
    ) {
        // If getting all data as array, remove phone_verified
        if ($key === null && is_array($result)) {
            $hasPhoneVerified = isset($result['phone_verified']) || isset($result['phoneVerified']) || isset($result['PhoneVerified']);
            
            if ($hasPhoneVerified) {
                $this->logger->info('QuoteAddressPlugin::afterGetData - Found phoneVerified', [
                    'phone_verified' => $result['phone_verified'] ?? null,
                    'phoneVerified' => $result['phoneVerified'] ?? null,
                    'PhoneVerified' => $result['PhoneVerified'] ?? null,
                ]);
            }
            
            unset($result['phone_verified'], $result['phoneVerified'], $result['PhoneVerified']);
        }
        
        // If specifically requesting phone_verified, return null
        if ($key !== null && (strtolower($key) === 'phone_verified' || strtolower($key) === 'phoneverified')) {
            $this->logger->info('QuoteAddressPlugin::afterGetData - Blocking phone_verified request', [
                'key' => $key
            ]);
            return null;
        }
        
        return $result;
    }
}
