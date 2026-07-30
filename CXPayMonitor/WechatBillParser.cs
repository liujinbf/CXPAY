using System;
using System.Collections.Generic;
using System.Globalization;
using System.Linq;
using System.Security.Cryptography;
using System.Text;
using System.Text.RegularExpressions;

namespace CXPayMonitor
{
    /// <summary>
    /// 从微信“收款单/小账本”可见文本中识别到账记录。规则故意偏保守，证据不足时不生成账单。
    /// </summary>
    public sealed class WechatBillParser
    {
        private static readonly Regex MoneyPattern = new Regex(
            @"(?<![\d.])(?:(?:[+＋]\s*)?[¥￥]\s*(?<amount>\d{1,8}(?:\.\d{1,2})?)\s*元?|[+＋]\s*(?<amount>\d{1,8}(?:\.\d{1,2})?)\s*元?|(?<amount>\d{1,8}(?:\.\d{1,2})?)\s*元)(?![\d.])",
            RegexOptions.Compiled);
        private static readonly Regex ReceiptPattern = new Regex(
            @"收款成功|已收款|收款金额|实收金额|顾客付款|微信支付收款|到账|收入|赞赏成功|收款记录|收款单|收款小账本|小账本|经营账户",
            RegexOptions.Compiled);
        private static readonly Regex SupportedPagePattern = new Regex(
            @"收款单|收款记录|收款小账本|小账本|经营账户|收款助手",
            RegexOptions.Compiled);
        private static readonly Regex NegativePattern = new Regex(
            @"退款|支出|付款给|转出|提现|手续费|余额|待结算|总收入|今日收入|昨日收入|累计收入|合计|总计|共计|累计",
            RegexOptions.Compiled);
        private static readonly Regex NegativePrefixPattern = new Regex(
            @"(?:退款|支出|付款给|转出|提现|手续费|余额|待结算|总收入|今日收入|昨日收入|累计收入|合计|总计|共计|累计)\s*$",
            RegexOptions.Compiled);
        private static readonly Regex TransactionPattern = new Regex(
            @"(?:交易单号|交易编号|订单号|商户单号|流水号)\s*[:：]?\s*([A-Za-z0-9_-]{10,64})",
            RegexOptions.Compiled | RegexOptions.IgnoreCase);
        private static readonly Regex FullDatePattern = new Regex(
            @"(?<!\d)(?<y>20\d{2})\s*(?:[-/.年])\s*(?<m>0?[1-9]|1[0-2])\s*(?:[-/.月])\s*(?<d>0?[1-9]|[12]\d|3[01])\s*日?\s*(?<h>[01]?\d|2[0-3])\s*[:：]\s*(?<n>\d{2})(?:\s*[:：]\s*(?<s>\d{2}))?(?!\d)",
            RegexOptions.Compiled);
        private static readonly Regex MonthDayPattern = new Regex(
            @"(?<!\d)(?<m>0?[1-9]|1[0-2])\s*(?:[-/.月])\s*(?<d>0?[1-9]|[12]\d|3[01])\s*日?\s*(?<h>[01]?\d|2[0-3])\s*[:：]\s*(?<n>\d{2})(?:\s*[:：]\s*(?<s>\d{2}))?(?!\d)",
            RegexOptions.Compiled);
        private static readonly Regex RelativePattern = new Regex(
            @"(?<day>今天|今日|昨天|昨日)\s*(?<h>\d{1,2})\s*[:：]\s*(?<n>\d{2})(?:\s*[:：]\s*(?<s>\d{2}))?",
            RegexOptions.Compiled);
        private static readonly Regex TimePattern = new Regex(
            @"(?<!\d)(?<h>[01]?\d|2[0-3])\s*[:：]\s*(?<n>\d{2})(?:\s*[:：]\s*(?<s>\d{2}))?(?!\d)",
            RegexOptions.Compiled);
        private static readonly Regex DateHeaderPattern = new Regex(
            @"(?<!\d)(?<m>0?[1-9]|1[0-2])\s*月\s*(?<d>0?[1-9]|[12]\d|3[01])\s*日",
            RegexOptions.Compiled);
        private static readonly Regex ReceiptListRowPattern = new Regex(
            @"(?<date>(?<m>0?[1-9]|1[0-2])[/\-](?<d>0?[1-9]|[12]\d|3[01])\s+(?<h>[01]?\d|2[0-3])[:：](?<n>\d{2})(?:[:：](?<s>\d{2}))?)\s*[¥￥]\s*(?<amount>\d{1,8}(?:\.\d{1,2})?)\s*(?<name>.{1,80}?)\s*(?<status>已支付\s*[,，]?\s*共计\s*\d+\s*笔|暂无人付款|未收款|已关闭)",
            RegexOptions.Compiled | RegexOptions.Singleline);
        private static readonly Regex YearHeaderPattern = new Regex(
            @"(?<!\d)(?<y>20\d{2})\s*年\s*(?:0?[1-9]|1[0-2])\s*月",
            RegexOptions.Compiled);
        private static readonly Regex OcrMoneyPattern = new Regex(
            @"[¥￥](?<amount>\d{1,8}(?:\.\d{1,2})?)",
            RegexOptions.Compiled);

