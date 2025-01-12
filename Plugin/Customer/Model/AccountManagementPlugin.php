<?php
namespace IDangerous\PhoneOtpVerification\Plugin\Customer\Model;

use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Model\AccountManagement;

class AccountManagementPlugin
{
    public function beforeSave(
        AccountManagement $subject,
        CustomerInterface $customer,
        $password = null,
        $redirectUrl = ''
    ) {
        if ($phone = $this->getRequest()->getParam('phone')) {
            $customer->setCustomAttribute('phone_number', $phone);
        }
        if ($phoneVerified = $this->getRequest()->getParam('phone_verified')) {
            $customer->setCustomAttribute('phone_verified', $phoneVerified);
        }
        return [$customer, $password, $redirectUrl];
    }
}