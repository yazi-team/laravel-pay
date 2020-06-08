<?php
namespace Xiaofan\Pay\Contracts;

interface Transferable
{
    public function getTransferNo();

    public function getExtra($name);

    public function getAmount();

    public function getRealName();

    public function getAccount();

    public function getChannel();
    public function getRemark();
}