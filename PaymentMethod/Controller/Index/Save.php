<?php


namespace Beverage\PaymentMethod\Controller\Index;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Vault\Model\CreditCardTokenFactory;
use Magento\Vault\Api\PaymentTokenRepositoryInterface;
use Magento\Customer\Model\Session;
use Magento\Framework\Encryption\EncryptorInterface;

use Pmclain\Authnet\CustomerProfile;
use Pmclain\Authnet\MerchantAuthentication;
use Pmclain\Authnet\Request\CreateCustomerProfile;
use Pmclain\Authnet\PaymentProfile;
use Pmclain\Authnet\Request\CreateTransaction;
use Pmclain\Authnet\TransactionRequest;
use Pmclain\Authnet\PaymentProfile\Payment\CreditCard;
use Pmclain\Authnet\Request\CreateCustomerPaymentProfile;

use Braintree;
use Braintree\Configuration;
use Magento\Braintree\Gateway\Config\Config;

class Save extends \Magento\Framework\App\Action\Action
{

    protected $resultPageFactory;
    protected $creditCardTokenFactory;
    protected $paymentTokenRepository;
    protected $paymentToken;
    protected $customerSession;
    protected $encryptor;
    protected $paymentConfig;
    protected $request;
    protected $config;
    private $scopeConfig;

