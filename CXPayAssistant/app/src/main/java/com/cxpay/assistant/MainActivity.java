package com.cxpay.assistant;

import android.app.Dialog;
import android.content.BroadcastReceiver;
import android.content.ComponentName;
import android.content.Context;
import android.content.Intent;
import android.content.IntentFilter;
import android.content.SharedPreferences;
import android.graphics.Color;
import android.graphics.drawable.ColorDrawable;
import android.net.Uri;
import android.os.Build;
import android.os.Bundle;
import android.os.Environment;
import android.os.Handler;
import android.os.Looper;
import android.os.PowerManager;
import android.provider.Settings;
import android.text.Editable;
import android.text.TextUtils;
import android.text.TextWatcher;
import android.view.View;
import android.view.Window;
import android.widget.Button;
import android.widget.EditText;
import android.widget.LinearLayout;
import android.widget.ProgressBar;
import android.widget.ScrollView;
import android.widget.TextView;
import android.widget.Toast;

import androidx.activity.result.ActivityResultLauncher;
import androidx.appcompat.app.AppCompatActivity;
import androidx.appcompat.widget.SwitchCompat;
import androidx.core.content.FileProvider;

import com.cxpay.assistant.service.KeepAliveService;
import com.journeyapps.barcodescanner.ScanContract;
import com.journeyapps.barcodescanner.ScanOptions;

import org.json.JSONObject;

import java.io.File;
import java.io.FileOutputStream;
import java.io.InputStream;
import java.text.SimpleDateFormat;
import java.util.Date;
import java.util.Locale;

import okhttp3.Call;
import okhttp3.Callback;
import okhttp3.OkHttpClient;
import okhttp3.Request;
import okhttp3.Response;

public class MainActivity extends AppCompatActivity {

    public static final String ACTION_LOG = "com.cxpay.assistant.ACTION_LOG";
    public static final String EXTRA_LOG_TEXT = "log_text";
    public static final String EXTRA_LOG_AMOUNT = "log_amount";
    public static final String EXTRA_LOG_PAY_TYPE = "log_pay_type";
    public static final String EXTRA_LOG_CHANNEL_ID = "log_channel_id";

    public static final String CURRENT_VERSION = "1.3.1";
    public static final int CURRENT_VERSION_CODE = 131;

    private TextView textStatusBadge, textDeviceBadge, textStatCount, textStatAmount;
    private TextView textStatWxTitle, textStatWxCount, textStatWxAmount;
    private TextView textStatAliTitle, textStatAliCount, textStatAliAmount;
    private TextView btnKeepAliveGuide, btnScanQr, btnCheckUpdate, btnResetStats;

    private EditText editWxChannelId, editAliChannelId, editServerUrl, editDeviceId, editNotifySecret;
    private SwitchCompat switchWx, switchAli;
    private TextView textWxStatus, textAliStatus, textPermStatus, btnGrantPerm, btnClearLogs, textLogTerminal;
    private ScrollView scrollLog;
    private Button btnSave, btnToggleService;

    private boolean isServiceRunning = false;
    private int todayCount = 0;
    private double todayAmount = 0.00;

    private int todayWxCount = 0;
    private double todayWxAmount = 0.00;

    private int todayAliCount = 0;
    private double todayAliAmount = 0.00;

    private final OkHttpClient httpClient = new OkHttpClient();
    private final SimpleDateFormat timeFormat = new SimpleDateFormat("HH:mm:ss", Locale.getDefault());
    private final Handler mainHandler = new Handler(Looper.getMainLooper());

    // 扫一扫二维码识别启动器
    private final ActivityResultLauncher<ScanOptions> barcodeLauncher = registerForActivityResult(
            new ScanContract(),
            result -> {
                if (result.getContents() != null) {
                    handleScanResult(result.getContents());
                }
            }
    );

