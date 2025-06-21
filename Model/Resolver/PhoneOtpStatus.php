<?php

namespace IDangerous\PhoneOtpVerification\Model\Resolver;

use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Customer\Model\Session;
use Psr\Log\LoggerInterface;
use Magento\Framework\App\State;

class PhoneOtpStatus implements ResolverInterface
{
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
     * OTP expiry time in seconds (5 minutes)
     */
    const OTP_EXPIRY_TIME = 300;

    /**
     * @param Session $customerSession
     * @param LoggerInterface $logger
     * @param State $appState
     */
    public function __construct(
        Session $customerSession,
        LoggerInterface $logger,
        State $appState
    ) {
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
        try {
            // Set area code for GraphQL context
            if (!$this->appState->getAreaCode()) {
                $this->appState->setAreaCode(\Magento\Framework\App\Area::AREA_GRAPHQL);
            }

            $otpData = $this->customerSession->getPhoneOtp();

            if (!$otpData || !isset($otpData['code']) || !isset($otpData['phone']) || !isset($otpData['timestamp'])) {
                return [
                    'has_pending_otp' => false,
                    'phone_number' => null,
                    'time_remaining' => 0,
                    'is_expired' => true
                ];
            }

            $currentTime = time();
            $otpTime = $otpData['timestamp'];
            $timeElapsed = $currentTime - $otpTime;
            $timeRemaining = self::OTP_EXPIRY_TIME - $timeElapsed;
            $isExpired = $timeRemaining <= 0;

            if ($isExpired) {
                // Clean up expired OTP from session
                $this->customerSession->unsPhoneOtp();

                return [
                    'has_pending_otp' => false,
                    'phone_number' => null,
                    'time_remaining' => 0,
                    'is_expired' => true
                ];
            }

            return [
                'has_pending_otp' => true,
                'phone_number' => $otpData['phone'],
                'time_remaining' => $timeRemaining,
                'is_expired' => false
            ];

        } catch (\Exception $e) {
            $this->logger->error('GraphQL PhoneOtpStatus Error: ' . $e->getMessage());

            return [
                'has_pending_otp' => false,
                'phone_number' => null,
                'time_remaining' => 0,
                'is_expired' => true
            ];
        }
    }
}