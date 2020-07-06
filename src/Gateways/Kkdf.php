<?php

namespace Xiaofan\Pay\Gateways;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Xiaofan\Pay\Contracts\GatewayApplicationInterface;
use Xiaofan\Pay\Contracts\GatewayInterface;
use Xiaofan\Pay\Events;
use Xiaofan\Pay\Exceptions\GatewayException;
use Xiaofan\Pay\Exceptions\InvalidArgumentException;
use Xiaofan\Pay\Exceptions\InvalidConfigException;
use Xiaofan\Pay\Exceptions\InvalidGatewayException;
use Xiaofan\Pay\Exceptions\InvalidSignException;
use Xiaofan\Pay\Gateways\Kkdf\Support;
use Yansongda\Supports\Collection;
use Yansongda\Supports\Config;
use Yansongda\Supports\Str;
use Xiaofan\Pay\Contracts\Payable;
use Xiaofan\Pay\Contracts\Transferable;
use Xiaofan\Pay\Entity\PurchaseResult;

/**
 * @method Response   wap(array $config)      手机网站支付
 */
class Kkdf implements GatewayApplicationInterface {

    /**
     * Const mode_normal.
     */
    const MODE_NORMAL = 'normal';

    /**
     * Const mode_dev.
     */
    const MODE_DEV = 'dev';
    
    /**
     * Const mode_query.
     */
    const MODE_QUERY = 'query';
    
    const MODE_TRANSFER = 'transfer';
    const MODE_TRANSFER_QUERY = 'transfer_query';

    /**
     * Const mode_service.
     */
    const MODE_SERVICE = 'service';

    /**
     * Const url.
     */
    const URL = [
        self::MODE_TRANSFER => 'http://gateway.kkmpay.com:88/order/submit',
        self::MODE_TRANSFER_QUERY => 'http://gateway.kkmpay.com:88/order/query',
    ];

    /**
     * Kkdf payload.
     *
     * @var array
     */
    protected $payload;

    /**
     * Kkdf gateway.
     *
     * @var string
     */
    protected $gateway;

    /**
     * Bootstrap.
     *
     * @author yansongda <me@yansongda.cn>
     *
     * @throws \Exception
     */
    public function __construct(Config $config) {
        $this->gateway = Support::create($config)->getBaseUri();
        $this->payload = [
            'Version' => '1.0',
            'MerchantNo' => $config->get('app_id'),
            'NoticeUrl' => $config->get('notify_url'),
        ];
    }

    /**
     * Magic pay.
     *
     * @author yansongda <me@yansongda.cn>
     *
     * @param string $method
     * @param array  $charge
     *
     * @throws GatewayException
     * @throws InvalidArgumentException
     * @throws InvalidConfigException
     * @throws InvalidGatewayException
     * @throws InvalidSignException
     *
     * @return Response|Collection
     */
    public function __call($method, Payable $charge) {
        return $this->pay($method, $charge);
    }

    /**
     * Pay an order.
     *
     * @author yansongda <me@yansongda.cn>
     *
     * @param string $gateway
     * @param array  $charge
     *
     * @throws InvalidGatewayException
     *
     * @return Response|Collection
     */
    public function pay($gateway, Payable $charge) {
        throw new InvalidGatewayException("Pay Gateway [{$gateway}] not exists");
    }
    /**
     * Pay an order.
     *
     * @author yansongda <me@yansongda.cn>
     *
     * @param array  $transfer
     *
     * @throws InvalidGatewayException
     *
     * @return Response|Collection
     */
    public function transfer(Transferable $transfer) {
        $gateway = 'transfer';
        $request = app('request');
        $request->setTrustedProxies($request->getClientIps(), Request::HEADER_X_FORWARDED_ALL);
        $NoticeUrl = $this->payload['NoticeUrl'];
        unset($this->payload['NoticeUrl']);
        $data = [
            "MerchantOrderNo" => $transfer->getTransferNo(),
            "BankCode" => Support::getBankCode($transfer->getAccount(), $transfer->getExtra('bank_name')),
            "PayeeType" => "0",
            "PayeeName" => $transfer->getRealName(),
            "PayeeAccount" => $transfer->getAccount(),
            "Amount" => sprintf("%.2f", intval($transfer->getAmount()) / 100),
            'NoticeUrl' => $NoticeUrl,
            "Remark" => $transfer->getRemark()
        ];
        
        $this->payload['Data'] = Support::rsa_encrypt(http_build_query($data));
        $this->payload['Sign'] = Support::generateSign($this->payload);
//        dump($this->payload);die;
        $gateway = get_class($this) . '\\' . Str::studly($gateway) . 'Gateway';
        \Illuminate\Support\Facades\Log::info($gateway);
        if (class_exists($gateway)) {
            return $this->makePay($gateway);
        }

        throw new InvalidGatewayException("Pay Gateway [{$gateway}] not exists");
    }

