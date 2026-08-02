<?php

namespace support\exception;

use Throwable;
use Webman\Exception\ExceptionHandler;
use Webman\Http\Request;
use Webman\Http\Response;

class Handler extends ExceptionHandler
{
    public function render(Request $request, Throwable $exception): Response
    {
        // 针对 API 请求，即使服务端抛出 500 异常，也统一返回 JSON 格式并带上详细错误说明，方便追踪
        if ($request->expectsJson() || str_starts_with($request->path(), '/api/')) {
            $json = [
                'code' => -1,
                'msg'  => $exception->getMessage() ?: 'Server internal error',
                'trace' => $this->debug ? (string)$exception : null
            ];
            return new Response(500, ['Content-Type' => 'application/json; charset=utf-8'],
                json_encode($json, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        return parent::render($request, $exception);
    }
}
