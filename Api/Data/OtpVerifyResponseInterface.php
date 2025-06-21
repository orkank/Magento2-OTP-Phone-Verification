<?php

namespace IDangerous\PhoneOtpVerification\Api\Data;

/**
 * Interface for OTP Verify Response Data
 * @api
 */
interface OtpVerifyResponseInterface extends OtpResponseInterface
{
    /**
     * Get phone verified status
     *
     * @return bool
     */
    public function getPhoneVerified();

    /**
     * Set phone verified status
     *
     * @param bool $phoneVerified
     * @return $this
     */
    public function setPhoneVerified($phoneVerified);

    /**
     * Get customer updated status
     *
     * @return bool
     */
    public function getCustomerUpdated();

    /**
     * Set customer updated status
     *
     * @param bool $customerUpdated
     * @return $this
     */
    public function setCustomerUpdated($customerUpdated);
}