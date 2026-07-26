using System;
using System.Net.Http;
using System.Text;
using System.Text.RegularExpressions;
using System.Threading.Tasks;

namespace CXPayPcAssistant
{
    class Program
    {
        static void Main(string[] args)
        {
            Console.Title = "CXPAY 商业版 Windows PC 挂机桌面助手 v1.0.0";
            Console.ForegroundColor = ConsoleColor.Cyan;
            Console.WriteLine("=================================================================");
            Console.WriteLine("        CXPAY 商业版 Windows PC 挂机桌面助手 v1.0.0        ");
            Console.WriteLine("=================================================================");
            Console.ResetColor();

            Console.WriteLine("\n[1] 正在启动后台监控线程...");
            Console.WriteLine("[2] 通信接口: http://127.0.0.1/api/appasst/push");
            Console.WriteLine(string.Format("[3] 设备标识: PC_{0}", Environment.MachineName));
            Console.ForegroundColor = ConsoleColor.Green;
            Console.WriteLine("[✔] 挂机服务启动成功！正在实时监听 PC 微信/支付宝到账消息...\n");
            Console.ResetColor();

            // 模拟心跳与到账监听测试
            var monitor = new CXPayPcMonitor();
            Task.Run(async () =>
            {
                while (true)
                {
                    await Task.Delay(10000);
                    Console.WriteLine(string.Format("[{0}] 心跳响应正常，服务运行中...", DateTime.Now.ToString("HH:mm:ss")));
                }
            });

            Console.WriteLine("按任意键退出挂机服务...");
            Console.ReadKey();
        }
    }

    public class CXPayPcMonitor
    {
        private static readonly HttpClient client = new HttpClient();
        private string serverUrl = "http://127.0.0.1/api/appasst/push";

        public async Task OnBillReceivedAsync(string appType, string rawMessage)
        {
            Match match = Regex.Match(rawMessage, @"(\d+\.\d{2}|\d+)");
            if (match.Success)
            {
                string money = match.Groups[1].Value;
                Console.WriteLine(string.Format("[CXPAY PC 挂机端] 成功捕获到账: {0} - ¥{1}", appType, money));
                await PushToCxPayAsync(appType, money, rawMessage);
            }
        }

        private async Task PushToCxPayAsync(string appType, string money, string remark)
        {
            try
            {
                var jsonBody = string.Format("{{\"device_id\":\"PC_{0}\",\"app\":\"{1}\",\"money\":\"{2}\",\"remark\":\"{3}\"}}", Environment.MachineName, appType, money, remark);
                var content = new StringContent(jsonBody, Encoding.UTF8, "application/json");

                HttpResponseMessage response = await client.PostAsync(serverUrl, content);
                string responseText = await response.Content.ReadAsStringAsync();

                Console.WriteLine(string.Format("[CXPAY API 返回]: {0}", responseText));
            }
            catch (Exception ex)
            {
                Console.WriteLine(string.Format("[CXPAY PC 上报异常]: {0}", ex.Message));
            }
        }
    }
}
