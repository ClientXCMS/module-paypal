<?php
namespace App\PayPal;

use App\Basket\Basket;
use App\Basket\BasketRow;
use App\PayPal\Database\PayPalTable;
use App\PayPal\Exceptions\PayPalException;
use App\PayPal\Exceptions\RedirectLinkNotFound;
use ClientX\Auth;
use App\Shop\Entity\Product;
use ClientX\Helpers\Countries;
use ClientX\Helpers\Vat;
use ClientX\Payment\PaymentManagerInterface;
use ClientX\Response\RedirectResponse;
use ClientX\Router;
use PayPal\Api\Amount;
use PayPal\Api\Details;
use PayPal\Api\Item;
use PayPal\Api\ItemList;
use PayPal\Api\Payer;
use PayPal\Api\Payment;
use PayPal\Api\PaymentExecution;
use PayPal\Api\RedirectUrls;
use PayPal\Api\Transaction;
use PayPal\Exception\PayPalConnectionException;
use PayPal\Rest\ApiContext;
use Psr\Http\Message\ServerRequestInterface;

class PayPalPaymentManager implements PaymentManagerInterface
{

    /**
     * @var Auth
     */
    private $auth;

    /**
     * @var ApiContext
     */
    private $context;

    /**
     * @var Product
     */
    private $product;

    /**
     * @var BasketRow[]
     */
    private $products;

    /**
     * @var string
     */
    private $country;

    /**
     * @var string[]
     */
    private $links;

    /**
     * @var string
     */
    private $currency = "EUR";

    /**
     * @var PayPalTable
     */
    private $table;

    /**
     * @var int|null
     */
    private $invoice;

    /**
     * @var bool
     */
    private $isBasket;

    /**
     * @var Basket
     */
    private $basket;

    public function __construct(ApiContext $context, Router $router, Auth $auth, PayPalTable $table)
    {
        $this->context  = $context;
        $this->router   = $router;
        $this->auth     = $auth;
        $this->table    = $table;
    }
    public function process(
        ServerRequestInterface $request,
        $product = null,
        bool $isBasket = false,
        ?Basket $basket = null
    ) {
        $this->isBasket = $isBasket;
        $this->basket = $basket;
        $this->getRedirectsLinks($request);
        $user = $this->auth->getUser();
        $this->user = $user->getId();
        $this->invoice = $request->getAttribute('invoice_id', null);
        $this->country = Countries::getFromName($user->getCountry()) ?? "FR";
        if (!$isBasket) {
            $this->product = $product;
        } else {
            $this->products = $product;
        }
        $payment = (new Payment())
            ->addTransaction($this->getTransaction())
            ->setIntent('sale')
            ->setRedirectUrls($this->getRedirectUrls())
            ->setPayer($this->getPayer());
        try {
            $payment->create($this->context);
            return new RedirectResponse($payment->getApprovalLink());
        } catch (PayPalConnectionException $e) {
            die(var_dump(json_decode($e->getData())));
        }
    }

    public function execute(
        ServerRequestInterface $request,
        $product = null,
        bool $isBasket = false,
        ?Basket $basket = null
    ) {
        $paymentId = $request->getQueryParams()['paymentId'];
        $this->isBasket = $isBasket;
        $this->basket = $basket;
        $payment = Payment::get($paymentId, $this->context);
        $transaction = json_decode($payment->getTransactions()[0]);
        $payer = $payment->getPayer()->getPayerInfo();
        $payerId = $payer->getPayerId();
        $userId  = json_decode($transaction->custom)->user;
        $this->user = $this->auth->getUser()->getId();
        $this->invoice = json_decode($transaction->custom)->invoice;
        $this->country = Countries::getFromName($payer->getCountryCode()) ?? "FR";
        if (!$isBasket) {
            $this->product = $product;
        } else {
            $this->products = $product;
        }
        $execution = $this->getExecution($payerId);
        $amount = $transaction->amount;
        try {
            $payment->execute($execution, $this->context);
            $this->table->insert([
                'payment_id' => $payment->getId(),
                'user_id' => $userId,
                'state' => $payment->getState(),
                'payer_id' => $payer->getPayerId(),
                'payer_email' => $payer->getEmail(),
                'payer_country' => $payer->getCountryCode(),
                'total' => $amount->total,
                'subtotal' => $amount->details->subtotal,
                'tax' => $amount->details->tax
            ]);
            return ['id' => $this->invoice, 'payment_id' => $payment->getId()];
        } catch (PayPalConnectionException $e) {
            die(var_dump(json_decode($e->getData())));
        }
    }