    private final BroadcastReceiver logReceiver = new BroadcastReceiver() {
        @Override
        public void onReceive(Context context, Intent intent) {
            if (ACTION_LOG.equals(intent.getAction())) {
                String msg = intent.getStringExtra(EXTRA_LOG_TEXT);
                double amount = intent.getDoubleExtra(EXTRA_LOG_AMOUNT, 0.0);
                String payType = intent.getStringExtra(EXTRA_LOG_PAY_TYPE);
                String chId = intent.getStringExtra(EXTRA_LOG_CHANNEL_ID);

                if (msg != null) {
                    appendLog(msg);
                }
                if (amount > 0) {
                    todayCount++;
                    todayAmount += amount;

                    if ("wxpay".equalsIgnoreCase(payType)) {
                        todayWxCount++;
                        todayWxAmount += amount;
                    } else if ("alipay".equalsIgnoreCase(payType)) {
                        todayAliCount++;
                        todayAliAmount += amount;
                    }

                    updateStatsDisplay();
                    saveStats();
                }
            }
        }
    };

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.LOLLIPOP) {
            getWindow().setStatusBarColor(0xFF0B0F19);
        }
        
        setContentView(R.layout.activity_main);

        // 绑定视图
        textStatusBadge = findViewById(R.id.text_status_badge);
        textDeviceBadge = findViewById(R.id.text_device_badge);
        btnKeepAliveGuide = findViewById(R.id.btn_keep_alive_guide);
        btnScanQr = findViewById(R.id.btn_scan_qr);
        btnCheckUpdate = findViewById(R.id.btn_check_update);
        btnResetStats = findViewById(R.id.btn_reset_stats);

        textStatCount = findViewById(R.id.text_stat_count);
        textStatAmount = findViewById(R.id.text_stat_amount);
        textStatWxTitle = findViewById(R.id.text_stat_wx_title);
        textStatWxCount = findViewById(R.id.text_stat_wx_count);
        textStatWxAmount = findViewById(R.id.text_stat_wx_amount);
        textStatAliTitle = findViewById(R.id.text_stat_ali_title);
        textStatAliCount = findViewById(R.id.text_stat_ali_count);
        textStatAliAmount = findViewById(R.id.text_stat_ali_amount);

        editWxChannelId = findViewById(R.id.edit_wx_channel_id);
        editAliChannelId = findViewById(R.id.edit_ali_channel_id);
        switchWx = findViewById(R.id.switch_wx);
        switchAli = findViewById(R.id.switch_ali);
        textWxStatus = findViewById(R.id.text_wx_status);
        textAliStatus = findViewById(R.id.text_ali_status);

        editServerUrl = findViewById(R.id.edit_server_url);
        editDeviceId = findViewById(R.id.edit_device_id);
        editNotifySecret = findViewById(R.id.edit_notify_secret);
        textPermStatus = findViewById(R.id.text_perm_status);
        btnGrantPerm = findViewById(R.id.btn_grant_perm);

        textLogTerminal = findViewById(R.id.text_log_terminal);
        scrollLog = findViewById(R.id.scroll_log);
        btnClearLogs = findViewById(R.id.btn_clear_logs);

        btnSave = findViewById(R.id.btn_save);
        btnToggleService = findViewById(R.id.btn_toggle_service);

        loadConfig();
        loadStats();

        btnSave.setOnClickListener(v -> saveConfig());
        btnGrantPerm.setOnClickListener(v -> requestNotificationPermission());
        btnToggleService.setOnClickListener(v -> toggleService());
        btnClearLogs.setOnClickListener(v -> textLogTerminal.setText(""));

        // 绑定按钮事件
        btnScanQr.setOnClickListener(v -> startQrScan());
        btnKeepAliveGuide.setOnClickListener(v -> showKeepAliveGuideDialog());
        btnCheckUpdate.setOnClickListener(v -> checkAppUpdate(true));
        btnResetStats.setOnClickListener(v -> resetStats());

        // 监听通道 ID 实时更新卡片标题
        TextWatcher channelIdWatcher = new TextWatcher() {
            @Override public void beforeTextChanged(CharSequence s, int start, int count, int after) {}
            @Override public void onTextChanged(CharSequence s, int start, int count, int after) {}
            @Override public void afterTextChanged(Editable s) { updateChannelTitles(); }
        };
        editWxChannelId.addTextChangedListener(channelIdWatcher);
        editAliChannelId.addTextChangedListener(channelIdWatcher);

        switchWx.setOnCheckedChangeListener((buttonView, isChecked) -> {
            textWxStatus.setText(isChecked ? "正常监听" : "已暂停");
            textWxStatus.setTextColor(isChecked ? 0xFF07C160 : 0xFF64748B);
        });

        switchAli.setOnCheckedChangeListener((buttonView, isChecked) -> {
            textAliStatus.setText(isChecked ? "正常监听" : "已暂停");
            textAliStatus.setTextColor(isChecked ? 0xFF1677FF : 0xFF64748B);
        });

        // 注册广播接收器
        IntentFilter filter = new IntentFilter(ACTION_LOG);
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            registerReceiver(logReceiver, filter, Context.RECEIVER_NOT_EXPORTED);
        } else {
            registerReceiver(logReceiver, filter);
        }

        appendLog("[系统] CXPAY 手机监控助手 v" + CURRENT_VERSION + " 初始化完毕！");

        // 启动时后台静默检查一次新版本
        mainHandler.postDelayed(() -> checkAppUpdate(false), 2000);
    }

    @Override
    protected void onResume() {
        super.onResume();
        checkPermission();
        updateChannelTitles();
    }

    @Override
    protected void onDestroy() {
        super.onDestroy();
        try {
            unregisterReceiver(logReceiver);
        } catch (Exception ignored) {}
    }

    private void updateChannelTitles() {
        String wxId = editWxChannelId.getText().toString().trim();
        String aliId = editAliChannelId.getText().toString().trim();
        textStatWxTitle.setText("🟢 微信 (#" + (wxId.isEmpty() ? "--" : wxId) + ")");
        textStatAliTitle.setText("🔵 支付宝 (#" + (aliId.isEmpty() ? "--" : aliId) + ")");
    }

    private void startQrScan() {
        ScanOptions options = new ScanOptions();
        options.setPrompt("请将取景框对准商户后台的【手机助手配对二维码】");
        options.setBeepEnabled(true);
        options.setOrientationLocked(true);
        options.setBarcodeImageEnabled(false);
        barcodeLauncher.launch(options);
    }

    private void handleScanResult(String raw) {
        if (TextUtils.isEmpty(raw)) return;
        try {
            JSONObject json = new JSONObject(raw);
            if (json.optBoolean("cxpay_config", false) || json.has("server_url") || json.has("notify_secret")) {
                String serverUrl = json.optString("server_url", editServerUrl.getText().toString().trim());
                String wxId = json.optString("wx_channel_id", "");
                String aliId = json.optString("ali_channel_id", "");
                String devId = json.optString("device_id", "AND_MCH_1");
                String secret = json.optString("notify_secret", "");

                if (!TextUtils.isEmpty(serverUrl)) editServerUrl.setText(serverUrl);
                if (!TextUtils.isEmpty(wxId)) {
                    editWxChannelId.setText(wxId);
                    switchWx.setChecked(true);
                }
                if (!TextUtils.isEmpty(aliId)) {
                    editAliChannelId.setText(aliId);
                    switchAli.setChecked(true);
                }
                if (!TextUtils.isEmpty(devId)) {
                    editDeviceId.setText(devId);
                    textDeviceBadge.setText("设备: " + devId);
                }
                if (!TextUtils.isEmpty(secret)) editNotifySecret.setText(secret);

                saveConfig();
                updateChannelTitles();

                appendLog("[扫码绑定] ✅ 成功识别商户专属配置！已自动绑定通道并保存！");
                Toast.makeText(this, "🎉 扫码配对成功！所有参数已自动保存生效！", Toast.LENGTH_LONG).show();

                if (!isServiceRunning) {
                    toggleService();
                }
            } else {
                appendLog("[扫码异常] 识别到非 CXPAY 专用配对码: " + raw);
                Toast.makeText(this, "非 CXPAY 手机助手专用配对码", Toast.LENGTH_SHORT).show();
            }
        } catch (Exception e) {
            appendLog("[扫码异常] 解析二维码配置失败: " + e.getMessage());
            Toast.makeText(this, "二维码内容解析失败: " + e.getMessage(), Toast.LENGTH_SHORT).show();
        }
    }

    private void loadConfig() {
        SharedPreferences sp = getSharedPreferences("cxpay_config", Context.MODE_PRIVATE);
        editServerUrl.setText(sp.getString("server_url", "https://cs.fcwan.cn"));
        editWxChannelId.setText(sp.getString("wx_channel_id", "37"));
        editAliChannelId.setText(sp.getString("ali_channel_id", "39"));
        String devId = sp.getString("device_id", "AND_MCH_1");
        editDeviceId.setText(devId);
        textDeviceBadge.setText("设备: " + devId);
        editNotifySecret.setText(sp.getString("notify_secret", ""));
        
        switchWx.setChecked(sp.getBoolean("wx_enabled", true));
        switchAli.setChecked(sp.getBoolean("ali_enabled", true));
        updateChannelTitles();
    }

    private void saveConfig() {
        String devId = editDeviceId.getText().toString().trim();
        if (devId.isEmpty()) devId = "AND_MCH_1";
        textDeviceBadge.setText("设备: " + devId);

        SharedPreferences sp = getSharedPreferences("cxpay_config", Context.MODE_PRIVATE);
        sp.edit()
                .putString("server_url", editServerUrl.getText().toString().trim())
                .putString("wx_channel_id", editWxChannelId.getText().toString().trim())
                .putString("ali_channel_id", editAliChannelId.getText().toString().trim())
                .putString("channel_id", editWxChannelId.getText().toString().trim())
                .putString("device_id", devId)
                .putString("notify_secret", editNotifySecret.getText().toString().trim())
                .putBoolean("wx_enabled", switchWx.isChecked())
                .putBoolean("ali_enabled", switchAli.isChecked())
                .apply();

        updateChannelTitles();
        appendLog("[配置] 所有参数已成功保存并立即生效！");
        Toast.makeText(this, "配置保存成功！", Toast.LENGTH_SHORT).show();
    }

    private void loadStats() {
        SharedPreferences sp = getSharedPreferences("cxpay_stats", Context.MODE_PRIVATE);
        String today = new SimpleDateFormat("yyyyMMdd", Locale.getDefault()).format(new Date());
        String lastDate = sp.getString("last_date", "");
        if (today.equals(lastDate)) {
            todayCount = sp.getInt("today_count", 0);
            todayAmount = Double.longBitsToDouble(sp.getLong("today_amount", Double.doubleToLongBits(0.0)));
            todayWxCount = sp.getInt("today_wx_count", 0);
            todayWxAmount = Double.longBitsToDouble(sp.getLong("today_wx_amount", Double.doubleToLongBits(0.0)));
            todayAliCount = sp.getInt("today_ali_count", 0);
            todayAliAmount = Double.longBitsToDouble(sp.getLong("today_ali_amount", Double.doubleToLongBits(0.0)));
        } else {
            todayCount = 0;
            todayAmount = 0.0;
            todayWxCount = 0;
            todayWxAmount = 0.0;
            todayAliCount = 0;
            todayAliAmount = 0.0;
        }
        updateStatsDisplay();
    }

    private void saveStats() {
        SharedPreferences sp = getSharedPreferences("cxpay_stats", Context.MODE_PRIVATE);
        String today = new SimpleDateFormat("yyyyMMdd", Locale.getDefault()).format(new Date());
        sp.edit()
                .putString("last_date", today)
                .putInt("today_count", todayCount)
                .putLong("today_amount", Double.doubleToRawLongBits(todayAmount))
                .putInt("today_wx_count", todayWxCount)
                .putLong("today_wx_amount", Double.doubleToRawLongBits(todayWxAmount))
                .putInt("today_ali_count", todayAliCount)
                .putLong("today_ali_amount", Double.doubleToRawLongBits(todayAliAmount))
                .apply();
    }

    private void resetStats() {
        todayCount = 0;
        todayAmount = 0.0;
        todayWxCount = 0;
        todayWxAmount = 0.0;
        todayAliCount = 0;
        todayAliAmount = 0.0;
        saveStats();
        updateStatsDisplay();
        Toast.makeText(this, "今日核销统计已全部清零！", Toast.LENGTH_SHORT).show();
        appendLog("[统计] 用户手动清零了今日所有统计数据。");
    }

    private void updateStatsDisplay() {
        textStatCount.setText(todayCount + " 笔");
        textStatAmount.setText(String.format(Locale.getDefault(), "¥ %.2f", todayAmount));

        textStatWxCount.setText(todayWxCount + " 笔");
        textStatWxAmount.setText(String.format(Locale.getDefault(), "¥ %.2f", todayWxAmount));

        textStatAliCount.setText(todayAliCount + " 笔");
        textStatAliAmount.setText(String.format(Locale.getDefault(), "¥ %.2f", todayAliAmount));
    }

    public void appendLog(String log) {
        String timestamp = timeFormat.format(new Date());
        String line = "[" + timestamp + "] " + log + "\n";
        mainHandler.post(() -> {
            textLogTerminal.append(line);
            scrollLog.post(() -> scrollLog.fullScroll(ScrollView.FOCUS_DOWN));
        });
    }

    private void checkPermission() {
        boolean enabled = isNotificationListenerEnabled();
        if (enabled) {
            textPermStatus.setText("通知使用权: ✅ 已开启授权");
            textPermStatus.setTextColor(0xFF10B981);
            btnGrantPerm.setText("已授权");
        } else {
            textPermStatus.setText("通知使用权: ❌ 未授予 (无法监听)");
            textPermStatus.setTextColor(0xFFEF4444);
            btnGrantPerm.setText("去授权");
        }
    }

    private boolean isNotificationListenerEnabled() {
        String pkgName = getPackageName();
        final String flat = Settings.Secure.getString(getContentResolver(), "enabled_notification_listeners");
        if (!TextUtils.isEmpty(flat)) {
            final String[] names = flat.split(":");
            for (String name : names) {
                final ComponentName cn = ComponentName.unflattenFromString(name);
                if (cn != null && TextUtils.equals(pkgName, cn.getPackageName())) {
                    return true;
                }
            }
        }
        return false;
    }

    private void requestNotificationPermission() {
        startActivity(new Intent(Settings.ACTION_NOTIFICATION_LISTENER_SETTINGS));
    }

    // 弹窗：系统后台保活与防掉线指引
    private void showKeepAliveGuideDialog() {
        Dialog dialog = new Dialog(this);
        dialog.requestWindowFeature(Window.FEATURE_NO_TITLE);
        dialog.setContentView(R.layout.dialog_keep_alive_guide);

        if (dialog.getWindow() != null) {
            dialog.getWindow().setBackgroundDrawable(new ColorDrawable(Color.TRANSPARENT));
            dialog.getWindow().setLayout(
                    (int) (getResources().getDisplayMetrics().widthPixels * 0.92),
                    (int) (getResources().getDisplayMetrics().heightPixels * 0.85)
            );
        }

        TextView btnClose = dialog.findViewById(R.id.btn_close_guide);
        TextView btnBattery = dialog.findViewById(R.id.btn_action_battery);
        TextView btnAutoStart = dialog.findViewById(R.id.btn_action_autostart);
        TextView btnNotification = dialog.findViewById(R.id.btn_action_notification);
        Button btnDone = dialog.findViewById(R.id.btn_guide_done);

        btnClose.setOnClickListener(v -> dialog.dismiss());
        btnDone.setOnClickListener(v -> dialog.dismiss());

        btnBattery.setOnClickListener(v -> {
            try {
                if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) {
                    Intent intent = new Intent();
                    PowerManager pm = (PowerManager) getSystemService(Context.POWER_SERVICE);
                    if (pm != null && !pm.isIgnoringBatteryOptimizations(getPackageName())) {
                        intent.setAction(Settings.ACTION_REQUEST_IGNORE_BATTERY_OPTIMIZATIONS);
                        intent.setData(Uri.parse("package:" + getPackageName()));
                    } else {
                        intent.setAction(Settings.ACTION_IGNORE_BATTERY_OPTIMIZATION_SETTINGS);
                    }
                    startActivity(intent);
                } else {
                    Toast.makeText(this, "您的安卓系统无需单独设置电池白名单", Toast.LENGTH_SHORT).show();
                }
            } catch (Exception e) {
                openAppDetailsSettings();
            }
        });

        btnAutoStart.setOnClickListener(v -> openAppDetailsSettings());
        btnNotification.setOnClickListener(v -> requestNotificationPermission());

        dialog.show();
    }

    private void openAppDetailsSettings() {
        try {
            Intent intent = new Intent(Settings.ACTION_APPLICATION_DETAILS_SETTINGS);
            intent.setData(Uri.parse("package:" + getPackageName()));
            startActivity(intent);
        } catch (Exception e) {
            Toast.makeText(this, "请在手机【系统设置】->【应用管理】中找到 CXPAY 助手设置", Toast.LENGTH_LONG).show();
        }
    }

    // 检查 App 版本更新
    private void checkAppUpdate(boolean isUserTriggered) {
        String serverUrl = editServerUrl.getText().toString().trim();
        if (TextUtils.isEmpty(serverUrl)) serverUrl = "https://cs.fcwan.cn";
        final String finalServerUrl = serverUrl;
        String endpoint = finalServerUrl.replaceAll("/+$", "") + "/api/appasst/version";

        Request request = new Request.Builder()
                .url(endpoint)
                .get()
                .build();

        if (isUserTriggered) {
            Toast.makeText(this, "正在连接服务器检查新版本...", Toast.LENGTH_SHORT).show();
        }

        httpClient.newCall(request).enqueue(new Callback() {
            @Override
            public void onFailure(Call call, java.io.IOException e) {
                mainHandler.post(() -> {
                    if (isUserTriggered) {
                        Toast.makeText(MainActivity.this, "检查更新失败: " + e.getMessage(), Toast.LENGTH_SHORT).show();
                    }
                });
            }

            @Override
            public void onResponse(Call call, Response response) {
                try {
                    String resp = response.body() != null ? response.body().string() : "";
                    JSONObject resJson = new JSONObject(resp);
                    if (resJson.optInt("code", 0) == 1) {
                        JSONObject data = resJson.optJSONObject("data");
                        if (data != null) {
                            int latestCode = data.optInt("version_code", CURRENT_VERSION_CODE);
                            String latestVersion = data.optString("latest_version", CURRENT_VERSION);
                            String downloadUrl = data.optString("download_url", finalServerUrl + "/download/CXPayAssistant.apk");
                            String updateLog = data.optString("update_log", "• 系统保活与统计功能全新升级！");
                            boolean forceUpdate = data.optBoolean("force_update", false);

                            mainHandler.post(() -> {
                                if (latestCode > CURRENT_VERSION_CODE) {
                                    showUpdateDialog(latestVersion, downloadUrl, updateLog, forceUpdate);
                                } else {
                                    if (isUserTriggered) {
                                        Toast.makeText(MainActivity.this, "当前已是最新版本 (v" + CURRENT_VERSION + ")！", Toast.LENGTH_SHORT).show();
                                    }
                                }
                            });
                        }
                    }
                } catch (Exception ignored) {
                } finally {
                    response.close();
                }
            }
        });
    }

    // 弹出更新弹窗
    private void showUpdateDialog(String newVersion, String downloadUrl, String updateLog, boolean forceUpdate) {
        Dialog dialog = new Dialog(this);
        dialog.requestWindowFeature(Window.FEATURE_NO_TITLE);
        dialog.setContentView(R.layout.dialog_app_update);

        if (dialog.getWindow() != null) {
            dialog.getWindow().setBackgroundDrawable(new ColorDrawable(Color.TRANSPARENT));
            dialog.getWindow().setLayout(
                    (int) (getResources().getDisplayMetrics().widthPixels * 0.90),
                    LinearLayout.LayoutParams.WRAP_CONTENT
            );
        }

        dialog.setCancelable(!forceUpdate);

        TextView textTitle = dialog.findViewById(R.id.text_update_title);
        TextView textLog = dialog.findViewById(R.id.text_update_log);
        TextView btnClose = dialog.findViewById(R.id.btn_close_update);
        Button btnBrowser = dialog.findViewById(R.id.btn_update_browser);
        Button btnNow = dialog.findViewById(R.id.btn_update_now);

        LinearLayout layoutProgress = dialog.findViewById(R.id.layout_update_progress);
        ProgressBar progressBar = dialog.findViewById(R.id.progress_update_download);
        TextView textProgressHint = dialog.findViewById(R.id.text_update_progress_hint);
        LinearLayout layoutButtons = dialog.findViewById(R.id.layout_update_buttons);

        textTitle.setText("🚀 发现新版本 v" + newVersion);
        if (!TextUtils.isEmpty(updateLog)) {
            textLog.setText(updateLog);
        }

        btnClose.setVisibility(forceUpdate ? View.GONE : View.VISIBLE);
        btnClose.setOnClickListener(v -> dialog.dismiss());

        btnBrowser.setOnClickListener(v -> {
            try {
                Intent browserIntent = new Intent(Intent.ACTION_VIEW, Uri.parse(downloadUrl));
                startActivity(browserIntent);
            } catch (Exception e) {
                Toast.makeText(this, "无法打开浏览器: " + e.getMessage(), Toast.LENGTH_SHORT).show();
            }
        });

        btnNow.setOnClickListener(v -> {
            layoutProgress.setVisibility(View.VISIBLE);
            layoutButtons.setVisibility(View.GONE);
            downloadAndInstallApk(downloadUrl, progressBar, textProgressHint, dialog);
        });

        dialog.show();
    }

    // 下载并安装 APK
    private void downloadAndInstallApk(String url, ProgressBar progressBar, TextView textHint, Dialog dialog) {
        Request request = new Request.Builder().url(url).build();

        httpClient.newCall(request).enqueue(new Callback() {
            @Override
            public void onFailure(Call call, java.io.IOException e) {
                mainHandler.post(() -> {
                    textHint.setText("❌ 下载失败: " + e.getMessage());
                    Toast.makeText(MainActivity.this, "下载更新失败，请选择浏览器下载", Toast.LENGTH_LONG).show();
                });
            }

            @Override
            public void onResponse(Call call, Response response) {
                if (!response.isSuccessful() || response.body() == null) {
                    mainHandler.post(() -> textHint.setText("❌ 下载失败: HTTP " + response.code()));
                    return;
                }

                try {
                    long totalBytes = response.body().contentLength();
                    File destDir = getExternalFilesDir(Environment.DIRECTORY_DOWNLOADS);
                    if (destDir == null) destDir = getFilesDir();
                    File apkFile = new File(destDir, "CXPayAssistant_update.apk");

                    InputStream is = response.body().byteStream();
                    FileOutputStream fos = new FileOutputStream(apkFile);

                    byte[] buffer = new byte[8192];
                    long downloadedBytes = 0;
                    int read;

                    while ((read = is.read(buffer)) != -1) {
                        fos.write(buffer, 0, read);
                        downloadedBytes += read;
                        if (totalBytes > 0) {
                            final int progress = (int) (downloadedBytes * 100 / totalBytes);
                            mainHandler.post(() -> {
                                progressBar.setProgress(progress);
                                textHint.setText("正在极速下载更新包 " + progress + "%...");
                            });
                        }
                    }

                    fos.flush();
                    fos.close();
                    is.close();
                    response.close();

                    mainHandler.post(() -> {
                        textHint.setText("✅ 下载完成，正在调起系统安装器...");
                        dialog.dismiss();
                        installApk(apkFile);
                    });

                } catch (Exception e) {
                    mainHandler.post(() -> {
                        textHint.setText("❌ 保存失败: " + e.getMessage());
                        Toast.makeText(MainActivity.this, "写入安装包失败: " + e.getMessage(), Toast.LENGTH_SHORT).show();
                    });
                }
            }
        });
    }

    private void installApk(File apkFile) {
        try {
            if (!apkFile.exists()) return;

            Intent installIntent = new Intent(Intent.ACTION_VIEW);
            installIntent.setFlags(Intent.FLAG_ACTIVITY_NEW_TASK | Intent.FLAG_GRANT_READ_URI_PERMISSION);

            Uri apkUri;
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.N) {
                apkUri = FileProvider.getUriForFile(this, getPackageName() + ".fileprovider", apkFile);
            } else {
                apkUri = Uri.fromFile(apkFile);
            }

            installIntent.setDataAndType(apkUri, "application/vnd.android.package-archive");
            startActivity(installIntent);
        } catch (Exception e) {
            Toast.makeText(this, "调起安装失败，请授予应用未知来源安装权限: " + e.getMessage(), Toast.LENGTH_LONG).show();
            appendLog("[更新异常] 安装失败: " + e.getMessage());
        }
    }

    private void toggleService() {
        Intent serviceIntent = new Intent(this, KeepAliveService.class);
        if (!isServiceRunning) {
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
                startForegroundService(serviceIntent);
            } else {
                startService(serviceIntent);
            }
            isServiceRunning = true;
            textStatusBadge.setText("🟢 正在后台实时监控 (ONLINE)");
            textStatusBadge.setTextColor(0xFF10B981);
            btnToggleService.setText("停止监控服务");
            btnToggleService.setBackgroundResource(R.drawable.btn_stop_bg);
            appendLog("[保活] 后台监控与心跳保活服务已成功启动！");
            Toast.makeText(this, "监控与保活服务已启动！", Toast.LENGTH_SHORT).show();
        } else {
            stopService(serviceIntent);
            isServiceRunning = false;
            textStatusBadge.setText("⚪ 监控服务已暂停 (PAUSED)");
            textStatusBadge.setTextColor(0xFF94A3B8);
            btnToggleService.setText("启动监控服务");
            btnToggleService.setBackgroundResource(R.drawable.btn_start_bg);
            appendLog("[保活] 监控与保活服务已停止。");
            Toast.makeText(this, "监控服务已停止。", Toast.LENGTH_SHORT).show();
        }
    }
}
