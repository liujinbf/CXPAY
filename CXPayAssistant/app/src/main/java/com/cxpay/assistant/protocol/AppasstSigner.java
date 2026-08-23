package com.cxpay.assistant.protocol;

import java.nio.charset.StandardCharsets;
import java.util.Locale;
import javax.crypto.Mac;
import javax.crypto.spec.SecretKeySpec;

/**
 * CXPAY 手机助手协议签名工具类
 * 与服务端 AppasstProtocol.php 及 PC 端 Protocol.cs 保持 100% 一致。
 */
public final class AppasstSigner {

    public static final String VERSION = "2";
    private static final String HMAC_SHA256 = "HmacSHA256";

    /**
     * 规范化签名原文：
     * version|channel_id|device_id|event|pay_type|money|source_bill_id|occurred_at|timestamp|nonce|client_version
     */
    public static String canonicalize(
            String version,
            String channelId,
            String deviceId,
            String event,
            String payType,
            String money,
            String sourceBillId,
            long occurredAt,
            long timestamp,
            String nonce,
            String clientVersion
    ) {
        return String.format(
                Locale.US,
                "%s|%s|%s|%s|%s|%s|%s|%d|%d|%s|%s",
                version != null ? version.trim() : VERSION,
                channelId != null ? channelId.trim() : "0",
                deviceId != null ? deviceId.trim() : "",
                event != null ? event.trim() : "bill",
                payType != null ? payType.trim() : "",
                money != null ? money.trim() : "0.00",
                sourceBillId != null ? sourceBillId.trim() : "",
                occurredAt,
                timestamp,
                nonce != null ? nonce.trim() : "",
                clientVersion != null ? clientVersion.trim() : "1.2.0"
        );
    }

    /**
     * 计算 HMAC-SHA256 签名
     */
    public static String sign(String canonicalStr, String secret) {
        try {
            Mac mac = Mac.getInstance(HMAC_SHA256);
            SecretKeySpec secretKeySpec = new SecretKeySpec(secret.getBytes(StandardCharsets.UTF_8), HMAC_SHA256);
            mac.init(secretKeySpec);
            byte[] hash = mac.doFinal(canonicalStr.getBytes(StandardCharsets.UTF_8));

            StringBuilder hexString = new StringBuilder();
            for (byte b : hash) {
                String hex = Integer.toHexString(0xff & b);
                if (hex.length() == 1) {
                    hexString.append('0');
                }
                hexString.append(hex);
            }
            return hexString.toString();
        } catch (Exception e) {
            throw new RuntimeException("HMAC-SHA256 签名计算失败: " + e.getMessage(), e);
        }
    }
}
