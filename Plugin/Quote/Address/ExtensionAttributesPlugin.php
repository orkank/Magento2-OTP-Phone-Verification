<?php
declare(strict_types=1);

namespace IDangerous\PhoneOtpVerification\Plugin\Quote\Address;

use Magento\Quote\Api\Data\AddressInterface;
use Psr\Log\LoggerInterface;

/**
 * Plugin to prevent phoneVerified from being exposed via extension attributes
 */
class ExtensionAttributesPlugin
{
    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param LoggerInterface $logger
     */
    public function __construct(
        LoggerInterface $logger
    ) {
        $this->logger = $logger;
    }

    /**
     * Remove phoneVerified from extension attributes
     *
     * @param AddressInterface $subject
     * @param mixed $result
     * @return mixed
     */
    public function afterGetExtensionAttributes(
        AddressInterface $subject,
        $result
    ) {
        if ($result && method_exists($result, 'setPhoneVerified')) {
            $this->logger->info('ExtensionAttributesPlugin: Clearing PhoneVerified from extension attributes');
            $result->setPhoneVerified(null);
        }
        
        if ($result && method_exists($result, 'setPhoneNumber')) {
            $result->setPhoneNumber(null);
        }
        
        return $result;
    }
}
