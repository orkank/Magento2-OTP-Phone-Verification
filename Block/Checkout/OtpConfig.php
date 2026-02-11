<?php
declare(strict_types=1);

namespace IDangerous\PhoneOtpVerification\Block\Checkout;

use IDangerous\PhoneOtpVerification\Helper\Config;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;

class OtpConfig extends Template
{
    /**
     * @var Config
     */
    private $configHelper;

    public function __construct(
        Context $context,
        Config $configHelper,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->configHelper = $configHelper;
    }

    public function getAddressOtpModalNote(): string
    {
        return $this->configHelper->getAddressOtpModalNote();
    }
}

