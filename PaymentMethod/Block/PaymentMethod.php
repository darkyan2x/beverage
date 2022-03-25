<?php

namespace Beverage\PaymentMethod\Block;

class PaymentMethod extends \Magento\Framework\View\Element\Template
{

    protected $paymentHelper;
    protected $paymentConfig;

    /**
     * Constructor
     *
     * @param \Magento\Framework\View\Element\Template\Context  $context
     * @param array $data
     */
    public function __construct(
        \Magento\Framework\View\Element\Template\Context $context,
        \Magento\Payment\Helper\Data $paymentHelper,
        \Magento\Payment\Model\Config $paymentConfig,
        array $data = []
    ) {
        $this->paymentHelper = $paymentHelper;
        $this->paymentConfig = $paymentConfig;
        parent::__construct($context, $data);
    }

    /**
     * @return string
     */
    public function getActivePaymentMethods()
    {
        $months=array();
        foreach ($this->paymentConfig->getMonths() as $month) {
            $mnth=explode("-", $month);
            $months[trim($mnth[0])]=$month;
        }

        $all_methods=$this->paymentHelper->getPaymentMethods();
        $includeMethods=array('braintree','pmclain_authorizenetcim');
        $cctypes=array();
        foreach ($this->paymentConfig->getActiveMethods() as $method) {
            if(in_array($method->getCode(), $includeMethods)){
                foreach ($this->paymentConfig->getCcTypes() as $key => $value) {
                    if(in_array($key, explode(",", $all_methods[$method->getCode()]['cctypes']))){
                        $cctypes[$method->getCode()][$key]=$value;
                    }
                }
            }
        }

        $returnArray=array('methods'=>$this->paymentConfig->getActiveMethods(),'cctypes'=>$cctypes,'months'=>$months,'years'=>$this->paymentConfig->getYears());
        return $returnArray;
    }
}
