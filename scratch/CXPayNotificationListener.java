package com.cxpay.asst;

import android.app.Notification;
import android.content.Intent;
import android.os.Bundle;
import android.service.notification.NotificationListenerService;
import android.service.notification.StatusBarNotification;
import android.util.Log;

import org.json.JSONObject;

import java.io.OutputStream;
import java.net.HttpURLConnection;
import java.net.URL;
import java.math.BigDecimal;
import java.math.RoundingMode;
import java.nio.charset.StandardCharsets;
import java.security.MessageDigest;
import java.util.UUID;
import java.util.concurrent.Executors;
import java.util.concurrent.ScheduledExecutorService;
import java.util.concurrent.TimeUnit;
import java.util.regex.Matcher;
import java.util.regex.Pattern;
import javax.crypto.Mac;
import javax.crypto.spec.SecretKeySpec;

/**
 * CXPAY 商业版安卓挂机助手核心服务 - 监听微信/支付宝通知栏推送并加密上报
 */
public class CXPayNotificationListener extends NotificationListenerService {

    private static final String TAG = "CXPayListener";

    // 参考实现：正式客户端应从 Android Keystore/加密配置读取这些值。
    private String serverUrl = "https://pay.example.com/api/appasst/push";
    private long channelId = 0;
    private String deviceId = "ANDROID_REPLACE_ME";
    private String payType = "alipay";
    private String deviceSecret = "REPLACE_WITH_CHANNEL_NOTIFY_SECRET";
    private static final String CLIENT_VERSION = "2.0.0";
    private final ScheduledExecutorService heartbeatExecutor = Executors.newSingleThreadScheduledExecutor();

    @Override
    public void onCreate() {
        super.onCreate();
        heartbeatExecutor.scheduleAtFixedRate(
                () -> sendEvent("heartbeat", payType, "0.00", "", "", 0),
                0,
                30,
                TimeUnit.SECONDS
        );
    }

    @Override
    public void onDestroy() {
        heartbeatExecutor.shutdownNow();
        super.onDestroy();
    }

    @Override
    public void onNotificationPosted(StatusBarNotification sbn) {
        if (sbn == null || sbn.getNotification() == null) return;

        String packageName = sbn.getPackageName();
        Notification notification = sbn.getNotification();
        Bundle extras = notification.extras;

        if (extras == null) return;

        String title = extras.getString(Notification.EXTRA_TITLE, "");
        String text = extras.getString(Notification.EXTRA_TEXT, "");

        Log.d(TAG, "收到通知 [" + packageName + "]: " + title + " - " + text);

        // 1. 微信支付通知监听
        if ("com.tencent.mm".equals(packageName)) {
            if (text.contains("微信支付") || text.contains("收款") || text.contains("入账")) {
                parseAndPush("wxpay", text, sbn);
            }
        }
        // 2. 支付宝通知监听
        else if ("com.eg.android.AlipayGphone".equals(packageName)) {
            if (text.contains("支付宝") || text.contains("成功收款") || text.contains("到账")) {
                parseAndPush("alipay", text, sbn);
            }
        }
        // QQ 钱包通知文案与可见性随客户端版本变化，正式上线前必须用目标版本实测。
        else if ("com.tencent.mobileqq".equals(packageName)) {
            if (text.contains("QQ钱包") || text.contains("收款") || text.contains("到账")) {
                parseAndPush("qqpay", text, sbn);
            }
        }
    }

