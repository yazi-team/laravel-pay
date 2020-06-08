<?php

namespace Xiaofan\Pay\Gateways\Yongli;

use Xiaofan\Pay\Contracts\GatewayInterface;
use Xiaofan\Pay\Events;
use Xiaofan\Pay\Exceptions\GatewayException;
use Xiaofan\Pay\Exceptions\InvalidConfigException;
use Xiaofan\Pay\Exceptions\InvalidSignException;
use Yansongda\Supports\Collection;
use Illuminate\Support\Facades\Log;

class TransferGateway implements GatewayInterface
{
    /**
     * Pay an order.
     *
     * @author yansongda <me@yansongda.cn>
     *
     * @param string $endpoint
     *
     * @throws GatewayException
     * @throws InvalidConfigException
     * @throws InvalidSignException
     */
    public function pay($endpoint, array $payload): Collection
    {
        $result = Support::requestApi($payload);
        if (empty($result)) {
            return true;
        }
        if (isset($result['code']) && $result['code'] != 0) {
            Log::error("YongLiTransferRequestError", $result->toArray());
            if ($result['msg'] != '订单号重复，请确认') {
                throw new GatewayException('Get Yongli API Error:' . (isset($result['msg']) ? $result['msg'] : 'unkown'), $result);
            }
        }
        return true;
    }

    /**
     * Find.
     *
     * @author yansongda <me@yansongda.cn>
     *
     * @param $order
     */
    public function find($order): array
    {
        return [
            'method' => 'alipay.fund.trans.order.query',
            'biz_content' => json_encode(is_array($order) ? $order : ['out_biz_no' => $order]),
        ];
    }
}
