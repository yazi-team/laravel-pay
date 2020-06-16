<?php

namespace Xiaofan\Pay\Gateways\Tianxia;

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
    public function pay($endpoint, array $payload)
    {
        $result = Support::requestApi($payload);
        if (empty($result)) {
            return true;
        }
        if (isset($result['code']) && $result['code'] != 200) {
            Log::error("TianxiaTransferRequestError", $result->toArray());
            throw new GatewayException('Get Tianxia API Error:' . (isset($result['msg']) ? $result['msg'] : 'unkown'), $result);
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
