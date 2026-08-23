package com.cxpay.assistant.service;

import android.app.Notification;
import android.content.Context;
import android.content.Intent;
import android.content.SharedPreferences;
import android.os.Bundle;
import android.service.notification.NotificationListenerService;
import android.service.notification.StatusBarNotification;
import android.util.Log;

import com.cxpay.assistant.MainActivity;
import com.cxpay.assistant.protocol.AppasstSigner;

import org.json.JSONObject;

import java.io.IOException;
import java.util.HashMap;
import java.util.Map;
import java.util.UUID;
import java.util.regex.Matcher;
import java.util.regex.Pattern;

import okhttp3.Call;
import okhttp3.Callback;
import okhttp3.MediaType;
import okhttp3.OkHttpClient;
import okhttp3.Request;
import okhttp3.RequestBody;
import okhttp3.Response;

public class NotificationMonitorService extends NotificationListenerService {

    private static final String TAG = "CXPayMonitorService";
    private static final String CLIENT_VERSION = "1.2.0";
    private final OkHttpClient httpClient = new OkHttpClient();

    private static final Pattern WX_PATTERN = Pattern.compile("(?:微信支付|收款|赞赏|转账|店员).*?(?:收款|到账|收到|赞赏|转账)?\\s*([0-9]+(?:\\.[0-9]{1,2})?)\\s*元");
    private static final Pattern ALI_PATTERN = Pattern.compile("(?:成功收款|收到一笔转账|收款到账|通过扫码向你付款|向你付款)\\s*([0-9]+(?:\\.[0-9]{1,2})?)\\s*元");
    private static final Pattern QQ_PATTERN = Pattern.compile("(?:QQ钱包收款|收到QQ钱包转账|QQ转账到账)\\s*([0-9]+(?:\\.[0-9]{1,2})?)\\s*元");

    @Override
    public void onNotificationPosted(StatusBarNotification sbn) {
        if (sbn == null || sbn.getNotification() == null) {
            return;
        }

        String pkg = sbn.getPackageName();
        Bundle extras = sbn.getNotification().extras;
        if (extras == null) {
            return;
        }

        String title = extras.getString(Notification.EXTRA_TITLE, "");
        CharSequence textChar = extras.getCharSequence(Notification.EXTRA_TEXT);
        String text = textChar != null ? textChar.toString() : "";
        String fullContent = title + " " + text;

        Log.d(TAG, "收到通知: [" + pkg + "] " + fullContent);

        String payType = null;
        String money = null;

        if ("com.tencent.mm".equals(pkg)) {
            Matcher m = WX_PATTERN.matcher(fullContent);
            if (m.find()) {
                payType = "wxpay";
                money = m.group(1);
            }
        } else if ("com.eg.android.AlipayGphone".equals(pkg)) {
            Matcher m = ALI_PATTERN.matcher(fullContent);
            if (m.find()) {
                payType = "alipay";
                money = m.group(1);
            }
        } else if ("com.tencent.mobileqq".equals(pkg)) {
            Matcher m = QQ_PATTERN.matcher(fullContent);
            if (m.find()) {
                payType = "qqpay";
                money = m.group(1);
            }
        }

        if (payType != null && money != null) {
            Log.i(TAG, "捕获到账: 类型=" + payType + ", 金额=" + money);
            reportBill(payType, money, fullContent);
        }
    }

