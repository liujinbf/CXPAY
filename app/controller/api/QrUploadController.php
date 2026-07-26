<?php

declare(strict_types=1);

namespace app\controller\api;

use app\service\QrDecodeService;
use support\Response;
use Throwable;

/**
 * 个人收款码图片上传与自动解码 API 控制器
 */
class QrUploadController
{
    protected QrDecodeService $qrService;

    public function __construct()
    {
        $this->qrService = new QrDecodeService();
    }

    /**
     * 上传微信/支付宝个人收款码图片并自动解码
     */
    public function upload(object $request): Response
    {
        try {
            $files = $request->file();
            $file  = $files['file'] ?? reset($files);

            if (!$file || !$file->isValid()) {
                return json(['code' => -1, 'msg' => '请选择有效的个人收款码图片上传']);
            }

            // 保存图片到 runtime 目录
            $tmpPath = base_path() . '/runtime/qr_' . uniqid() . '.png';
            $file->move($tmpPath);

            // 调用 QrDecodeService 解码文本
            $qrText = $this->qrService->decodeFile($tmpPath);
            @unlink($tmpPath);

            if (empty($qrText)) {
                return json(['code' => -1, 'msg' => '收款码图像解析失败，请确保上传清晰的微信/支付宝收款码']);
            }

            return json([
                'code'    => 1,
                'msg'     => '收款码自动解析成功！',
                'qr_data' => $qrText,
            ]);
        } catch (Throwable $e) {
            return json(['code' => -1, 'msg' => '解析异常: ' . $e->getMessage()]);
        }
    }
}
