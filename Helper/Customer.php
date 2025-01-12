<?php
namespace IDangerous\PhoneOtpVerification\Helper;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Model\Session;
use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;

class Customer extends AbstractHelper
{
    /**
     * @var CustomerRepositoryInterface
     */
    protected $customerRepository;

    /**
     * @var Session
     */
    protected $customerSession;

    public function __construct(
        Context $context,
        CustomerRepositoryInterface $customerRepository,
        Session $customerSession
    ) {
        parent::__construct($context);
        $this->customerRepository = $customerRepository;
        $this->customerSession = $customerSession;
    }

    public function saveVerifiedPhone($phoneNumber)
    {
        try {
            if ($this->customerSession->isLoggedIn()) {
                $customerId = $this->customerSession->getCustomerId();
                $this->_logger->debug('Saving phone for customer ID: ' . $customerId);

                $customer = $this->customerRepository->getById($customerId);

                // Check if attributes exist before setting them
                $phoneAttribute = $customer->getCustomAttribute('phone_number');

                if (!$phoneAttribute) {
                    $this->_logger->debug('Creating phone_number attribute');
                    $customer->setCustomAttribute('phone_number', $phoneNumber);
                } else {
                    $this->_logger->debug('Updating phone_number attribute');
                    $phoneAttribute->setValue($phoneNumber);
                }

                $verifiedAttribute = $customer->getCustomAttribute('phone_verified');

                if (!$verifiedAttribute) {
                    $this->_logger->debug('Creating phone_verified attribute');
                    $customer->setCustomAttribute('phone_verified', 1);
                } else {
                    $this->_logger->debug('Updating phone_verified attribute');
                    $verifiedAttribute->setValue(1);
                }

                // Save the customer
                $this->customerRepository->save($customer);
                $this->_logger->debug('Customer saved successfully');

                return true;
            } else {
                $this->_logger->debug('Customer not logged in');
            }
        } catch (\Exception $e) {
            $this->_logger->error('Error saving verified phone: ' . $e->getMessage());
            $this->_logger->error($e->getTraceAsString());
        }
        return false;
    }
}