        public IList<BillEvent> Parse(WechatSnapshot snapshot)
        {
            var result = new List<Candidate>();
            if (snapshot == null || snapshot.Items == null) return new List<BillEvent>();
            string pageReason;
            if (!IsSupportedPage(snapshot, out pageReason)) return new List<BillEvent>();
            if (snapshot.Items.Any(x => string.Equals(x.ControlType, "OcrLine", StringComparison.Ordinal)))
                return ParseOcrLedger(snapshot);
            var texts = snapshot.Items.Select(x => x.Text ?? string.Empty).ToList();
            string combined = string.Join("\n", snapshot.Items.Select(x => x.Text ?? string.Empty));
            if (combined.Contains("微信收款单") && combined.Contains("全部收款单"))
                return ParseReceiptList(combined, snapshot.CapturedAt);
            texts.Add(combined);
            foreach (string rawText in texts.OrderBy(x => x.Length))
            {
                string text = Normalize(rawText);
                if (text.Length == 0 || !ReceiptPattern.IsMatch(text)) continue;
                foreach (Match moneyMatch in MoneyPattern.Matches(text))
                {
                    Candidate candidate = CreateCandidate(text, moneyMatch, snapshot.CapturedAt);
                    if (candidate != null) result.Add(candidate);
                }
            }

            var bills = new List<BillEvent>();
            var semanticKeys = new HashSet<string>(StringComparer.Ordinal);
            foreach (Candidate candidate in result.OrderBy(x => x.Context.Length))
            {
                if (!semanticKeys.Add(candidate.SemanticKey)) continue;
                bills.Add(new BillEvent
                {
                    source_bill_id = "wxui:" + Sha256(candidate.SemanticKey),
                    money = candidate.Money.ToString("0.00", CultureInfo.InvariantCulture),
                    occurred_at = ToUnixTime(candidate.OccurredAt),
                    remark = "微信收款单/小账本界面记录",
                    pay_type = "wxpay"
                });
            }
            return bills;
        }

