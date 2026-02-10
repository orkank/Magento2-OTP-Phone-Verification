<?php
declare(strict_types=1);

namespace IDangerous\PhoneOtpVerification\Model\Resolver;

use IDangerous\PhoneOtpVerification\Model\OtpManager;
use Magento\Framework\App\State;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Psr\Log\LoggerInterface;

/**
 * Send OTP for address/checkout verification.
 *
 * Differences from sendPhoneOtp:
 * - Skips "phone already verified by another user" restriction, because
 *   address phones can be shared across customers.
 */
class SendAddressPhoneOtp implements ResolverInterface
{
    private $otpManager;
    private $logger;
    private $appState;

    public function __construct(
        OtpManager $otpManager,
        LoggerInterface $logger,
        State $appState
    ) {
        $this->otpManager = $otpManager;
        $this->logger = $logger;
        $this->appState = $appState;
    }

    public function resolve(
        Field $field,
        $context,
        ResolveInfo $info,
        array $value = null,
        array $args = null
    ) {
        if (!isset($args['input']['phone_number'])) {
            throw new GraphQlInputException(__('Phone number is required.'));
        }

        $phoneNumber = trim((string)$args['input']['phone_number']);
        if ($phoneNumber === '') {
            throw new GraphQlInputException(__('Phone number cannot be empty.'));
        }

        try {
            if (!$this->appState->getAreaCode()) {
                $this->appState->setAreaCode(\Magento\Framework\App\Area::AREA_GRAPHQL);
            }

            $phoneNumber = $this->normalizePhoneNumber($phoneNumber);
            $this->logger->info('GraphQL SendAddressPhoneOtp: Sending OTP', ['phone' => $phoneNumber]);

            // Skip availability check for address/checkout
            $this->otpManager->sendOtp($phoneNumber, true);

            return [
                'success' => true,
                'message' => __('OTP sent successfully to your phone number.')
            ];
        } catch (\Exception $e) {
            $this->logger->error('GraphQL SendAddressPhoneOtp Error: ' . $e->getMessage());

            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    private function normalizePhoneNumber(string $phoneNumber): string
    {
        $phoneNumber = preg_replace('/[^0-9+]/', '', $phoneNumber);

        if (strpos($phoneNumber, '+90') === 0) {
            $phoneNumber = substr($phoneNumber, 3);
        }

        if (strpos($phoneNumber, '0') === 0) {
            $phoneNumber = substr($phoneNumber, 1);
        }

        return $phoneNumber;
    }
}

