<?php

namespace Xiaofan\Pay\Gateways\Tongyi;

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
            Log::error("TongyiTransferRequestError", $result->toArray());
            if ($result['msg'] != '订单号重复，请确认') {
                throw new GatewayException('Get Tongyi API Error:' . (isset($result['msg']) ? $result['msg'] : 'unkown'), $result);
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
            'type' => 'check_withdraw_detail',
        ];
    }
}
