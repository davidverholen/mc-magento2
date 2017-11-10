<?php
/**
 * mc-magento2 Magento Component
 *
 * @category Ebizmarts
 * @package mc-magento2
 * @author Ebizmarts Team <info@ebizmarts.com>
 * @copyright Ebizmarts (http://ebizmarts.com)
 * @license     http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 * @date: 10/6/17 1:15 PM
 * @file: Coupon.php
 */
namespace Ebizmarts\MailChimp\Model\Api;

use Magento\TestFramework\Inspection\Exception;

class PromoCodes
{
    const MAX = 100;
    protected $_batchId;
    protected $_token;
    /**
     * @var \Ebizmarts\MailChimp\Helper\Data
     */
    private $_helper;
    /**
     * @var \Magento\SalesRule\Model\ResourceModel\Coupon\CollectionFactory
     */
    private $_collection;
    /**
     * @var \Ebizmarts\MailChimp\Model\MailChimpSyncEcommerceFactory
     */
    private $_chimpSyncEcommerce;
    /**
     * @var \Magento\Framework\Stdlib\DateTime\DateTime
     */
    private $_date;
    /**
     * @var PromoRules
     */
    private $_promoRules;
    /**
     * @var \Ebizmarts\MailChimp\Model\ResourceModel\MailChimpSyncEcommerce\CollectionFactory
     */
    private $_syncCollection;

