<?php
namespace IDangerous\PhoneOtpVerification\Controller\Otp;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use IDangerous\PhoneOtpVerification\Model\OtpManager;
use Magento\Framework\Session\SessionManagerInterface;

class Send extends Action
{
    protected $resultJsonFactory;
    protected $otpManager;
    protected $session;

    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        OtpManager $otpManager,
        SessionManagerInterface $session
    ) {
        parent::__construct($context);
        $this->resultJsonFactory = $resultJsonFactory;
        $this->otpManager = $otpManager;
        $this->session = $session;
    }

    public function execute()
    {
        $result = $this->resultJsonFactory->create();
        try {
            $phone = $this->getRequest()->getParam('phone');
            if (!$phone) {
                throw new \Exception(__('Phone number is required.'));
            }

            $otp = $this->otpManager->sendOtp($phone);
            $this->session->setOtpCode($otp);
            $this->session->setPhoneNumber($phone);

            return $result->setData([
                'success' => true,
                'message' => __('OTP sent successfully.')
            ]);
        } catch (\Exception $e) {
            return $result->setData([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }
}