<?php

namespace App\PayPal;

use App\Account\User;
use ClientX\Auth;
use App\Shop\Entity\Transaction;
use App\Shop\Entity\TransactionItem;
use App\Shop\Payment\AbstractPaymentManager;
use App\Shop\Services\TransactionService;
use ClientX\Payment\PaymentManagerInterface;
use ClientX\Response\RedirectResponse;
use ClientX\Router;
use Exception;
use PayPalCheckoutSdk\Orders\OrdersCaptureRequest;
use PayPalCheckoutSdk\Orders\OrdersCreateRequest;
use Psr\Http\Message\ServerRequestInterface as Request;
use stdClass;

class PayPalPaymentManager extends AbstractPaymentManager implements PaymentManagerInterface
{
    private PaypalCredential $credential;

    public function __construct(Router $router, Auth $auth, TransactionService $service, PaypalCredential $credential)
    {
        parent::__construct($router, $auth, $service);
        $this->credential = $credential;
    }

    public function process(Transaction $transaction, Request $request, User $user)
    {
        $user = $this->getUser();
        if ($user === null) {
            return;
        }
        $items = collect($transaction->getItems())->map(function (TransactionItem $item) use ($transaction) {
            return [
                'name' => $item->getName(),
                'sku' => $item->getId(),
                'unit_amount' => [
                    'currency_code' => $transaction->getCurrency(),
                    'value' => $item->priceWithTax(),
                ],
                'quantity' => $item->getQuantity(),
                'category' => 'DIGITAL_GOODS'
            ];
        })->toArray();


        $links = $this->getRedirectsLinks($request, $transaction);

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
                        'value' => $transaction->priceWithTax(),
                        'breakdown' => [
                            'item_total' => [
                                'currency_code' => $transaction->getCurrency(),
                                'value' => $transaction->priceWithTax(),
                            ],
                        ],
                    ],
                    'items' => $items,
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
            $this->service->changeState($transaction);
            return $transaction;
        } catch (Exception $e) {
            $transaction->setState($transaction::REFUSED);
            $transaction->setReason("PayPal error");
            $this->service->changeState($transaction);
            $this->service->setReason($transaction);
        }
        return null;
    }

    public function refund(array $items): bool
    {
        return false;
    }
}