    /**
     * Constructor
     *
     * @param \Magento\Framework\App\Action\Context  $context
     * @param \Magento\Framework\View\Result\PageFactory $resultPageFactory
     */
    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \Magento\Framework\View\Result\PageFactory $resultPageFactory,
        CreditCardTokenFactory $creditCardTokenFactory,
        PaymentTokenRepositoryInterface $paymentTokenRepository,
        \Magento\Vault\Model\PaymentToken $paymentToken,
        Session $customerSession,
        EncryptorInterface $encryptor,
        \Magento\Payment\Model\Config $paymentConfig,
        Config $config,
        ScopeConfigInterface $scopeConfig
    ) {
        $this->resultPageFactory = $resultPageFactory;
        $this->creditCardTokenFactory = $creditCardTokenFactory;
        $this->paymentTokenRepository = $paymentTokenRepository;
        $this->paymentToken = $paymentToken;
        $this->customerSession = $customerSession;
        $this->encryptor = $encryptor;
        $this->paymentConfig = $paymentConfig;
        $this->config = $config;
        $this->scopeConfig = $scopeConfig;

        parent::__construct($context);
    }

    /**
     * Execute view action
     *
     * @return \Magento\Framework\Controller\ResultInterface
     */
    public function execute()
    {
        $returnArray=array();
        $post = $this->getRequest()->getPostValue();
        /*print_r($post);*/
        try {
            if (!\Zend_Validate::is(trim($post['payment_method_code']), 'NotEmpty')) {
                throw new LocalizedException(__('Payment Method is missing'));
            }
            if (!\Zend_Validate::is(trim($post['cc_type']), 'NotEmpty')) {
                throw new LocalizedException(__('Card Type is missing'));
            }
            if (!\Zend_Validate::is(trim($post['cc_number']), 'NotEmpty')) {
                throw new LocalizedException(__('Card Number is missing'));
            }
            if (!\Zend_Validate::is(trim($post['cc_exp_month']), 'NotEmpty')) {
                throw new LocalizedException(__('Month is missing'));
            }
            if (!\Zend_Validate::is(trim($post['cc_exp_year']), 'NotEmpty')) {
                throw new LocalizedException(__('Year is missing'));
            }
            if (!\Zend_Validate::is(trim($post['cc_cvv']), 'NotEmpty')) {
                throw new LocalizedException(__('CVV is missing'));
            }

            $customerId=$this->customerSession->getCustomer()->getId();
            // $cardType=$this->paymentConfig->getCcTypes()[$post['cc_type']];
            $cardType=$post['cc_type'];
            $amount='0.01';

            $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
            $customerSession = $objectManager->create('Magento\Customer\Model\Session');

            $customerRepository = $objectManager->get('Magento\Customer\Api\CustomerRepositoryInterface');
            $customer = $customerRepository->getById($customerId);

            $authorizenet_cim_profile_id = "";
            if($attr = $customer->getCustomAttribute('authorizenet_cim_profile_id')){
                $authorizenet_cim_profile_id = $attr->getValue();                
            }

            $addressFactory = $objectManager->create('Magento\Customer\Model\AddressFactory');
            $billingAddressId = $customerSession->getCustomerData()->getDefaultBilling();

            if($post['payment_method_code']=="pmclain_authorizenetcim"){
                $login=$this->scopeConfig->getValue('payment/pmclain_authorizenetcim/login', \Magento\Store\Model\ScopeInterface::SCOPE_STORE);
                $trans_key=$this->scopeConfig->getValue('payment/pmclain_authorizenetcim/trans_key', \Magento\Store\Model\ScopeInterface::SCOPE_STORE);

                $merchantAuth = new MerchantAuthentication(
                    $login,
                    $trans_key
                );

                $request = new CreateTransaction(true);
                $request->setMerchantAuthentication($merchantAuth);

                $transaction = new TransactionRequest();
                $transaction->setTransactionType(TransactionRequest\TransactionType::TYPE_AUTH_ONLY);
                $transaction->setAmount($amount);

                $payment = new CreditCard();
                $payment->setCardNumber($post['cc_number']);
                $payment->setExpirationDate($post['cc_exp_year'].'-'.$post['cc_exp_month']);
                $payment->setCardCode($post['cc_cvv']);

                $paymentProfile = new PaymentProfile();
                if($billingAddressId){
                    $billingAddress = $addressFactory->create()->load($billingAddressId);
                    $address = new PaymentProfile\Address();
                    $address->setFirstname($billingAddress->getFirstname());
                    $address->setLastname($billingAddress->getLastname());
                    $address->setCompany($billingAddress->getCompany());
                    $address->setAddress($billingAddress->getData('street'));
                    $address->setCity($billingAddress->getCity());
                    $address->setState($billingAddress->getRegion());
                    $address->setZip($billingAddress->getPostcode());
                    $address->setCountry($billingAddress->getCountryId());
                    $address->setPhoneNumber($billingAddress->getTelephone());
                    $paymentProfile->setBillTo($address);
                }
                if($authorizenet_cim_profile_id) {
                    $paymentProfile->setPayment($payment);
                    $paymentProfile->setCustomerType('individual');

                    $paymentprofilerequest = new CreateCustomerPaymentProfile(true);
                    $paymentprofilerequest->setMerchantAuthentication($merchantAuth);
                    $paymentprofilerequest->setPaymentProfile($paymentProfile);
                    $paymentprofilerequest->setCustomerProfileId($authorizenet_cim_profile_id);
                    $paymentprofileresult = $paymentprofilerequest->submit();

                    $transaction->setCustomerProfileId($authorizenet_cim_profile_id);
                    $transaction->setPaymentProfileId($paymentprofileresult['customerPaymentProfileId']);
                    // $transaction->setPayment($payment);
                } else {

                    $customerProfile = new CustomerProfile();
                    $customerProfile->setEmail($customerSession->getCustomerData()->getEmail());
                    $customerProfile->setId($customerSession->getCustomerData()->getId());
                    // $customerProfile->setPaymentProfile($paymentProfile);
                    if($address) {
                        $transaction->setBillTo($address);                        
                    }
                    $transaction->setPayment($payment);
                    $transaction->setCustomer($customerProfile);
                    $transaction->setCreateProfile(true);
                }

                $request->setTransactionRequest($transaction);                    
                $result = $request->submit();
                if(!isset($result['transactionResponse'])){
                    $message = __('Invalid card details.');
                    $returnArray['success']=false;
                    $returnArray['message']=$message;
                    echo json_encode($returnArray);
                    die();
                }else{
                    if(!empty($result['transactionResponse']['authCode']) && $result['transactionResponse']['transId']>0){
                        $paymentToken = $this->creditCardTokenFactory->create();
                        $paymentToken->setExpiresAt(date('Y-m-d 00:00:00',strtotime('+1 year')));
                        if($authorizenet_cim_profile_id) {
                            $paymentToken->setGatewayToken($paymentprofileresult['customerPaymentProfileId']);
                        } else {
                            $customer->setCustomAttribute('authorizenet_cim_profile_id', $result['profileResponse']['customerProfileId']);
                            $customerRepository->save($customer);
                            $payment_profile_id_list = $result['profileResponse']['customerPaymentProfileIdList'];
                            $paymentToken->setGatewayToken($payment_profile_id_list[0]);
                        }
                        $tokenDetails=array(
                              'type'              => $cardType,
                              'maskedCC'          => substr($post['cc_number'], -4),
                              'expirationDate'    => $post['cc_exp_month'].'/'.$post['cc_exp_year']
                            );
                        $paymentToken->setTokenDetails(json_encode($tokenDetails));
                        $paymentToken->setIsActive(true);
                        $paymentToken->setIsVisible(true);
                        $paymentToken->setPaymentMethodCode($post['payment_method_code']);
                        $paymentToken->setCustomerId($customerId);
                        $hashKey = $result['transactionResponse']['transId'];
                        $hashKey .= $post['payment_method_code']
                        . $cardType
                        . json_encode($tokenDetails);
                        $paymentToken->setPublicHash($this->encryptor->getHash($hashKey));
                        $this->paymentTokenRepository->save($paymentToken);

                        $message = __('Credit Card saved successfully.');
                        $returnArray['success']=true;
                        $returnArray['message']=$message;
                        echo json_encode($returnArray);
                        die();
                    }else{
                        $message = $result['messages']['message'][0]['text'];
                        $returnArray['success']=false;
                        $returnArray['message']=$message;
                        echo json_encode($returnArray);
                        die();
                    }
                }
            }elseif($post['payment_method_code']=="braintree"){
                $environment=$this->scopeConfig->getValue('payment/braintree/environment', \Magento\Store\Model\ScopeInterface::SCOPE_STORE);
                $merchant_id=$this->scopeConfig->getValue('payment/braintree/merchant_id', \Magento\Store\Model\ScopeInterface::SCOPE_STORE);
                $public_key=$this->scopeConfig->getValue('payment/braintree/public_key', \Magento\Store\Model\ScopeInterface::SCOPE_STORE);
                $private_key=$this->scopeConfig->getValue('payment/braintree/private_key', \Magento\Store\Model\ScopeInterface::SCOPE_STORE);

                $gateway = new Braintree\Gateway([
                    'environment' => $environment,
                    'merchantId' => $merchant_id,
                    'publicKey' => $public_key,
                    'privateKey' => $private_key
                ]);
                $result = $gateway->transaction()->sale([
                    'amount' => $amount,
                    'creditCard' => [
                        'number' => $post['cc_number'],
                        'expirationDate' => $post['cc_exp_month'].'/'.$post['cc_exp_year'],
                        'cvv' => $post['cc_cvv']
                    ],
                    'options' => [
                        'submitForSettlement' => false,
                        'storeInVaultOnSuccess' => true
                    ]
                ]);
                if(!empty($result->success)){
                    $paymentToken = $this->creditCardTokenFactory->create();
                    $paymentToken->setExpiresAt(date('Y-m-d 00:00:00',strtotime('+1 year')));
                    $paymentToken->setGatewayToken($result->transaction->creditCardDetails->token);
                    $tokenDetails=array(
                          'type'              => $cardType,
                          'maskedCC'          => substr($post['cc_number'], -4),
                          'expirationDate'    => $post['cc_exp_month'].'/'.$post['cc_exp_year']
                        );
                    $paymentToken->setTokenDetails(json_encode($tokenDetails));
                    $paymentToken->setIsActive(true);
                    $paymentToken->setIsVisible(true);
                    $paymentToken->setPaymentMethodCode($post['payment_method_code']);
                    $paymentToken->setCustomerId($customerId);
                    $hashKey = $result->transaction->id;
                    $hashKey .= $post['payment_method_code']
                    . $cardType
                    . json_encode($tokenDetails);
                    $paymentToken->setPublicHash($this->encryptor->getHash($hashKey));
                    $this->paymentTokenRepository->save($paymentToken);

                    $message = __('Credit Card saved successfully.');
                    $returnArray['success']=true;
                    $returnArray['message']=$message;
                    echo json_encode($returnArray);
                    die();
                }else{
                    $returnArray['success']=false;
                    $returnArray['message']=$result->message;
                    echo json_encode($returnArray);
                    die();
                }
            }else{
                $message = __('Invalid payment method.');
                $returnArray['success']=false;
                $returnArray['message']=$message;
                echo json_encode($returnArray);
                die();
            }

            $message = __('We can\'t process your request right now. Sorry, that\'s all we know.');
            $returnArray['success']=false;
            $returnArray['message']=$message;
            echo json_encode($returnArray);
            die();
        } catch (\Exception $e) {
            $message = __('We can\'t process your request right now. Sorry, that\'s all we know.');
            $returnArray['success']=false;
            $returnArray['message']=$e->getMessage();
            echo json_encode($returnArray);
            die();
        }
    }
}