        private static IList<BillEvent> ParseOcrLedger(WechatSnapshot snapshot)
        {
            var dates = new List<OcrDate>();
            var amounts = new List<OcrAmount>();
            var times = new List<OcrTime>();
            foreach (AutomationTextItem item in snapshot.Items)
            {
                if (!string.Equals(item.ControlType, "OcrLine", StringComparison.Ordinal)) continue;
                string text = NormalizeOcr(item.Text);
                int scale = ReadOcrScale(item.Path);

                Match dateMatch = DateHeaderPattern.Match(text);
                if (dateMatch.Success)
                {
                    var date = new OcrDate
                    {
                        Month = ReadInt(dateMatch, "m"), Day = ReadInt(dateMatch, "d"),
                        Top = item.Top, Scale = scale
                    };
                    AddOrReplaceDate(dates, date);
                }

                if (!NegativePattern.IsMatch(text) && !text.Contains("共收款")
                    && (snapshot.CaptureWidth <= 0 || item.Left >= snapshot.CaptureWidth * 0.45))
                {
                    Match moneyMatch = OcrMoneyPattern.Match(text);
                    decimal money;
                    if (moneyMatch.Success && decimal.TryParse(moneyMatch.Groups["amount"].Value,
                        NumberStyles.Number, CultureInfo.InvariantCulture, out money)
                        && money > 0 && money <= 99999999m)
                    {
                        AddOrReplaceAmount(amounts, new OcrAmount
                        {
                            Money = money, Top = item.Top, Left = item.Left, Scale = scale
                        });
                    }
                }

                TimeSpan parsedTime;
                if (TryRepairOcrTime(text, out parsedTime))
                {
                    AddOrReplaceTime(times, new OcrTime
                    {
                        Value = parsedTime, Top = item.Top, Left = item.Left, Scale = scale
                    });
                }
            }

            var bills = new List<BillEvent>();
            var sourceIds = new HashSet<string>(StringComparer.Ordinal);
            foreach (OcrAmount amount in amounts.OrderBy(x => x.Top))
            {
                OcrDate date = dates.Where(x => x.Top <= amount.Top + 8)
                    .OrderByDescending(x => x.Top).FirstOrDefault();
                OcrTime time = times.Where(x => x.Top >= amount.Top - 12 && x.Top <= amount.Top + 85)
                    .OrderBy(x => Math.Abs(x.Top - amount.Top)).ThenByDescending(x => x.Scale).FirstOrDefault();
                if (date == null || time == null) continue;
                int year = snapshot.CapturedAt.Year;
                if (date.Month > snapshot.CapturedAt.Month + 1) year--;
                DateTime occurredAt;
                try
                {
                    occurredAt = new DateTime(year, date.Month, date.Day,
                        time.Value.Hours, time.Value.Minutes, time.Value.Seconds, DateTimeKind.Local);
                }
                catch { continue; }
                string identity = "ledger-ocr|" + occurredAt.ToString("yyyyMMddHHmmss", CultureInfo.InvariantCulture)
                    + "|" + amount.Money.ToString("0.00", CultureInfo.InvariantCulture);
                string sourceId = "wxocr:" + Sha256(identity);
                if (!sourceIds.Add(sourceId)) continue;
                bills.Add(new BillEvent
                {
                    source_bill_id = sourceId,
                    money = amount.Money.ToString("0.00", CultureInfo.InvariantCulture),
                    occurred_at = ToUnixTime(occurredAt),
                    remark = "微信收款小账本OCR记录",
                    pay_type = "wxpay"
                });
            }
            return bills;
        }

        private static IList<BillEvent> ParseReceiptList(string text, DateTime capturedAt)
        {
            text = Normalize(text);
            Match yearMatch = YearHeaderPattern.Match(text);
            int year = yearMatch.Success ? ReadInt(yearMatch, "y") : capturedAt.Year;
            var bills = new List<BillEvent>();
            var sourceIds = new HashSet<string>(StringComparer.Ordinal);
            foreach (Match row in ReceiptListRowPattern.Matches(text))
            {
                if (!row.Groups["status"].Value.StartsWith("已支付", StringComparison.Ordinal)) continue;
                decimal money;
                if (!decimal.TryParse(row.Groups["amount"].Value, NumberStyles.Number,
                    CultureInfo.InvariantCulture, out money) || money <= 0 || money > 99999999m) continue;
                DateTime occurredAt;
                try
                {
                    occurredAt = new DateTime(year, ReadInt(row, "m"), ReadInt(row, "d"),
                        ReadInt(row, "h"), ReadInt(row, "n"), ReadInt(row, "s"), DateTimeKind.Local);
                }
                catch { continue; }
                string identity = Regex.Replace(row.Groups["name"].Value, @"\s+", string.Empty);
                string sourceId = "wxui:" + Sha256("receipt-list|"
                    + occurredAt.ToString("yyyyMMddHHmmss", CultureInfo.InvariantCulture) + "|"
                    + money.ToString("0.00", CultureInfo.InvariantCulture) + "|" + identity);
                if (!sourceIds.Add(sourceId)) continue;
                bills.Add(new BillEvent
                {
                    source_bill_id = sourceId,
                    money = money.ToString("0.00", CultureInfo.InvariantCulture),
                    occurred_at = ToUnixTime(occurredAt),
                    remark = "微信收款单已支付记录",
                    pay_type = "wxpay"
                });
            }
            return bills;
        }

