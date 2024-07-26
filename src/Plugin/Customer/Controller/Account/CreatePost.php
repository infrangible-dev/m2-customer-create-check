<?php

declare(strict_types=1);

namespace Infrangible\CustomerCreateCheck\Plugin\Customer\Controller\Account;

use Closure;
use Exception;
use FeWeDev\Base\Variables;
use Infrangible\Core\Helper\Stores;
use Magento\Customer\Model\CustomerExtractor;
use Magento\Customer\Model\Delegation\Storage;
use Magento\Customer\Model\Registration;
use Magento\Customer\Model\Session;
use Magento\Customer\Model\Session\Proxy as SessionProxy;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Request\Http;
use Magento\Framework\App\Response\RedirectInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Data\Form\FormKey\Validator;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\UrlFactory;
use Magento\Framework\UrlInterface;

/**
 * @author      Andreas Knollmann
 * @copyright   2014-2024 Softwareentwicklung Andreas Knollmann
 * @license     http://www.opensource.org/licenses/mit-license.php MIT
 */
class CreatePost
{
    /** @var Variables */
    protected $variables;

    /** @var RedirectFactory */
    protected $resultRedirectFactory;

    /** @var Session */
    protected $customerSession;

    /** @var Registration */
    protected $registration;

    /** @var Validator */
    protected $formKeyValidator;

    /** @var UrlInterface */
    protected $urlModel;

    /** @var RedirectInterface */
    protected $redirect;

    /** @var ManagerInterface */
    protected $messageManager;

    /** @var CustomerExtractor */
    protected $customerExtractor;

    /** @var Stores */
    protected $storeHelper;

    /** @var Session */
    protected $session;

    /** @var Storage */
    protected $delegatedStorage;

    public function __construct(
        Variables $variables,
        Context $context,
        Session $customerSession,
        Registration $registration,
        Validator $formKeyValidator,
        UrlFactory $urlFactory,
        CustomerExtractor $customerExtractor,
        Stores $storeHelper,
        SessionProxy $session,
        Storage $delegatedStorage)
    {
        $this->variables = $variables;
        $this->resultRedirectFactory = $context->getResultRedirectFactory();
        $this->customerSession = $customerSession;
        $this->registration = $registration;
        $this->formKeyValidator = $formKeyValidator;
        $this->urlModel = $urlFactory->create();
        $this->redirect = $context->getRedirect();
        $this->messageManager = $context->getMessageManager();
        $this->customerExtractor = $customerExtractor;
        $this->storeHelper = $storeHelper;
        $this->session = $session;
        $this->delegatedStorage = $delegatedStorage;
    }

