<?php

namespace IDangerous\PhoneOtpVerification\Model\Resolver\Customer;

use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;

class PhoneVerified implements ResolverInterface
{
    /**
     * @inheritdoc
     */
    public function resolve(
        Field $field,
        $context,
        ResolveInfo $info,
        array $value = null,
        array $args = null
    ) {
        if (!isset($value['model'])) {
            return false;
        }

        $customer = $value['model'];

        // Get phone_verified custom attribute
        $phoneVerifiedAttribute = $customer->getCustomAttribute('phone_verified');

        return $phoneVerifiedAttribute ? (bool)$phoneVerifiedAttribute->getValue() : false;
    }
}