        public bool IsSupportedPage(WechatSnapshot snapshot, out string reason)
        {
            reason = string.Empty;
            if (snapshot == null || snapshot.Items == null || snapshot.Items.Count == 0)
            {
                reason = "窗口没有暴露可读文本";
                return false;
            }
            string visible = string.Join("\n", snapshot.Items.Select(x => x.Text ?? string.Empty));
            bool ocrSnapshot = snapshot.Items.Any(x => string.Equals(x.ControlType, "OcrLine", StringComparison.Ordinal));
            if (ocrSnapshot)
            {
                string compact = NormalizeOcr(visible);
                string title = NormalizeOcr(snapshot.WindowTitle);
                bool trustedTitle = title.Contains("收款小账本") || title.Contains("微信收款单")
                    || title.Contains("经营账户");
                bool ledgerChrome = compact.Contains("自定义查询") && compact.Contains("经营报表");
                bool receiptChrome = compact.Contains("全部收款单") && compact.Contains("已支付");
                if (!trustedTitle || (!ledgerChrome && !receiptChrome))
                {
                    reason = "OCR画面未同时通过微信记录窗口标题和页面特征校验";
                    return false;
                }
                return true;
            }
            if (!SupportedPagePattern.IsMatch(visible))
            {
                reason = "当前不是微信收款单、小账本、经营账户或收款助手记录页";
                return false;
            }
            return true;
        }

        private static void AddOrReplaceDate(List<OcrDate> values, OcrDate candidate)
        {
            OcrDate existing = values.FirstOrDefault(x => Math.Abs(x.Top - candidate.Top) <= 6);
            if (existing == null) values.Add(candidate);
            else if (candidate.Scale > existing.Scale)
            {
                existing.Month = candidate.Month;
                existing.Day = candidate.Day;
                existing.Scale = candidate.Scale;
            }
        }

        private static void AddOrReplaceAmount(List<OcrAmount> values, OcrAmount candidate)
        {
            OcrAmount existing = values.FirstOrDefault(x => Math.Abs(x.Top - candidate.Top) <= 6);
            if (existing == null) values.Add(candidate);
            else if (candidate.Scale == 1 && existing.Scale != 1)
            {
                existing.Money = candidate.Money;
                existing.Left = candidate.Left;
                existing.Scale = candidate.Scale;
            }
        }

        private static void AddOrReplaceTime(List<OcrTime> values, OcrTime candidate)
        {
            OcrTime existing = values.FirstOrDefault(x => Math.Abs(x.Top - candidate.Top) <= 6);
            if (existing == null) values.Add(candidate);
            else if (candidate.Scale > existing.Scale)
            {
                existing.Value = candidate.Value;
                existing.Left = candidate.Left;
                existing.Scale = candidate.Scale;
            }
        }

