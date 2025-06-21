<?php

namespace IDangerous\PhoneOtpVerification\Api;

/**
 * Interface for Phone OTP Management
 * @api
 */
interface PhoneOtpManagementInterface
{
    /**
     * Send OTP to phone number
     *
     * @param string $phoneNumber
     * @return \IDangerous\PhoneOtpVerification\Api\Data\OtpResponseInterface
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function sendOtp($phoneNumber);

    /**
     * Verify OTP code
     *
     * @param string $otpCode
     * @return \IDangerous\PhoneOtpVerification\Api\Data\OtpVerifyResponseInterface
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function verifyOtp($otpCode);

    /**
     * Get current OTP status
     *
     * @return \IDangerous\PhoneOtpVerification\Api\Data\OtpStatusInterface
     */
    public function getOtpStatus();

    /**
     * Get verified phone number from registration session
     *
     * @return string|null
     */
    public function getRegistrationVerifiedPhone();
}