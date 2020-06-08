<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK IT ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006-2017 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: yunwuxin <448901948@qq.com>
// +----------------------------------------------------------------------

namespace Xiaofan\Pay\Entity;

class TransferResult
{
    protected $tradeNo;

    /** @var Date */
    protected $payDate;

    public function __construct($tradeNo, $payDate)
    {
        $this->tradeNo = $tradeNo;
        $this->payDate = $payDate;
    }

    /**
     * @return Date
     */
    public function getPayDate()
    {
        return $this->payDate;
    }

    /**
     * @return mixed
     */
    public function getTradeNo()
    {
        return $this->tradeNo;
    }

}