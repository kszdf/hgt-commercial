<?php

namespace App\Exceptions;

use Exception;

/**
 * 8500 出片微服务暂时不可达（连接拒绝/超时，且重试已耗尽）。
 * 由各控制器捕获后降级为 503 + 友好提示，而非未捕获的 500。
 */
class PipelineUnavailableException extends Exception
{
}
