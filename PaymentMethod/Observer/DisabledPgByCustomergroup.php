<?php
namespace Beverage\PaymentMethod\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Framework\App\ObjectManager;
class DisabledPgByCustomergroup implements ObserverInterface
{
    public function __construct(\Psr\Log\LoggerInterface $logger)
    {
        $this->_logger = $logger;
    }
    /**
     *
     * @param \Magento\Framework\Event\Observer $observer
     * @return void
     */
    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        $result          = $observer->getEvent()->getResult();
        $method_instance = $observer->getEvent()->getMethodInstance();
        $quote           = $observer->getEvent()->getQuote();
        //$this->_logger->info($method_instance->getCode());
        /* If Cusomer  group is match then work */
        
        // leave purchaseorder (bill me) payment method enabled for corporate and tax exempt user groups only.
        if (null !== $quote && ($quote->getCustomerGroupId() != 5 && $quote->getCustomerGroupId() != 14)) {
            /* Disable All payment gateway  exclude Your payment Gateway*/
            if ($method_instance->getCode() == 'purchaseorder') {
                $result->setData('is_available', false);
            }
        }
        /*else{
        if($method_instance->getCode() =='purchaseorder'){
        $result->setData('is_available', true);

        }
        }*/
    }
}