<?php

namespace Xiaofan\Pay\Tests;

use Xiaofan\Pay\Contracts\GatewayApplicationInterface;
use Xiaofan\Pay\Exceptions\InvalidGatewayException;
use Xiaofan\Pay\Gateways\Alipay;
use Xiaofan\Pay\Gateways\Wechat;
use Xiaofan\Pay\Pay;

class PayTest extends TestCase
{
    public function testAlipayGateway()
    {
        $alipay = Pay::alipay(['foo' => 'bar']);

        $this->assertInstanceOf(Alipay::class, $alipay);
        $this->assertInstanceOf(GatewayApplicationInterface::class, $alipay);
    }

    public function testWechatGateway()
    {
        $wechat = Pay::wechat(['foo' => 'bar']);

        $this->assertInstanceOf(Wechat::class, $wechat);
        $this->assertInstanceOf(GatewayApplicationInterface::class, $wechat);
    }

    public function testFooGateway()
    {
        $this->expectException(InvalidGatewayException::class);
        $this->expectExceptionMessage('INVALID_GATEWAY: Gateway [foo] Not Exists');

        Pay::foo([]);
    }
}
