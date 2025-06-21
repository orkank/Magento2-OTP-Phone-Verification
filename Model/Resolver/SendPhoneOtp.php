<?php

namespace IDangerous\PhoneOtpVerification\Model\Resolver;

use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use IDangerous\PhoneOtpVerification\Model\OtpManager;
use Magento\Customer\Model\Session;
use Psr\Log\LoggerInterface;
use Magento\Framework\App\State;

class SendPhoneOtp implements ResolverInterface
{
    /**
     * @var OtpManager
     */
    private $otpManager;

    /**
     * @var Session
     */
    private $customerSession;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var State
     */
    private $appState;

    /**
     * @param OtpManager $otpManager
     * @param Session $customerSession
     * @param LoggerInterface $logger
     * @param State $appState
     */
    public function __construct(
        OtpManager $otpManager,
        Session $customerSession,
        LoggerInterface $logger,
        State $appState
    ) {
        $this->otpManager = $otpManager;
        $this->customerSession = $customerSession;
        $this->logger = $logger;
        $this->appState = $appState;
    }

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
        if (!isset($args['input']['phone_number'])) {
            throw new GraphQlInputException(__('Phone number is required.'));
        }

        $phoneNumber = trim($args['input']['phone_number']);

        if (empty($phoneNumber)) {
            throw new GraphQlInputException(__('Phone number cannot be empty.'));
        }

                try {
            // Set area code for GraphQL context
            if (!$this->appState->getAreaCode()) {
                $this->appState->setAreaCode(\Magento\Framework\App\Area::AREA_GRAPHQL);
            }

            // Normalize phone number (remove +90 and leading 0 if present)
            $phoneNumber = $this->normalizePhoneNumber($phoneNumber);

            $this->logger->info('GraphQL SendPhoneOtp: Attempting to send OTP to phone: ' . $phoneNumber);

            // Send OTP using the existing manager
            $this->otpManager->sendOtp($phoneNumber);

            // Store the phone number for later cache lookup
            $this->customerSession->setLastOtpPhone($phoneNumber);

            $this->logger->info('GraphQL SendPhoneOtp: OTP sent successfully to phone: ' . $phoneNumber);

            return [
                'success' => true,
                'message' => __('OTP sent successfully to your phone number.')
            ];

        } catch (\Exception $e) {
            $this->logger->error('GraphQL SendPhoneOtp Error: ' . $e->getMessage());

            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Normalize phone number by removing country code and leading zero
     *
     * @param string $phoneNumber
     * @return string
     */
    private function normalizePhoneNumber($phoneNumber)
    {
        // Remove all non-numeric characters except +
        $phoneNumber = preg_replace('/[^0-9+]/', '', $phoneNumber);

        // Remove +90 country code if present
        if (strpos($phoneNumber, '+90') === 0) {
            $phoneNumber = substr($phoneNumber, 3);
        }

        // Remove leading 0 if present
        if (strpos($phoneNumber, '0') === 0) {
            $phoneNumber = substr($phoneNumber, 1);
        }

        return $phoneNumber;
    }
}