    /**
     * Verify sign.
     *
     * @author yansongda <me@yansongda.cn>
     *
     * @param array|null $data
     *
     * @throws InvalidSignException
     * @throws InvalidConfigException
     */
    public function verify($data = null, bool $refund = false): PurchaseResult {
        if (is_null($data)) {
            $request = Request::createFromGlobals();

            $data = $request->request->count() > 0 ? $request->request->all() : $request->query->all();
        }
//        Events::dispatch(new Events\RequestReceived('Kkdf', '', $data));

        if (Support::verifySign($data)) {
            parse_str(urldecode(Support::rsa_decrypt($data['Data'])), $params);
            $find = $this->find($params['OrderNo']);
            $params['err_msg'] = isset($params['error_note']) ? $params['error_note'] : '';
            return new PurchaseResult('Kkdf', 
                    $params['MerchantOrderNo'], 
                    $params['OrderNo'],
                    0, 
                    trim($params['Status']) === 'Success', 
                    date("Y-m-d H:i:s"), $params);
        }

        Events::dispatch(new Events\SignFailed('Kkdf', '', $data));

        throw new InvalidSignException('Kkdf Sign Verify FAILED', $data);
    }

    /**
     * Query an order.
     *
     * @author yansongda <me@yansongda.cn>
     *
     * @param string $order
     *
     * @throws GatewayException
     * @throws InvalidConfigException
     * @throws InvalidSignException
     */
    public function find($order, string $type = 'wap'): Collection {
        $data = ['OrderNo' => $order];
        $this->payload['Data'] = Support::rsa_encrypt(http_build_query($data));
        $this->payload['Sign'] = Support::generateSign($this->payload);
        
        Events::dispatch(new Events\MethodCalled('Kkdf', 'Find', $this->gateway, $this->payload));

        return Support::requestApi($this->payload, "get");
    }

    /**
     * Refund an order.
     *
     * @author yansongda <me@yansongda.cn>
     *
     * @throws GatewayException
     * @throws InvalidConfigException
     * @throws InvalidSignException
     */
    public function refund(array $order): Collection {
        
    }

    /**
     * Cancel an order.
     *
     * @author yansongda <me@yansongda.cn>
     *
     * @param array|string $order
     *
     * @throws GatewayException
     * @throws InvalidConfigException
     * @throws InvalidSignException
     */
    public function cancel($order): Collection {
    }

    /**
     * Close an order.
     *
     * @param string|array $order
     *
     * @author yansongda <me@yansongda.cn>
     *
     * @throws GatewayException
     * @throws InvalidConfigException
     * @throws InvalidSignException
     */
    public function close($order): Collection {
        
    }

    /**
     * Reply success to alipay.
     *
     * @author yansongda <me@yansongda.cn>
     */
    public function success(): Response {
        Events::dispatch(new Events\MethodCalled('Kkdf', 'Success', $this->gateway));

        return Response::create('OK');
    }

    /**
     * Make pay gateway.
     *
     * @author yansongda <me@yansongda.cn>
     *
     * @throws InvalidGatewayException
     *
     * @return Response|Collection
     */
    protected function makePay(string $gateway) {
        $app = new $gateway();

        if ($app instanceof GatewayInterface) {
            return $app->pay($this->gateway, array_filter($this->payload, function ($value) {
                                return '' !== $value && !is_null($value);
                            }));
        }

        throw new InvalidGatewayException("Pay Gateway [{$gateway}] Must Be An Instance Of GatewayInterface");
    }

}
