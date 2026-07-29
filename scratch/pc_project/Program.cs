using System;
using System.Collections.Generic;
using System.Globalization;
using System.Net.Http;
using System.Security.Cryptography;
using System.Text;
using System.Threading.Tasks;

namespace CXPayPcAssistant
{
    internal static class Program
    {
        private static void Main()
        {
            Console.Title = "CXPAY Windows 账单上报适配器";
            Console.WriteLine("CXPAY Windows v1 安全上报协议客户端已加载。");
            Console.WriteLine("当前仓库未包含可信的微信/支付宝/QQ桌面账单数据源，因此不会模拟到账或自动核销。");
            Console.WriteLine("接入经过授权的真实账单事件后，请调用 AssistantProtocolClient.PushBillAsync。");
        }
    }

    /// <summary>
    /// Windows账单数据源适配器应调用的安全上报客户端。
    /// sourceBillId 必须来自真实数据源且在重试时保持稳定。
    /// </summary>
    public sealed class AssistantProtocolClient
    {
        private const string ClientVersion = "1.0.0";
        private static readonly HttpClient HttpClient = new HttpClient { Timeout = TimeSpan.FromSeconds(5) };
        private readonly string endpoint;
        private readonly long channelId;
        private readonly string deviceId;
        private readonly string payType;
        private readonly string secret;

        public AssistantProtocolClient(string endpoint, long channelId, string deviceId, string payType, string secret)
        {
            Uri uri;
            if (!Uri.TryCreate(endpoint, UriKind.Absolute, out uri) || uri.Scheme != Uri.UriSchemeHttps)
                throw new ArgumentException("服务端地址必须是HTTPS绝对地址", "endpoint");
            if (channelId <= 0) throw new ArgumentOutOfRangeException("channelId");
            if (string.IsNullOrWhiteSpace(deviceId) || deviceId.Length > 64)
                throw new ArgumentException("设备ID不合法", "deviceId");
            if (payType != "alipay" && payType != "wxpay" && payType != "qqpay")
                throw new ArgumentException("支付类型不合法", "payType");
            if (string.IsNullOrEmpty(secret) || secret.Length < 32 || secret.Length > 128)
                throw new ArgumentException("上报密钥长度必须为32至128位", "secret");

            this.endpoint = endpoint.TrimEnd('/') + "/api/appasst/push";
            this.channelId = channelId;
            this.deviceId = deviceId;
            this.payType = payType;
            this.secret = secret;
        }

        public Task<HttpResponseMessage> PushHeartbeatAsync()
        {
            return SendAsync("heartbeat", "0.00", string.Empty, string.Empty, 0);
        }

        public Task<HttpResponseMessage> PushBillAsync(
            decimal money,
            string remark,
            string sourceBillId,
            DateTimeOffset occurredAt)
        {
            if (money <= 0) throw new ArgumentOutOfRangeException("money");
            if (string.IsNullOrWhiteSpace(sourceBillId) || sourceBillId.Length < 16 || sourceBillId.Length > 128)
                throw new ArgumentException("来源账单ID必须为16至128位稳定标识", "sourceBillId");
            return SendAsync(
                "bill",
                money.ToString("0.00", CultureInfo.InvariantCulture),
                (remark ?? string.Empty).Length > 255 ? remark.Substring(0, 255) : remark ?? string.Empty,
                sourceBillId,
                occurredAt.ToUnixTimeSeconds()
            );
        }

        private Task<HttpResponseMessage> SendAsync(
            string eventName,
            string money,
            string remark,
            string sourceBillId,
            long occurredAt)
        {
            long timestamp = DateTimeOffset.UtcNow.ToUnixTimeSeconds();
            string nonce = Guid.NewGuid().ToString("N");
            string canonical = string.Join("|", new[]
            {
                "2", channelId.ToString(CultureInfo.InvariantCulture), deviceId, eventName, payType, money,
                sourceBillId, occurredAt.ToString(CultureInfo.InvariantCulture),
                timestamp.ToString(CultureInfo.InvariantCulture), nonce, ClientVersion
            });
            var fields = new Dictionary<string, string>();
            fields.Add("version", "2");
            fields.Add("channel_id", channelId.ToString(CultureInfo.InvariantCulture));
            fields.Add("device_id", deviceId);
            fields.Add("event", eventName);
            fields.Add("pay_type", payType);
            fields.Add("money", money);
            fields.Add("remark", remark);
            fields.Add("source_bill_id", sourceBillId);
            fields.Add("occurred_at", occurredAt.ToString(CultureInfo.InvariantCulture));
            fields.Add("timestamp", timestamp.ToString(CultureInfo.InvariantCulture));
            fields.Add("nonce", nonce);
            fields.Add("client_version", ClientVersion);
            fields.Add("sign", HmacSha256(canonical, secret));
            return HttpClient.PostAsync(endpoint, new FormUrlEncodedContent(fields));
        }

        private static string HmacSha256(string value, string key)
        {
            using (var hmac = new HMACSHA256(Encoding.UTF8.GetBytes(key)))
            {
                byte[] hash = hmac.ComputeHash(Encoding.UTF8.GetBytes(value));
                var result = new StringBuilder(hash.Length * 2);
                foreach (byte item in hash) result.Append(item.ToString("x2"));
                return result.ToString();
            }
        }
    }
}
