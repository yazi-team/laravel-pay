<?php

namespace Xiaofan\Pay\Gateways\Jiupay;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Yansongda\Supports\Collection;
use Xiaofan\Pay\Events;
use Xiaofan\Pay\Exceptions\InvalidArgumentException;
use Xiaofan\Pay\Exceptions\InvalidConfigException;
use Xiaofan\Pay\Gateways\Jiupay;
use Xiaofan\Pay\Entity\ResponseResult;

class WebGateway extends Gateway {

    /**
     * Pay an order.
     *
     * @author yansongda <me@yansongda.cn>
     *
     * @param string $endpoint
     *
     * @throws InvalidConfigException
     * @throws InvalidArgumentException
     */
    public function pay($endpoint, array $payload): ResponseResult {
        
        Events::dispatch(new Events\PayStarted('Jiupay', 'Web/Wap', $endpoint, $payload));
        if ($payload['pay_format'] == 'pay_str') {
            $result = Support::requestApi($payload);
            if ($result['qrcode']) {
                $response = redirect($result['qrcode']);
            }else{
                throw new GatewayException('Get Jiupay API Error:' . (isset($result['msg']) ? $result['msg'] : 'unkown'), $result);
            }
        }else{
            $response = $this->buildPayHtml($endpoint, $payload, "POST");
        }
        
        return new ResponseResult($response);
    }

    /**
     * Build Html response.
     *
     * @author yansongda <me@yansongda.cn>
     *
     * @param string $endpoint
     * @param array  $payload
     * @param string $method
     */
    protected function buildPayHtml($endpoint, $payload, $method = 'POST'): Response {
        if ('GET' === strtoupper($method)) {
            return RedirectResponse::create($endpoint . '&' . http_build_query($payload));
        }

        $sHtml = "<form id='alipay_submit' name='alipay_submit' action='" . $endpoint . "' method='" . $method . "'>";
        foreach ($payload as $key => $val) {
            $val = str_replace("'", '&apos;', $val);
            $sHtml .= "<input type='hidden' name='" . $key . "' value='" . $val . "'/>";
        }
        $sHtml .= "<input type='submit' value='ok' style='display:none;'></form>";
        $sHtml .= "<script>document.forms['alipay_submit'].submit();</script>";

        return Response::create($sHtml);
    }

}