    /**
     * 从通知文本中提取金额并发起 HTTP 上报
     */
    private void parseAndPush(String type, String text, StatusBarNotification sbn) {
        try {
            if (!type.equals(payType)) {
                return;
            }
            // 正则匹配提取金额数字 (例如 "成功收款 10.01 元")
            Pattern pattern = Pattern.compile("(\\d+\\.\\d{2}|\\d+)");
            Matcher matcher = pattern.matcher(text);

            if (matcher.find()) {
                String money = new BigDecimal(matcher.group(1)).setScale(2, RoundingMode.HALF_UP).toPlainString();
                Log.i(TAG, "成功提取到账金额: " + money + " 元, 渠道: " + type);

                // 异步上报至 CXPAY 后端
                String sourceBillId = sha256(sbn.getPackageName() + "|" + sbn.getKey() + "|" + sbn.getPostTime());
                long occurredAt = sbn.getPostTime() / 1000L;
                new Thread(() -> sendPushRequest(type, money, text, sourceBillId, occurredAt)).start();
            }
        } catch (Exception e) {
            Log.e(TAG, "解析金额异常: " + e.getMessage());
        }
    }

    /**
     * 发送网络请求上报数据给 CXPAY 后端
     */
    private void sendPushRequest(String appType, String money, String remark, String sourceBillId, long occurredAt) {
        sendEvent("bill", appType, money, remark, sourceBillId, occurredAt);
    }

    private void sendEvent(String event, String appType, String money, String remark, String sourceBillId, long occurredAt) {
        try {
            if (channelId <= 0 || deviceSecret.length() < 32 || !serverUrl.startsWith("https://")) {
                throw new IllegalStateException("必须配置有效通道ID、至少32位密钥并使用HTTPS服务地址");
            }
            URL url = new URL(serverUrl);
            HttpURLConnection conn = (HttpURLConnection) url.openConnection();
            conn.setRequestMethod("POST");
            conn.setRequestProperty("Content-Type", "application/json; charset=UTF-8");
            conn.setDoOutput(true);
            conn.setConnectTimeout(5000);

            long timestamp = System.currentTimeMillis() / 1000L;
            String nonce = UUID.randomUUID().toString().replace("-", "");
            JSONObject jsonParam = new JSONObject();
            jsonParam.put("version", "2");
            jsonParam.put("channel_id", channelId);
            jsonParam.put("device_id", deviceId);
            jsonParam.put("event", event);
            jsonParam.put("pay_type", appType);
            jsonParam.put("money", money);
            jsonParam.put("remark", remark);
            jsonParam.put("source_bill_id", sourceBillId);
            jsonParam.put("occurred_at", occurredAt);
            jsonParam.put("timestamp", timestamp);
            jsonParam.put("nonce", nonce);
            jsonParam.put("client_version", CLIENT_VERSION);

            String canonical = "2|" + channelId + "|" + deviceId + "|" + event + "|" + appType + "|" + money + "|"
                    + sourceBillId + "|" + occurredAt + "|" + timestamp + "|" + nonce + "|" + CLIENT_VERSION;
            jsonParam.put("sign", hmacSha256(canonical, deviceSecret));

            OutputStream os = conn.getOutputStream();
            os.write(jsonParam.toString().getBytes("UTF-8"));
            os.flush();
            os.close();

            int responseCode = conn.getResponseCode();
            Log.i(TAG, "CXPAY 上报响应码: " + responseCode);
        } catch (Exception e) {
            Log.e(TAG, "CXPAY HTTP 上报失败: " + e.getMessage());
        }
    }

    private String hmacSha256(String input, String secret) throws Exception {
        Mac mac = Mac.getInstance("HmacSHA256");
        mac.init(new SecretKeySpec(secret.getBytes(StandardCharsets.UTF_8), "HmacSHA256"));
        return toHex(mac.doFinal(input.getBytes(StandardCharsets.UTF_8)));
    }

    private String sha256(String input) {
        try {
            return toHex(MessageDigest.getInstance("SHA-256").digest(input.getBytes(StandardCharsets.UTF_8)));
        } catch (Exception e) {
            return "";
        }
    }

    private String toHex(byte[] bytes) {
        StringBuilder output = new StringBuilder(bytes.length * 2);
        for (byte value : bytes) {
            output.append(String.format("%02x", value & 0xff));
        }
        return output.toString();
    }
}
