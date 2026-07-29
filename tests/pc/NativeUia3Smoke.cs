using System;
using System.Collections.Generic;
using System.Diagnostics;
using System.Runtime.InteropServices;
using System.Text;
using System.Text.RegularExpressions;

internal static class NativeUia3Smoke
{
    private static void Main()
    {
        IntPtr handle = FindWindow("微信收款单");
        if (handle == IntPtr.Zero) throw new InvalidOperationException("未找到微信收款单窗口");
        Type automationType = Type.GetTypeFromProgID("UIAutomationClient.CUIAutomation8")
            ?? Type.GetTypeFromProgID("UIAutomationClient.CUIAutomation");
        if (automationType == null) throw new InvalidOperationException("系统未注册UI Automation COM组件");
        dynamic automation = Activator.CreateInstance(automationType);
        dynamic root = automation.ElementFromHandle(handle);
        dynamic condition = automation.CreateTrueCondition();
        dynamic elements = root.FindAll(4, condition);
        int count = elements.Length;
        int named = 0;
        bool page = false;
        bool money = false;
        bool paid = false;
        for (int index = 0; index < count && index < 2500; index++)
        {
            dynamic element = elements.GetElement(index);
            string name = Convert.ToString(element.CurrentName);
            if (string.IsNullOrWhiteSpace(name)) continue;
            named++;
            page = page || name.Contains("微信收款单");
            money = money || Regex.IsMatch(name, "[¥￥]\\s*\\d");
            paid = paid || name.Contains("已支付");
        }
        Console.WriteLine("原生UIA3/元素=" + count + "/有名称=" + named
            + "/页面词=" + page + "/金额=" + money + "/已支付=" + paid);
        Marshal.FinalReleaseComObject(automation);
    }

    private static IntPtr FindWindow(string expectedTitle)
    {
        IntPtr result = IntPtr.Zero;
        EnumWindows(delegate(IntPtr handle, IntPtr parameter)
        {
            int length = GetWindowTextLength(handle);
            if (length <= 0) return true;
            var title = new StringBuilder(length + 1);
            GetWindowText(handle, title, title.Capacity);
            if (title.ToString() != expectedTitle) return true;
            result = handle;
            return false;
        }, IntPtr.Zero);
        return result;
    }

    private delegate bool EnumWindowsProc(IntPtr handle, IntPtr parameter);

    [DllImport("user32.dll")]
    private static extern bool EnumWindows(EnumWindowsProc callback, IntPtr parameter);

    [DllImport("user32.dll", CharSet = CharSet.Unicode)]
    private static extern int GetWindowText(IntPtr handle, StringBuilder text, int maximumCount);

    [DllImport("user32.dll", CharSet = CharSet.Unicode)]
    private static extern int GetWindowTextLength(IntPtr handle);
}