    /**
     * PromoCodes constructor.
     * @param \Ebizmarts\MailChimp\Helper\Data $helper
     * @param \Magento\SalesRule\Model\ResourceModel\Coupon\CollectionFactory $collection
     * @param \Ebizmarts\MailChimp\Model\MailChimpSyncEcommerceFactory $chimpSyncEcommerce
     * @param \Magento\Framework\Stdlib\DateTime\DateTime $date
     * @param PromoRules $promoRules
     * @param \Ebizmarts\MailChimp\Model\ResourceModel\MailChimpSyncEcommerce\CollectionFactory $syncCollection
     */
    public function __construct(
        \Ebizmarts\MailChimp\Helper\Data $helper,
        \Magento\SalesRule\Model\ResourceModel\Coupon\CollectionFactory $collection,
        \Ebizmarts\MailChimp\Model\MailChimpSyncEcommerceFactory $chimpSyncEcommerce,
        \Magento\Framework\Stdlib\DateTime\DateTime $date,
        \Ebizmarts\MailChimp\Model\Api\PromoRules $promoRules,
        \Ebizmarts\MailChimp\Model\ResourceModel\MailChimpSyncEcommerce\CollectionFactory $syncCollection
    )
    {
        $this->_helper              = $helper;
        $this->_collection          = $collection;
        $this->_chimpSyncEcommerce  = $chimpSyncEcommerce;
        $this->_date                = $date;
        $this->_batchId             = \Ebizmarts\MailChimp\Helper\Data::IS_PROMO_CODE. '_' . $this->_date->gmtTimestamp();
        $this->_promoRules          = $promoRules;
        $this->_syncCollection      = $syncCollection;
    }
    public function sendCoupons($magentoStoreId)
    {
        $mailchimpStoreId = $this->_helper->getConfigValue(\Ebizmarts\MailChimp\Helper\Data::XML_MAILCHIMP_STORE, $magentoStoreId);
        $batchArray = [];
        $batchArray = array_merge($batchArray, $this->_sendDeletedCoupons($mailchimpStoreId, $magentoStoreId));
//        $batchArray = array_merge($batchArray, $this->_sendModifiedCoupons($mailchimpStoreId, $magentoStoreId));
        $batchArray = array_merge($batchArray, $this->_sendNewCoupons($mailchimpStoreId, $magentoStoreId));

        return $batchArray;
    }
    protected function _sendDeletedCoupons($mailchimpStoreId, $magentoStoreId)
    {
        $batchArray = [];
        $websiteId = $this->_helper->getWebsiteId($magentoStoreId);
        $collection = $this->_syncCollection->create();
        $collection->addFieldToFilter('mailchimp_store_id',['eq'=>$mailchimpStoreId])
            ->addFieldToFilter('type',['eq'=>\Ebizmarts\MailChimp\Helper\Data::IS_PROMO_CODE])
            ->addFieldToFilter('mailchimp_sync_deleted',['eq'=>1]);
        $collection->getSelect()->limit(self::MAX);
        $counter = 0;
        /**
         * @var $syncCoupon \Ebizmarts\MailChimp\Model\MailChimpSyncEcommerce
         */
        foreach($collection as $coupon)
        {
            $couponId = $coupon->getRelatedId();
            $ruleId = $coupon->getDeletedRelatedId();
            $batchArray[$counter]['method'] = 'DELETE';
            $batchArray[$counter]['operation_id'] = $this->_batchId . '_' . $couponId;
            $batchArray[$counter]['path'] = "/ecommerce/stores/$mailchimpStoreId/promo-rules/$ruleId/promo-codes/$couponId";
            $counter++;
            $syncCoupon =$this->_helper->getChimpSyncEcommerce($mailchimpStoreId, $couponId, \Ebizmarts\MailChimp\Helper\Data::IS_PROMO_CODE);
            $syncCoupon->getResource()->delete($syncCoupon);
        }
        return $batchArray;
    }
    protected function _sendNewCoupons($mailchimpStoreId, $magentoStoreId)
    {
        $batchArray = [];
        $websiteId = $this->_helper->getWebsiteId($magentoStoreId);
        $collection = $this->_collection->create();
        $collection->getSelect()->joinLeft(
            ["websites" => $this->_helper->getTableName("salesrule_website")],
            "main_table.rule_id = websites.rule_id and website_id = $websiteId"
        );
        $collection->getSelect()->joinLeft(
            ['m4m' => $this->_helper->getTableName('mailchimp_sync_ecommerce')],
            "m4m.related_id = main_table.coupon_id and m4m.type = '".\Ebizmarts\MailChimp\Helper\Data::IS_PROMO_CODE.
            "' and m4m.mailchimp_store_id = '".$mailchimpStoreId."'",
            ['m4m.*']
        );
        $collection->getSelect()->joinLeft(
          ['rules'=>$this->_helper->getTableName('salesrule')],
          'main_table.rule_id = rules.rule_id'
        );
        $collection->getSelect()->where("m4m.mailchimp_sync_delta IS null and (rules.use_auto_generation = 1 and main_table.is_primary is null or rules.use_auto_generation = 0 and main_table.is_primary = 1)");
        $collection->getSelect()->limit(self::MAX);
        $counter = 0;
        /**
         * @var $item \Magento\SalesRule\Model\Coupon
         */
        foreach($collection as $item)
        {
            $this->_token = null;
            $ruleId = $item->getRuleId();
            $couponId = $item->getCouponId();
            try {
                $promoRule = $this->_helper->getChimpSyncEcommerce($mailchimpStoreId, $ruleId, \Ebizmarts\MailChimp\Helper\Data::IS_PROMO_RULE);
                if (!$promoRule->getMailchimpSyncDelta() || $promoRule->getMailchimpSyncDelta() < $this->_helper->getMCMinSyncDateFlag($magentoStoreId)) {
                    // must send the promorule before the promocode
                    $newPromoRule = $this->_promoRules->getNewPromoRule($ruleId,$mailchimpStoreId,$magentoStoreId);
                    if(!empty($newPromoRule)) {
                        $batchArray[$counter] = $newPromoRule;
                        $counter++;
                    } else {
                        $error = __('Parent rule with id ' . $ruleId . 'has not been correctly sent.');
                        $this->_updateSyncData($mailchimpStoreId, $ruleId, $this->_date->gmtDate(), $error, 0);
                        continue;
                    }
                }
                if ($promoRule->getMailchimpSyncError()) {
                    // the promorule associated has an error
                    $error = __('Parent rule with id ' . $ruleId . 'has not been correctly sent.');
                    $this->_updateSyncData($mailchimpStoreId, $couponId, $this->_date->gmtDate(), $error, 0);
                    continue;
                }
                $promoCodeJson = json_encode($this->generateCodeData($item, $magentoStoreId));
                if (!empty($promoCodeJson)) {
                    $batchArray[$counter]['method'] = 'POST';
                    $batchArray[$counter]['path'] = "/ecommerce/stores/$mailchimpStoreId/promo-rules/$ruleId/promo-codes/";
                    $batchArray[$counter]['operation_id'] = $this->_batchId . '_' . $couponId;
                    $batchArray[$counter]['body'] = $promoCodeJson;
                } else {
                    $error = __('Something went wrong when retrieving the information for promo rule');
                    $this->_updateSyncData($mailchimpStoreId, $couponId, $this->_date->gmtDate(), $error, 0);
                    continue;
                }
                $counter++;
                $this->_updateSyncData($mailchimpStoreId, $couponId, $this->_date->gmtDate(), '', 0);
            } catch(Exception $e) {
                $this->_helper->log($e->getMessage());
            }
        }
        return $batchArray;
    }
    protected function generateCodeData($item, $magentoStoreId)
    {
        $data = [];
        $data['id'] = $item->getCouponId();
        $data['code'] = $item->getCode();
        $data['redemption_url'] = $this->_getRedemptionUrl($item->getCode(),$magentoStoreId);
        $data['usage_count'] = (int)$item->getTimesUsed();

        return $data;
    }
    protected function _getRedemptionUrl($code,$magentoStoreId)
    {
        $token = md5(rand(0, 9999999));
        $url = $this->_helper->getRedemptionUrl($magentoStoreId,$code,$token);
        $this->_token = $token;
        return $url;

    }
    protected function _updateSyncData($storeId, $entityId, $sync_delta, $sync_error = '', $sync_modified = 0, $sync_deleted = 0)
    {
        $this->_helper->saveEcommerceData(
            $storeId,
            $entityId,
            $sync_delta,
            $sync_error,
            $sync_modified,
            \Ebizmarts\MailChimp\Helper\Data::IS_PROMO_CODE,
            $sync_deleted,
            $this->_token
        );
    }
}