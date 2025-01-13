<?php
namespace IDangerous\PhoneOtpVerification\Plugin\Customer;

use Magento\Customer\Api\AccountManagementInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Model\Session;
use IDangerous\PhoneOtpVerification\Helper\Config;

class AccountManagement
{
    protected $session;
    protected $config;

    public function __construct(
        Session $session,
        Config $config
    ) {
        $this->session = $session;
        $this->config = $config;
    }

    public function beforeCreateAccount(
        AccountManagementInterface $subject,
        CustomerInterface $customer,
        $password = null,
        $redirectUrl = ''
    ) {
        if ($this->config->isEnabledForRegistration()) {
            $verifiedPhone = $this->session->getRegistrationVerifiedPhone();

            if ($verifiedPhone) {
                // Set the verified phone number
                $customer->setCustomAttribute('phone_number', $verifiedPhone);
                $customer->setCustomAttribute('phone_verified', 1);

                // Clear the session data
                $this->session->unsRegistrationVerifiedPhone();
            } elseif (!$this->config->isOptionalForRegistration()) {
                throw new \Magento\Framework\Exception\LocalizedException(
                    __('Phone verification is required.')
                );
            }
        }

        return [$customer, $password, $redirectUrl];
    }
}