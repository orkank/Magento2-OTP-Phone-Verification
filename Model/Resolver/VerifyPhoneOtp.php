<?php

namespace IDangerous\PhoneOtpVerification\Model\Resolver;

use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use IDangerous\PhoneOtpVerification\Model\OtpManager;
use IDangerous\PhoneOtpVerification\Helper\Customer as CustomerHelper;
use Magento\Customer\Model\Session;
use Psr\Log\LoggerInterface;
use Magento\Framework\App\State;
use Magento\Framework\App\CacheInterface;

class VerifyPhoneOtp implements ResolverInterface
{
    /**
     * @var OtpManager
     */
    private $otpManager;

    /**
     * @var CustomerHelper
     */
    private $customerHelper;

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
     * @var CacheInterface
     */
    private $cache;

    /**
     * @param OtpManager $otpManager
     * @param CustomerHelper $customerHelper
     * @param Session $customerSession
     * @param LoggerInterface $logger
     * @param State $appState
     * @param CacheInterface $cache
     */
    public function __construct(
        OtpManager $otpManager,
        CustomerHelper $customerHelper,
        Session $customerSession,
        LoggerInterface $logger,
        State $appState,
        CacheInterface $cache
    ) {
        $this->otpManager = $otpManager;
        $this->customerHelper = $customerHelper;
        $this->customerSession = $customerSession;
        $this->logger = $logger;
        $this->appState = $appState;
        $this->cache = $cache;
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
        if (!isset($args['input']['otp_code'])) {
            throw new GraphQlInputException(__('OTP code is required.'));
        }

        $otpCode = trim($args['input']['otp_code']);

        if (empty($otpCode)) {
            throw new GraphQlInputException(__('OTP code cannot be empty.'));
        }

                try {
            // Set area code for GraphQL context
            if (!$this->appState->getAreaCode()) {
                $this->appState->setAreaCode(\Magento\Framework\App\Area::AREA_GRAPHQL);
            }

            $this->logger->info('GraphQL VerifyPhoneOtp: Attempting to verify OTP: ' . $otpCode);

            // Debug: Check what's in session
            $otpData = $this->customerSession->getPhoneOtp();
            $this->logger->info('GraphQL VerifyPhoneOtp: Session OTP data: ' . json_encode($otpData));

            if ($this->otpManager->verifyOtp($otpCode)) {
                // Get the verified phone data from session
                $otpData = $this->customerSession->getPhoneOtp();
                $phoneNumber = $otpData['phone'] ?? '';

                if (!$phoneNumber) {
                    throw new \Exception(__('Phone number not found in session.'));
                }

                $isLoggedIn = $this->customerSession->isLoggedIn();
                $customerUpdated = false;
                $phoneVerified = true;

                if ($isLoggedIn) {
                    // For logged-in users, save the verified phone number
                    $customerUpdated = $this->customerHelper->saveVerifiedPhone($phoneNumber);

                    if ($customerUpdated) {
                        // Clear the OTP data from session after successful verification
                        $this->customerSession->unsPhoneOtp();

                        $this->logger->info('GraphQL VerifyPhoneOtp: Phone verified and saved for logged user: ' . $phoneNumber);

                        return [
                            'success' => true,
                            'message' => __('Phone number verified and saved successfully.'),
                            'phone_verified' => true,
                            'customer_updated' => true
                        ];
                    } else {
                        $this->logger->error('GraphQL VerifyPhoneOtp: Failed to save verified phone for logged user');

                        return [
                            'success' => false,
                            'message' => __('Phone number verified but could not be saved. Please try again.'),
                            'phone_verified' => true,
                            'customer_updated' => false
                        ];
                    }
                } else {
                    // For registration flow, store the verified phone in session AND cache
                    $this->customerSession->setRegistrationVerifiedPhone($phoneNumber);

                    // Also store in cache for GraphQL context
                    $cacheKey = 'verified_phone_' . md5($phoneNumber);
                    $verificationData = [
                        'phone' => $phoneNumber,
                        'timestamp' => time(),
                        'verified' => true
                    ];
                    $this->cache->save(json_encode($verificationData), $cacheKey, [], 600); // 10 minutes expiry

                    $this->logger->info('GraphQL VerifyPhoneOtp: Phone verified for registration: ' . $phoneNumber);
                    $this->logger->info('GraphQL VerifyPhoneOtp: Stored verification in cache with key: ' . $cacheKey);

                    return [
                        'success' => true,
                        'message' => __('Phone number verified successfully. You can now complete your registration.'),
                        'phone_verified' => true,
                        'customer_updated' => false
                    ];
                }
            } else {
                $this->logger->warning('GraphQL VerifyPhoneOtp: Invalid or expired OTP: ' . $otpCode);

                return [
                    'success' => false,
                    'message' => __('Invalid OTP code or OTP has expired.'),
                    'phone_verified' => false,
                    'customer_updated' => false
                ];
            }

        } catch (\Exception $e) {
            $this->logger->error('GraphQL VerifyPhoneOtp Error: ' . $e->getMessage());

            return [
                'success' => false,
                'message' => $e->getMessage(),
                'phone_verified' => false,
                'customer_updated' => false
            ];
        }
    }
}