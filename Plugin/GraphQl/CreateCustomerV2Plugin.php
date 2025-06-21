<?php

namespace IDangerous\PhoneOtpVerification\Plugin\GraphQl;

use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Api\Data\CustomerInterfaceFactory;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Customer\Model\Session;
use Psr\Log\LoggerInterface;

class CreateCustomerV2Plugin
{
    /**
     * @var Session
     */
    private $customerSession;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var CustomerInterfaceFactory
     */
    private $customerFactory;

    /**
     * @var CustomerRepositoryInterface
     */
    private $customerRepository;

    /**
     * @param Session $customerSession
     * @param LoggerInterface $logger
     * @param CustomerInterfaceFactory $customerFactory
     * @param CustomerRepositoryInterface $customerRepository
     */
    public function __construct(
        Session $customerSession,
        LoggerInterface $logger,
        CustomerInterfaceFactory $customerFactory,
        CustomerRepositoryInterface $customerRepository
    ) {
        $this->customerSession = $customerSession;
        $this->logger = $logger;
        $this->customerFactory = $customerFactory;
        $this->customerRepository = $customerRepository;
    }

    /**
     * After customer creation, add phone attributes if they exist
     *
     * @param CustomerRepositoryInterface $subject
     * @param CustomerInterface $result
     * @param CustomerInterface $customer
     * @param string|null $passwordHash
     * @return CustomerInterface
     */
    public function afterSave(
        CustomerRepositoryInterface $subject,
        CustomerInterface $result,
        CustomerInterface $customer,
        $passwordHash = null
    ): CustomerInterface {
        try {
            // Check if this is a GraphQL context (we can detect by checking for verified phone in session)
            $verifiedPhone = $this->customerSession->getRegistrationVerifiedPhone();

            // If we have a verified phone in session, this is likely a GraphQL registration
            if ($verifiedPhone) {
                $this->logger->info('GraphQL CreateCustomer: Processing phone attributes for customer ID: ' . $result->getId());

                // Get the customer again to ensure we have the latest data
                $savedCustomer = $this->customerRepository->getById($result->getId());

                // Check if phone_number is not already set
                $phoneAttribute = $savedCustomer->getCustomAttribute('phone_number');
                if (!$phoneAttribute || empty($phoneAttribute->getValue())) {
                    $savedCustomer->setCustomAttribute('phone_number', $verifiedPhone);
                    $this->logger->info('GraphQL CreateCustomer: Setting phone_number to: ' . $verifiedPhone);
                }

                // Set phone_verified to 1
                $savedCustomer->setCustomAttribute('phone_verified', 1);
                $this->logger->info('GraphQL CreateCustomer: Setting phone_verified to 1');

                // Save the customer again with the attributes
                $result = $this->customerRepository->save($savedCustomer);

                // Clean up the session
                $this->customerSession->unsRegistrationVerifiedPhone();
                $this->logger->info('GraphQL CreateCustomer: Phone attributes saved and session cleaned');
            }

        } catch (\Exception $e) {
            $this->logger->error('GraphQL CreateCustomer Plugin Error: ' . $e->getMessage());
            $this->logger->error($e->getTraceAsString());
        }

        return $result;
    }
}