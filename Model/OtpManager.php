<?php

namespace IDangerous\PhoneOtpVerification\Model;

use Magento\Customer\Model\Session;
use Magento\Framework\Exception\LocalizedException;
use IDangerous\Sms\Model\Api\SmsService;
use Magento\Customer\Model\ResourceModel\Customer\CollectionFactory;

class OtpManager
{
    /**
     * @var Session
     */
    protected $session;

    /**
     * @var SmsService
     */
    protected $smsService;

    /**
     * @var CollectionFactory
     */
    protected $customerCollectionFactory;

    /**
     * @param SmsService $smsService
     * @param Session $session
     * @param CollectionFactory $customerCollectionFactory
     */
    public function __construct(
        SmsService $smsService,
        Session $session,
        CollectionFactory $customerCollectionFactory
    ) {
        $this->smsService = $smsService;
        $this->session = $session;
        $this->customerCollectionFactory = $customerCollectionFactory;
    }

    protected function isPhoneAvailable($phone)
    {
        // Skip validation for logged-in users updating their own number
        if ($this->session->isLoggedIn()) {
            $currentCustomer = $this->session->getCustomer();
            $currentPhone = $currentCustomer->getCustomAttribute('phone_number');
            if ($currentPhone && $currentPhone->getValue() === $phone) {
                return true;
            }
        }

        $collection = $this->customerCollectionFactory->create();
        $collection->addAttributeToSelect('phone_number')
                  ->addAttributeToSelect('phone_verified')
                  ->addAttributeToFilter('phone_number', $phone)
                  ->addAttributeToFilter('phone_verified', 1);

        return $collection->getSize() === 0;
    }

    public function sendOtp($phone)
    {
        try {
            // Check if phone is available (only for non-logged in users or different number)
            if (!$this->isPhoneAvailable($phone)) {
                throw new LocalizedException(
                    __('This phone number is already registered and verified by another user.')
                );
            }

            $otp = $this->generateOtp();
            $message = "Your verification code is: " . $otp;

            // Store both OTP and phone number in session
            $this->session->setPhoneOtp([
                'code' => $otp,
                'phone' => $phone,
                'timestamp' => time()
            ]);

            $result = $this->smsService->sendOtpSms($phone, $message);

            if (!$result['success']) {
                throw new LocalizedException(__($result['message']));
            }

            return $otp;
        } catch (\Exception $e) {
            throw new LocalizedException(__('Failed to send OTP: %1', $e->getMessage()));
        }
    }

    public function verifyOtp($inputOtp)
    {
        $otpData = $this->session->getPhoneOtp();

        if (!$otpData || !isset($otpData['code']) || !isset($otpData['phone'])) {
            return false;
        }

        // Check if OTP is expired (5 minutes validity)
        if (time() - $otpData['timestamp'] > 300) {
            $this->session->unsPhoneOtp();
            return false;
        }

        // Convert both to strings and trim for comparison
        $storedOtp = trim((string)$otpData['code']);
        $inputOtp = trim((string)$inputOtp);

        if ($storedOtp === $inputOtp) {
            // Keep the OTP data in session until verification is complete
            // It will be used by the Verify controller to get the phone number
            return true;
        }

        return false;
    }

    protected function generateOtp()
    {
        return str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }
}