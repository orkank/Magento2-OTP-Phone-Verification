<?php

namespace IDangerous\PhoneOtpVerification\Model;

use IDangerous\Sms\Model\Api\SmsService;
use Magento\Customer\Model\Session;
use Magento\Framework\Exception\LocalizedException;

class OtpManager
{
    /**
     * @var SmsService
     */
    protected $smsService;

    /**
     * @var Session
     */
    protected $session;

    /**
     * @param SmsService $smsService
     * @param Session $session
     */
    public function __construct(
        SmsService $smsService,
        Session $session
    ) {
        $this->smsService = $smsService;
        $this->session = $session;
    }

    /**
     * Send OTP to phone number
     *
     * @param string $phone
     * @return string
     * @throws LocalizedException
     */
    public function sendOtp($phone)
    {
        try {
            $otp = $this->generateOtp();
            $message = "Your OTP code is: " . $otp;

            // Store both OTP and phone number in session
            $this->session->setPhoneOtp([
                'code' => $otp,
                'phone' => $phone,
                'timestamp' => time()
            ]);

            // Also store phone separately for verification
            $this->session->setVerifiedPhoneData([
                'phone' => $phone,
                'timestamp' => time()
            ]);

            $this->smsService->sendOtpSms($phone, $message);
            return $otp;
        } catch (\Exception $e) {
            throw new LocalizedException(__('Failed to send OTP: %1', $e->getMessage()));
        }
    }

    /**
     * Verify OTP code
     *
     * @param string $inputOtp
     * @return bool
     */
    public function verifyOtp($inputOtp)
    {
        $otpData = $this->session->getPhoneOtp();

        if (!$otpData || !isset($otpData['code'])) {
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
            // Keep the verified phone data in session
            // The phone data is already stored in verifiedPhoneData from sendOtp

            // Clear only the OTP data
            $this->session->unsPhoneOtp();
            return true;
        }

        return false;
    }

    /**
     * Generate 6-digit OTP
     *
     * @return string
     */
    protected function generateOtp()
    {
        return str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }
}