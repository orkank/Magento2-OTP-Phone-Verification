<?php
namespace IDangerous\PhoneOtpVerification\Plugin\Customer;

use Magento\Customer\Api\AddressRepositoryInterface;
use Magento\Customer\Api\Data\AddressInterface;
use Magento\Framework\Exception\LocalizedException;
use IDangerous\PhoneOtpVerification\Helper\Customer as CustomerHelper;
use IDangerous\PhoneOtpVerification\Model\PhoneVerificationTokenManager;
use Magento\Customer\Model\Session;
use Magento\Framework\App\RequestInterface;
use Psr\Log\LoggerInterface;

class AddressRepositoryPlugin
{
    /**
     * @var CustomerHelper
     */
    protected $customerHelper;

    /**
     * @var Session
     */
    protected $customerSession;

    /**
     * @var RequestInterface
     */
    protected $request;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @var PhoneVerificationTokenManager
     */
    private $tokenManager;

    /**
     * @param CustomerHelper $customerHelper
     * @param Session $customerSession
     * @param RequestInterface $request
     * @param LoggerInterface $logger
     */
    public function __construct(
        CustomerHelper $customerHelper,
        Session $customerSession,
        RequestInterface $request,
        LoggerInterface $logger,
        PhoneVerificationTokenManager $tokenManager
    ) {
        $this->customerHelper = $customerHelper;
        $this->customerSession = $customerSession;
        $this->request = $request;
        $this->logger = $logger;
        $this->tokenManager = $tokenManager;
    }

