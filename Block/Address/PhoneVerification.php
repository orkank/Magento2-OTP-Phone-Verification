<?php
namespace IDangerous\PhoneOtpVerification\Block\Address;

use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use IDangerous\PhoneOtpVerification\Helper\Config;
use Magento\Framework\UrlInterface;
use Magento\Customer\Model\Session as CustomerSession;

class PhoneVerification extends Template
{
    /**
     * @var Config
     */
    protected $configHelper;

    /**
     * @var UrlInterface
     */
    protected $urlBuilder;

    /**
     * @var CustomerSession
     */
    private $customerSession;

    /**
     * @param Context $context
     * @param Config $configHelper
     * @param UrlInterface $urlBuilder
     * @param array $data
     */
    public function __construct(
        Context $context,
        Config $configHelper,
        UrlInterface $urlBuilder,
        CustomerSession $customerSession,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->configHelper = $configHelper;
        $this->urlBuilder = $urlBuilder;
        $this->customerSession = $customerSession;
    }

    /**
     * Get configuration for JavaScript
     *
     * @return array
     */
    public function getConfig()
    {
        $customerPhone = '';
        $customerPhoneVerified = false;
        if ($this->customerSession->isLoggedIn()) {
            $customer = $this->customerSession->getCustomer();
            $phoneAttr = $customer->getCustomAttribute('phone_number');
            $verifiedAttr = $customer->getCustomAttribute('phone_verified');
            $customerPhone = $phoneAttr ? (string)$phoneAttr->getValue() : '';
            $customerPhoneVerified = $verifiedAttr ? (bool)$verifiedAttr->getValue() : false;
        }

        return [
            'enabled' => $this->configHelper->isAddressPhoneVerificationEnabled(),
            'sendOtpUrl' => $this->urlBuilder->getUrl('phoneotp/otp/send'),
            'verifyOtpUrl' => $this->urlBuilder->getUrl('phoneotp/otp/verify'),
            'customerPhoneNumber' => $customerPhone,
            'customerPhoneVerified' => $customerPhoneVerified ? 1 : 0,
            'translations' => [
                'sendOtp' => __('Send OTP'),
                'verifyOtp' => __('Verify OTP'),
                'sendingOtp' => __('Sending OTP...'),
                'verifyingOtp' => __('Verifying...'),
                'otpSent' => __('OTP sent successfully'),
                'phoneVerified' => __('Phone number verified'),
                'invalidOtp' => __('Invalid OTP code'),
                'otpExpired' => __('OTP code has expired'),
                'errorSendingOtp' => __('Error sending OTP. Please try again.'),
                'errorVerifyingOtp' => __('Error verifying OTP. Please try again.'),
                'phoneVerificationRequired' => __('Phone number verification is required for this address.'),
                'timeRemaining' => __('Time remaining: ')
            ]
        ];
    }
}
