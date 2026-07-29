<?php

declare(strict_types=1);

namespace app\payment\Contracts;

/**
 * 扫码授权账号能力探测契约。
 */
interface AccountCapabilityDetectorInterface
{
    public const STATUS_UNKNOWN = 'UNKNOWN';
    public const STATUS_RECEIPT_AVAILABLE = 'RECEIPT_AVAILABLE';
    public const STATUS_RECEIPT_NOT_OPENED = 'RECEIPT_NOT_OPENED';
    public const STATUS_BOOK_AVAILABLE = 'BOOK_AVAILABLE';
    public const STATUS_REAUTH_REQUIRED = 'REAUTH_REQUIRED';
    public const STATUS_TEMPORARY_ERROR = 'TEMPORARY_ERROR';

    /**
     * @return array{status:string,message:string,capabilities?:array<string,bool>}
     */
    public function detectAccountCapabilities(array $config): array;
}
