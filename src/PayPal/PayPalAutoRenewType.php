<?php


namespace App\PayPal;

use App\Shop\Entity\Recurring;
use App\Shop\Entity\Service;
use App\Shop\Entity\Transaction;
use App\Shop\Payment\AutoRenewRedirectUri;
use App\Shop\Renew\AutoRenewTypeInterface;
use App\Shop\Renew\RenewResponse;
use App\Shop\Renew\RenewTrait;
use ClientX\Router;
use Exception;
use PayPal\Api\Agreement;
use PayPal\Api\Currency;
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

class PayPalAutoRenewType implements AutoRenewTypeInterface
{
    private Router $router;
    private PaypalCredential $paypal;
    private AutoRenewRedirectUri $uri;

    use RenewTrait;
    
    public function __construct(Router $router, AutoRenewRedirectUri $uri, PaypalCredential $paypal)
    {
        $this->router = $router;
        $this->uri = $uri;
        $this->paypal = $paypal;
    }

    public function getName(): string
    {
        return "paypal";
    }

    public function getTitle(): string
    {
        return "PayPal";
    }

    public function getSvg(): string
    {
        return "https://clientxcms.com/Themes/CLIENTXCMS/images/modules/PayPal.svg";
    }

    public function getRedirectLink(Service $service):string{
        $plan = new Plan();
        $definition = new PaymentDefinition();
        $definition
            ->setName($service->getName() . " - CLIENTXCMS ")

            ->setType("REGULAR")
            ->setAmount(new Currency([
                'value' => $service->getPrice() * Recurring::from($service->getRecurring())->getMonths(),
                'currency' => 'EUR',
            ]))
            ->setFrequency("MONTH")
            ->setFrequencyInterval(Recurring::from($service->getRecurring())->getMonths());
        
            $merchants  = new MerchantPreferences();
            $merchants
                ->setReturnUrl($this->uri->makeReturn($this->router, request(), $service->getId(), 'paypal'))
                ->setMaxFailAttempts(3)
                ->setCancelUrl($this->uri->makeCancel($this->router, request(), $service->getId(), 'paypal') . '?auto=1');
            
        $plan->setName($service->getName() . " - CLIENTXCMS ");
        $plan->setType("INFINITE")
        ->setDescription('Billing Agreement');

        $plan->setPaymentDefinitions([$definition]);
        $plan->setMerchantPreferences($merchants);
        $context = new ApiContext(new OAuthTokenCredential($this->paypal->id, $this->paypal->secret));
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
            $startDate = date('c', $this->getNextRenew($service)->format('U'));
            $agreement = new Agreement();
            $agreement->setName($service->getName() . ' Agreement')
                ->setDescription('Billing Agreement')
                ->setStartDate($startDate);
                $agreement->setPlan((new Plan())->setId($patchedPlan->getId()));
                $payer = new Payer();
                $payer->setPaymentMethod("paypal");
                $agreement->setPayer($payer);
                $agreement = $agreement->create($context);
                return $agreement->getApprovalLink();
;        } catch (PayPalConnectionException $e){
            echo $e->getCode();
            echo $e->getData();
            die();
        }
        return "";
    }

    public function fetchToken(string $token, Transaction $transaction): ?RenewResponse
    {
        return null;
    }

    public function subscribe(Service $service): string
    {
        $context = new ApiContext(new OAuthTokenCredential($this->paypal->id, $this->paypal->secret));

        $request = request();
        try {
            $token = $request->getQueryParams()['token'];
            $agreement = new Agreement();
            $agreement->execute($token, $context);
            return $token;
        } catch (Exception $e){

        }
        return "XXX";
    }

    public function cancel(Service $service): string
    {
        dd($service);
        $context = new ApiContext(new OAuthTokenCredential($this->paypal->id, $this->paypal->secret));
        try {
            $patch = new Patch();
            $value = new PayPalModel(['state' => 'CANCELLED']);
            $patch->setOp('replace')
                ->setPath('/')
                ->setValue($value);
            $patchRequest = new PatchRequest();
            $patchRequest->addPatch($patch);
            $createdPlan->update($patchRequest, $context);
            $patchedPlan = Plan::get($createdPlan->getId(), $context);
            $startDate = date('c', $this->getNextRenew($service)->format('U'));
            $agreement = new Agreement();
            $agreement->setName($service->getName() . ' Agreement')
                ->setDescription('Billing Agreement')
                ->setStartDate($startDate);
                $agreement->setPlan((new Plan())->setId($patchedPlan->getId()));
                $payer = new Payer();
                $payer->setPaymentMethod("paypal");
                $agreement->setPayer($payer);
                $agreement = $agreement->create($context);
                return $agreement->getApprovalLink();
;        } catch (PayPalConnectionException $e){
            echo $e->getCode();
            echo $e->getData();
            die();
        }
        return "success";
    }
}
