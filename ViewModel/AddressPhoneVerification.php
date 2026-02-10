<?php
declare(strict_types=1);

namespace IDangerous\PhoneOtpVerification\ViewModel;

use IDangerous\PhoneOtpVerification\Helper\Customer as CustomerHelper;
use Magento\Framework\View\Element\Block\ArgumentInterface;

/**
 * ViewModel for address phone verification display
 */
class AddressPhoneVerification implements ArgumentInterface
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
     * Check if address phone is verified
     *
     * @param int $addressId
     * @return bool
     */
    public function isAddressPhoneVerified($addressId): bool
    {
        return $this->customerHelper->isAddressPhoneVerified($addressId);
    }
}
