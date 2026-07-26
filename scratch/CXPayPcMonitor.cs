using System;
using System.Net.Http;
using System.Text;
using System.Text.RegularExpressions;
using System.Threading.Tasks;

namespace CXPayPcAssistant
{
    /// <summary>
    /// CXPAY 商业版 Windows PC 挂机桌面助手核心逻辑 - 监听 PC 微信/支付宝到账窗口并上报
    /// </summary>
    public class CXPayPcMonitor
    {
        private static readonly HttpClient client = new HttpClient();
        private string serverUrl = "http://127.0.0.1/api/appasst/push";
        private string deviceSecret = "CX_PC_SECRET_9999";

        /// <summary>
        /// 监听到的到账消息文本处理
        /// </summary>
        public async Task OnBillReceivedAsync(string appType, string rawMessage)
        {
            // 正则匹配提取金额数字
            Match match = Regex.Match(rawMessage, @"(\d+\.\d{2}|\d+)");
            if (match.Success)
            {
                string money = match.Groups[1].Value;
                Console.WriteLine($"[CXPAY PC 挂机端] 成功捕获到账: {appType} - ¥{money}");

                await PushToCxPayAsync(appType, money, rawMessage);
            }
        }

        private async Task PushToCxPayAsync(string appType, string money, string remark)
        {
            try
            {
                var jsonBody = $"{{\"device_id\":\"PC_{Environment.MachineName}\",\"app\":\"{appType}\",\"money\":\"{money}\",\"remark\":\"{remark}\"}}";
                var content = new StringContent(jsonBody, Encoding.UTF8, "application/json");

                HttpResponseMessage response = await client.PostAsync(serverUrl, content);
                string responseText = await response.Content.ReadAsStringAsync();

                Console.WriteLine($"[CXPAY API 返回]: {responseText}");
            }
            catch (Exception ex)
            {
                Console.WriteLine($"[CXPAY PC 上报异常]: {ex.Message}");
            }
        }
    }
}
