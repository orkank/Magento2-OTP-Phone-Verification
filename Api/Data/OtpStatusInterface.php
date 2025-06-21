<?php

namespace IDangerous\PhoneOtpVerification\Api\Data;

/**
 * Interface for OTP Status Data
 * @api
 */
interface OtpStatusInterface
{
    /**
     * Get has pending OTP status
     *
     * @return bool
     */
    public function getHasPendingOtp();

    /**
     * Set has pending OTP status
     *
     * @param bool $hasPendingOtp
     * @return $this
     */
    public function setHasPendingOtp($hasPendingOtp);

    /**
     * Get phone number
     *
     * @return string|null
     */
    public function getPhoneNumber();

    /**
     * Set phone number
     *
     * @param string|null $phoneNumber
     * @return $this
     */
    public function setPhoneNumber($phoneNumber);

    /**
     * Get time remaining
     *
     * @return int
     */
    public function getTimeRemaining();

    /**
     * Set time remaining
     *
     * @param int $timeRemaining
     * @return $this
     */
    public function setTimeRemaining($timeRemaining);

    /**
     * Get is expired status
     *
     * @return bool
     */
    public function getIsExpired();

    /**
     * Set is expired status
     *
     * @param bool $isExpired
     * @return $this
     */
    public function setIsExpired($isExpired);
}