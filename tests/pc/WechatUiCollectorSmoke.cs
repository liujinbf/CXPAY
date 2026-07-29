using System;
using System.Linq;
using System.Runtime.InteropServices;
using System.Text.RegularExpressions;
using CXPayMonitor;

internal static class WechatUiCollectorSmoke
{
    private static void Main()
    {
        var collector = new WechatUiCollector();
        var parser = new WechatBillParser();
        var windows = collector.ListWindows().Where(x =>
            (x.Title ?? string.Empty).Contains("收款小账本")
            || (x.Title ?? string.Empty).Contains("微信收款单")).ToList();
        Console.WriteLine("候选微信窗口：" + windows.Count);
        foreach (WindowDescriptor window in windows)
        {
            try
            {
                Console.WriteLine("目标窗口在前台=" + (GetForegroundWindow().ToInt64() == window.Handle));
                WechatSnapshot snapshot = collector.Capture(window);
                Console.WriteLine(window.ProcessName + " / " + window.Title
                    + " / 可读项=" + snapshot.Items.Count + " / 账单候选=" + parser.Parse(snapshot).Count);
                if ((window.Title ?? string.Empty).Contains("收款小账本"))
                {
                    foreach (AutomationTextItem item in snapshot.Items)
                    {
                        string value = item.Text ?? string.Empty;
                        Console.WriteLine("  " + item.Path + "/" + item.ControlType + "/长度=" + value.Length
                            + "/页面词=" + Regex.IsMatch(value, "收款小账本|收款记录")
                            + "/金额=" + Regex.IsMatch(value, "[¥￥]\\s*\\d")
                            + "/日期=" + Regex.IsMatch(value, "\\d{1,2}月\\d{1,2}日")
                            + "/时间=" + Regex.IsMatch(value, "\\d{1,2}:\\d{2}"));
                    }
                }
            }
            catch (Exception ex)
            {
                Console.WriteLine(window.ProcessName + " / " + window.Title + " / 检测失败=" + ex.Message);
            }
        }
    }

    [DllImport("user32.dll")]
    private static extern IntPtr GetForegroundWindow();
}
