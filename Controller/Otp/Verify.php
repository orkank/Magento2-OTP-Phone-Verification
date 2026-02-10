<?php
namespace IDangerous\PhoneOtpVerification\Controller\Otp;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use IDangerous\PhoneOtpVerification\Model\OtpManager;
use Magento\Customer\Model\Session;
use IDangerous\PhoneOtpVerification\Helper\Customer as CustomerHelper;

class Verify extends Action
{
    protected $resultJsonFactory;
    protected $otpManager;
    protected $session;
    protected $customerHelper;

    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        OtpManager $otpManager,
        Session $session,
        CustomerHelper $customerHelper
    ) {
        parent::__construct($context);
        $this->resultJsonFactory = $resultJsonFactory;
        $this->otpManager = $otpManager;
        $this->session = $session;
        $this->customerHelper = $customerHelper;
    }

    public function execute()
    {
        $result = $this->resultJsonFactory->create();
        try {
            $inputOtp = $this->getRequest()->getParam('otp');

            if (!$inputOtp) {
                throw new \Exception(__('Please enter OTP code.'));
            }

            if ($this->otpManager->verifyOtp($inputOtp)) {
                // Get the verified phone data from session
                $otpData = $this->session->getPhoneOtp();
                $phone = $otpData['phone'] ?? '';
                $requestPhone = $this->getRequest()->getParam('phone');
                $addressType = (string)$this->getRequest()->getParam('address_type'); // shipping|billing (optional)
                $customerAddressId = (int)$this->getRequest()->getParam('customer_address_id');

                // Use phone from request if provided (for address verification)
                if ($requestPhone) {
                    $phone = $requestPhone;
                }

                if (!$phone) {
                    throw new \Exception(__('Phone number not found in session.'));
                }

                // Check if this is for address verification
                $addressPhoneVerified = $this->getRequest()->getParam('address_phone_verified');
                if ($addressPhoneVerified) {
                    // Store in session for address verification
                    // Normalize phone number for consistent hashing
                    $normalizedPhone = preg_replace('/[^0-9]/', '', $phone);
                    
                    // Use phone number hash as key for new addresses (both normalized and original)
                    $sessionKey = 'new_address_phone_verified_' . md5($normalizedPhone);
                    $sessionKeyOriginal = 'new_address_phone_verified_' . md5($phone);
                    $this->session->setData($sessionKey, true);
                    $this->session->setData($sessionKeyOriginal, true);
                    
                    // Also set for checkout (both shipping and billing)
                    if ($addressType === 'shipping' || $addressType === 'billing') {
                        $checkoutKey = 'checkout_' . $addressType . '_phone_verified_' . md5($normalizedPhone);
                        $this->session->setData($checkoutKey, true);
                    } else {
                        $checkoutShippingKey = 'checkout_shipping_phone_verified_' . md5($normalizedPhone);
                        $checkoutBillingKey = 'checkout_billing_phone_verified_' . md5($normalizedPhone);
                        $this->session->setData($checkoutShippingKey, true);
                        $this->session->setData($checkoutBillingKey, true);
                    }

                    // If this verification belongs to an existing customer address (checkout address selection),
                    // store by address_id so ShippingInformationManagementPlugin::validateExistingAddress() can pass.
                    if ($customerAddressId > 0) {
                        $sessionKeyById = 'address_phone_verified_' . $customerAddressId;
                        $this->session->setData($sessionKeyById, true);

                        // Persist verified status for this address (only if logged in and owns the address)
                        if ($this->session->isLoggedIn()) {
                            $this->customerHelper->saveVerifiedAddressPhone($customerAddressId, $phone);
                        }
                    }
                    
                    // Store phone in session for address form
                    $this->session->setData('address_verified_phone', $phone);
                    
                    return $result->setData([
                        'success' => true,
                        'message' => __('Phone number verified successfully.')
                    ]);
                }

                if ($this->session->isLoggedIn()) {
                    // For logged-in users, save directly
                    if ($this->customerHelper->saveVerifiedPhone($phone)) {
                        return $result->setData([
                            'success' => true,
                            'message' => __('Phone number verified and saved successfully.')
                        ]);
                    }
                    return $result->setData([
                        'success' => false,
                        'message' => __('Phone number verified but could not be saved. Please try again.')
                    ]);
                } else {
                    // For registration, store in session
                    $this->session->setRegistrationVerifiedPhone($phone);
                    return $result->setData([
                        'success' => true,
                        'message' => __('Phone number verified successfully.')
                    ]);
                }
            }

            return $result->setData([
                'success' => false,
                'message' => __('Invalid OTP code or OTP has expired.')
            ]);
        } catch (\Exception $e) {
            return $result->setData([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }
}