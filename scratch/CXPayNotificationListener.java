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
import java.util.regex.Matcher;
import java.util.regex.Pattern;

/**
 * CXPAY 商业版安卓挂机助手核心服务 - 监听微信/支付宝通知栏推送并加密上报
 */
public class CXPayNotificationListener extends NotificationListenerService {

    private static final String TAG = "CXPayListener";

    // 请配置您的 CXPAY 后端接收地址
    private String serverUrl = "http://127.0.0.1/api/appasst/push";
    private String deviceSecret = "CX_SECRET_KEY_8888";

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
                parseAndPush("wxpay", text);
            }
        }
        // 2. 支付宝通知监听
        else if ("com.eg.android.AlipayGphone".equals(packageName)) {
            if (text.contains("支付宝") || text.contains("成功收款") || text.contains("到账")) {
                parseAndPush("alipay", text);
            }
        }
    }

    /**
     * 从通知文本中提取金额并发起 HTTP 上报
     */
    private void parseAndPush(String type, String text) {
        try {
            // 正则匹配提取金额数字 (例如 "成功收款 10.01 元")
            Pattern pattern = Pattern.compile("(\\d+\\.\\d{2}|\\d+)");
            Matcher matcher = pattern.matcher(text);

            if (matcher.find()) {
                String money = matcher.group(1);
                Log.i(TAG, "成功提取到账金额: " + money + " 元, 渠道: " + type);

                // 异步上报至 CXPAY 后端
                new Thread(() -> sendPushRequest(type, money, text)).start();
            }
        } catch (Exception e) {
            Log.e(TAG, "解析金额异常: " + e.getMessage());
        }
    }

    /**
     * 发送网络请求上报数据给 CXPAY 后端
     */
    private void sendPushRequest(String appType, String money, String remark) {
        try {
            URL url = new URL(serverUrl);
            HttpURLConnection conn = (HttpURLConnection) url.openConnection();
            conn.setRequestMethod("POST");
            conn.setRequestProperty("Content-Type", "application/json; charset=UTF-8");
            conn.setDoOutput(true);
            conn.setConnectTimeout(5000);

            JSONObject jsonParam = new JSONObject();
            jsonParam.put("device_id", "ANDROID_" + android.os.Build.MODEL);
            jsonParam.put("app", appType);
            jsonParam.put("money", money);
            jsonParam.put("remark", remark);
            jsonParam.put("sign", md5(appType + money + deviceSecret));

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

    private String md5(String input) {
        try {
            java.security.MessageDigest md = java.security.MessageDigest.getInstance("MD5");
            byte[] array = md.digest(input.getBytes("UTF-8"));
            StringBuilder sb = new StringBuilder();
            for (byte b : array) {
                sb.append(Integer.toHexString((b & 0xFF) | 0x100).substring(1, 3));
            }
            return sb.toString();
        } catch (Exception e) {
            return "";
        }
    }
}
