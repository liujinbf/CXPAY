package com.cxpay.asst;

import android.content.Context;
import android.content.SharedPreferences;
import android.security.keystore.KeyGenParameterSpec;
import android.security.keystore.KeyProperties;
import android.util.Base64;

import java.nio.charset.StandardCharsets;
import java.security.KeyStore;

import javax.crypto.Cipher;
import javax.crypto.KeyGenerator;
import javax.crypto.SecretKey;
import javax.crypto.spec.GCMParameterSpec;

/** 使用 Android Keystore 加密保存通道上报密钥。 */
final class AssistantConfig {
    private static final String PREFS = "cxpay_assistant";
    private static final String KEY_ALIAS = "cxpay_notify_secret_v1";

    private AssistantConfig() {}

    static void save(
            Context context,
            String serverUrl,
            long channelId,
            String deviceId,
            String payType,
            String secret,
            String deliveryMode,
            String collectorId,
            String ingestToken
    ) throws Exception {
        SharedPreferences preferences = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE);
        String encryptedSecret = secret.isEmpty()
                ? preferences.getString("secret", "")
                : encrypt(secret);
        String encryptedIngestToken = ingestToken.isEmpty()
                ? preferences.getString("ingest_token", "")
                : encrypt(ingestToken);
        if ("direct".equals(deliveryMode) && (encryptedSecret == null || encryptedSecret.isEmpty())) {
            throw new IllegalArgumentException("直接上报模式首次配置必须填写通道上报密钥");
        }
        if ("feed".equals(deliveryMode) && (encryptedIngestToken == null || encryptedIngestToken.isEmpty())) {
            throw new IllegalArgumentException("PC中转模式首次配置必须填写账单源写入令牌");
        }
        preferences.edit()
                .putString("server_url", serverUrl)
                .putLong("channel_id", channelId)
                .putString("device_id", deviceId)
                .putString("pay_type", payType)
                .putString("secret", encryptedSecret)
                .putString("delivery_mode", deliveryMode)
                .putString("collector_id", collectorId)
                .putString("ingest_token", encryptedIngestToken)
                .apply();
    }

    static String serverUrl(Context context) {
        return preferences(context).getString("server_url", "");
    }

    static long channelId(Context context) {
        return preferences(context).getLong("channel_id", 0);
    }

    static String deviceId(Context context) {
        return preferences(context).getString("device_id", "");
    }

    static String payType(Context context) {
        return preferences(context).getString("pay_type", "alipay");
    }

    static String secret(Context context) throws Exception {
        String encrypted = preferences(context).getString("secret", "");
        return encrypted == null || encrypted.isEmpty() ? "" : decrypt(encrypted);
    }

    static String deliveryMode(Context context) {
        return preferences(context).getString("delivery_mode", "direct");
    }

    static String collectorId(Context context) {
        return preferences(context).getString("collector_id", "");
    }

    static String ingestToken(Context context) throws Exception {
        String encrypted = preferences(context).getString("ingest_token", "");
        return encrypted == null || encrypted.isEmpty() ? "" : decrypt(encrypted);
    }

    private static SharedPreferences preferences(Context context) {
        return context.getSharedPreferences(PREFS, Context.MODE_PRIVATE);
    }

    private static SecretKey key() throws Exception {
        KeyStore keyStore = KeyStore.getInstance("AndroidKeyStore");
        keyStore.load(null);
        if (!keyStore.containsAlias(KEY_ALIAS)) {
            KeyGenerator generator = KeyGenerator.getInstance(KeyProperties.KEY_ALGORITHM_AES, "AndroidKeyStore");
            generator.init(new KeyGenParameterSpec.Builder(
                    KEY_ALIAS,
                    KeyProperties.PURPOSE_ENCRYPT | KeyProperties.PURPOSE_DECRYPT
            ).setBlockModes(KeyProperties.BLOCK_MODE_GCM)
                    .setEncryptionPaddings(KeyProperties.ENCRYPTION_PADDING_NONE)
                    .build());
            generator.generateKey();
        }
        return ((KeyStore.SecretKeyEntry) keyStore.getEntry(KEY_ALIAS, null)).getSecretKey();
    }

    private static String encrypt(String plaintext) throws Exception {
        Cipher cipher = Cipher.getInstance("AES/GCM/NoPadding");
        cipher.init(Cipher.ENCRYPT_MODE, key());
        String iv = Base64.encodeToString(cipher.getIV(), Base64.NO_WRAP);
        String ciphertext = Base64.encodeToString(
                cipher.doFinal(plaintext.getBytes(StandardCharsets.UTF_8)),
                Base64.NO_WRAP
        );
        return iv + "." + ciphertext;
    }

    private static String decrypt(String stored) throws Exception {
        String[] parts = stored.split("\\.", 2);
        if (parts.length != 2) {
            throw new IllegalStateException("通道密钥存储格式损坏");
        }
        Cipher cipher = Cipher.getInstance("AES/GCM/NoPadding");
        cipher.init(
                Cipher.DECRYPT_MODE,
                key(),
                new GCMParameterSpec(128, Base64.decode(parts[0], Base64.NO_WRAP))
        );
        return new String(cipher.doFinal(Base64.decode(parts[1], Base64.NO_WRAP)), StandardCharsets.UTF_8);
    }
}
