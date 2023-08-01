<?php

namespace App\PayPal;

use App\Account\User;
use App\PayPal\Exceptions\PayPalException;
use App\Shop\Entity\Product;
use App\Shop\Services\SubscriptionService;
use ClientX\Auth;
use App\Shop\Entity\Transaction;
use App\Shop\Entity\TransactionItem;
use App\Shop\Payment\AbstractPaymentManager;
use App\Shop\Services\TransactionService;
use ClientX\Payment\PaymentManagerInterface;
use ClientX\Response\RedirectResponse;
use ClientX\Router;
use PayPalCheckoutSdk\Orders\OrdersCaptureRequest;
use PayPalCheckoutSdk\Orders\OrdersCreateRequest;
use Psr\Http\Message\ServerRequestInterface as Request;
use stdClass;

use App\Shop\VATCalculator;

class PayPalPaymentManager extends AbstractPaymentManager implements PaymentManagerInterface
{
    private PaypalCredential $credential;
    private SubscriptionService $subscription;

    public function __construct(Router $router, Auth $auth, TransactionService $service, PaypalCredential $credential, SubscriptionService $subscription)
    {
        parent::__construct($router, $auth, $service);
        $this->credential = $credential;
        $this->subscription = $subscription;
    }

    public function process(Transaction $transaction, Request $request, User $user)
    {
        $user = $this->getUser();
        if ($user === null) {
            return;
        }

        $links = $this->getRedirectsLinks($request, $transaction);

        if ($this->checkIfTransactionCanSubscribe($transaction)) {
            return (new PayPalSubscribe($this->credential, $this->subscription, $this->getCurrency()))->getLink($user, $transaction, $links);
        }
        $discounts = collect($transaction->getItems())->filter(function ($item) {
            return $item->price() < 0;
        })->reduce(function ($i, TransactionItem $item) {
            return $i + $item->price();
        }, 0);
        $items = collect($transaction->getItems())->filter(function ($item) {
            return $item->price() > 0;
        })->map(function (TransactionItem $item, $i) use ($transaction) {
            $discount = 0;
            $next = $transaction->getItems()[$i+1] ?? null;
            if ($next != null) {
                if ($next->price() < 0) {
                    $discount = $next->price();
                }
            }
            return [
                'name' => $item->getName(),
                'sku' => $item->getId(),
                'unit_amount' => [
                    'currency_code' => $transaction->getCurrency(),
                    'value' => round($item->setupfee() + $item->price() + $discount, 2),
                ],
                'quantity' => $item->getQuantity(),
                'category' => 'DIGITAL_GOODS'
            ];
        })->toArray();

        $order = new OrdersCreateRequest();
        $order->headers['prefer'] = 'return=representation';
        $order->body = [
            'intent' => 'CAPTURE',
            'application_context' => [
                'return_url' => $links['return'],
                'cancel_url' => $links['cancel'],
                'brand_name' => 'CLIENTXCMS',
                'landing_page' => 'BILLING',
                'user_action' => 'PAY_NOW',
            ],
            "note_to_payer" => "Contact us for any questions on your order.",

            'purchase_units' => [
                [
                    'description' => "PayPal Checkout By CLIENTXCMS",
                    'custom_id' => $transaction->getId(),
                    'soft_descriptor' => $transaction->getId(),
                    'amount' => [
                        'currency_code' => $transaction->getCurrency(),
                        'value' => round($transaction->subtotal(), 2),
                        'breakdown' => [
                            'item_total' => [
                                'currency_code' => $transaction->getCurrency(),
                                'value' => round($transaction->subtotal(), 2),
                            ],
                        ],
                    ],
                    'items' => $items,
                    'discount' => [
                        'currency_code' => $transaction->getCurrency(),
                        'value' => $discounts,
                    ]
                ],
            ],
        ];
        $response = $this->credential->getClient()->execute($order);
        /** @var stdClass */
        $result = $response->result;
        $transaction->setTransactionId($result->id);
        $this->service->updateTransactionId($transaction);

        $approveLink = collect($result->links)->first(function ($link) {
            return $link->rel === 'approve';
        });
        return new RedirectResponse($approveLink->href);
    }

    public function execute(Transaction $transaction, Request $request, User $user)
    {
        $token = $request->getQueryParams()['token'] ?? "";

        if (array_key_exists('auto', $request->getQueryParams())) {
            return (new PayPalSubscribe($this->credential, $this->subscription, $this->getCurrency()))->execute($transaction->getItems(), $transaction, $this->service);
        }
        if ($transaction->getTransactionId() !== $token) {
            return new RedirectResponse($this->getRedirectsLinks($request, $transaction)['cancel']);
        }
        try {
            $responsePaypal = $this->credential->getClient()->execute(new OrdersCaptureRequest($token));

            /** @var stdClass */
            $result = $responsePaypal->result;
            $captures = $result->purchase_units[0]->payments->captures;
            $transaction->setTransactionId($captures[0]->id);
            $this->service->updateTransactionId($transaction);
            $transaction->setState($transaction::COMPLETED);
            $this->service->complete($transaction);

            foreach ($transaction->getItems() as $item) {
                $this->service->delivre($item);
            }
            $this->service->changeState($transaction);
            return $transaction;
        } catch (PayPalException $e) {
            $transaction->setState($transaction::REFUSED);
            $transaction->setReason("PayPal error : " . $e->getMessage());
            $this->service->changeState($transaction);
            $this->service->setReason($transaction);
        }
        return null;
    }

    public function refund(array $items): bool
    {
        return false;
    }

    private function checkIfTransactionCanSubscribe(Transaction $transaction): bool
    {
        if (!array_key_exists(SubscriptionService::KEY_SUBSCRIBE, \ClientX\request()->getParsedBody())) {
            return false;
        }

        if (!array_key_exists('PAYPAL_SUBSCRIPTION', $_ENV) || $_ENV['PAYPAL_SUBSCRIPTION'] == 'false') {
            return false;
        }
        return collect($transaction->getItems())->filter(function (TransactionItem $transactionItem) {
            return $transactionItem->getOrderable() instanceof Product && $transactionItem->getOrderable()->getPaymentType() == 'recurring';
        })->count() == 1;
    }
}
