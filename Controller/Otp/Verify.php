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

            // print_r($this->session->getData(), true);
            // $this->_logger->debug('Session data before verification: ' . print_r($this->session->getData(), true));

            if ($this->otpManager->verifyOtp($inputOtp)) {
                // Get the verified phone data from session
                $verifiedPhoneData = $this->session->getVerifiedPhoneData();
                $phone = $verifiedPhoneData['phone'] ?? '';

                if (!$phone) {
                    throw new \Exception(__('Phone number not found in session.'));
                }

                // Save the verified phone number
                if ($this->customerHelper->saveVerifiedPhone($phone)) {
                    // Clear the verified phone data from session after successful save
                    $this->session->unsVerifiedPhoneData();

                    return $result->setData([
                        'success' => true,
                        'message' => __('Phone number verified and saved successfully.')
                    ]);
                }

                return $result->setData([
                    'success' => false,
                    'message' => __('Phone number verified but could not be saved. Please try again.')
                ]);
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