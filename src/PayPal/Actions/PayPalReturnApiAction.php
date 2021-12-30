<?php


namespace App\PayPal\Actions;

use App\Admin\DatabaseAdminAuth;
use App\Auth\DatabaseUserAuth;
use App\PayPal\PaypalCredential;
use App\Shop\Services\TransactionService;
use ClientX\Actions\Action;
use ClientX\Helpers\Str;
use ClientX\Renderer\RendererInterface;
use ClientX\Response\RedirectResponse;
use GuzzleHttp\Client;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

class PayPalReturnApiAction extends Action
{

    /**
     * @var \App\PayPal\PaypalCredential
     */
    private PaypalCredential $credential;
    /**
     * @var \App\Shop\Services\TransactionService
     */
    private TransactionService $transactionService;
    /**
     * @var \Psr\Log\LoggerInterface
     */
    private LoggerInterface $logger;
    /**
     * @var \App\Admin\DatabaseAdminAuth
     */
    private DatabaseAdminAuth $adminAuth;

    /**
     * @param \App\PayPal\PaypalCredential $credential
     * @param \App\Shop\Services\TransactionService $transactionService
     * @param \Psr\Log\LoggerInterface $logger
     * @param \App\Admin\DatabaseUserAuth $auth
     */
    public function __construct(PaypalCredential $credential, TransactionService $transactionService, LoggerInterface $logger, DatabaseUserAuth $auth, DatabaseAdminAuth $adminAuth, RendererInterface $renderer)
    {
        $this->credential = $credential;
        $this->transactionService = $transactionService;
        $this->logger = $logger;
        $this->auth = $auth;
        $this->adminAuth = $adminAuth;
        $this->renderer = $renderer;
    }

    public function __invoke(ServerRequestInterface $request)
    {

        $postipn = "cmd=_notify-validate";
        $orgipn = "";
        $params = $request->getParsedBody();
        if (empty($params)) {
            return 'ERROR INTERNAL';
        }
        foreach ($params as $key => $value) {
            $orgipn .= (string)$key . " => " . $value . "\n";
            $postipn .= "&" . $key . "=" . urlencode($value);
        }
        $sub = $this->credential->isLive() ? '' : 'sandbox.';
        $url = "https://www." . $sub . "paypal.com/cgi-bin/webscr";

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postipn);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 100);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        $reply = curl_exec($ch);
        curl_close($ch);
        if ($reply == false) {
            return 'ERROR REPLY';
        }
        [$url, $transactionId, $adminId] = explode('---', $params['custom']);
        $transaction = $this->transactionService->findTransaction($transactionId);
        if (Str::contains($reply, "VERIFIED")) {
            $transaction->setTransactionId($params['txn_id']);
            $this->transactionService->updateTransactionId($transaction);
            $transaction->setState($transaction::COMPLETED);
            $this->transactionService->complete($transaction);
            $this->transactionService->changeState($transaction);
        } else {
            $transaction->setState($transaction::REFUSED);
            $this->transactionService->complete($transaction);
            $this->transactionService->changeState($transaction);
        }
        if ($adminId != 0) {
            $this->adminAuth->setUser($adminId);
        }
        $this->auth->setUser($transaction->getUserId());
        return $this->render('@paypal_admin/return', compact('url'));
    }
}
