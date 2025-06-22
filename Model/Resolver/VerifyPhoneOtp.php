<?php

namespace IDangerous\PhoneOtpVerification\Model\Resolver;

use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use IDangerous\PhoneOtpVerification\Model\OtpManager;
use IDangerous\PhoneOtpVerification\Helper\Customer as CustomerHelper;
use IDangerous\PhoneOtpVerification\Helper\CustomerGraphql as CustomerGraphqlHelper;
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
     * @var CustomerGraphqlHelper
     */
    private $customerGraphqlHelper;

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
     * @param CustomerGraphqlHelper $customerGraphqlHelper
     * @param Session $customerSession
     * @param LoggerInterface $logger
     * @param State $appState
     * @param CacheInterface $cache
     */
    public function __construct(
        OtpManager $otpManager,
        CustomerHelper $customerHelper,
        CustomerGraphqlHelper $customerGraphqlHelper,
        Session $customerSession,
        LoggerInterface $logger,
        State $appState,
        CacheInterface $cache
    ) {
        $this->otpManager = $otpManager;
        $this->customerHelper = $customerHelper;
        $this->customerGraphqlHelper = $customerGraphqlHelper;
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

                // Check if customer is authenticated in GraphQL context
                $customerId = $this->customerGraphqlHelper->getCurrentCustomerId($context);
                $isLoggedIn = $customerId !== null && $customerId > 0;
                $customerUpdated = false;
                $phoneVerified = true;

                $this->logger->info('GraphQL VerifyPhoneOtp: Customer ID from context: ' . ($customerId ?: 'none'));
                $this->logger->info('GraphQL VerifyPhoneOtp: Is logged in: ' . ($isLoggedIn ? 'yes' : 'no'));

                if ($isLoggedIn) {
                    // For logged-in users, save the verified phone number using GraphQL helper
                    $customerUpdated = $this->customerGraphqlHelper->saveVerifiedPhone($customerId, $phoneNumber);

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

                    // Normalize phone number for consistent cache storage
                    $normalizedPhone = $this->normalizePhoneNumber($phoneNumber);

                    // Store in cache with multiple keys for better lookup
                    $cacheKey1 = 'verified_phone_' . md5($phoneNumber); // Original phone
                    $cacheKey2 = 'verified_phone_' . md5($normalizedPhone); // Normalized phone
                    $verificationData = [
                        'phone' => $phoneNumber,
                        'normalized_phone' => $normalizedPhone,
                        'timestamp' => time(),
                        'verified' => true
                    ];

                    // Store with both keys to ensure lookup works
                    $this->cache->save(json_encode($verificationData), $cacheKey1, [], 600); // 10 minutes expiry
                    $this->cache->save(json_encode($verificationData), $cacheKey2, [], 600); // 10 minutes expiry

                    $this->logger->info('GraphQL VerifyPhoneOtp: Phone verified for registration: ' . $phoneNumber);
                    $this->logger->info('GraphQL VerifyPhoneOtp: Normalized phone: ' . $normalizedPhone);
                    $this->logger->info('GraphQL VerifyPhoneOtp: Stored verification in cache with keys: ' . $cacheKey1 . ', ' . $cacheKey2);

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