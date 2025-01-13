<?php
namespace IDangerous\PhoneOtpVerification\Controller\Phone;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Customer\Model\ResourceModel\Customer\CollectionFactory;

class Validate extends Action
{
    protected $resultJsonFactory;
    protected $customerCollectionFactory;

    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        CollectionFactory $customerCollectionFactory
    ) {
        parent::__construct($context);
        $this->resultJsonFactory = $resultJsonFactory;
        $this->customerCollectionFactory = $customerCollectionFactory;
    }

    public function execute()
    {
        $result = $this->resultJsonFactory->create();
        try {
            $phone = $this->getRequest()->getParam('phone');

            if (!$phone) {
                throw new \Exception(__('Please enter phone number.'));
            }

            $collection = $this->customerCollectionFactory->create();
            $collection->addAttributeToSelect('phone_number')
                      ->addAttributeToSelect('phone_verified')
                      ->addAttributeToFilter('phone_number', $phone)
                      ->addAttributeToFilter('phone_verified', 1);

            if ($collection->getSize() > 0) {
                return $result->setData([
                    'success' => false,
                    'message' => __('This phone number is already registered and verified by another user.')
                ]);
            }

            return $result->setData([
                'success' => true,
                'message' => __('Phone number is available.')
            ]);
        } catch (\Exception $e) {
            return $result->setData([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }
}