    public function aroundExecute(\Magento\Customer\Controller\Account\CreatePost $subject, Closure $proceed): Redirect
    {
        $resultRedirect = $this->resultRedirectFactory->create();

        if ($this->customerSession->isLoggedIn() || !$this->registration->isAllowed()) {
            $resultRedirect->setPath('*/*/');

            return $resultRedirect;
        }

        /** @var Http $request */
        $request = $subject->getRequest();

        if (!$request->isPost() || !$this->formKeyValidator->validate($request)) {
            $url = $this->urlModel->getUrl('*/*/create', ['_secure' => true]);

            return $this->resultRedirectFactory->create()->setUrl($this->redirect->error($url));
        }

        $defaultUrl = $this->urlModel->getUrl('*/*/create', ['_secure' => true]);

        try {
            $customer = $this->customerExtractor->extract('customer_account_create', $request);

            $customerAttributes = [
                'prefix' => $customer->getPrefix(),
                'firstname' => $customer->getFirstname(),
                'middlename' => $customer->getMiddlename(),
                'lastname' => $customer->getLastname(),
                'suffix' => $customer->getSuffix()];

            if (!$this->validateCustomerAttributes($customerAttributes)) {
                $this->messageManager->addErrorMessage(__('We can\'t save the customer.'));

                /** @noinspection PhpUndefinedMethodInspection */
                $this->session->setCustomerFormData($request->getPostValue());

                return $resultRedirect->setUrl($this->redirect->error($defaultUrl));
            }

            $serialized = $this->session->getData('delegated_new_customer_data');

            $delegatedNewOperation = $this->delegatedStorage->consumeNewOperation();

            if ($delegatedNewOperation !== null) {
                $addresses = $delegatedNewOperation->getCustomer()->getAddresses();

                /** @noinspection PhpUndefinedMethodInspection */
                $this->session->setDelegatedNewCustomerData($serialized);
            } else {
                $addresses = [];
            }

            if (!$this->variables->isEmpty($addresses)) {
                foreach ($addresses as $address) {
                    if ($address !== null) {
                        $customerAttributes = [
                            'prefix' => $address->getPrefix(),
                            'firstname' => $address->getFirstname(),
                            'middlename' => $address->getMiddlename(),
                            'lastname' => $address->getLastname(),
                            'suffix' => $address->getSuffix(),
                            'street' => $address->getStreet(),
                            'postcode' => $address->getPostcode(),
                            'city' => $address->getCity(),
                            'company' => $address->getCompany(),
                            'telephone' => $address->getTelephone(),
                            'fax' => $address->getFax()];

                        if (!$this->validateCustomerAttributes($customerAttributes)) {
                            $this->messageManager->addErrorMessage(__('We can\'t save the customer.'));

                            /** @noinspection PhpUndefinedMethodInspection */
                            $this->session->setCustomerFormData($request->getPostValue());

                            return $resultRedirect->setUrl($this->redirect->error($defaultUrl));
                        }
                    }
                }
            }
        } catch (Exception $exception) {
            $this->messageManager->addExceptionMessage($exception, __('We can\'t save the customer.'));

            /** @noinspection PhpUndefinedMethodInspection */
            $this->session->setCustomerFormData($request->getPostValue());

            return $resultRedirect->setUrl($this->redirect->error($defaultUrl));
        }

        return $proceed();
    }

    public function validateCustomerAttributes(array $customerAttributes): bool
    {
        foreach ($customerAttributes as $customerAttributeName => $customerAttributeValue) {
            if ($customerAttributeValue === null) {
                $customerAttributeValue = '';
            }

            if (is_array($customerAttributeValue)) {
                $customerAttributeValue = implode(' ', $customerAttributeValue);
            }

            if (!$this->validateCustomerAttribute(
                $customerAttributeName,
                $customerAttributeValue !== null ? $this->variables->stringValue($customerAttributeValue) : null)) {
                return false;
            }
        }

        return true;
    }

    public function validateCustomerAttribute(string $customerAttributeName, ?string $customerAttributeValue): bool
    {
        $allowPattern = $this->getAllowPattern($customerAttributeName);
        $denyPattern = $this->getDenyPattern($customerAttributeName);

        if (!$this->variables->isEmpty($allowPattern) && !$this->variables->isEmpty($customerAttributeValue) &&
            !preg_match(sprintf('/%s/s', $allowPattern), $customerAttributeValue)) {
            return false;
        }

        if (!$this->variables->isEmpty($denyPattern) && !$this->variables->isEmpty($customerAttributeValue) &&
            preg_match(sprintf('/%s/s', $denyPattern), $customerAttributeValue)) {

            return false;
        }

        return true;
    }

    public function getAllowPattern(string $customerAttributeName): ?string
    {
        $allowCheck = $this->storeHelper->getStoreConfigFlag(
            sprintf('infrangible_customercreatecheck/%s/allow_check', $customerAttributeName));

        return $allowCheck ? $this->storeHelper->getStoreConfig(
            sprintf('infrangible_customercreatecheck/%s/allow_pattern', $customerAttributeName)) : null;
    }

    public function getDenyPattern(string $customerAttributeName): ?string
    {
        $denyCheck = $this->storeHelper->getStoreConfigFlag(
            sprintf('infrangible_customercreatecheck/%s/deny_check', $customerAttributeName));

        return $denyCheck ? $this->storeHelper->getStoreConfig(
            sprintf('infrangible_customercreatecheck/%s/deny_pattern', $customerAttributeName)) : null;
    }
}
