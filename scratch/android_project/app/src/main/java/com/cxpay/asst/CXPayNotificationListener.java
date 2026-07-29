package com.cxpay.asst;

import android.app.Notification;
import android.os.Bundle;
import android.service.notification.NotificationListenerService;
import android.service.notification.StatusBarNotification;
import android.util.Log;

import org.json.JSONObject;

import java.io.OutputStream;
import java.math.BigDecimal;
import java.math.RoundingMode;
import java.net.HttpURLConnection;
import java.net.URL;
import java.net.URLEncoder;
import java.nio.charset.StandardCharsets;
import java.security.MessageDigest;
import java.util.UUID;
import java.util.concurrent.Executors;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.ScheduledExecutorService;
import java.util.concurrent.TimeUnit;
import java.util.regex.Matcher;
import java.util.regex.Pattern;

import javax.crypto.Mac;
import javax.crypto.spec.SecretKeySpec;

/**
 * 支付宝、微信、QQ钱包通知监听器。
 * 只处理配置支付类型对应的官方应用通知，避免跨通道金额误匹配。
 */
public class CXPayNotificationListener extends NotificationListenerService {
    private static final String TAG = "CXPayListener";
    private static final String CLIENT_VERSION = "2.0.0";
    private static final Pattern MONEY_PATTERN = Pattern.compile(
            "(?:￥|¥|人民币)?\\s*(\\d{1,8}(?:\\.\\d{1,2})?)\\s*(?:元|RMB)",
            Pattern.CASE_INSENSITIVE
    );
    private final ScheduledExecutorService heartbeatExecutor = Executors.newSingleThreadScheduledExecutor();
    private final ExecutorService uploadExecutor = Executors.newSingleThreadExecutor();

    @Override
    public void onCreate() {
        super.onCreate();
        heartbeatExecutor.scheduleAtFixedRate(
                () -> sendEvent("heartbeat", "0.00", "", "", 0),
                0,
                30,
                TimeUnit.SECONDS
        );
    }

    @Override
    public void onDestroy() {
        heartbeatExecutor.shutdownNow();
        uploadExecutor.shutdownNow();
        super.onDestroy();
    }

    @Override
    public void onNotificationPosted(StatusBarNotification notification) {
        if (notification == null || notification.getNotification() == null) return;
        String payType = AssistantConfig.payType(this);
        if (!expectedPackage(payType).equals(notification.getPackageName())) return;

        Bundle extras = notification.getNotification().extras;
        if (extras == null) return;
        String title = String.valueOf(extras.getCharSequence(Notification.EXTRA_TITLE, ""));
        String text = String.valueOf(extras.getCharSequence(Notification.EXTRA_TEXT, ""));
        String content = title + " " + text;
        if (!looksLikeIncome(content)) return;

        Matcher matcher = MONEY_PATTERN.matcher(content);
        if (!matcher.find()) return;
        String money = new BigDecimal(matcher.group(1)).setScale(2, RoundingMode.HALF_UP).toPlainString();
        String sourceBillId = sha256(
                notification.getPackageName() + "|" + notification.getKey() + "|" + notification.getPostTime()
        );
        long occurredAt = notification.getPostTime() / 1000L;
        String safeRemark = content.length() > 255 ? content.substring(0, 255) : content;
        uploadExecutor.execute(
                () -> sendEvent("bill", money, safeRemark, sourceBillId, occurredAt)
        );
    }