    private function getExecution(string $payerId)
    {
        return (new PaymentExecution())
            ->setPayerId($payerId)
            ->addTransaction($this->getTransaction());
    }

    private function getUri(string $link)
    {
        if (!$this->getRedirectsLinks()) {
            throw new PayPalException('the redirects links is missing for generate uri');
        }
        if (!isset($this->getRedirectsLinks()[$link])) {
            throw new RedirectLinkNotFound(sprintf('The link array does not contain the key %s', $link));
        }
        return $this->getRedirectsLinks()[$link] ?? null;
    }

    private function getRedirectUrls():RedirectUrls
    {
        $redirectUrls = new RedirectUrls();
        $redirectUrls->setReturnUrl($this->getUri('return'));
        $redirectUrls->setCancelUrl($this->getUri('cancel'));
        return $redirectUrls;
    }

    private function getRedirectsLinks(?ServerRequestInterface $request = null):array
    {
        if ($this->links) {
            return $this->links;
        }
        $domain = sprintf(
            '%s://%s%s',
            $request->getUri()->getScheme(),
            $request->getUri()->getHost(),
            $request->getUri()->getPort() ? ':' . $request->getUri()->getPort() : ''
        );
        $isRenewal = false;
        if ($request) {
            $isRenewal = strpos($request->getUri()->getPath(), 'services') ==! false;
        }
        $id = $request->getAttribute('id');
        $type = $request->getParsedBody()['type'];
        $prefix = ($this->isBasket) ? 'basket' : 'shop';
        $prefix = ($isRenewal) ? 'shop.services.renew' : $prefix;
        $cancel = $domain . $this->router->generateURI("$prefix.cancel", compact('type', 'id'));
        $return = $domain . $this->router->generateURI("$prefix.return", compact('type', 'id'));
        $this->links = compact('return', 'cancel');
        return compact('return', 'cancel');
    }


    private function getPayer(): Payer
    {
        return (new Payer())->setPaymentMethod('paypal');
    }

    private function getTransaction():Transaction
    {
        $transaction = (new Transaction())
                ->setAmount($this->getAmount())
                ->setItemList($this->getItemList())
                ->setDescription('Achat via PayPal')
                ->setCustom(json_encode([
                    'user' => $this->user,
                    'invoice' => $this->invoice
                ]));
            return $transaction;
    }

    private function getItemList():ItemList
    {
        $list = new ItemList();
        if ($this->isBasket) {
            foreach ($this->products as $row) {
                $product = $row->getProduct();
                $item = (new Item())
                ->setName($product->getName())
                ->setPrice($product->getPrice())
                ->setCurrency($this->getCurrency())
                ->setQuantity($row->getQuantity());
                $list->addItem($item);
            }
        } else {
            $product = $this->product;
            $item = (new Item())
            ->setName($product->getName())
            ->setPrice($product->getPrice())
            ->setCurrency($this->getCurrency())
            ->setQuantity(1);
            $list->addItem($item);
        }

        return $list;
    }

    private function getAmount():Amount
    {
    
        return (new Amount())
        ->setTotal($this->getTotal())
        ->setDetails($this->getDetails())
        ->setCurrency($this->getCurrency());
    }

    private function getDetails():Details
    {
        if ($this->isBasket) {
            $price = $this->basket->price();
            $vat = $this->basket->vat();
        } else {
            $price = $this->product->getPrice();
        }
        $vat = ($price / 100) * $this->getVat();

        
        return (new Details())
            ->setSubtotal($price)
            ->setTax($vat);
    }

    private function getTotal():float
    {
        if ($this->isBasket) {
            $price = $this->basket->total();
        } else {
            $price = $this->product->getPrice() + ($this->product->getPrice() / 100) * $this->getVat();
        }
        return $price;
    }

    private function getVat():float
    {
        return Vat::get($this->auth->getUser());
    }

    public function getCurrency()
    {
        return $this->currency;
    }
    public function setCurrency(string $currency)
    {
        $this->currency = $currency;
    }
}
