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
import android.app.AlarmManager;
import android.app.PendingIntent;
import android.os.PowerManager;
import android.os.SystemClock;
import okhttp3.Request;
import okhttp3.RequestBody;
import okhttp3.Response;

import java.util.concurrent.TimeUnit;

public class KeepAliveService extends Service {

    private static final String TAG = "CXPayKeepAlive";
    private static final String CHANNEL_ID = "cxpay_monitor_channel";
    private static final String CLIENT_VERSION = "1.3.2";
    public static final String ACTION_ALARM_HEARTBEAT = "com.cxpay.assistant.ACTION_ALARM_HEARTBEAT";
    private static final long HEARTBEAT_INTERVAL_MS = 15000; // 15秒一次心跳保活

    private final Handler handler = new Handler(Looper.getMainLooper());
    private final OkHttpClient httpClient = new OkHttpClient.Builder()
            .connectTimeout(10, TimeUnit.SECONDS)
            .readTimeout(10, TimeUnit.SECONDS)
            .writeTimeout(10, TimeUnit.SECONDS)
            .build();

    private PowerManager.WakeLock wakeLock;
    private AlarmManager alarmManager;
    private PendingIntent alarmPendingIntent;

    private final Runnable heartbeatRunnable = new Runnable() {
        @Override
        public void run() {
            sendHeartbeat();
            scheduleNextAlarm();
            handler.postDelayed(this, HEARTBEAT_INTERVAL_MS);
        }
    };

    @Override
    public void onCreate() {
        super.onCreate();
        createNotificationChannel();
        acquireWakeLock();
        setupAlarmManager();

        Notification notification = new NotificationCompat.Builder(this, CHANNEL_ID)
                .setContentTitle("CXPAY 手机监控助手")
                .setContentText("正在后台防休眠实时监听收款通知...")
                .setSmallIcon(android.R.drawable.stat_notify_sync)
                .setOngoing(true)
                .setPriority(NotificationCompat.PRIORITY_HIGH)
                .build();
        startForeground(1001, notification);
        handler.post(heartbeatRunnable);
    }

    private void acquireWakeLock() {
        try {
            if (wakeLock == null) {
                PowerManager pm = (PowerManager) getSystemService(Context.POWER_SERVICE);
                if (pm != null) {
                    wakeLock = pm.newWakeLock(
                            PowerManager.PARTIAL_WAKE_LOCK,
                            "CXPayAssistant:KeepAliveWakeLock"
                    );
                    wakeLock.setReferenceCounted(false);
                }
            }
            if (wakeLock != null && !wakeLock.isHeld()) {
                wakeLock.acquire();
                Log.d(TAG, "已成功获取 PARTIAL_WAKE_LOCK，息屏 CPU 保持微功耗运行！");
            }
        } catch (Exception e) {
            Log.e(TAG, "获取 WakeLock 异常: " + e.getMessage());
        }
    }

    private void releaseWakeLock() {
        try {
            if (wakeLock != null && wakeLock.isHeld()) {
                wakeLock.release();
                Log.d(TAG, "已释放 WakeLock");
            }
        } catch (Exception e) {
            Log.e(TAG, "释放 WakeLock 异常: " + e.getMessage());
        }
    }

    private void setupAlarmManager() {
        try {
            alarmManager = (AlarmManager) getSystemService(Context.ALARM_SERVICE);
            Intent intent = new Intent(this, KeepAliveService.class);
            intent.setAction(ACTION_ALARM_HEARTBEAT);
            int flags = PendingIntent.FLAG_UPDATE_CURRENT;
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) {
                flags |= PendingIntent.FLAG_IMMUTABLE;
            }
            alarmPendingIntent = PendingIntent.getService(this, 1002, intent, flags);
        } catch (Exception e) {
            Log.e(TAG, "初始化 AlarmManager 异常: " + e.getMessage());
        }
    }

    private void scheduleNextAlarm() {
        if (alarmManager == null || alarmPendingIntent == null) return;
        try {
            long triggerAt = SystemClock.elapsedRealtime() + HEARTBEAT_INTERVAL_MS;
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) {
                alarmManager.setExactAndAllowWhileIdle(
                        AlarmManager.ELAPSED_REALTIME_WAKEUP,
                        triggerAt,
                        alarmPendingIntent
                );
            } else if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.KITKAT) {
                alarmManager.setExact(
                        AlarmManager.ELAPSED_REALTIME_WAKEUP,
                        triggerAt,
                        alarmPendingIntent
                );
            } else {
                alarmManager.set(
                        AlarmManager.ELAPSED_REALTIME_WAKEUP,
                        triggerAt,
                        alarmPendingIntent
                );
            }
        } catch (Exception e) {
            Log.w(TAG, "调度精确闹钟失败 (可能缺少精确闹钟权限): " + e.getMessage());
        }
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
        acquireWakeLock();
        if (intent != null && ACTION_ALARM_HEARTBEAT.equals(intent.getAction())) {
            Log.d(TAG, "⏰ 收到系统精确定时心跳闹钟广播，立即发送心跳并调度下一次！");
            sendHeartbeat();
            scheduleNextAlarm();
        }
        return START_STICKY;
    }

    @Override
    public void onDestroy() {
        handler.removeCallbacks(heartbeatRunnable);
        try {
            if (alarmManager != null && alarmPendingIntent != null) {
                alarmManager.cancel(alarmPendingIntent);
            }
        } catch (Exception ignored) {}
        releaseWakeLock();
        super.onDestroy();
    }

    @Override
    public IBinder onBind(Intent intent) {
        return null;
    }
}
