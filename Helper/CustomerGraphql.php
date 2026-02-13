<?php

namespace IDangerous\PhoneOtpVerification\Helper;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Authorization\Model\UserContextInterface;
use Psr\Log\LoggerInterface;

class CustomerGraphql extends AbstractHelper
{
    /**
     * @var CustomerRepositoryInterface
     */
    protected $customerRepository;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @param Context $context
     * @param CustomerRepositoryInterface $customerRepository
     * @param LoggerInterface $logger
     */
    public function __construct(
        Context $context,
        CustomerRepositoryInterface $customerRepository,
        LoggerInterface $logger
    ) {
        parent::__construct($context);
        $this->customerRepository = $customerRepository;
        $this->logger = $logger;
    }

    /**
     * Get customer ID from GraphQL context
     *
     * @param mixed $context
     * @return int|null
     */
    public function getCurrentCustomerId($context)
    {
        try {
            // Check if context has user information
            if (isset($context) && is_object($context)) {
                // Check if context has getUserType and getUserId methods (Magento GraphQL pattern)
                if (method_exists($context, 'getUserType') && method_exists($context, 'getUserId')) {
                    $userType = $context->getUserType();
                    $userId = $context->getUserId();

                    // Make sure it's a customer context, not admin
                    if ($userType === UserContextInterface::USER_TYPE_CUSTOMER && $userId > 0) {
                        $this->logger->info('GraphQL CustomerHelper: Found customer ID from context with user type: ' . $userId);
                        return (int)$userId;
                    }
                }

                // Fallback: if userType is not available, try getUserId (some setups)
                if (method_exists($context, 'getUserId')) {
                    $userId = $context->getUserId();
                    if ($userId && $userId > 0) {
                        $this->logger->info('GraphQL CustomerHelper: Found customer ID from getUserId (no userType): ' . $userId);
                        return (int)$userId;
                    }
                }

                // Try to get extension attributes
                if (method_exists($context, 'getExtensionAttributes')) {
                    $contextExtensions = $context->getExtensionAttributes();
                    if ($contextExtensions && method_exists($contextExtensions, 'getIsCustomer') && $contextExtensions->getIsCustomer()) {
                        if (method_exists($contextExtensions, 'getCustomerId')) {
                            $userId = $contextExtensions->getCustomerId();
                            if ($userId) {
                                $this->logger->info('GraphQL CustomerHelper: Found customer ID from context extensions: ' . $userId);
                                return (int)$userId;
                            }
                        }
                    }
                }
            }

            // Fallback - check for array context
            if (isset($context) && is_array($context)) {
                if (isset($context['user_id']) && $context['user_id'] > 0) {
                    $this->logger->info('GraphQL CustomerHelper: Found customer ID from array context: ' . $context['user_id']);
                    return (int)$context['user_id'];
                }
            }

            $this->logger->info('GraphQL CustomerHelper: No authenticated customer found in context');
            return null;

        } catch (\Exception $e) {
            $this->logger->error('GraphQL CustomerHelper: Error getting customer ID: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Get customer by ID
     *
     * @param int $customerId
     * @return \Magento\Customer\Api\Data\CustomerInterface|null
     */
    public function getCustomerById($customerId)
    {
        try {
            return $this->customerRepository->getById($customerId);
        } catch (NoSuchEntityException $e) {
            $this->logger->error('GraphQL CustomerHelper: Customer not found: ' . $customerId);
            return null;
        } catch (\Exception $e) {
            $this->logger->error('GraphQL CustomerHelper: Error loading customer: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Check if customer is authenticated in GraphQL context
     *
     * @param mixed $context
     * @return bool
     */
    public function isCustomerLoggedIn($context)
    {
        $customerId = $this->getCurrentCustomerId($context);
        return $customerId !== null && $customerId > 0;
    }

    /**
     * Save verified phone number to customer
     *
     * @param int $customerId
     * @param string $phoneNumber
     * @return bool
     */
    public function saveVerifiedPhone($customerId, $phoneNumber)
    {
        try {
            $customer = $this->getCustomerById($customerId);
            if (!$customer) {
                $this->logger->error('GraphQL CustomerHelper: Customer not found for ID: ' . $customerId);
                return false;
            }

            $this->logger->info('GraphQL CustomerHelper: Saving phone for customer ID: ' . $customerId);

            // Set phone number attribute
            $customer->setCustomAttribute('phone_number', $phoneNumber);

            // Set phone verified attribute
            $customer->setCustomAttribute('phone_verified', 1);

            // Save the customer
            $this->customerRepository->save($customer);

            $this->logger->info('GraphQL CustomerHelper: Phone verified and saved successfully for customer: ' . $customerId);
            return true;

        } catch (\Exception $e) {
            $this->logger->error('GraphQL CustomerHelper: Error saving verified phone: ' . $e->getMessage());
            $this->logger->error($e->getTraceAsString());
            return false;
        }
    }
}