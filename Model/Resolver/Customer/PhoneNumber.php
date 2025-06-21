<?php

namespace IDangerous\PhoneOtpVerification\Model\Resolver\Customer;

use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;

class PhoneNumber implements ResolverInterface
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
            return null;
        }

        $customer = $value['model'];

        // Get phone_number custom attribute
        $phoneAttribute = $customer->getCustomAttribute('phone_number');

        return $phoneAttribute ? $phoneAttribute->getValue() : null;
    }
}