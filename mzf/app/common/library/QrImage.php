<?php

namespace app\common\library;

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use chillerlan\QRCode\Output\QRGdImagePNG;

/**
 * 二维码生成（由文本生成可展示的二维码 data URI）
 *
 * 收银台对"解码后的支付串/链接"重新生成二维码展示，避免直接热链上传图片。
 *
 * 使用 PNG 输出而非默认 SVG，防止浏览器暗黑模式下自动反相 SVG 颜色导致二维码不可扫。
 */
class QrImage
{
    /**
     * 由文本生成二维码 data URI（PNG 格式，白底黑模块，防暗黑模式反相）
     */
    public static function dataUri(string $text): string
    {
        if ($text === '') {
            return '';
        }
        $options = new QROptions([
            'outputInterface'  => QRGdImagePNG::class,
            'outputBase64'     => true,
            'scale'            => 8,
            'imageTransparent' => false,
        ]);
        return (new QRCode($options))->render($text);
    }

    /**
     * 判断字符串是否已是可直接展示的图片地址（http 图片 / data URI）
     */
    public static function isImageSource(string $s): bool
    {
        if (str_starts_with($s, 'data:image')) {
            return true;
        }
        if (preg_match('#^https?://#i', $s) && preg_match('#\.(png|jpe?g|gif|webp|bmp)(\?.*)?$#i', $s)) {
            return true;
        }
        return false;
    }
}
