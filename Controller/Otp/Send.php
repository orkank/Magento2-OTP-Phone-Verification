<?php
namespace IDangerous\PhoneOtpVerification\Controller\Otp;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use IDangerous\PhoneOtpVerification\Model\OtpManager;
use Magento\Framework\App\ObjectManager;

class Send extends Action
{
    protected $resultJsonFactory;
    protected $otpManager;

    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        OtpManager $otpManager
    ) {
        parent::__construct($context);
        $this->resultJsonFactory = $resultJsonFactory;
        $this->otpManager = $otpManager;
    }

    public function execute()
    {
        $result = $this->resultJsonFactory->create();
        try {
            $phone = $this->getRequest()->getParam('phone');
            $context = (string)$this->getRequest()->getParam('context'); // registration|account|address|checkout

            if (!$phone) {
                throw new \Exception(__('Please enter phone number.'));
            }

            // Send OTP
            $skipAvailabilityCheck = in_array($context, ['address', 'checkout'], true);
            $otp = $this->otpManager->sendOtp($phone, $skipAvailabilityCheck);

            // Debug: Verify session data
            $session = ObjectManager::getInstance()->get(\Magento\Customer\Model\Session::class);
            $otpData = $session->getPhoneOtp();

            if (!$otpData || !isset($otpData['phone'])) {
                throw new \Exception(__('Failed to store phone number in session.'));
            }

            return $result->setData([
                'success' => true,
                'message' => __('OTP sent successfully to your phone number.')
            ]);
        } catch (\Exception $e) {
            return $result->setData([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }
}