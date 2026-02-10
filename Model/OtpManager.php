<?php

namespace IDangerous\PhoneOtpVerification\Model;

use Magento\Customer\Model\Session;
use Magento\Framework\Exception\LocalizedException;
use IDangerous\Sms\Model\Api\SmsService;
use Magento\Customer\Model\ResourceModel\Customer\CollectionFactory;
use Magento\Framework\App\CacheInterface;
use IDangerous\PhoneOtpVerification\Helper\Config;

class OtpManager
{
    /**
     * @var Session
     */
    protected $session;

    /**
     * @var SmsService
     */
    protected $smsService;

    /**
     * @var CollectionFactory
     */
    protected $customerCollectionFactory;

    /**
     * @var CacheInterface
     */
    protected $cache;

    /**
     * @var Config
     */
    protected $configHelper;

    /**
     * @param SmsService $smsService
     * @param Session $session
     * @param CollectionFactory $customerCollectionFactory
     * @param CacheInterface $cache
     * @param Config $configHelper
     */
    public function __construct(
        SmsService $smsService,
        Session $session,
        CollectionFactory $customerCollectionFactory,
        CacheInterface $cache,
        Config $configHelper
    ) {
        $this->smsService = $smsService;
        $this->session = $session;
        $this->customerCollectionFactory = $customerCollectionFactory;
        $this->cache = $cache;
        $this->configHelper = $configHelper;
    }

    protected function isPhoneAvailable($phone)
    {
        // Skip validation for logged-in users updating their own number
        if ($this->session->isLoggedIn()) {
            $currentCustomer = $this->session->getCustomer();
            $currentPhone = $currentCustomer->getCustomAttribute('phone_number');
            if ($currentPhone && $currentPhone->getValue() === $phone) {
                return true;
            }
        }

        $collection = $this->customerCollectionFactory->create();
        $collection->addAttributeToSelect('phone_number')
                  ->addAttributeToSelect('phone_verified')
                  ->addAttributeToFilter('phone_number', $phone)
                  ->addAttributeToFilter('phone_verified', 1);

        return $collection->getSize() === 0;
    }

    public function sendOtp($phone, bool $skipAvailabilityCheck = false)
    {
        try {
            // Check if phone is available (only for non-logged in users or different number)
            if (!$skipAvailabilityCheck && !$this->isPhoneAvailable($phone)) {
                throw new LocalizedException(
                    __('This phone number is already registered and verified by another user.')
                );
            }

            $otp = $this->generateOtp();
            // Get message template from admin panel configuration
            $messageTemplate = $this->configHelper->getOtpMessage();
            // Replace {otp} placeholder with actual OTP code
            $message = str_replace('{otp}', $otp, $messageTemplate);

            $otpData = [
                'code' => $otp,
                'phone' => $phone,
                'timestamp' => time()
            ];

                        // Store in session (for web context)
            $this->session->setPhoneOtp($otpData);

            // Also store in cache (for GraphQL context) - use multiple cache keys for better lookup
            $phoneKey = 'phone_otp_' . md5($phone);
            $otpKey = 'otp_code_' . $otp; // Direct OTP lookup

            $this->cache->save(json_encode($otpData), $phoneKey, [], 300); // 5 minutes expiry
            $this->cache->save(json_encode($otpData), $otpKey, [], 300); // 5 minutes expiry

            // Debug logging
            error_log('OtpManager::sendOtp - Storing OTP data: ' . json_encode($otpData));
            error_log('OtpManager::sendOtp - Cache keys: ' . $phoneKey . ', ' . $otpKey);

            $result = $this->smsService->sendOtpSms($phone, $message);

            if (!$result['success']) {
                throw new LocalizedException(__($result['message']));
            }

            return $otp;
        } catch (\Exception $e) {
            throw new LocalizedException(__('Failed to send OTP: %1', $e->getMessage()));
        }
    }

    public function verifyOtp($inputOtp)
    {
        // First try session storage
        $otpData = $this->session->getPhoneOtp();

        // If no session data, try direct OTP cache lookup
        if (!$otpData || !isset($otpData['code']) || !isset($otpData['phone'])) {
            $otpKey = 'otp_code_' . $inputOtp;
            $cachedData = $this->cache->load($otpKey);
            if ($cachedData) {
                $otpData = json_decode($cachedData, true);
                error_log('OtpManager::verifyOtp - Found OTP data in cache: ' . $cachedData);
            }
        }

        // Debug logging
        error_log('OtpManager::verifyOtp - Session data: ' . json_encode($otpData));
        error_log('OtpManager::verifyOtp - Input OTP: ' . $inputOtp);

        if (!$otpData || !isset($otpData['code']) || !isset($otpData['phone'])) {
            error_log('OtpManager::verifyOtp - No OTP data found in session or cache');
            return false;
        }

        // Check if OTP is expired (5 minutes validity)
        $timestamp = $otpData['timestamp'] ?? 0;
        $currentTime = time();
        $timeElapsed = $currentTime - $timestamp;
        error_log('OtpManager::verifyOtp - Time elapsed: ' . $timeElapsed . ' seconds');

        if ($timeElapsed > 300) {
            error_log('OtpManager::verifyOtp - OTP expired');
            $this->session->unsPhoneOtp();
            // Also clean cache
            if (isset($otpData['phone']) && isset($otpData['code'])) {
                $phoneKey = 'phone_otp_' . md5($otpData['phone']);
                $otpKey = 'otp_code_' . $otpData['code'];
                $this->cache->remove($phoneKey);
                $this->cache->remove($otpKey);
            }
            return false;
        }

        // Convert both to strings and trim for comparison
        $storedOtp = trim((string)$otpData['code']);
        $inputOtp = trim((string)$inputOtp);

        error_log('OtpManager::verifyOtp - Stored OTP: ' . $storedOtp . ' vs Input OTP: ' . $inputOtp);

        if ($storedOtp === $inputOtp) {
            error_log('OtpManager::verifyOtp - OTP match successful');

            // Store in session for further processing
            if (!$this->session->getPhoneOtp()) {
                $this->session->setPhoneOtp($otpData);
            }

            return true;
        }

        error_log('OtpManager::verifyOtp - OTP match failed');
        return false;
    }



    protected function generateOtp()
    {
        return str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }
}