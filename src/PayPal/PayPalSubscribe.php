<?php

namespace App\PayPal;

use App\Auth\User;
use App\Shop\Entity\Product;
use App\Shop\Entity\Recurring;
use App\Shop\Entity\SubscriptionDetails;
use App\Shop\Entity\Transaction;
use App\Shop\Entity\TransactionItem;
use App\Shop\Payment\SubscribeInterface;
use App\Shop\Services\SubscriptionService;
use App\Shop\Services\TransactionService;
use ClientX\Response\RedirectResponse;
use DateTimeInterface;
use PayPal\Api\Agreement;
use PayPal\Api\AgreementStateDescriptor;
use PayPal\Api\AgreementTransaction;
use PayPal\Api\Currency;
use PayPal\Api\Links;
use PayPal\Api\MerchantPreferences;
use PayPal\Api\Patch;
use PayPal\Api\PatchRequest;
use PayPal\Api\Payer;
use PayPal\Api\PaymentDefinition;
use PayPal\Api\Plan;
use PayPal\Auth\OAuthTokenCredential;
use PayPal\Common\PayPalModel;
use PayPal\Exception\PayPalConnectionException;
use PayPal\Rest\ApiContext;
use function ClientX\request;

class PayPalSubscribe implements SubscribeInterface
{

    private PaypalCredential $credential;
    private SubscriptionService $subscriptionService;
    private string $currency;

    public function __construct(PaypalCredential $credential, SubscriptionService $subscriptionService, string $currency)
    {
        $this->credential = $credential;
        $this->subscriptionService = $subscriptionService;
        $this->currency = $currency;
    }

    public function getLink(User $user, Transaction $transaction, array $links)
    {
        $items = $transaction->getItems();
        $discounts = collect($items)->filter(function ($item) {
            return $item->price() < 0;
        })->reduce(function ($i, TransactionItem $item) {
            return $i + $item->price();
        }, 0);
        $product = collect($items)->filter(function (TransactionItem $transactionItem) {
                return $transactionItem->getOrderable() instanceof Product && $transactionItem->getOrderable()->getPaymentType() == 'recurring';
        })->first();

        $plan = new Plan();
        $definition = new PaymentDefinition();
        $months = Recurring::from(json_decode($product->getData(), true)['_recurring'])->getMonths();
        $definition
            ->setName($product->getName() . " - CLIENTXCMS ")
            ->setType("REGULAR")
            ->setAmount(new Currency([
                'value' => $transaction->priceWithTax() - $discounts,
                'currency' => $this->currency,
            ]))
            ->setFrequency("MONTH")
            ->setFrequencyInterval($months);

        $merchants  = new MerchantPreferences();
        $merchants
            ->setReturnUrl($links['return']. '?auto=true')
            ->setMaxFailAttempts(3)
            ->setSetupFee(new Currency([
                'value' => $transaction->setupfee(),
                'currency' => $this->currency,
            ]))
            ->setCancelUrl($links['cancel'] . '?auto=true');

        $plan->setName($product->getName() . " - CLIENTXCMS ");
        $plan->setType("INFINITE")
            ->setDescription('Billing Agreement');

        $plan->setPaymentDefinitions([$definition]);
        $plan->setMerchantPreferences($merchants);
        $context = new ApiContext(new OAuthTokenCredential($this->credential->id, $this->credential->secret));
        try {
            $createdPlan = $plan->create($context);
            $patch = new Patch();
            $value = new PayPalModel(['state' => 'ACTIVE']);
            $patch->setOp('replace')
                ->setPath('/')
                ->setValue($value);
            $patchRequest = new PatchRequest();
            $patchRequest->addPatch($patch);
            $createdPlan->update($patchRequest, $context);
            $patchedPlan = Plan::get($createdPlan->getId(), $context);
            $startDate = date('c', time() + 3600);
            $agreement = new Agreement();
            $agreement->setName($transaction->getName() . ' Agreement')
                ->setStartDate($startDate)
                //->setLinks([(new Links())->setHref("")])
                ->setDescription('Billing Agreement');

            $agreement->setPlan((new Plan())->setId($patchedPlan->getId()));
            $payer = new Payer();
            $payer->setPaymentMethod("paypal");
            $agreement->setPayer($payer);
            $agreement = $agreement->create($context);
            return new RedirectResponse($agreement->getApprovalLink());
        } catch (PayPalConnectionException $e) {
            echo $e->getCode();
            echo $e->getData();
            die();
        }
    }

    public function execute(array $items, Transaction $transaction, TransactionService $transactionService)
    {
        $transactionItem = collect($items)->filter(function (TransactionItem $transactionItem) {
            return $transactionItem->getOrderable() instanceof Product && $transactionItem->getOrderable()->getPaymentType() == 'recurring';
        })->first();
        $context = new ApiContext(new OAuthTokenCredential($this->credential->id, $this->credential->secret));

        $request = request();
        try {
            $token = $request->getQueryParams()['token'];
            $agreement = new Agreement();
            $agreement->execute($token, $context);
            $user  = (new User())->setId($transaction->getUserId());
            $this->subscriptionService->addSubscription($user, $transactionItem, $agreement->getId());
            $transaction->setTransactionId($token);
            $transactionService->updateTransactionId($transaction);
            $transaction->setState($transaction::COMPLETED);
            $transactionService->complete($transaction);

            foreach ($transaction->getItems() as $item) {
                $this->service->delivre($item);
            }
            $transactionService->changeState($transaction);
            return $transaction;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function getDetails(string $token)
    {

        $context = new ApiContext(new OAuthTokenCredential($this->credential->id, $this->credential->secret));
        return Agreement::get($token, $context);
    }

    public function cancel(string $token)
    {
        $context = new ApiContext(new OAuthTokenCredential($this->credential->id, $this->credential->secret));
        $agreement = Agreement::get($token, $context);
        $agreementStateDescriptor = new AgreementStateDescriptor();
        $agreementStateDescriptor->setNote("Cancel the agreement");
        $agreement->cancel($agreementStateDescriptor, $context);
    }

    public function reactive(string $token)
    {
        return null;
    }

    public function type(): string
    {
        return "paypal";
    }

    /**
     * @param Agreement $subscription
     * @return SubscriptionDetails
     */
    public function formatSubscription(int $id, string $token, $subscription): SubscriptionDetails
    {
        $details = new SubscriptionDetails();
        $details->setId($id);
        $details->setType('paypal');
        $details->setToken($token);
        $details->setState($subscription->getState());
        $details->setPrice($subscription->getPlan()->getPaymentDefinitions()[0]->getAmount()->getValue());
        $details->setStartDate(\DateTime::createFromFormat(DateTimeInterface::ISO8601, $subscription->getStartDate()));
        $details->setNextRenewal(\DateTime::createFromFormat(DateTimeInterface::ISO8601, $subscription->getAgreementDetails()->getNextBillingDate()));
        $details->setEmailPayer($subscription->getPayer()->getPayerInfo()->getEmail());

        return $details;
    }

    public function fetchLastTransactionId(string $token, string $last): ?string
    {
        $context = new ApiContext(new OAuthTokenCredential($this->credential->id, $this->credential->secret));

        $transactions = Agreement::searchTransactions($token,array('start_date' => date('Y-m-d', strtotime('-2 years')), 'end_date' => date('Y-m-d', strtotime('+5 days'))), $context);
        if ($last == current($transactions->getAgreementTransactionList())->getTransactionId()){
            return null;
        }
        return current($transactions->getAgreementTransactionList())->getTransactionId();
    }
}
