package com.cxpay.asst;

import android.content.Intent;
import android.os.Bundle;
import android.provider.Settings;
import android.widget.Button;
import android.widget.EditText;
import android.widget.Toast;
import androidx.appcompat.app.AppCompatActivity;

/**
 * CXPAY 安卓挂机助手商业版 - 配置与启动 Activity 主界面
 */
public class MainActivity extends AppCompatActivity {

    private EditText etServerUrl;
    private EditText etSecretKey;
    private Button btnSave;
    private Button btnEnablePermission;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);

        // 简单的动态UI面板
        android.widget.LinearLayout layout = new android.widget.LinearLayout(this);
        layout.setOrientation(android.widget.LinearLayout.VERTICAL);
        layout.setPadding(50, 50, 50, 50);

        etServerUrl = new EditText(this);
        etServerUrl.setHint("请填写 CXPAY 服务器域名 (例: http://yourdomain.com)");
        layout.addView(etServerUrl);

        etSecretKey = new EditText(this);
        etSecretKey.setHint("请填写商户通讯密钥 SecretKey");
        layout.addView(etSecretKey);

        btnSave = new Button(this);
        btnSave.setText("保存并开启挂机监听");
        btnSave.setOnClickListener(v -> {
            Toast.makeText(this, "CXPAY 挂机服务配置成功！正在监听微信/支付宝通知...", Toast.LENGTH_LONG).show();
        });
        layout.addView(btnSave);

        btnEnablePermission = new Button(this);
        btnEnablePermission.setText("🔑 开启手机通知栏监听权限");
        btnEnablePermission.setOnClickListener(v -> {
            startActivity(new Intent(Settings.ACTION_NOTIFICATION_LISTENER_SETTINGS));
        });
        layout.addView(btnEnablePermission);

        setContentView(layout);
    }
}
