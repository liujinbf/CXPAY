package com.cxpay.asst;

import android.content.Intent;
import android.app.Activity;
import android.os.Bundle;
import android.provider.Settings;
import android.text.InputType;
import android.widget.Button;
import android.widget.ArrayAdapter;
import android.widget.EditText;
import android.widget.LinearLayout;
import android.widget.Spinner;
import android.widget.Toast;

import java.net.URL;

/** 挂机助手本地配置页。 */
public class MainActivity extends Activity {
    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);

        LinearLayout layout = new LinearLayout(this);
        layout.setOrientation(LinearLayout.VERTICAL);
        layout.setPadding(48, 48, 48, 48);

        EditText serverUrl = input("HTTPS服务地址，例如 https://pay.example.com", layout);
        EditText channelId = input("通道ID", layout);
        channelId.setInputType(InputType.TYPE_CLASS_NUMBER);
        EditText deviceId = input("设备ID，需与后台通道配置完全一致", layout);
        Spinner payType = new Spinner(this);
        String[] payTypes = {"alipay", "wxpay", "qqpay"};
        payType.setAdapter(new ArrayAdapter<>(this, android.R.layout.simple_spinner_dropdown_item, payTypes));
        layout.addView(payType);
        Spinner deliveryMode = new Spinner(this);
        String[] deliveryModes = {"direct", "feed"};
        deliveryMode.setAdapter(new ArrayAdapter<>(this, android.R.layout.simple_spinner_dropdown_item, deliveryModes));
        layout.addView(deliveryMode);
        EditText secret = input("通道上报密钥；留空保持原密钥", layout);
        secret.setInputType(InputType.TYPE_CLASS_TEXT | InputType.TYPE_TEXT_VARIATION_PASSWORD);
        EditText collectorId = input("采集端ID，PC中转模式必填", layout);
        EditText ingestToken = input("账单源写入令牌；留空保持原令牌", layout);
        ingestToken.setInputType(InputType.TYPE_CLASS_TEXT | InputType.TYPE_TEXT_VARIATION_PASSWORD);

        serverUrl.setText(AssistantConfig.serverUrl(this));
        long storedChannelId = AssistantConfig.channelId(this);
        channelId.setText(storedChannelId > 0 ? String.valueOf(storedChannelId) : "");
        String storedDeviceId = AssistantConfig.deviceId(this);
        if (storedDeviceId.isEmpty()) {
            storedDeviceId = "ANDROID_" + Settings.Secure.getString(getContentResolver(), Settings.Secure.ANDROID_ID);
        }
        deviceId.setText(storedDeviceId);
        String storedPayType = AssistantConfig.payType(this);
        for (int index = 0; index < payTypes.length; index++) {
            if (payTypes[index].equals(storedPayType)) payType.setSelection(index);
        }
        String storedMode = AssistantConfig.deliveryMode(this);
        deliveryMode.setSelection("feed".equals(storedMode) ? 1 : 0);
        String storedCollectorId = AssistantConfig.collectorId(this);
        if (storedCollectorId.isEmpty()) storedCollectorId = storedDeviceId;
        collectorId.setText(storedCollectorId);

        Button save = new Button(this);
        save.setText("保存安全配置");
        save.setOnClickListener(view -> {
            try {
                String url = serverUrl.getText().toString().trim();
                URL parsed = new URL(url);
                if (!"https".equalsIgnoreCase(parsed.getProtocol())) {
                    throw new IllegalArgumentException("服务地址必须使用HTTPS");
                }
                long selectedChannelId = Long.parseLong(channelId.getText().toString().trim());
                String selectedDeviceId = deviceId.getText().toString().trim();
                if (selectedChannelId <= 0 || !selectedDeviceId.matches("[A-Za-z0-9_.:-]{1,64}")) {
                    throw new IllegalArgumentException("通道ID或设备ID格式不正确");
                }
                String selectedSecret = secret.getText().toString();
                if (!selectedSecret.isEmpty() && selectedSecret.length() < 32) {
                    throw new IllegalArgumentException("通道上报密钥至少32位");
                }
                String selectedMode = String.valueOf(deliveryMode.getSelectedItem());
                String selectedCollectorId = collectorId.getText().toString().trim();
                String selectedIngestToken = ingestToken.getText().toString();
                if ("feed".equals(selectedMode)
                        && (!selectedCollectorId.matches("[A-Za-z0-9_.:-]{1,64}")
                        || (!selectedIngestToken.isEmpty()
                        && (selectedIngestToken.length() < 32 || selectedIngestToken.length() > 128)))) {
                    throw new IllegalArgumentException("采集端ID或账单源写入令牌格式不正确");
                }
                AssistantConfig.save(
                        this,
                        url,
                        selectedChannelId,
                        selectedDeviceId,
                        String.valueOf(payType.getSelectedItem()),
                        selectedSecret,
                        selectedMode,
                        selectedCollectorId,
                        selectedIngestToken
                );
                secret.setText("");
                ingestToken.setText("");
                Toast.makeText(this, "配置已加密保存", Toast.LENGTH_LONG).show();
            } catch (Exception exception) {
                Toast.makeText(this, exception.getMessage(), Toast.LENGTH_LONG).show();
            }
        });
        layout.addView(save);

        Button permission = new Button(this);
        permission.setText("打开通知读取授权设置");
        permission.setOnClickListener(view -> startActivity(
                new Intent(Settings.ACTION_NOTIFICATION_LISTENER_SETTINGS)
        ));
        layout.addView(permission);
        setContentView(layout);
    }

    private EditText input(String hint, LinearLayout parent) {
        EditText input = new EditText(this);
        input.setHint(hint);
        parent.addView(input);
        return input;
    }
}
