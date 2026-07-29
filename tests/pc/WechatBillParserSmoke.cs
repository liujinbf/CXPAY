using System;
using System.Collections.Generic;
using CXPayMonitor;

internal static class WechatBillParserSmoke
{
    private static int failures;

    private static void Main()
    {
        DateTime capturedAt = new DateTime(2026, 7, 29, 14, 30, 0, DateTimeKind.Local);
        AssertCount("收款单详情", capturedAt,
            "收款单\n微信支付收款\n收款成功\n¥12.34\n今天 14:25\n交易单号：420000202607291234567890", 1);
        AssertCount("小账本记录", capturedAt,
            "经营账户\n收入\n+8.88元\n2026年7月29日 14:20\n订单号：WX202607291420001234", 1);
        AssertCount("汇总金额不能当账单", capturedAt,
            "经营账户\n今日收入 ¥100.00\n今天 14:20", 0);
        AssertCount("证据不足不能上报", capturedAt,
            "收款单\n收款成功\n¥6.66", 0);
        AssertCount("退款不能当收款", capturedAt,
            "收款单\n微信支付收款\n退款 ¥9.90\n今天 14:22\n订单号：WX202607291422009999", 0);
        AssertDuplicateCollapsed(capturedAt);
        AssertSeparateControls(capturedAt);
        AssertRealLedgerLayout(capturedAt);
        AssertReceiptListStatuses(capturedAt);
        AssertOcrLedgerCoordinates(capturedAt);

        if (failures > 0)
        {
            Console.Error.WriteLine("微信账单解析测试失败：" + failures + "项");
            Environment.Exit(1);
        }
        Console.WriteLine("微信账单解析测试通过：10项");
    }

    private static void AssertCount(string name, DateTime capturedAt, string text, int expected)
    {
        IList<BillEvent> result = Parse(capturedAt, new[] { text });
        if (result.Count == expected) return;
        failures++;
        Console.Error.WriteLine(name + "：预期" + expected + "条，实际" + result.Count + "条");
    }

    private static void AssertDuplicateCollapsed(DateTime capturedAt)
    {
        string shortText = "收款单\n收款成功\n￥18.00\n今天 14:29\n交易单号：420000202607291800000001";
        string longText = "微信支付收款记录\n" + shortText + "\n支付方式：零钱";
        IList<BillEvent> result = Parse(capturedAt, new[] { shortText, longText });
        if (result.Count == 1) return;
        failures++;
        Console.Error.WriteLine("父子控件重复文本去重：预期1条，实际" + result.Count + "条");
    }

    private static void AssertSeparateControls(DateTime capturedAt)
    {
        IList<BillEvent> result = Parse(capturedAt, new[]
        {
            "收款单", "收款成功", "￥3.21", "今天 14:28", "交易单号：420000202607290321000001"
        });
        if (result.Count == 1) return;
        failures++;
        Console.Error.WriteLine("分离文本控件组合：预期1条，实际" + result.Count + "条");
    }

    private static void AssertRealLedgerLayout(DateTime capturedAt)
    {
        string text = "收款小账本\n自定义查询\n经营报表\n"
            + "7月14日\n共收款1笔，累计￥650.00\n甲*乙\n￥650.00\n20:35:46\n"
            + "6月20日\n共收款2笔，累计￥103.50\n客*一\n￥3.50\n11:07:30\n客*二\n￥100.00\n10:10:01";
        IList<BillEvent> result = Parse(capturedAt, new[] { text });
        if (result.Count == 3) return;
        failures++;
        Console.Error.WriteLine("真实小账本分组结构：预期3条，实际" + result.Count + "条");
    }

    private static void AssertReceiptListStatuses(DateTime capturedAt)
    {
        string text = "微信收款单\n测试门店\n2026年07月\n全部收款单\n"
            + "07/29 14:26\n￥8.88\n门店甲\n已支付，共计\n1\n笔\n"
            + "07/29 14:25\n￥9.99\n门店乙\n暂无人付款\n"
            + "07/29 14:24\n￥6.66\n门店丙\n已关闭";
        IList<BillEvent> result = Parse(capturedAt, new[] { text });
        if (result.Count == 1 && result[0].money == "8.88") return;
        failures++;
        Console.Error.WriteLine("收款单支付状态过滤：预期1条已支付记录，实际" + result.Count + "条");
    }

    private static void AssertOcrLedgerCoordinates(DateTime capturedAt)
    {
        var items = new List<AutomationTextItem>();
        AddOcr(items, "自定义查询", 97, 3);
        AddOcr(items, "经营报表", 97, 3);
        AddOcr(items, "7月14日", 164, 3);
        AddOcr(items, "共收款1笔，累计¥650.00", 188, 1);
        AddOcr(items, "¥650.00", 242, 1);
        AddOcr(items, "203亍46", 273, 3);
        AddOcr(items, "7月11日", 333, 3);
        AddOcr(items, "共收款1笔，累计¥400.00", 357, 1);
        AddOcr(items, "¥400.00", 411, 1);
        AddOcr(items, "17:45:10", 442, 3);
        AddOcr(items, "6月20日", 502, 3);
        AddOcr(items, "共收款2笔，累计¥103.50", 526, 1);
        AddOcr(items, "¥3.50", 580, 1);
        AddOcr(items, "11℃7:30", 611, 3);
        AddOcr(items, "¥100.00", 663, 1);
        AddOcr(items, "101001", 694, 3);
        IList<BillEvent> result = new WechatBillParser().Parse(new WechatSnapshot
        {
            CapturedAt = capturedAt,
            WindowTitle = "收款小账本",
            CaptureWidth = 430,
            CaptureHeight = 788,
            Items = items
        });
        if (result.Count == 4) return;
        failures++;
        Console.Error.WriteLine("Windows OCR坐标账单重建：预期4条，实际" + result.Count + "条");
    }

    private static void AddOcr(List<AutomationTextItem> items, string text, double top, int scale)
    {
        items.Add(new AutomationTextItem
        {
            Text = text,
            Path = "ocr:" + scale + ":" + items.Count,
            ControlType = "OcrLine",
            Top = top,
            Left = text.StartsWith("¥", StringComparison.Ordinal) ? 348 : 25
        });
    }

    private static IList<BillEvent> Parse(DateTime capturedAt, string[] values)
    {
        var items = new List<AutomationTextItem>();
        for (int i = 0; i < values.Length; i++)
            items.Add(new AutomationTextItem { Text = values[i], Path = i.ToString(), ControlType = "Text" });
        return new WechatBillParser().Parse(new WechatSnapshot
        {
            CapturedAt = capturedAt,
            WindowTitle = "微信收款单",
            Items = items
        });
    }
}
