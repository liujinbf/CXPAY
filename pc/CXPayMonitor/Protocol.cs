using System;
using System.Collections.Generic;
using System.Globalization;
using System.Net.Http;
using System.Net.Http.Headers;
using System.Security.Cryptography;
using System.Text;
using System.Threading.Tasks;
using System.Web.Script.Serialization;

namespace CXPayMonitor
{
    public sealed class AssistantProtocolClient
    {
        private const string ClientVersion = "2.1.0";
        private static readonly HttpClient Client = new HttpClient { Timeout = TimeSpan.FromSeconds(10) };
        private readonly string endpoint;
        private readonly AppConfig config;
        private readonly JavaScriptSerializer json = new JavaScriptSerializer();

        public AssistantProtocolClient(AppConfig config)
        {
            ValidateHttps(config.ServerUrl, "CXPAY服务端地址");
            if (config.ChannelId <= 0) throw new ArgumentException("通道ID必须大于0");
            if (string.IsNullOrWhiteSpace(config.DeviceId) || config.DeviceId.Length > 64)
                throw new ArgumentException("设备ID不能为空且不能超过64位");
            if (config.PayType != "alipay" && config.PayType != "wxpay" && config.PayType != "qqpay")
                throw new ArgumentException("支付类型不正确");
            if (string.IsNullOrEmpty(config.NotifySecret) || config.NotifySecret.Length < 32 || config.NotifySecret.Length > 128)
                throw new ArgumentException("上报密钥必须为32至128位");
            this.config = config;
            endpoint = config.ServerUrl.TrimEnd('/') + "/api/appasst/push";
        }

        public Task SendHeartbeatAsync()
        {
            return SendAsync("heartbeat", "0.00", string.Empty, string.Empty, 0);
        }

        public Task SendBillAsync(BillEvent bill)
        {
            decimal money;
            if (!decimal.TryParse(bill.money, NumberStyles.Number, CultureInfo.InvariantCulture, out money) || money <= 0)
                throw new ArgumentException("账单金额无效");
            return SendAsync("bill", money.ToString("0.00", CultureInfo.InvariantCulture),
                Truncate(bill.remark, 255), bill.source_bill_id, bill.occurred_at);
        }

        private async Task SendAsync(string eventName, string money, string remark, string sourceBillId, long occurredAt)
        {
            long timestamp = UnixNow();
            string nonce = Guid.NewGuid().ToString("N");
            string canonical = string.Join("|", new[]
            {
                "2", config.ChannelId.ToString(CultureInfo.InvariantCulture), config.DeviceId,
                eventName, config.PayType, money, sourceBillId,
                occurredAt.ToString(CultureInfo.InvariantCulture), timestamp.ToString(CultureInfo.InvariantCulture),
                nonce, ClientVersion
            });
            var fields = new Dictionary<string, string>
            {
                { "version", "2" },
                { "channel_id", config.ChannelId.ToString(CultureInfo.InvariantCulture) },
                { "device_id", config.DeviceId },
                { "event", eventName },
                { "pay_type", config.PayType },
                { "money", money },
                { "remark", remark ?? string.Empty },
                { "source_bill_id", sourceBillId },
                { "occurred_at", occurredAt.ToString(CultureInfo.InvariantCulture) },
                { "timestamp", timestamp.ToString(CultureInfo.InvariantCulture) },
                { "nonce", nonce },
                { "client_version", ClientVersion },
                { "sign", HmacSha256(canonical, config.NotifySecret) }
            };
            using (HttpResponseMessage response = await Client.PostAsync(endpoint, new FormUrlEncodedContent(fields)))
            {
                string body = await response.Content.ReadAsStringAsync();
                if (!response.IsSuccessStatusCode) throw new InvalidOperationException("服务端返回HTTP " + (int)response.StatusCode + "：" + body);
                var result = json.Deserialize<ProtocolResponse>(body);
                if (result == null || result.code != 1)
                    throw new InvalidOperationException(result == null ? "服务端响应无法解析" : result.msg);
            }
        }

        internal static long UnixNow()
        {
            return (long)(DateTime.UtcNow - new DateTime(1970, 1, 1)).TotalSeconds;
        }

        internal static void ValidateHttps(string value, string field)
        {
            Uri uri;
            if (!Uri.TryCreate(value, UriKind.Absolute, out uri) || uri.Scheme != Uri.UriSchemeHttps)
                throw new ArgumentException(field + "必须是HTTPS绝对地址");
        }

        private static string Truncate(string value, int length)
        {
            if (string.IsNullOrEmpty(value)) return string.Empty;
            return value.Length > length ? value.Substring(0, length) : value;
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

        private sealed class ProtocolResponse
        {
            public int code { get; set; }
            public string msg { get; set; }
        }
    }

    public sealed class AuthorizedFeedClient
    {
        private static readonly HttpClient Client = new HttpClient { Timeout = TimeSpan.FromSeconds(10) };
        private readonly JavaScriptSerializer json = new JavaScriptSerializer();

        public async Task<FeedResponse> PollAsync(AppConfig config)
        {
            AssistantProtocolClient.ValidateHttps(config.FeedUrl, "授权账单源地址");
            string separator = config.FeedUrl.Contains("?") ? "&" : "?";
            string url = config.FeedUrl + separator + "cursor=" + Uri.EscapeDataString(config.FeedCursor ?? string.Empty)
                         + "&pay_type=" + Uri.EscapeDataString(config.PayType)
                         + "&channel_id=" + config.ChannelId.ToString(CultureInfo.InvariantCulture)
                         + "&device_id=" + Uri.EscapeDataString(config.DeviceId);
            using (var request = new HttpRequestMessage(HttpMethod.Get, url))
            {
                request.Headers.Accept.Add(new MediaTypeWithQualityHeaderValue("application/json"));
                if (!string.IsNullOrWhiteSpace(config.FeedToken))
                    request.Headers.Authorization = new AuthenticationHeaderValue("Bearer", config.FeedToken);
                using (HttpResponseMessage response = await Client.SendAsync(request))
                {
                    string body = await response.Content.ReadAsStringAsync();
                    if (!response.IsSuccessStatusCode)
                        throw new InvalidOperationException("账单源返回HTTP " + (int)response.StatusCode);
                    FeedResponse result = json.Deserialize<FeedResponse>(body);
                    if (result == null || result.code != 1)
                        throw new InvalidOperationException(result == null ? "账单源响应无法解析" : result.message);
                    if (result.data == null) result.data = new List<BillEvent>();
                    return result;
                }
            }
        }
    }
}
