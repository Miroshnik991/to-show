<?php

namespace App\Listeners\V1\Broker;

use Lime\Kafka\Error;
use Lime\Kafka\Message\Message;

abstract class BaseSingleMessageHandler
{
    abstract public function process(Message $message): bool;

    abstract public function error(Error $error): void;
}
