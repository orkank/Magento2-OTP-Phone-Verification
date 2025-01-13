<?php
namespace IDangerous\PhoneOtpVerification\Plugin\Customer;

use Magento\Customer\Controller\Account\CreatePost;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Message\ManagerInterface;
use IDangerous\PhoneOtpVerification\Helper\Config;

class AccountCreatePost
{
    protected $config;
    protected $messageManager;
    protected $resultRedirectFactory;
    protected $request;

    public function __construct(
        Config $config,
        ManagerInterface $messageManager,
        RedirectFactory $resultRedirectFactory,
        RequestInterface $request
    ) {
        $this->config = $config;
        $this->messageManager = $messageManager;
        $this->resultRedirectFactory = $resultRedirectFactory;
        $this->request = $request;
    }

    public function aroundExecute(CreatePost $subject, callable $proceed)
    {
        if ($this->config->isEnabledForRegistration()
            && !$this->config->isOptionalForRegistration()
        ) {
            $phoneVerified = $this->request->getParam('phone_verified');
            if (!$phoneVerified) {
                $this->messageManager->addErrorMessage(
                    __('Phone verification is required to create an account.')
                );
                $resultRedirect = $this->resultRedirectFactory->create();
                return $resultRedirect->setPath('*/*/create');
            }
        }

        return $proceed();
    }
}