<?php
namespace IDangerous\PhoneOtpVerification\Helper;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\AddressRepositoryInterface;
use Magento\Customer\Model\Session;
use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use IDangerous\PhoneOtpVerification\Helper\Config;
use Magento\Customer\Model\ResourceModel\Address as AddressResource;
use Magento\Customer\Model\AddressFactory;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;

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

    /**
     * @var AddressRepositoryInterface
     */
    protected $addressRepository;

    /**
     * @var Config
     */
    protected $configHelper;

    /**
     * @var AddressResource
     */
    protected $addressResource;

    /**
     * @var AddressFactory
     */
    protected $addressFactory;

    /**
     * @var ResourceConnection
     */
    protected $resourceConnection;

    /**
     * @var RemoteAddress
     */
    private $remoteAddress;

    public function __construct(
        Context $context,
        CustomerRepositoryInterface $customerRepository,
        Session $customerSession,
        AddressRepositoryInterface $addressRepository,
        Config $configHelper,
        AddressResource $addressResource,
        AddressFactory $addressFactory,
        ResourceConnection $resourceConnection,
        RemoteAddress $remoteAddress
    ) {
        parent::__construct($context);
        $this->customerRepository = $customerRepository;
        $this->customerSession = $customerSession;
        $this->addressRepository = $addressRepository;
        $this->configHelper = $configHelper;
        $this->addressResource = $addressResource;
        $this->addressFactory = $addressFactory;
        $this->resourceConnection = $resourceConnection;
        $this->remoteAddress = $remoteAddress;
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

    /**
     * Check if address phone matches customer's verified phone
     *
     * @param string $addressPhone
     * @return bool
     */
    public function isAddressPhoneSameAsCustomerPhone($addressPhone)
    {
        if (!$this->customerSession->isLoggedIn()) {
            return false;
        }

        try {
            $customerId = $this->customerSession->getCustomerId();
            $customer = $this->customerRepository->getById($customerId);
            $customerPhoneAttribute = $customer->getCustomAttribute('phone_number');
            $customerPhoneVerifiedAttribute = $customer->getCustomAttribute('phone_verified');

            if (!$customerPhoneAttribute || !$customerPhoneVerifiedAttribute) {
                return false;
            }

            $customerPhone = $customerPhoneAttribute->getValue();
            $customerPhoneVerified = (bool)$customerPhoneVerifiedAttribute->getValue();

            // Normalize phone numbers for comparison (remove spaces, dashes, etc.)
            $normalizedAddressPhone = preg_replace('/[^0-9]/', '', $addressPhone);
            $normalizedCustomerPhone = preg_replace('/[^0-9]/', '', $customerPhone);

            return $normalizedAddressPhone === $normalizedCustomerPhone && $customerPhoneVerified;
        } catch (\Exception $e) {
            $this->_logger->error('Error checking phone match: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Check if address phone matches a specific customer's verified profile phone.
     * Useful for API contexts where customer session is not available.
     */
    public function isAddressPhoneSameAsCustomerPhoneForCustomerId(int $customerId, string $addressPhone): bool
    {
        if ($customerId <= 0) {
            return false;
        }

        try {
            $customer = $this->customerRepository->getById($customerId);
            $customerPhoneAttribute = $customer->getCustomAttribute('phone_number');
            $customerPhoneVerifiedAttribute = $customer->getCustomAttribute('phone_verified');

            if (!$customerPhoneAttribute || !$customerPhoneVerifiedAttribute) {
                return false;
            }

            $customerPhone = (string)$customerPhoneAttribute->getValue();
            $customerPhoneVerified = (bool)$customerPhoneVerifiedAttribute->getValue();
            if (!$customerPhoneVerified) {
                return false;
            }

            $normalizedAddressPhone = preg_replace('/[^0-9]/', '', $addressPhone);
            $normalizedCustomerPhone = preg_replace('/[^0-9]/', '', $customerPhone);

            return $normalizedAddressPhone !== '' && $normalizedAddressPhone === $normalizedCustomerPhone;
        } catch (\Exception $e) {
            $this->_logger->error('Error checking phone match by customerId: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Per-phone skip rule: if customer has any verified address with the same phone, treat as verified.
     */
    public function isAnyVerifiedAddressPhoneForCustomer(int $customerId, string $addressPhone): bool
    {
        if ($customerId <= 0) {
            return false;
        }

        $normalized = preg_replace('/[^0-9]/', '', $addressPhone);
        if ($normalized === '' || strlen($normalized) < 10) {
            return false;
        }

        try {
            $connection = $this->resourceConnection->getConnection();
            $verTable = $this->resourceConnection->getTableName('idangerous_address_phone_verification');
            $addrTable = $this->resourceConnection->getTableName('customer_address_entity');

            $rows = $connection->fetchCol(
                $connection->select()
                    ->from(['v' => $verTable], [])
                    ->joinInner(['a' => $addrTable], 'a.entity_id = v.address_id', ['telephone'])
                    ->where('a.parent_id = ?', $customerId)
                    ->where('v.is_verified = ?', 1)
            );

            foreach ($rows as $tel) {
                $telNorm = preg_replace('/[^0-9]/', '', (string)$tel);
                if ($telNorm !== '' && $telNorm === $normalized) {
                    return true;
                }
            }

            return false;
        } catch (\Exception $e) {
            $this->_logger->error('Error checking any verified address phone: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Persist verification for all of customer's saved addresses that match the given phone.
     *
     * This is used for app checkout flows where REST payload may not include customer_address_id.
     * In that case we can only mark verification "per phone" across saved addresses.
     */
    public function markVerifiedAddressesByPhoneForCustomer(int $customerId, string $addressPhone): int
    {
        if ($customerId <= 0) {
            return 0;
        }

        $normalized = preg_replace('/[^0-9]/', '', $addressPhone);
        if ($normalized === '' || strlen($normalized) < 10) {
            return 0;
        }

        try {
            $connection = $this->resourceConnection->getConnection();
            $addrTable = $this->resourceConnection->getTableName('customer_address_entity');
            $verTable = $this->resourceConnection->getTableName('idangerous_address_phone_verification');

            $rows = $connection->fetchAll(
                $connection->select()
                    ->from($addrTable, ['entity_id', 'telephone'])
                    ->where('parent_id = ?', $customerId)
            );

            if (!$rows) {
                return 0;
            }

            $ip = (string)$this->remoteAddress->getRemoteAddress();
            $count = 0;

            foreach ($rows as $row) {
                $tel = (string)($row['telephone'] ?? '');
                $telNorm = preg_replace('/[^0-9]/', '', $tel);
                if ($telNorm === '' || $telNorm !== $normalized) {
                    continue;
                }

                $addressId = (int)($row['entity_id'] ?? 0);
                if ($addressId <= 0) {
                    continue;
                }

                $connection->insertOnDuplicate(
                    $verTable,
                    [
                        'address_id' => $addressId,
                        'is_verified' => 1,
                        'verified_at' => new \Zend_Db_Expr('NOW()'),
                        'verified_ip' => $ip ?: null
                    ],
                    ['is_verified', 'verified_at', 'verified_ip']
                );
                $count++;
            }

            return $count;
        } catch (\Exception $e) {
            $this->_logger->error('Error marking verified addresses by phone: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Check if address phone is verified
     *
     * @param int $addressId
     * @return bool
     */
    public function isAddressPhoneVerified($addressId)
    {
        try {
            // Read from separate verification table
            $connection = $this->resourceConnection->getConnection();
            $tableName = $this->resourceConnection->getTableName('idangerous_address_phone_verification');
            
            $select = $connection->select()
                ->from($tableName, ['is_verified'])
                ->where('address_id = ?', $addressId);
            
            $isVerified = $connection->fetchOne($select);

            if ((bool)$isVerified) {
                return true;
            }

            /**
             * Backward compatibility / convenience:
             * If the address phone matches customer's already verified profile phone,
             * treat the address as verified even if it wasn't explicitly marked yet.
             *
             * This is important for old addresses created before the feature
             * and for cases where the address was never re-saved.
             */
            if (!$this->customerSession->isLoggedIn()) {
                return false;
            }

            $customerId = (int)$this->customerSession->getCustomerId();
            if ($customerId <= 0) {
                return false;
            }

            $addressTable = $this->resourceConnection->getTableName('customer_address_entity');
            $addressRow = $connection->fetchRow(
                $connection->select()
                    ->from($addressTable, ['telephone', 'parent_id'])
                    ->where('entity_id = ?', (int)$addressId)
                    ->limit(1)
            );

            if (!$addressRow || empty($addressRow['telephone'])) {
                return false;
            }

            // Only auto-treat as verified for the logged-in customer's own address
            if ((int)$addressRow['parent_id'] !== $customerId) {
                return false;
            }

            $telephone = (string)$addressRow['telephone'];
            if (!$this->isAddressPhoneSameAsCustomerPhone($telephone)) {
                return false;
            }

            // Persist the verified marker so UI/queries stay consistent.
            // (idempotent insert/update)
            $ip = (string)$this->remoteAddress->getRemoteAddress();
            $connection->insertOnDuplicate(
                $tableName,
                [
                    'address_id' => (int)$addressId,
                    'is_verified' => 1,
                    'verified_at' => new \Zend_Db_Expr('NOW()'),
                    'verified_ip' => $ip ?: null
                ],
                ['is_verified', 'verified_at', 'verified_ip']
            );

            return true;
        } catch (\Exception $e) {
            $this->_logger->error('Error checking address phone verification: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Save verified phone for address
     * Uses direct resource model to avoid infinite loop with AddressRepositoryPlugin
     *
     * @param int $addressId
     * @param string $phoneNumber
     * @return bool
     */
    public function saveVerifiedAddressPhone($addressId, $phoneNumber)
    {
        try {
            $this->_logger->info('Helper::saveVerifiedAddressPhone - START', [
                'address_id' => $addressId,
                'phone_number' => $phoneNumber
            ]);

            $address = $this->addressRepository->getById($addressId);
            
            // Check if customer owns this address
            if ($this->customerSession->isLoggedIn()) {
                $customerId = $this->customerSession->getCustomerId();
                if ($address->getCustomerId() != $customerId) {
                    $this->_logger->error('Customer does not own this address', [
                        'customer_id' => $customerId,
                        'address_customer_id' => $address->getCustomerId()
                    ]);
                    return false;
                }
            }

            // Insert or update in separate verification table
            $connection = $this->resourceConnection->getConnection();
            $tableName = $this->resourceConnection->getTableName('idangerous_address_phone_verification');
            
            $this->_logger->info('Helper::saveVerifiedAddressPhone - Inserting/Updating', [
                'table' => $tableName,
                'address_id' => $addressId
            ]);
            
            $ip = (string)$this->remoteAddress->getRemoteAddress();
            $connection->insertOnDuplicate(
                $tableName,
                [
                    'address_id' => $addressId,
                    'is_verified' => 1,
                    'verified_at' => new \Zend_Db_Expr('NOW()'),
                    'verified_ip' => $ip ?: null
                ],
                ['is_verified', 'verified_at', 'verified_ip']
            );
            
            $this->_logger->info('Helper::saveVerifiedAddressPhone - SUCCESS', [
                'address_id' => $addressId
            ]);
            return true;
        } catch (\Exception $e) {
            $this->_logger->error('Helper::saveVerifiedAddressPhone - EXCEPTION', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    /**
     * Check if address phone verification is required
     *
     * @param \Magento\Customer\Api\Data\AddressInterface $address
     * @return bool
     */
    public function isAddressPhoneVerificationRequired($address)
    {
        if (!$this->configHelper->isAddressPhoneVerificationEnabled()) {
            return false;
        }

        $telephone = $address->getTelephone();
        if (empty($telephone)) {
            return false;
        }

        // If phone matches customer's verified phone, no verification needed
        if ($this->isAddressPhoneSameAsCustomerPhone($telephone)) {
            return false;
        }

        // For existing addresses, check if verification is required
        if ($address->getId()) {
            if ($this->isAddressPhoneVerified($address->getId())) {
                return false;
            }
            // If address exists but not verified, check if unverified verification is required
            return $this->configHelper->isUnverifiedAddressVerificationRequired();
        }

        // For new addresses, verification is required if enabled
        return true;
    }
}