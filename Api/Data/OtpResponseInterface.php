<?php

namespace IDangerous\PhoneOtpVerification\Api\Data;

/**
 * Interface for OTP Response Data
 * @api
 */
interface OtpResponseInterface
{
    /**
     * Get success status
     *
     * @return bool
     */
    public function getSuccess();

    /**
     * Set success status
     *
     * @param bool $success
     * @return $this
     */
    public function setSuccess($success);

    /**
     * Get message
     *
     * @return string
     */
    public function getMessage();

    /**
     * Set message
     *
     * @param string $message
     * @return $this
     */
    public function setMessage($message);
}