    private void sendEvent(String event, String money, String remark, String sourceBillId, long occurredAt) {
        HttpURLConnection connection = null;
        try {
            String serverUrl = AssistantConfig.serverUrl(this);
            long channelId = AssistantConfig.channelId(this);
            String payType = AssistantConfig.payType(this);
            String deliveryMode = AssistantConfig.deliveryMode(this);
            if (!serverUrl.startsWith("https://") || channelId <= 0) {
                return;
            }
            if ("feed".equals(deliveryMode)) {
                if ("bill".equals(event)) {
                    sendToAuthorizedFeed(serverUrl, channelId, payType, money, remark, sourceBillId, occurredAt);
                }
                return;
            }

            String deviceId = AssistantConfig.deviceId(this);
            String secret = AssistantConfig.secret(this);
            if (deviceId.isEmpty() || secret.length() < 32) return;

            long timestamp = System.currentTimeMillis() / 1000L;
            String nonce = UUID.randomUUID().toString().replace("-", "");
            String canonical = "2|" + channelId + "|" + deviceId + "|" + event + "|" + payType + "|"
                    + money + "|" + sourceBillId + "|" + occurredAt + "|" + timestamp + "|" + nonce
                    + "|" + CLIENT_VERSION;

            JSONObject payload = new JSONObject();
            payload.put("version", "2");
            payload.put("channel_id", channelId);
            payload.put("device_id", deviceId);
            payload.put("event", event);
            payload.put("pay_type", payType);
            payload.put("money", money);
            payload.put("remark", remark);
            payload.put("source_bill_id", sourceBillId);
            payload.put("occurred_at", occurredAt);
            payload.put("timestamp", timestamp);
            payload.put("nonce", nonce);
            payload.put("client_version", CLIENT_VERSION);
            payload.put("sign", hmacSha256(canonical, secret));

            connection = (HttpURLConnection) new URL(serverUrl + "/api/appasst/push").openConnection();
            connection.setRequestMethod("POST");
            connection.setRequestProperty("Content-Type", "application/json; charset=UTF-8");
            connection.setConnectTimeout(5000);
            connection.setReadTimeout(5000);
            connection.setDoOutput(true);
            try (OutputStream output = connection.getOutputStream()) {
                output.write(payload.toString().getBytes(StandardCharsets.UTF_8));
            }
            int responseCode = connection.getResponseCode();
            if (responseCode < 200 || responseCode >= 300) {
                Log.w(TAG, "账单/心跳上报失败，HTTP状态=" + responseCode);
            }
        } catch (Exception exception) {
            Log.w(TAG, "安全上报失败：" + exception.getClass().getSimpleName());
        } finally {
            if (connection != null) connection.disconnect();
        }
    }

    private void sendToAuthorizedFeed(
            String serverUrl,
            long channelId,
            String payType,
            String money,
            String remark,
            String sourceBillId,
            long occurredAt
    ) {
        HttpURLConnection connection = null;
        try {
            String collectorId = AssistantConfig.collectorId(this);
            String ingestToken = AssistantConfig.ingestToken(this);
            if (!collectorId.matches("[A-Za-z0-9_.:-]{1,64}") || ingestToken.length() < 32) return;

            String body = "channel_id=" + encode(String.valueOf(channelId))
                    + "&collector_id=" + encode(collectorId)
                    + "&pay_type=" + encode(payType)
                    + "&money=" + encode(money)
                    + "&occurred_at=" + encode(String.valueOf(occurredAt))
                    + "&source_bill_id=" + encode(sourceBillId)
                    + "&remark=" + encode(remark);
            connection = (HttpURLConnection) new URL(serverUrl + "/api/bill-source/ingest").openConnection();
            connection.setRequestMethod("POST");
            connection.setRequestProperty("Authorization", "Bearer " + ingestToken);
            connection.setRequestProperty("Content-Type", "application/x-www-form-urlencoded; charset=UTF-8");
            connection.setConnectTimeout(5000);
            connection.setReadTimeout(5000);
            connection.setDoOutput(true);
            try (OutputStream output = connection.getOutputStream()) {
                output.write(body.getBytes(StandardCharsets.UTF_8));
            }
            int responseCode = connection.getResponseCode();
            if (responseCode < 200 || responseCode >= 300) {
                Log.w(TAG, "账单源写入失败，HTTP状态=" + responseCode);
            }
        } catch (Exception exception) {
            Log.w(TAG, "账单源写入失败：" + exception.getClass().getSimpleName());
        } finally {
            if (connection != null) connection.disconnect();
        }
    }

    private String encode(String value) throws Exception {
        return URLEncoder.encode(value == null ? "" : value, "UTF-8");
    }

    private boolean looksLikeIncome(String content) {
        return content.contains("收款") || content.contains("到账") || content.contains("入账");
    }

    private String expectedPackage(String payType) {
        if ("wxpay".equals(payType)) return "com.tencent.mm";
        if ("qqpay".equals(payType)) return "com.tencent.mobileqq";
        return "com.eg.android.AlipayGphone";
    }

    private String hmacSha256(String input, String secret) throws Exception {
        Mac mac = Mac.getInstance("HmacSHA256");
        mac.init(new SecretKeySpec(secret.getBytes(StandardCharsets.UTF_8), "HmacSHA256"));
        return toHex(mac.doFinal(input.getBytes(StandardCharsets.UTF_8)));
    }

    private String sha256(String input) {
        try {
            return toHex(MessageDigest.getInstance("SHA-256").digest(input.getBytes(StandardCharsets.UTF_8)));
        } catch (Exception exception) {
            return "";
        }
    }

    private String toHex(byte[] bytes) {
        StringBuilder output = new StringBuilder(bytes.length * 2);
        for (byte value : bytes) output.append(String.format("%02x", value & 0xff));
        return output.toString();
    }
}
