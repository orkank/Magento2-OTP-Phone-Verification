<?php
declare(strict_types=1);

namespace IDangerous\PhoneOtpVerification\Model\Resolver;

use IDangerous\PhoneOtpVerification\Helper\CustomerGraphql as CustomerGraphqlHelper;
use IDangerous\PhoneOtpVerification\Model\OtpManager;
use IDangerous\PhoneOtpVerification\Model\PhoneVerificationTokenManager;
use Magento\Framework\App\State;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Customer\Model\Session;
use Psr\Log\LoggerInterface;

/**
 * Verify OTP for address/checkout and return a short-lived verification token.
 */
class VerifyAddressPhoneOtp implements ResolverInterface
{
    private $otpManager;
    private $tokenManager;
    private $customerGraphqlHelper;
    private $customerSession;
    private $logger;
    private $appState;

    public function __construct(
        OtpManager $otpManager,
        PhoneVerificationTokenManager $tokenManager,
        CustomerGraphqlHelper $customerGraphqlHelper,
        Session $customerSession,
        LoggerInterface $logger,
        State $appState
    ) {
        $this->otpManager = $otpManager;
        $this->tokenManager = $tokenManager;
        $this->customerGraphqlHelper = $customerGraphqlHelper;
        $this->customerSession = $customerSession;
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
        if (!isset($args['input']['otp_code'])) {
            throw new GraphQlInputException(__('OTP code is required.'));
        }

        $otpCode = trim((string)$args['input']['otp_code']);
        if ($otpCode === '') {
            throw new GraphQlInputException(__('OTP code cannot be empty.'));
        }

        try {
            if (!$this->appState->getAreaCode()) {
                $this->appState->setAreaCode(\Magento\Framework\App\Area::AREA_GRAPHQL);
            }

            $customerId = (int)($this->customerGraphqlHelper->getCurrentCustomerId($context) ?? 0);
            if ($customerId <= 0) {
                // Token is used to bridge to checkout REST (mine). Require auth.
                return [
                    'success' => false,
                    'message' => __('Customer authentication is required.'),
                    'verification_token' => null,
                    'expires_in' => null
                ];
            }

            $this->logger->info('GraphQL VerifyAddressPhoneOtp: verifying', ['customer_id' => $customerId]);

            if (!$this->otpManager->verifyOtp($otpCode)) {
                return [
                    'success' => false,
                    'message' => __('Invalid OTP code or OTP has expired.'),
                    'verification_token' => null,
                    'expires_in' => null
                ];
            }

            // OtpManager will populate session from cache if needed.
            $otpData = $this->customerSession->getPhoneOtp();
            $phone = (string)($otpData['phone'] ?? '');
            if ($phone === '') {
                return [
                    'success' => false,
                    'message' => __('Phone number not found in session.'),
                    'verification_token' => null,
                    'expires_in' => null
                ];
            }

            $normalizedPhone = preg_replace('/[^0-9]/', '', $phone);
            $issued = $this->tokenManager->issueToken($customerId, $normalizedPhone);

            return [
                'success' => true,
                'message' => __('Phone number verified successfully.'),
                'verification_token' => $issued['token'],
                'expires_in' => $issued['expires_in']
            ];
        } catch (\Exception $e) {
            $this->logger->error('GraphQL VerifyAddressPhoneOtp Error: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'verification_token' => null,
                'expires_in' => null
            ];
        }
    }
}

