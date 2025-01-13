<?php
namespace IDangerous\PhoneOtpVerification\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Store\Model\ScopeInterface;

class Config extends AbstractHelper
{
    const XML_PATH_ENABLED = 'phone_otp/general/enabled';
    const XML_PATH_ENABLE_REGISTRATION = 'phone_otp/general/enable_registration';
    const XML_PATH_OPTIONAL_REGISTRATION = 'phone_otp/general/optional_registration';
    const XML_PATH_OTP_MESSAGE = 'phone_otp/general/otp_message';

    public function isEnabled($store = null)
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $store
        );
    }

    public function isEnabledForRegistration($store = null)
    {
        return $this->isEnabled($store) && $this->scopeConfig->isSetFlag(
            self::XML_PATH_ENABLE_REGISTRATION,
            ScopeInterface::SCOPE_STORE,
            $store
        );
    }

    public function isOptionalForRegistration($store = null)
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_OPTIONAL_REGISTRATION,
            ScopeInterface::SCOPE_STORE,
            $store
        );
    }

    public function getOtpMessage($store = null)
    {
        $message = $this->scopeConfig->getValue(
            self::XML_PATH_OTP_MESSAGE,
            ScopeInterface::SCOPE_STORE,
            $store
        );
        return $message ?: 'Your verification code is: {otp}';
    }
}