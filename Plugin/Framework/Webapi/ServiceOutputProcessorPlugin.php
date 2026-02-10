<?php
declare(strict_types=1);

namespace IDangerous\PhoneOtpVerification\Plugin\Framework\Webapi;

use Magento\Framework\Webapi\ServiceOutputProcessor;
use Psr\Log\LoggerInterface;

/**
 * Plugin to clean phoneVerified from WebAPI output
 */
class ServiceOutputProcessorPlugin
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
     * Remove phoneVerified from output data before WebAPI processing
     *
     * @param ServiceOutputProcessor $subject
     * @param mixed $data
     * @param string $type
     * @return array
     */
    public function beforeConvertValue(
        ServiceOutputProcessor $subject,
        $data,
        $type
    ): array {
        // If data is an object with phoneVerified, log it
        if (is_object($data)) {
            $className = get_class($data);
            if (strpos($className, 'Quote') !== false && strpos($className, 'Address') !== false) {
                $this->logger->info('ServiceOutputProcessor: Processing Quote Address', [
                    'class' => $className,
                    'type' => $type
                ]);
                
                // Try to remove phoneVerified from object data
                if (method_exists($data, 'getData')) {
                    $objectData = $data->getData();
                    if (isset($objectData['phone_verified']) || isset($objectData['phoneVerified']) || isset($objectData['PhoneVerified'])) {
                        $this->logger->critical('ServiceOutputProcessor: Found phoneVerified in Quote Address!', [
                            'phone_verified' => $objectData['phone_verified'] ?? null,
                            'phoneVerified' => $objectData['phoneVerified'] ?? null,
                            'PhoneVerified' => $objectData['PhoneVerified'] ?? null,
                        ]);
                        
                        if (method_exists($data, 'unsetData')) {
                            $data->unsetData('phone_verified');
                            $data->unsetData('phoneVerified');
                            $data->unsetData('PhoneVerified');
                        }
                    }
                }
            }
        }
        
        return [$data, $type];
    }
}