    /**
     * Before save address - check if phone verification is required
     *
     * @param AddressRepositoryInterface $subject
     * @param AddressInterface $address
     * @return array
     * @throws LocalizedException
     */
    public function beforeSave(
        AddressRepositoryInterface $subject,
        AddressInterface $address
    ) {
        $this->logger->info('AddressRepositoryPlugin::beforeSave - START', [
            'address_id' => $address->getId(),
            'telephone' => $address->getTelephone(),
            'customer_id' => $address->getCustomerId()
        ]);

        try {
            // Check if phone verification is required for this address
            $isVerificationRequired = $this->customerHelper->isAddressPhoneVerificationRequired($address);
            $this->logger->info('AddressRepositoryPlugin::beforeSave - Verification required check', [
                'is_verification_required' => $isVerificationRequired
            ]);

            if ($isVerificationRequired) {
                $telephone = $address->getTelephone();
                $this->logger->info('AddressRepositoryPlugin::beforeSave - Phone verification required', [
                    'telephone' => $telephone
                ]);

                $customerId = (int)$address->getCustomerId();

                // Check if phone matches customer's verified phone (session or by customerId)
                $isSameAsCustomerPhone = $this->customerHelper->isAddressPhoneSameAsCustomerPhone($telephone)
                    || $this->customerHelper->isAddressPhoneSameAsCustomerPhoneForCustomerId($customerId, (string)$telephone);
                $this->logger->info('AddressRepositoryPlugin::beforeSave - Phone match check', [
                    'is_same_as_customer_phone' => $isSameAsCustomerPhone
                ]);

                if (!$isSameAsCustomerPhone) {
                    // Token bridge for mobile app: accept proof via header
                    $token = (string)$this->request->getHeader('X-Phone-Verification-Token');
                    $normalizedPhone = preg_replace('/[^0-9]/', '', (string)$telephone);
                    if ($token && $customerId > 0 && $normalizedPhone) {
                        $tokenValid = $this->tokenManager->validateToken($token, $customerId, $normalizedPhone);
                        $this->logger->info('AddressRepositoryPlugin::beforeSave - Token validation', [
                            'customer_id' => $customerId,
                            'token_valid' => $tokenValid
                        ]);
                        if ($tokenValid) {
                            // Allow save; afterSave will persist verified marker
                            return [$address];
                        }
                    }

                    // Check if verification was done via session or request parameter
                    $phoneVerified = $this->request->getParam('phone_verified');
                    $addressPhoneVerified = $this->request->getParam('address_phone_verified');
                    $addressId = $address->getId();

                    $this->logger->info('AddressRepositoryPlugin::beforeSave - Verification status check', [
                        'phone_verified_param' => $phoneVerified,
                        'address_phone_verified_param' => $addressPhoneVerified,
                        'address_id' => $addressId
                    ]);

                    // For existing addresses, check session
                    if ($addressId) {
                        $sessionKey = 'address_phone_verified_' . $addressId;
                        $sessionVerified = $this->customerSession->getData($sessionKey);
                        $this->logger->info('AddressRepositoryPlugin::beforeSave - Existing address check', [
                            'session_key' => $sessionKey,
                            'session_verified' => $sessionVerified
                        ]);

                        if (!$sessionVerified && !$addressPhoneVerified) {
                            $this->logger->warning('AddressRepositoryPlugin::beforeSave - Verification failed for existing address');
                            throw new LocalizedException(
                                __('Phone number verification is required for this address. Please verify the phone number before saving.')
                            );
                        }
                    } else {
                        // For new addresses, check session with phone number as key
                        // Normalize phone number for consistent hashing
                        $normalizedPhone = preg_replace('/[^0-9]/', '', $telephone);
                        $sessionKey = 'new_address_phone_verified_' . md5($normalizedPhone);
                        $sessionVerified = $this->customerSession->getData($sessionKey);

                        // Also check with original phone format
                        $sessionKeyOriginal = 'new_address_phone_verified_' . md5($telephone);
                        $sessionVerifiedOriginal = $this->customerSession->getData($sessionKeyOriginal);

                        $this->logger->info('AddressRepositoryPlugin::beforeSave - New address check', [
                            'normalized_phone' => $normalizedPhone,
                            'session_key' => $sessionKey,
                            'session_key_original' => $sessionKeyOriginal,
                            'session_verified' => $sessionVerified,
                            'session_verified_original' => $sessionVerifiedOriginal
                        ]);

                        if (!$sessionVerified && !$sessionVerifiedOriginal && !$phoneVerified && !$addressPhoneVerified) {
                            $this->logger->warning('AddressRepositoryPlugin::beforeSave - Verification failed for new address');
                            throw new LocalizedException(
                                __('Phone number verification is required for this address. Please verify the phone number before saving.')
                            );
                        }
                    }
                }
            }

            $this->logger->info('AddressRepositoryPlugin::beforeSave - SUCCESS');
            return [$address];
        } catch (\Exception $e) {
            $this->logger->error('AddressRepositoryPlugin::beforeSave - EXCEPTION', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * After save address - mark phone as verified if verification was done
     *
     * @param AddressRepositoryInterface $subject
     * @param AddressInterface $result
     * @param AddressInterface $address
     * @return AddressInterface
     */
    public function afterSave(
        AddressRepositoryInterface $subject,
        AddressInterface $result,
        AddressInterface $address
    ) {
        $this->logger->info('AddressRepositoryPlugin::afterSave - START', [
            'address_id' => $result->getId(),
            'telephone' => $result->getTelephone()
        ]);

        try {
            $telephone = $result->getTelephone();

            if (!empty($telephone)) {
                $phoneVerified = $this->request->getParam('phone_verified');
                $addressPhoneVerified = $this->request->getParam('address_phone_verified');
                $addressId = $result->getId();

                $this->logger->info('AddressRepositoryPlugin::afterSave - Request params', [
                    'phone_verified' => $phoneVerified,
                    'address_phone_verified' => $addressPhoneVerified,
                    'address_id' => $addressId
                ]);

                // Check if verification was done
                $isVerified = false;

                // For new addresses, check session with phone number hash
                // Normalize phone number for consistent hashing
                $normalizedPhone = preg_replace('/[^0-9]/', '', $telephone);
                $sessionKey = 'new_address_phone_verified_' . md5($normalizedPhone);
                $sessionKeyOriginal = 'new_address_phone_verified_' . md5($telephone);

                $sessionVerified = $this->customerSession->getData($sessionKey);
                $sessionVerifiedOriginal = $this->customerSession->getData($sessionKeyOriginal);

                $this->logger->info('AddressRepositoryPlugin::afterSave - Session check', [
                    'session_key' => $sessionKey,
                    'session_key_original' => $sessionKeyOriginal,
                    'session_verified' => $sessionVerified,
                    'session_verified_original' => $sessionVerifiedOriginal
                ]);

                if ($sessionVerified || $sessionVerifiedOriginal || $phoneVerified || $addressPhoneVerified) {
                    $isVerified = true;
                    $this->logger->info('AddressRepositoryPlugin::afterSave - Verification found, clearing session');
                    // Clear session after use
                    $this->customerSession->unsetData($sessionKey);
                    $this->customerSession->unsetData($sessionKeyOriginal);
                }

                // For existing addresses, also check by address ID
                if ($addressId && !$isVerified) {
                    $addressSessionKey = 'address_phone_verified_' . $addressId;
                    $addressSessionVerified = $this->customerSession->getData($addressSessionKey);
                    $this->logger->info('AddressRepositoryPlugin::afterSave - Existing address session check', [
                        'address_session_key' => $addressSessionKey,
                        'address_session_verified' => $addressSessionVerified
                    ]);
                    if ($addressSessionVerified) {
                        $isVerified = true;
                        $this->customerSession->unsetData($addressSessionKey);
                    }
                }

                // If phone matches customer's verified phone, mark as verified automatically
                if (!$isVerified) {
                    $isSameAsCustomerPhone = $this->customerHelper->isAddressPhoneSameAsCustomerPhone($telephone);
                    $this->logger->info('AddressRepositoryPlugin::afterSave - Customer phone match check', [
                        'is_same_as_customer_phone' => $isSameAsCustomerPhone
                    ]);
                    if ($isSameAsCustomerPhone) {
                        $isVerified = true;
                    }
                }

                // App token bridge: if request carries a valid verification token, treat as verified
                if (!$isVerified) {
                    $token = (string)$this->request->getHeader('X-Phone-Verification-Token');
                    $customerId = (int)($result->getCustomerId() ?: $address->getCustomerId());
                    $normalizedPhone = preg_replace('/[^0-9]/', '', (string)$telephone);

                    if ($token && $customerId > 0 && $normalizedPhone) {
                        $tokenValid = $this->tokenManager->validateToken($token, $customerId, $normalizedPhone);
                        $this->logger->info('AddressRepositoryPlugin::afterSave - Token validation', [
                            'customer_id' => $customerId,
                            'token_valid' => $tokenValid
                        ]);

                        if ($tokenValid) {
                            $isVerified = true;
                        }
                    }
                }

                // Save verification status if verified
                // Use a flag to prevent infinite loop - only save if we're not already in a save cycle
                if ($isVerified && !$this->request->getParam('_phone_verification_saving')) {
                    $this->logger->info('AddressRepositoryPlugin::afterSave - Saving verified address phone', [
                        'address_id' => $result->getId()
                    ]);

                    // Set flag to prevent re-entry
                    $this->request->setParam('_phone_verification_saving', true);

                    try {
                        $saveResult = $this->customerHelper->saveVerifiedAddressPhone($result->getId(), $telephone);
                        $this->logger->info('AddressRepositoryPlugin::afterSave - Save result', [
                            'save_result' => $saveResult
                        ]);
                    } finally {
                        // Clear flag
                        $this->request->setParam('_phone_verification_saving', false);
                    }
                } else {
                    if ($isVerified) {
                        $this->logger->info('AddressRepositoryPlugin::afterSave - Skipping save (already in progress)');
                    } else {
                        $this->logger->warning('AddressRepositoryPlugin::afterSave - Address not verified, skipping save');
                    }
                }
            } else {
                $this->logger->info('AddressRepositoryPlugin::afterSave - No telephone, skipping');
            }

            $this->logger->info('AddressRepositoryPlugin::afterSave - SUCCESS');
            return $result;
        } catch (\Exception $e) {
            $this->logger->error('AddressRepositoryPlugin::afterSave - EXCEPTION', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            // Don't throw exception in afterSave, just log it
            return $result;
        }
    }
}
