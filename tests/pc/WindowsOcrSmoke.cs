using System;
using System.IO;
using System.Runtime.InteropServices.WindowsRuntime;
using System.Text.RegularExpressions;
using System.Threading.Tasks;
using Windows.Globalization;
using Windows.Graphics.Imaging;
using Windows.Media.Ocr;
using Windows.Storage.Streams;

internal static class WindowsOcrSmoke
{
    private static void Main(string[] args)
    {
        try
        {
            if (args.Length != 1) throw new ArgumentException("请提供PNG图片路径");
            RunAsync(args[0]).GetAwaiter().GetResult();
        }
        catch (Exception ex)
        {
            Console.Error.WriteLine("OCR_ERROR_TYPE=" + ex.GetType().FullName);
            Console.Error.WriteLine("OCR_ERROR_MESSAGE=" + ex.Message);
            if (ex.InnerException != null)
            {
                Console.Error.WriteLine("OCR_INNER_TYPE=" + ex.InnerException.GetType().FullName);
                Console.Error.WriteLine("OCR_INNER_MESSAGE=" + ex.InnerException.Message);
            }
            Environment.Exit(1);
        }
    }

    private static async Task RunAsync(string path)
    {
        OcrEngine engine = OcrEngine.TryCreateFromLanguage(new Language("zh-CN"));
        Console.WriteLine("OCR_STEP=engine");
        if (engine == null) throw new InvalidOperationException("Windows中文OCR引擎不可用");
        using (var randomAccess = new InMemoryRandomAccessStream())
        {
            Stream output = randomAccess.AsStreamForWrite();
            using (FileStream input = File.OpenRead(path))
            {
                await input.CopyToAsync(output);
                await output.FlushAsync();
            }
            randomAccess.Seek(0);
            BitmapDecoder decoder = await BitmapDecoder.CreateAsync(randomAccess);
            Console.WriteLine("OCR_STEP=decoder");
            uint scale = 3;
            var transform = new BitmapTransform
            {
                ScaledWidth = decoder.PixelWidth * scale,
                ScaledHeight = decoder.PixelHeight * scale,
                InterpolationMode = BitmapInterpolationMode.Cubic
            };
            SoftwareBitmap bitmap = await decoder.GetSoftwareBitmapAsync(
                BitmapPixelFormat.Bgra8, BitmapAlphaMode.Premultiplied, transform,
                ExifOrientationMode.IgnoreExifOrientation, ColorManagementMode.DoNotColorManage);
            Console.WriteLine("OCR_BITMAP=" + bitmap.PixelWidth + "x" + bitmap.PixelHeight);
            OcrResult result = await engine.RecognizeAsync(bitmap);
            Console.WriteLine("OCR_STEP=recognized");
            string text = result.Text ?? string.Empty;
            Console.WriteLine("WindowsOCR/字符=" + text.Length + "/行=" + result.Lines.Count
                + "/小账本=" + text.Contains("收款小账本")
                + "/收款记录=" + text.Contains("收款记录")
                + "/金额=" + Regex.IsMatch(text, "[¥￥]\\s*\\d"));
            output.Dispose();
        }
    }
}
