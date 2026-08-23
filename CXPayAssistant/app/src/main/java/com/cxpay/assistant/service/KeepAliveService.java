package com.cxpay.assistant.service;

import android.app.Notification;
import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.app.Service;
import android.content.Context;
import android.content.Intent;
import android.content.SharedPreferences;
import android.os.Build;
import android.os.Handler;
import android.os.IBinder;
import android.os.Looper;
import android.util.Log;

import androidx.core.app.NotificationCompat;

import com.cxpay.assistant.protocol.AppasstSigner;

import org.json.JSONObject;

import java.util.HashMap;
import java.util.Map;
import java.util.UUID;

import okhttp3.Call;
import okhttp3.Callback;
import okhttp3.MediaType;
import okhttp3.OkHttpClient;
import okhttp3.Request;
import okhttp3.RequestBody;
import okhttp3.Response;

public class KeepAliveService extends Service {

    private static final String TAG = "CXPayKeepAlive";
    private static final String CHANNEL_ID = "cxpay_monitor_channel";
    private static final String CLIENT_VERSION = "1.2.0";
    private final Handler handler = new Handler(Looper.getMainLooper());
    private final OkHttpClient httpClient = new OkHttpClient();

    private final Runnable heartbeatRunnable = new Runnable() {
        @Override
        public void run() {
            sendHeartbeat();
            handler.postDelayed(this, 15000); // 15秒一次心跳保活
        }
    };

    @Override
    public void onCreate() {
        super.onCreate();
        createNotificationChannel();
        Notification notification = new NotificationCompat.Builder(this, CHANNEL_ID)
                .setContentTitle("CXPAY 手机监控助手")
                .setContentText("正在后台实时监听收款通知...")
                .setSmallIcon(android.R.drawable.stat_notify_sync)
                .setOngoing(true)
                .build();
        startForeground(1001, notification);
        handler.post(heartbeatRunnable);
    }

    private void sendHeartbeat() {
        SharedPreferences sp = getSharedPreferences("cxpay_config", Context.MODE_PRIVATE);
        String serverUrl = sp.getString("server_url", "https://cs.fcwan.cn");
        String deviceId = sp.getString("device_id", "AND_DEVICE_01");
        String notifySecret = sp.getString("notify_secret", "");

        if (serverUrl.isEmpty() || notifySecret.isEmpty()) {
            return;
        }

        Map<String, String> activeChannels = new HashMap<>();
        String wxId = sp.getString("wx_channel_id", sp.getString("channel_id", "1"));
        String aliId = sp.getString("ali_channel_id", "2");
        String qqId = sp.getString("qq_channel_id", "");

        if (wxId != null && !wxId.trim().isEmpty()) activeChannels.put("wxpay", wxId.trim());
        if (aliId != null && !aliId.trim().isEmpty()) activeChannels.put("alipay", aliId.trim());
        if (qqId != null && !qqId.trim().isEmpty()) activeChannels.put("qqpay", qqId.trim());

        for (Map.Entry<String, String> entry : activeChannels.entrySet()) {
            String payType = entry.getKey();
            String channelId = entry.getValue();

            long timestamp = System.currentTimeMillis() / 1000;
            String nonce = UUID.randomUUID().toString().replace("-", "");

            String canonicalStr = AppasstSigner.canonicalize(
                    AppasstSigner.VERSION,
                    channelId,
                    deviceId,
                    "heartbeat",
                    payType,
                    "0.00",
                    "",
                    0,
                    timestamp,
                    nonce,
                    CLIENT_VERSION
            );

            String sign = AppasstSigner.sign(canonicalStr, notifySecret);

            Map<String, Object> params = new HashMap<>();
            params.put("version", AppasstSigner.VERSION);
            params.put("channel_id", Integer.parseInt(channelId));
            params.put("device_id", deviceId);
            params.put("event", "heartbeat");
            params.put("pay_type", payType);
            params.put("money", "0.00");
            params.put("source_bill_id", "");
            params.put("occurred_at", 0);
            params.put("timestamp", timestamp);
            params.put("nonce", nonce);
            params.put("client_version", CLIENT_VERSION);
            params.put("sign", sign);

            String endpoint = serverUrl.replaceAll("/+$", "") + "/api/appasst/push";
            RequestBody body = RequestBody.create(
                    MediaType.parse("application/json; charset=utf-8"),
                    new JSONObject(params).toString()
            );

            Request request = new Request.Builder()
                    .url(endpoint)
                    .post(body)
                    .addHeader("User-Agent", "CXPayAssistant/" + CLIENT_VERSION + " (Heartbeat)")
                    .build();

            httpClient.newCall(request).enqueue(new Callback() {
                @Override
                public void onFailure(Call call, java.io.IOException e) {
                    Log.w(TAG, "心跳发送失败: " + e.getMessage());
                }

                @Override
                public void onResponse(Call call, Response response) {
                    response.close();
                }
            });
        }
    }


    private void createNotificationChannel() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            NotificationChannel channel = new NotificationChannel(
                    CHANNEL_ID,
                    "CXPAY 监控保活服务",
                    NotificationManager.IMPORTANCE_LOW
            );
            NotificationManager manager = getSystemService(NotificationManager.class);
            if (manager != null) {
                manager.createNotificationChannel(channel);
            }
        }
    }

    @Override
    public int onStartCommand(Intent intent, int flags, int startId) {
        return START_STICKY;
    }

    @Override
    public void onDestroy() {
        handler.removeCallbacks(heartbeatRunnable);
        super.onDestroy();
    }

    @Override
    public IBinder onBind(Intent intent) {
        return null;
    }
}
