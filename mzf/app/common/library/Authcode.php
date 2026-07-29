<?php

namespace app\common\library;

/**
 * 通道配置加解密（Discuz authcode RC4 变体）
 *
 * 从旧系统 protected/lib/CoreClass/Payplug.php 逐字节移植：
 *   authcode / encryption_array / decrypt_array / encryption / decrypt
 *
 * 用途：迁移期读取旧库 ba_channel/peakpay_channel.config 中以
 *   authcode(旧 Authcode 常量 . SKY) 加密的 JSON 配置，并按原格式回写，
 *   保证新旧系统对同一通道配置互通。
 *
 * ⚠ 算法必须与旧系统 100% 一致，改动前须跑新旧比对用例。
 *
 * 用法：
 *   $ac = new Authcode($secret);            // $secret = 旧 Authcode 常量 . SKY
 *   $plain = $ac->decrypt($cipher);
 *   $cipher = $ac->encrypt($plain);
 *   $conf = $ac->decryptArray(json_decode($row['config'], true));
 */
class Authcode
{
    /**
     * 传入底层 authcode() 的完整密钥（旧实现为 Authcode 常量 . conf['SKY']）
     * @var string
     */
    protected string $secret;

    public function __construct(string $secret)
    {
        $this->secret = $secret;
    }

    /**
     * 用旧库授权常量 + SKY 构造（迁移兼容）。
     * 常量与 SKY 从 config/payment.php 读取，避免硬编码散落。
     */
    public static function legacy(): self
    {
        $authcode = (string) config('payment.legacy_authcode');
        $sky      = (string) config('payment.legacy_sky');
        return new self($authcode . $sky);
    }

    /**
     * 单值加密
     */
    public function encrypt(string $string): string
    {
        return self::authcode($string, 'ENCODE', $this->secret);
    }

    /**
     * 单值解密，失败返回 false
     * @return string|false
     */
    public function decrypt(string $string)
    {
        return self::authcode($string, 'DECODE', $this->secret);
    }

    /**
     * 批量加密（与旧 encryption_array 一致）
     *
     * - 跳过 bill/config/status 字段与空值
     * - 若某值已是密文（能解开）则原样保留，避免二次加密
     *
     * @param array $config
     * @return array
     */
    public function encryptArray(array $config): array
    {
        $upconfig = [];
        foreach ($config as $k => $v) {
            if ($k != 'bill' && $k != 'config' && $k != 'status' && $v !== false && $v !== null && $v !== '') {
                if (self::authcode((string) $v, 'DECODE', $this->secret) !== false) {
                    $upconfig[$k] = $v;
                } else {
                    $upconfig[$k] = self::authcode((string) $v, 'ENCODE', $this->secret);
                }
            }
        }
        return $upconfig;
    }

    /**
     * 批量解密（与旧 decrypt_array 一致）
     *
     * @param array $config
     * @param int   $operation 1=解密失败保留原值(明文兼容)，0=失败置 false
     * @return array
     */
    public function decryptArray(array $config, int $operation = 0): array
    {
        $upconfig = [];
        foreach ($config as $k => $v) {
            if ($v) {
                $dec = self::authcode((string) $v, 'DECODE', $this->secret);
                if ($dec !== false) {
                    $upconfig[$k] = $dec;
                } else {
                    $upconfig[$k] = $operation ? $v : false;
                }
            }
        }
        return $upconfig;
    }

    /**
     * Discuz authcode RC4 变体（逐字节移植旧实现）
     *
     * @param string     $string
     * @param string     $operation ENCODE|DECODE（旧代码写死 1 表示加密，这里统一用 'ENCODE'）
     * @param string     $key
     * @param int        $expiry    有效期秒，0=永久
     * @return string|false DECODE 失败返回 false
     */
    public static function authcode(string $string, string $operation = 'DECODE', string $key = '', int $expiry = 0)
    {
        $ckey_length = 4;

        $global_key = $GLOBALS['discuz_auth_key'] ?? '';
        $key        = md5($key ?: $global_key);

        $keya = md5(substr($key, 0, 16));
        $keyb = md5(substr($key, 16, 16));
        $keyc = $ckey_length ? ($operation == 'DECODE' ? substr($string, 0, $ckey_length) : substr(md5(microtime()), -$ckey_length)) : '';

        $cryptkey   = $keya . md5($keya . $keyc);
        $key_length = strlen($cryptkey);

        if ($operation == 'DECODE') {
            $base64 = substr($string, $ckey_length);
            if ($pad = strlen($base64) % 4) {
                $base64 .= str_repeat('=', 4 - $pad);
            }
            $decoded = base64_decode($base64);
            $string  = ($decoded === false) ? '' : $decoded;
        } else {
            $string = sprintf('%010d', $expiry ? $expiry + time() : 0)
                . substr(md5($string . $keyb), 0, 16)
                . $string;
        }

        $string_length = strlen($string);
        $result        = '';
        $box           = range(0, 255);
        $rndkey        = [];

        for ($i = 0; $i <= 255; $i++) {
            $rndkey[$i] = ord($cryptkey[$i % $key_length]);
        }

        for ($j = $i = 0; $i < 256; $i++) {
            $j       = ($j + $box[$i] + $rndkey[$i]) % 256;
            $tmp     = $box[$i];
            $box[$i] = $box[$j];
            $box[$j] = $tmp;
        }

        for ($a = $j = $i = 0; $i < $string_length; $i++) {
            $a       = ($a + 1) % 256;
            $j       = ($j + $box[$a]) % 256;
            $tmp     = $box[$a];
            $box[$a] = $box[$j];
            $box[$j] = $tmp;
            $result .= chr(ord($string[$i]) ^ ($box[($box[$a] + $box[$j]) % 256]));
        }

        if ($operation == 'DECODE') {
            $timestamp = substr($result, 0, 10);
            $signature = substr($result, 10, 16);
            $data      = substr($result, 26);

            if (
                ($timestamp === '0000000000' || (int) $timestamp - time() > 0)
                && $signature === substr(md5($data . $keyb), 0, 16)
            ) {
                return $data;
            }
            return false;
        }

        return $keyc . str_replace('=', '', base64_encode($result));
    }
}