        private static bool TryRepairOcrTime(string text, out TimeSpan value)
        {
            value = TimeSpan.Zero;
            if (string.IsNullOrEmpty(text) || text.IndexOf('¥') >= 0 || text.IndexOf('￥') >= 0) return false;
            string repaired = text.Replace("℃", ":0").Replace("亍", "5:")
                .Replace('O', '0').Replace('o', '0').Replace('D', '0');
            repaired = Regex.Replace(repaired, @"[^0-9:]", string.Empty);
            if (Regex.IsMatch(repaired, @"^\d{6}$"))
                repaired = repaired.Substring(0, 2) + ":" + repaired.Substring(2, 2) + ":" + repaired.Substring(4, 2);
            else if (Regex.IsMatch(repaired, @"^\d{4}:\d{2}$"))
                repaired = repaired.Substring(0, 2) + ":" + repaired.Substring(2);
            Match match = Regex.Match(repaired, @"^(?<h>[01]\d|2[0-3]):(?<m>[0-5]\d):(?<s>[0-5]\d)$");
            if (!match.Success) return false;
            value = new TimeSpan(ReadInt(match, "h"), ReadInt(match, "m"), ReadInt(match, "s"));
            return true;
        }

        private static int ReadOcrScale(string path)
        {
            Match match = Regex.Match(path ?? string.Empty, @"^ocr:(\d+):");
            int scale;
            return match.Success && int.TryParse(match.Groups[1].Value, out scale) ? scale : 1;
        }

        private static string NormalizeOcr(string value)
        {
            return Regex.Replace(value ?? string.Empty, @"\s+", string.Empty)
                .Replace('．', '.').Replace('。', '.').Replace('：', ':');
        }

        private static Candidate CreateCandidate(string text, Match moneyMatch, DateTime capturedAt)
        {
            decimal money;
            if (!decimal.TryParse(moneyMatch.Groups["amount"].Value, NumberStyles.Number,
                CultureInfo.InvariantCulture, out money) || money <= 0 || money > 99999999m) return null;

            int start = Math.Max(0, moneyMatch.Index - 120);
            int end = Math.Min(text.Length, moneyMatch.Index + moneyMatch.Length + 120);
            string context = text.Substring(start, end - start);
            int nearStart = Math.Max(0, moneyMatch.Index - 35);
            int nearEnd = Math.Min(text.Length, moneyMatch.Index + moneyMatch.Length + 35);
            string near = text.Substring(nearStart, nearEnd - nearStart);
            int prefixStart = Math.Max(0, moneyMatch.Index - 36);
            string prefix = text.Substring(prefixStart, moneyMatch.Index - prefixStart);
            if (NegativePrefixPattern.IsMatch(prefix)) return null;
            if (NegativePattern.IsMatch(near) && Regex.IsMatch(near, @"退款\s*[¥￥+＋]?\s*$")) return null;

            Match transaction = TransactionPattern.Match(context);
            DateTime occurredAt;
            string visibleTime;
            bool hasTime = TryReadTime(context, moneyMatch.Index - start, capturedAt, out occurredAt, out visibleTime);
            if (!hasTime && !transaction.Success) return null;
            if (!hasTime) occurredAt = capturedAt;

            string transactionId = transaction.Success ? transaction.Groups[1].Value : string.Empty;
            string semanticKey = transactionId.Length > 0
                ? "transaction|" + transactionId
                : "visible|" + money.ToString("0.00", CultureInfo.InvariantCulture) + "|"
                  + occurredAt.ToString("yyyyMMddHHmmss", CultureInfo.InvariantCulture) + "|" + visibleTime;
            return new Candidate
            {
                Money = money,
                OccurredAt = occurredAt,
                Context = context,
                SemanticKey = semanticKey
            };
        }

