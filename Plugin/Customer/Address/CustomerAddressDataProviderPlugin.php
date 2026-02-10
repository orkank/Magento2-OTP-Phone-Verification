<?php
declare(strict_types=1);

namespace IDangerous\PhoneOtpVerification\Plugin\Customer\Address;

use IDangerous\PhoneOtpVerification\Helper\Customer as CustomerHelper;
use Magento\Customer\Model\Address\CustomerAddressDataFormatter;
use Magento\Customer\Api\Data\AddressInterface;

/**
 * Plugin to add phone_verified information to customer address data
 */
class CustomerAddressDataProviderPlugin
{
    /**
     * @var CustomerHelper
     */
    private $customerHelper;

    /**
     * @param CustomerHelper $customerHelper
     */
    public function __construct(
        CustomerHelper $customerHelper
    ) {
        $this->customerHelper = $customerHelper;
    }

    /**
     * Add phone_verified to address data (lowercase only for customer addresses)
     *
     * @param CustomerAddressDataFormatter $subject
     * @param array $result
     * @param AddressInterface $customerAddress
     * @return array
     */
    public function afterPrepareAddress(
        CustomerAddressDataFormatter $subject,
        array $result,
        AddressInterface $customerAddress
    ): array {
        // Remove any PhoneVerified with capital P that might have been added
        unset($result['PhoneVerified'], $result['phoneVerified']);
        
        if ($customerAddress->getId()) {
            $addressId = (int)$customerAddress->getId();
            try {
                $isPhoneVerified = $this->customerHelper->isAddressPhoneVerified($addressId);
                // Use lowercase only
                $result['phone_verified'] = $isPhoneVerified ? 1 : 0;
            } catch (\Exception $e) {
                $result['phone_verified'] = 0;
            }
        } else {
            $result['phone_verified'] = 0;
        }

        return $result;
    }
}
