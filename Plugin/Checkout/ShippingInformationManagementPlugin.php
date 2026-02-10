<?php
namespace IDangerous\PhoneOtpVerification\Plugin\Checkout;

use Magento\Checkout\Api\Data\ShippingInformationInterface;
use Magento\Checkout\Model\ShippingInformationManagement;
use Magento\Framework\Exception\LocalizedException;
use IDangerous\PhoneOtpVerification\Helper\Customer as CustomerHelper;
use IDangerous\PhoneOtpVerification\Model\PhoneVerificationTokenManager;
use Magento\Customer\Model\Session;
use Magento\Framework\App\RequestInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Customer\Api\AddressRepositoryInterface;

class ShippingInformationManagementPlugin
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
     * @var CartRepositoryInterface
     */
    protected $cartRepository;

    /**
     * @var AddressRepositoryInterface
     */
    protected $addressRepository;

    /**
     * @var PhoneVerificationTokenManager
     */
    private $tokenManager;

    /**
     * @param CustomerHelper $customerHelper
     * @param Session $customerSession
     * @param RequestInterface $request
     * @param CartRepositoryInterface $cartRepository
     * @param AddressRepositoryInterface $addressRepository
     */
    public function __construct(
        CustomerHelper $customerHelper,
        Session $customerSession,
        RequestInterface $request,
        CartRepositoryInterface $cartRepository,
        AddressRepositoryInterface $addressRepository,
        PhoneVerificationTokenManager $tokenManager
    ) {
        $this->customerHelper = $customerHelper;
        $this->customerSession = $customerSession;
        $this->request = $request;
        $this->cartRepository = $cartRepository;
        $this->addressRepository = $addressRepository;
        $this->tokenManager = $tokenManager;
    }

    /**
     * Before save shipping information - check phone verification
     *
     * @param ShippingInformationManagement $subject
     * @param int $cartId
     * @param ShippingInformationInterface $addressInformation
     * @return array
     * @throws LocalizedException
     */
    public function beforeSaveAddressInformation(
        ShippingInformationManagement $subject,
        $cartId,
        ShippingInformationInterface $addressInformation
    ) {
        $quote = $this->cartRepository->get($cartId);
        $customerId = (int)$quote->getCustomerId();

        $shippingAddress = $addressInformation->getShippingAddress();
        $billingAddress = $addressInformation->getBillingAddress();

        // Check shipping address phone verification
        if ($shippingAddress) {
            $this->validateAddressPhone($shippingAddress, 'shipping', $customerId);
        }

        // Check billing address phone verification if different from shipping
        if ($billingAddress && $billingAddress->getTelephone() !== $shippingAddress->getTelephone()) {
            $this->validateAddressPhone($billingAddress, 'billing', $customerId);
        }

        // Check if existing address is being used
        if ($shippingAddress->getCustomerAddressId()) {
            $this->validateExistingAddress($shippingAddress->getCustomerAddressId(), $customerId);
        }

        return [$cartId, $addressInformation];
    }

    /**
     * Validate address phone verification
     *
     * @param \Magento\Quote\Api\Data\AddressInterface $address
     * @param string $addressType
     * @return void
     * @throws LocalizedException
     */
    protected function validateAddressPhone($address, $addressType, int $customerId = 0)
    {
        $telephone = $address->getTelephone();
        
        if (empty($telephone)) {
            return; // No phone number, skip verification
        }

        // Token bridge for mobile app: accept proof via header
        $token = (string)$this->request->getHeader('X-Phone-Verification-Token');
        $normalizedPhone = preg_replace('/[^0-9]/', '', (string)$telephone);
        if ($token && $customerId > 0 && $normalizedPhone) {
            if ($this->tokenManager->validateToken($token, $customerId, $normalizedPhone)) {
                // Persist verification on matching saved addresses (app may not send customer_address_id)
                if ($address->getCustomerAddressId()) {
                    $this->customerHelper->saveVerifiedAddressPhone((int)$address->getCustomerAddressId(), (string)$telephone);
                } else {
                    $this->customerHelper->markVerifiedAddressesByPhoneForCustomer($customerId, (string)$telephone);
                }
                return;
            }
        }

        // Check if phone matches customer's verified profile phone
        if ($this->customerHelper->isAddressPhoneSameAsCustomerPhone($telephone)
            || ($customerId > 0 && $this->customerHelper->isAddressPhoneSameAsCustomerPhoneForCustomerId($customerId, (string)$telephone))) {
            return; // Phone matches customer's verified phone, no verification needed
        }

        // Per-phone skip rule for app payloads without customer_address_id
        if ($customerId > 0 && $this->customerHelper->isAnyVerifiedAddressPhoneForCustomer($customerId, (string)$telephone)) {
            return;
        }

        // Check if verification is required
        $addressDataObject = $this->createAddressDataObject($address);
        if (!$this->customerHelper->isAddressPhoneVerificationRequired($addressDataObject)) {
            return; // Verification not required
        }

        // Check if verification was done
        $phoneVerified = $this->request->getParam('phone_verified');
        $addressPhoneVerified = $this->request->getParam($addressType . '_address_phone_verified');
        
        // Check session for new address verification
        $sessionKey = 'checkout_' . $addressType . '_phone_verified_' . md5($telephone);
        $sessionVerified = $this->customerSession->getData($sessionKey);

        if (!$phoneVerified && !$addressPhoneVerified && !$sessionVerified) {
            throw new LocalizedException(
                __('Phone number verification is required for the %1 address. Please verify the phone number before proceeding.', $addressType)
            );
        }
    }

    /**
     * Validate existing address phone verification
     *
     * @param int $addressId
     * @return void
     * @throws LocalizedException
     */
    protected function validateExistingAddress($addressId, int $customerId = 0)
    {
        try {
            $address = $this->addressRepository->getById($addressId);
            
            // Check if verification is required for this existing address
            if ($this->customerHelper->isAddressPhoneVerificationRequired($address)) {
                $telephone = $address->getTelephone();
                
                // Token bridge for mobile app: accept proof via header
                $token = (string)$this->request->getHeader('X-Phone-Verification-Token');
                $normalizedPhone = preg_replace('/[^0-9]/', '', (string)$telephone);
                if ($token && $customerId > 0 && $normalizedPhone) {
                    if ($this->tokenManager->validateToken($token, $customerId, $normalizedPhone)) {
                        // Persist verification for this specific saved address
                        $this->customerHelper->saveVerifiedAddressPhone((int)$addressId, (string)$telephone);
                        return;
                    }
                }

                // Check if phone matches customer's verified phone
                if ($this->customerHelper->isAddressPhoneSameAsCustomerPhone($telephone)
                    || ($customerId > 0 && $this->customerHelper->isAddressPhoneSameAsCustomerPhoneForCustomerId($customerId, (string)$telephone))) {
                    return; // Phone matches customer's verified phone, no verification needed
                }

                // Check if address phone is already verified
                if ($this->customerHelper->isAddressPhoneVerified($addressId)) {
                    return; // Already verified
                }

                if ($customerId > 0 && $this->customerHelper->isAnyVerifiedAddressPhoneForCustomer($customerId, (string)$telephone)) {
                    return;
                }

                // Check if verification was done
                $phoneVerified = $this->request->getParam('phone_verified');
                $addressPhoneVerified = $this->request->getParam('address_phone_verified');
                $sessionKey = 'address_phone_verified_' . $addressId;
                $sessionVerified = $this->customerSession->getData($sessionKey);

                if (!$phoneVerified && !$addressPhoneVerified && !$sessionVerified) {
                    throw new LocalizedException(
                        __('The selected address has an unverified phone number. Please verify the phone number before proceeding.')
                    );
                }
            }
        } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
            // Address not found, skip validation
        }
    }

    /**
     * Create address data object from quote address
     *
     * @param \Magento\Quote\Api\Data\AddressInterface $quoteAddress
     * @return \Magento\Customer\Api\Data\AddressInterface
     */
    protected function createAddressDataObject($quoteAddress)
    {
        // Create a temporary address data object for validation
        $addressDataObject = \Magento\Framework\App\ObjectManager::getInstance()
            ->create(\Magento\Customer\Api\Data\AddressInterfaceFactory::class)
            ->create();

        $addressDataObject->setTelephone($quoteAddress->getTelephone());
        // Magento\Customer\Api\Data\AddressInterface does not have setCustomerAddressId().
        // For our "existing vs new" checks we only need the ID.
        if ($quoteAddress->getCustomerAddressId()) {
            $addressDataObject->setId((int)$quoteAddress->getCustomerAddressId());
        }

        return $addressDataObject;
    }

    /**
     * After save shipping information - mark phone as verified if verification was done
     *
     * @param ShippingInformationManagement $subject
     * @param \Magento\Checkout\Api\Data\PaymentDetailsInterface $result
     * @param int $cartId
     * @param ShippingInformationInterface $addressInformation
     * @return \Magento\Checkout\Api\Data\PaymentDetailsInterface
     */
    public function afterSaveAddressInformation(
        ShippingInformationManagement $subject,
        $result,
        $cartId,
        ShippingInformationInterface $addressInformation
    ) {
        $quote = $this->cartRepository->get($cartId);
        $shippingAddress = $quote->getShippingAddress();
        $billingAddress = $quote->getBillingAddress();

        // Process shipping address phone verification
        if ($shippingAddress && $shippingAddress->getTelephone()) {
            $this->processAddressPhoneVerification($shippingAddress, 'shipping');
        }

        // Process billing address phone verification if different
        if ($billingAddress && $billingAddress->getTelephone() 
            && $billingAddress->getTelephone() !== $shippingAddress->getTelephone()) {
            $this->processAddressPhoneVerification($billingAddress, 'billing');
        }

        return $result;
    }

    /**
     * Process address phone verification after save
     *
     * @param \Magento\Quote\Api\Data\AddressInterface $address
     * @param string $addressType
     * @return void
     */
    protected function processAddressPhoneVerification($address, $addressType)
    {
        $telephone = $address->getTelephone();
        
        if (empty($telephone)) {
            return;
        }

        // Check if phone matches customer's verified phone
        if ($this->customerHelper->isAddressPhoneSameAsCustomerPhone($telephone)) {
            // If address is saved to address book, mark as verified
            if ($address->getSaveInAddressBook() && $address->getCustomerAddressId()) {
                $this->customerHelper->saveVerifiedAddressPhone($address->getCustomerAddressId(), $telephone);
            }
            return;
        }

        // Check if verification was done
        $phoneVerified = $this->request->getParam('phone_verified');
        $addressPhoneVerified = $this->request->getParam($addressType . '_address_phone_verified');
        $sessionKey = 'checkout_' . $addressType . '_phone_verified_' . md5($telephone);
        $sessionVerified = $this->customerSession->getData($sessionKey);

        if ($phoneVerified || $addressPhoneVerified || $sessionVerified) {
            // If address is saved to address book, mark as verified
            if ($address->getSaveInAddressBook() && $address->getCustomerAddressId()) {
                $this->customerHelper->saveVerifiedAddressPhone($address->getCustomerAddressId(), $telephone);
            }
            // Clear session
            $this->customerSession->unsetData($sessionKey);
        }
    }
}
