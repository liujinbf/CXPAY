using System;
using System.Collections.Generic;
using CXPayMonitor;

internal static class WechatOcrCollectorSmoke
{
    private static void Main(string[] args)
    {
        if (args.Length != 1) throw new ArgumentException("请提供微信记录页截图路径");
        var collector = new WechatOcrCollector();
        WechatSnapshot snapshot = collector.RecognizeFileForTest(args[0], "收款小账本", DateTime.Now);
        var parser = new WechatBillParser();
        string reason;
        bool supported = parser.IsSupportedPage(snapshot, out reason);
        IList<BillEvent> bills = parser.Parse(snapshot);
        Console.WriteLine("OCR文本行=" + snapshot.Items.Count);
        Console.WriteLine("页面校验=" + supported);
        Console.WriteLine("账单候选=" + bills.Count);
        if (!supported || bills.Count == 0) Environment.Exit(1);
    }
}