    private void reportBill(String payType, String money, String rawText) {
        SharedPreferences sp = getSharedPreferences("cxpay_config", Context.MODE_PRIVATE);
        String serverUrl = sp.getString("server_url", "https://cs.fcwan.cn");
        String channelId = "alipay".equals(payType)
                ? sp.getString("ali_channel_id", "2")
                : ("qqpay".equals(payType)
                ? sp.getString("qq_channel_id", "3")
                : sp.getString("wx_channel_id", sp.getString("channel_id", "1")));
        String deviceId = sp.getString("device_id", "AND_DEVICE_01");
        String notifySecret = sp.getString("notify_secret", "");

        if (serverUrl.isEmpty() || notifySecret.isEmpty() || channelId == null || channelId.trim().isEmpty()) {
            Log.w(TAG, "未配置服务器地址、账单上报密钥或对应通道ID，跳过上报");
            return;
        }


        long timestamp = System.currentTimeMillis() / 1000;
        long occurredAt = timestamp;
        String nonce = UUID.randomUUID().toString().replace("-", "");
        String sourceBillId = "AND_" + timestamp + "_" + UUID.randomUUID().toString().substring(0, 8);

        String canonicalStr = AppasstSigner.canonicalize(
                AppasstSigner.VERSION,
                channelId,
                deviceId,
                "bill",
                payType,
                money,
                sourceBillId,
                occurredAt,
                timestamp,
                nonce,
                CLIENT_VERSION
        );

        String sign = AppasstSigner.sign(canonicalStr, notifySecret);

        Map<String, Object> params = new HashMap<>();
        params.put("version", AppasstSigner.VERSION);
        params.put("channel_id", Integer.parseInt(channelId));
        params.put("device_id", deviceId);
        params.put("event", "bill");
        params.put("pay_type", payType);
        params.put("money", money);
        params.put("source_bill_id", sourceBillId);
        params.put("occurred_at", occurredAt);
        params.put("timestamp", timestamp);
        params.put("nonce", nonce);
        params.put("client_version", CLIENT_VERSION);
        params.put("remark", rawText.length() > 200 ? rawText.substring(0, 200) : rawText);
        params.put("sign", sign);

        String endpoint = serverUrl.replaceAll("/+$", "") + "/api/appasst/push";
        JSONObject jsonBody = new JSONObject(params);

        RequestBody body = RequestBody.create(
                MediaType.parse("application/json; charset=utf-8"),
                jsonBody.toString()
        );

        Request request = new Request.Builder()
                .url(endpoint)
                .post(body)
                .addHeader("User-Agent", "CXPayAssistant/" + CLIENT_VERSION + " (Android)")
                .build();

        String payName = "wxpay".equalsIgnoreCase(payType) ? "微信" : ("alipay".equalsIgnoreCase(payType) ? "支付宝" : "QQ钱包");

        Intent logIntent = new Intent(MainActivity.ACTION_LOG);
        logIntent.setPackage(getPackageName());
        logIntent.putExtra(MainActivity.EXTRA_LOG_TEXT, "[" + payName + "] 捕获到账 ¥" + money + " ➔ 正在推送到 CXPAY (通道 " + channelId + ")...");
        logIntent.putExtra(MainActivity.EXTRA_LOG_PAY_TYPE, payType);
        logIntent.putExtra(MainActivity.EXTRA_LOG_CHANNEL_ID, channelId);
        try {
            double amt = Double.parseDouble(money);
            logIntent.putExtra(MainActivity.EXTRA_LOG_AMOUNT, amt);
        } catch (Exception ignored) {}
        sendBroadcast(logIntent);

        httpClient.newCall(request).enqueue(new Callback() {
            @Override
            public void onFailure(Call call, IOException e) {
                Log.e(TAG, "上报账单请求失败: " + e.getMessage());
                Intent errIntent = new Intent(MainActivity.ACTION_LOG);
                errIntent.setPackage(getPackageName());
                errIntent.putExtra(MainActivity.EXTRA_LOG_TEXT, "❌ [" + payName + "] 上报失败: " + e.getMessage());
                sendBroadcast(errIntent);
            }

            @Override
            public void onResponse(Call call, Response response) throws IOException {
                String respStr = response.body() != null ? response.body().string() : "";
                Log.i(TAG, "上报账单成功返回: " + respStr);
                Intent respIntent = new Intent(MainActivity.ACTION_LOG);
                respIntent.setPackage(getPackageName());
                respIntent.putExtra(MainActivity.EXTRA_LOG_TEXT, "✅ [" + payName + "] 上报完成: " + respStr);
                sendBroadcast(respIntent);
                response.close();
            }
        });
    }
}
