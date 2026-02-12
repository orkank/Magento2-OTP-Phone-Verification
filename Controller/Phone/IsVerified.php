<?php
declare(strict_types=1);

namespace IDangerous\PhoneOtpVerification\Controller\Phone;

use IDangerous\PhoneOtpVerification\Helper\Customer as CustomerHelper;
use Magento\Customer\Model\Session;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;

/**
 * Check if a phone is already verified for the current customer (per-phone rule).
 */
class IsVerified extends Action
{
    private $resultJsonFactory;
    private $customerSession;
    private $customerHelper;

    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        Session $customerSession,
        CustomerHelper $customerHelper
    ) {
        parent::__construct($context);
        $this->resultJsonFactory = $resultJsonFactory;
        $this->customerSession = $customerSession;
        $this->customerHelper = $customerHelper;
    }

    public function execute()
    {
        $result = $this->resultJsonFactory->create();
        $phone = (string)$this->getRequest()->getParam('phone');

        if (!$this->customerSession->isLoggedIn() || trim($phone) === '') {
            return $result->setData(['success' => true, 'verified' => false]);
        }

        $customerId = (int)$this->customerSession->getCustomerId();
        $verified = false;

        // profile verified phone
        if ($this->customerHelper->isAddressPhoneSameAsCustomerPhone($phone)) {
            $verified = true;
        }

        // any verified address phone for this customer
        if (!$verified && $customerId > 0) {
            $verified = $this->customerHelper->isAnyVerifiedAddressPhoneForCustomer($customerId, $phone);
        }

        return $result->setData(['success' => true, 'verified' => (bool)$verified]);
    }
}