        private static bool TryReadTime(string context, int moneyIndex, DateTime capturedAt, out DateTime value, out string visible)
        {
            Match match = FindClosestMatch(FullDatePattern, context, moneyIndex);
            if (match.Success)
            {
                visible = match.Value;
                return TryBuildDate(match, ReadInt(match, "y"), out value);
            }
            match = FindClosestMatch(MonthDayPattern, context, moneyIndex);
            if (match.Success)
            {
                visible = match.Value;
                int year = capturedAt.Year;
                if (ReadInt(match, "m") > capturedAt.Month + 1) year--;
                return TryBuildDate(match, year, out value);
            }
            match = FindClosestMatch(RelativePattern, context, moneyIndex);
            if (match.Success)
            {
                visible = match.Value;
                DateTime day = capturedAt.Date;
                if (match.Groups["day"].Value == "昨天" || match.Groups["day"].Value == "昨日") day = day.AddDays(-1);
                value = day.AddHours(ReadInt(match, "h")).AddMinutes(ReadInt(match, "n")).AddSeconds(ReadInt(match, "s"));
                return true;
            }
            match = FindClosestMatch(TimePattern, context, moneyIndex);
            if (match.Success)
            {
                Match dateHeader = FindClosestPrecedingDate(context, match.Index);
                DateTime date = capturedAt.Date;
                if (dateHeader.Success)
                {
                    int year = capturedAt.Year;
                    if (ReadInt(dateHeader, "m") > capturedAt.Month + 1) year--;
                    try { date = new DateTime(year, ReadInt(dateHeader, "m"), ReadInt(dateHeader, "d")); }
                    catch { date = capturedAt.Date; }
                    visible = dateHeader.Value + " " + match.Value;
                }
                else visible = match.Value;
                value = date.AddHours(ReadInt(match, "h")).AddMinutes(ReadInt(match, "n")).AddSeconds(ReadInt(match, "s"));
                if (value > capturedAt.AddMinutes(5)) value = value.AddDays(-1);
                return true;
            }
            value = capturedAt;
            visible = string.Empty;
            return false;
        }

        private static Match FindClosestMatch(Regex pattern, string text, int center)
        {
            Match best = Match.Empty;
            int bestDistance = int.MaxValue;
            foreach (Match match in pattern.Matches(text))
            {
                int distance = Math.Abs(match.Index - center);
                if (distance >= bestDistance) continue;
                best = match;
                bestDistance = distance;
            }
            return best;
        }

        private static Match FindClosestPrecedingDate(string text, int timeIndex)
        {
            Match best = Match.Empty;
            foreach (Match match in DateHeaderPattern.Matches(text))
            {
                if (match.Index > timeIndex) break;
                best = match;
            }
            return best;
        }

        private static bool TryBuildDate(Match match, int year, out DateTime value)
        {
            try
            {
                value = new DateTime(year, ReadInt(match, "m"), ReadInt(match, "d"),
                    ReadInt(match, "h"), ReadInt(match, "n"), ReadInt(match, "s"), DateTimeKind.Local);
                return true;
            }
            catch
            {
                value = DateTime.Now;
                return false;
            }
        }

        private static int ReadInt(Match match, string name)
        {
            int result;
            return int.TryParse(match.Groups[name].Value, out result) ? result : 0;
        }

        private static string Normalize(string value)
        {
            if (string.IsNullOrWhiteSpace(value)) return string.Empty;
            value = value.Replace("\r", "\n");
            value = Regex.Replace(value, @"[\t\f\v ]+", " ");
            value = Regex.Replace(value, @"\n+", "\n");
            return value.Trim();
        }

        private static string Sha256(string value)
        {
            using (SHA256 algorithm = SHA256.Create())
            {
                byte[] hash = algorithm.ComputeHash(Encoding.UTF8.GetBytes(value));
                var builder = new StringBuilder(hash.Length * 2);
                foreach (byte item in hash) builder.Append(item.ToString("x2"));
                return builder.ToString();
            }
        }

        private static long ToUnixTime(DateTime value)
        {
            return (long)(value.ToUniversalTime() - new DateTime(1970, 1, 1)).TotalSeconds;
        }

        private sealed class Candidate
        {
            public decimal Money;
            public DateTime OccurredAt;
            public string Context;
            public string SemanticKey;
        }

        private sealed class OcrDate
        {
            public int Month;
            public int Day;
            public double Top;
            public int Scale;
        }

        private sealed class OcrAmount
        {
            public decimal Money;
            public double Top;
            public double Left;
            public int Scale;
        }

        private sealed class OcrTime
        {
            public TimeSpan Value;
            public double Top;
            public double Left;
            public int Scale;
        }
    }
}
