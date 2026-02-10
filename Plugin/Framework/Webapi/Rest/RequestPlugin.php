<?php
declare(strict_types=1);

namespace IDangerous\PhoneOtpVerification\Plugin\Framework\Webapi\Rest;

use Magento\Framework\Webapi\Rest\Request;

/**
 * Defensive cleanup: remove any phone verification fields from REST body/params.
 *
 * If `phoneVerified` (or variants) ends up in the checkout payload, Magento WebAPI
 * tries to map it onto Magento\Quote\Api\Data\AddressInterface and throws:
 * "Property 'PhoneVerified' does not have accessor method 'getPhoneVerified'".
 *
 * This plugin strips those keys before the input processing runs.
 */
class RequestPlugin
{
    /**
     * @param Request $subject
     * @param array $result
     * @return array
     */
    public function afterGetBodyParams(Request $subject, array $result): array
    {
        return $this->stripPhoneVerifiedKeys($result);
    }

    /**
     * @param Request $subject
     * @param array $result
     * @return array
     */
    public function afterGetRequestData(Request $subject, array $result): array
    {
        return $this->stripPhoneVerifiedKeys($result);
    }

    /**
     * Recursively remove keys that cause WebAPI reflection failures.
     *
     * @param mixed $data
     * @return mixed
     */
    private function stripPhoneVerifiedKeys($data)
    {
        if (!is_array($data)) {
            return $data;
        }

        foreach (['phone_verified', 'phoneVerified', 'PhoneVerified'] as $badKey) {
            if (array_key_exists($badKey, $data)) {
                unset($data[$badKey]);
            }
        }

        foreach ($data as $k => $v) {
            if (is_array($v)) {
                $data[$k] = $this->stripPhoneVerifiedKeys($v);
            }
        }

        return $data;
    }
}

