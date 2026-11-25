"""Build languages/ files for Rival Price Watch.

Scans the plugin for translatable strings and writes:
  - rival-price-watch.pot          (template for translators)
  - rival-price-watch-fa_IR.po     (Persian, human-editable)
  - rival-price-watch-fa_IR.mo     (Persian, compiled)
  - rival-price-watch-fa_IR.l10n.php (Persian, fast format for WordPress 6.5+)

Run from anywhere:  python tools/build_translations.py
"""
import glob
import os
import re
import struct

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DOMAIN = 'rival-price-watch'
LANG = os.path.join(ROOT, 'languages')

FA = {
    'Rival Price Watch': 'ردیاب قیمت رقبا',
    'Track competitor prices for your WooCommerce products. Add competitor product links, get daily price checks, price history and email alerts when a rival is cheaper.':
        'قیمت رقبا را برای محصولات ووکامرس خود دنبال کنید. لینک همین محصول در سایت رقبا را اضافه کنید تا قیمت‌ها هر روز بررسی شوند، تاریخچه قیمت ذخیره شود و هر وقت رقیبی ارزان‌تر شد ایمیل بگیرید.',
    'Rival Prices': 'قیمت رقبا',
    'Open': 'باز کردن',
    'Remove': 'حذف',
    'You are not allowed to do this.': 'شما اجازه انجام این کار را ندارید.',
    'Checking started. Large catalogues are checked in small batches in the background; refresh this page in a few minutes.':
        'بررسی شروع شد. فروشگاه‌های بزرگ در دسته‌های کوچک و در پس‌زمینه بررسی می‌شوند؛ چند دقیقه دیگر این صفحه را تازه کنید.',
    'Overview': 'نمای کلی',
    'Settings': 'تنظیمات',
    'Checking in progress: %d links left.': 'در حال بررسی: %d لینک مانده است.',
    'Next automatic check: %s': 'بررسی خودکار بعدی: %s',
    'Automatic checks are off.': 'بررسی خودکار خاموش است.',
    'Check all now': 'بررسی همه، همین حالا',
    'Show all products': 'نمایش همه محصولات',
    'Only where a rival is cheaper': 'فقط جایی که رقیب ارزان‌تر است',
    'No competitor links yet': 'هنوز لینک رقیبی اضافه نشده',
    'Open any product, find the "Rival prices" box, and paste the links of the same product on competitor sites. Prices are then checked automatically.':
        'یک محصول را باز کنید، کادر «قیمت رقبا» را پیدا کنید و لینک همین محصول را در سایت رقبا در آن بگذارید. از آن به بعد قیمت‌ها خودکار بررسی می‌شوند.',
    'Product': 'محصول',
    'Your price': 'قیمت شما',
    'Cheapest rival': 'ارزان‌ترین رقیب',
    'Difference': 'اختلاف',
    'All rivals': 'همه رقبا',
    'Last checked': 'آخرین بررسی',
    'Never': 'هرگز',
    'Check now': 'بررسی همین حالا',
    'Rival %s%% cheaper': 'رقیب %s٪ ارزان‌تر',
    'You are %s%% cheaper': 'شما %s٪ ارزان‌ترید',
    'Same price': 'قیمت برابر',
    'not checked yet': 'هنوز بررسی نشده',
    'out of stock': 'ناموجود',
    'no price found': 'قیمتی پیدا نشد',
    'error': 'خطا',
    '%s ago': '%s پیش',
    'Check prices': 'بررسی قیمت‌ها',
    'Uses WP-Cron, which runs when someone visits your site. On quiet sites, set up a real server cron job for exact timing.':
        'از WP-Cron استفاده می‌شود که با بازدید از سایت اجرا می‌شود. اگر سایت بازدید کمی دارد، برای زمان‌بندی دقیق یک کران‌جاب واقعی روی سرور تنظیم کنید.',
    'Email alerts': 'هشدار ایمیلی',
    'Email me when a competitor becomes cheaper than me': 'وقتی رقیبی از من ارزان‌تر شد به من ایمیل بزن',
    'Alert email address': 'نشانی ایمیل هشدار',
    'One email per check run, listing every product that changed. You are not emailed again until the price changes.':
        'در هر نوبت بررسی فقط یک ایمیل با فهرست همه محصولات تغییرکرده فرستاده می‌شود. تا قیمت دوباره عوض نشود ایمیل تکراری نمی‌گیرید.',
    'Pause between requests': 'مکث بین درخواست‌ها',
    'seconds': 'ثانیه',
    'A pause between pages keeps checks gentle on competitor sites and lowers the chance of being blocked.':
        'مکث بین صفحه‌ها فشار کمتری به سایت رقبا می‌آورد و احتمال مسدود شدن را کم می‌کند.',
    'Keep price history for': 'نگه‌داشتن تاریخچه قیمت به مدت',
    'days': 'روز',
    'The page answered with HTTP %d.': 'صفحه با کد HTTP %d پاسخ داد.',
    'The site may be blocking automatic checks.': 'ممکن است آن سایت بررسی خودکار را مسدود کرده باشد.',
    'No price found on the page. The site does not publish price data that can be read automatically.':
        'قیمتی در صفحه پیدا نشد. این سایت داده قیمتی که به‌صورت خودکار خوانده شود منتشر نمی‌کند.',
    '- %1$s: your price %2$s, %3$s sells it for %4$s (%5$s)': '- %1$s: قیمت شما %2$s، %3$s آن را %4$s می‌فروشد (%5$s)',
    'These products are now cheaper at a competitor:': 'این محصولات اکنون نزد یک رقیب ارزان‌ترند:',
    'Rival prices': 'قیمت رقبا',
    "Paste the link of this same product on competitor sites. The price is read from the page's product data (the same data Google uses), so most shops work without any setup.":
        'لینک همین محصول در سایت رقبا را اینجا بگذارید. قیمت از داده‌های محصولِ صفحه خوانده می‌شود (همان داده‌ای که گوگل استفاده می‌کند)، پس بیشتر فروشگاه‌ها بدون هیچ تنظیمی کار می‌کنند.',
    'Competitor product link': 'لینک محصول رقیب',
    'Name (optional)': 'نام (اختیاری)',
    'The competitor price is multiplied by this number. Use it when the site shows prices in another unit, for example 0.1 to turn Iranian rials into tomans.':
        'قیمت رقیب در این عدد ضرب می‌شود. وقتی آن سایت قیمت را با واحد دیگری نشان می‌دهد از آن استفاده کنید؛ مثلاً 0.1 برای تبدیل ریال به تومان.',
    'Price × ': 'قیمت × ',
    '+ Add competitor': '+ افزودن رقیب',
    'Latest prices': 'آخرین قیمت‌ها',
    'Save the product first if you changed the links.': 'اگر لینک‌ها را تغییر داده‌اید، اول محصول را به‌روزرسانی کنید.',
    'Price history': 'تاریخچه قیمت',
    'Twice a day': 'روزی دو بار',
    'Once a day': 'روزی یک بار',
    'Once a week': 'هفته‌ای یک بار',
    'Only when I click "Check now"': 'فقط وقتی «بررسی همین حالا» را بزنم',
    'Rival Price Watch needs WooCommerce to be installed and active.': 'افزونه ردیاب قیمت رقبا به ووکامرس نصب‌شده و فعال نیاز دارد.',
    'Prices by market': 'قیمت‌ها به تفکیک بازار',
    'Rivals are grouped by the currency they sell in and compared in that currency. Set your own price for each foreign market; without it, your shop price is converted with your exchange rates (≈).':
        'رقبا بر اساس واحد پولی که با آن می‌فروشند گروه‌بندی و در همان واحد پول مقایسه می‌شوند. برای هر بازار خارجی قیمت خودتان را وارد کنید؛ در غیر این صورت قیمت فروشگاه با نرخ تبدیل شما محاسبه می‌شود (≈).',
    'Your price:': 'قیمت شما:',
    'shop price': 'قیمت فروشگاه',
    'Your price in %s:': 'قیمت شما به %s:',
    '(empty: converted from your shop price)': '(خالی: از قیمت فروشگاه تبدیل شده)',
    '(set a price or an exchange rate to compare)': '(برای مقایسه، قیمت یا نرخ تبدیل وارد کنید)',
    'Market': 'بازار',
    'All markets (converted to shop currency)': 'همه بازارها (تبدیل به واحد پول فروشگاه)',
    # Currencies.
    'Currency': 'واحد پول',
    'Auto-detect': 'تشخیص خودکار',
    'Which currency this site sells in. Auto-detect reads it from the page; if the page does not say, the link is not compared until you choose one here.':
        'این سایت با چه واحد پولی می‌فروشد. «تشخیص خودکار» آن را از صفحه می‌خواند؛ اگر صفحه چیزی نگوید، تا وقتی واحد پول را اینجا انتخاب نکنید این لینک مقایسه نمی‌شود.',
    'The competitor price is multiplied by this number before comparing, for example 1.1 if the site shows prices without a 10% tax. Leave it at 1 in most cases.':
        'قیمت رقیب پیش از مقایسه در این عدد ضرب می‌شود؛ مثلاً 1.1 اگر آن سایت قیمت را بدون ۱۰٪ مالیات نشان می‌دهد. در بیشتر موارد آن را 1 بگذارید.',
    'This site sells in %1$s. Add a %1$s to %2$s exchange rate in Settings to compare it.':
        'این سایت با %1$s می‌فروشد. برای مقایسه، نرخ تبدیل %1$s به %2$s را در تنظیمات وارد کنید.',
    'needs an exchange rate': 'نرخ تبدیل لازم است',
    'Exchange rates': 'نرخ تبدیل ارز',
    'Rival prices in another currency are converted to your shop currency (%s) before comparing. Fill in only the currencies your competitors use; leave the rest empty.':
        'قیمت رقبایی که با ارز دیگری می‌فروشند، پیش از مقایسه به واحد پول فروشگاه شما (%s) تبدیل می‌شود. فقط ارزهایی را که رقبایتان استفاده می‌کنند پر کنید و بقیه را خالی بگذارید.',
    'Rial and toman are converted automatically (1 toman = 10 rials).': 'ریال و تومان خودکار به هم تبدیل می‌شوند (هر تومان = ۱۰ ریال).',
    'Official rates downloaded on %s.': 'نرخ‌های رسمی در %s دریافت شد.',
    'Last saved on %s.': 'آخرین ذخیره: %s',
    "Fill in today's official rates": 'پر کردن با نرخ رسمی امروز',
    'From the free open.er-api.com service. Only sent when you click.': 'از سرویس رایگان open.er-api.com. فقط وقتی کلیک کنید درخواست فرستاده می‌شود.',
    'Your shop uses the rial or toman. Official rates are far from the free-market rate, so enter the market rate for each currency yourself.':
        'فروشگاه شما با ریال یا تومان کار می‌کند. نرخ رسمی با نرخ بازار آزاد فاصله زیادی دارد، پس نرخ بازار هر ارز را خودتان وارد کنید.',
    'Save exchange rates': 'ذخیره نرخ‌های تبدیل',
    'Exchange rates updated.': 'نرخ‌های تبدیل به‌روز شد.',
    'Exchange rates saved.': 'نرخ‌های تبدیل ذخیره شد.',
    'Could not download exchange rates.': 'دریافت نرخ‌های تبدیل ممکن نشد.',
    'The exchange-rate service returned no rates.': 'سرویس نرخ ارز هیچ نرخی برنگرداند.',
    'The exchange-rate service does not know your shop currency (%s). Enter rates by hand.':
        'سرویس نرخ ارز واحد پول فروشگاه شما (%s) را نمی‌شناسد. نرخ‌ها را دستی وارد کنید.',
    'Automatic rates are official rates, which are far from the free-market rate for the rial and toman. Enter your rates by hand.':
        'نرخ‌های خودکار نرخ رسمی هستند که برای ریال و تومان با نرخ بازار آزاد فاصله زیادی دارند. نرخ‌ها را دستی وارد کنید.',
    'US dollar': 'دلار آمریکا',
    'Euro': 'یورو',
    'British pound': 'پوند انگلیس',
    'Japanese yen': 'ین ژاپن',
    'Chinese yuan': 'یوان چین',
    'Swiss franc': 'فرانک سوئیس',
    'Canadian dollar': 'دلار کانادا',
    'Australian dollar': 'دلار استرالیا',
    'New Zealand dollar': 'دلار نیوزیلند',
    'Hong Kong dollar': 'دلار هنگ‌کنگ',
    'Singapore dollar': 'دلار سنگاپور',
    'South Korean won': 'وون کره جنوبی',
    'Indian rupee': 'روپیه هند',
    'Pakistani rupee': 'روپیه پاکستان',
    'Afghan afghani': 'افغانی افغانستان',
    'Iranian rial': 'ریال ایران',
    'Iranian toman': 'تومان ایران',
    'Iraqi dinar': 'دینار عراق',
    'UAE dirham': 'درهم امارات',
    'Saudi riyal': 'ریال عربستان',
    'Qatari riyal': 'ریال قطر',
    'Kuwaiti dinar': 'دینار کویت',
    'Bahraini dinar': 'دینار بحرین',
    'Omani rial': 'ریال عمان',
    'Turkish lira': 'لیر ترکیه',
    'Azerbaijani manat': 'منات آذربایجان',
    'Armenian dram': 'درام ارمنستان',
    'Georgian lari': 'لاری گرجستان',
    'Russian ruble': 'روبل روسیه',
    'Ukrainian hryvnia': 'گریونا اوکراین',
    'Israeli shekel': 'شکل اسرائیل',
    'Egyptian pound': 'پوند مصر',
    'Swedish krona': 'کرون سوئد',
    'Norwegian krone': 'کرون نروژ',
    'Danish krone': 'کرون دانمارک',
    'Polish zloty': 'زلوتی لهستان',
    'Czech koruna': 'کرون چک',
    'Hungarian forint': 'فورینت مجارستان',
    'Malaysian ringgit': 'رینگیت مالزی',
    'Thai baht': 'بات تایلند',
    'Indonesian rupiah': 'روپیه اندونزی',
    'Philippine peso': 'پزو فیلیپین',
    'Brazilian real': 'رئال برزیل',
    'Mexican peso': 'پزو مکزیک',
    'South African rand': 'راند آفریقای جنوبی',
    'None of the %2$d offers on the page matches "%1$s". Check the variant text for this link.':
        'هیچ\u200cکدام از %2$d پیشنهاد این صفحه با «%1$s» جور نیست. متن «مدل» این لینک را بررسی کنید.',
    'The alert email address in Settings is not valid.':
        'نشانی ایمیل هشدار در تنظیمات معتبر نیست.',
    'WordPress could not send the email.':
        'وردپرس نتوانست ایمیل را بفرستد.',
    'Only needed when the competitor page lists several versions of the product. Type the SKU or barcode of the right one, or words that describe it, for example: 256GB black. Leave empty to use the first product on the page.':
        'فقط وقتی لازم است که صفحه رقیب چند مدل از محصول را نشان می\u200cدهد. شناسه (SKU) یا بارکد مدل درست را بنویسید، یا چند کلمه که آن را مشخص کند، مثلاً: 256GB black. اگر خالی بماند، اولین محصول صفحه خوانده می\u200cشود.',
    'Variant (optional)':
        'مدل (اختیاری)',
    'Use "Test" to see what is read from a link before you save.':
        'پیش از ذخیره، با «آزمایش» ببینید از هر لینک چه چیزی خوانده می\u200cشود.',
    'Test':
        'آزمایش',
    'Reading the page…':
        'در حال خواندن صفحه…',
    'The test could not be run. Please try again.':
        'آزمایش انجام نشد. دوباره تلاش کنید.',
    'Paste a link first.':
        'اول یک لینک وارد کنید.',
    'This is not a link that can be checked.':
        'این لینک قابل بررسی نیست.',
    'Price: %s':
        'قیمت: %s',
    'In your currency: %s':
        'به واحد پول شما: %s',
    'not stated on the page':
        'در صفحه نوشته نشده',
    'in stock':
        'موجود',
    'Stock: %s':
        'موجودی: %s',
    'Product on the page: %s':
        'محصول در صفحه: %s',
    'Read from: %s':
        'خوانده\u200cشده از: %s',
    'This page lists %d offers or versions. The cheapest one in stock of the first product was used. Fill in "Variant" to pick the right one.':
        'این صفحه %d پیشنهاد یا مدل دارد. ارزان\u200cترین مدل موجودِ اولین محصول انتخاب شد. برای انتخاب مدل درست، خانه «مدل» را پر کنید.',
    'This page does not describe its versions in a way that can be read, so the variant text could not be checked.':
        'این صفحه مدل\u200cهایش را به شکلی که قابل خواندن باشد معرفی نکرده، پس متن «مدل» بررسی نشد.',
    'The last alert email could not be sent (%s). The alerts are kept and sending will be tried again.':
        'آخرین ایمیل هشدار فرستاده نشد (%s). هشدارها نگه داشته شده\u200cاند و دوباره برای ارسال تلاش می\u200cشود.',
    'variant not found':
        'مدل پیدا نشد',
    'currency unknown':
        'واحد پول نامشخص',
    'The page shows the price %s but does not say which currency it is in. Choose the currency for this link to compare it.':
        'صفحه قیمت %s را نشان می‌دهد اما نمی‌گوید به چه واحد پولی است. برای مقایسه، واحد پول این لینک را انتخاب کنید.',
    'Only when the competitor is cheaper by at least':
        'فقط وقتی رقیب دست\u200cکم این\u200cقدر ارزان\u200cتر است:',
    'variant not confirmed':
        'مدل تأیید نشد',
    'settings changed, waiting for a new check':
        'تنظیمات تغییر کرد، در انتظار بررسی دوباره',
    'A price was found, but the page does not show that it belongs to "%s", so it is not compared. Clear the variant text if this page only sells one version.':
        'قیمتی پیدا شد، اما صفحه نشان نمی\u200cدهد که مربوط به «%s» است، پس مقایسه نمی\u200cشود. اگر این صفحه فقط یک مدل می\u200cفروشد، خانه «مدل» را خالی کنید.',
    'The link settings were changed. The price is read again at the next check.':
        'تنظیمات این لینک تغییر کرد. قیمت در بررسی بعدی دوباره خوانده می\u200cشود.',
}
FA_PLURAL = {
    ('%d more is being checked in the background; refresh in a minute.', '%d more are being checked in the background; refresh in a minute.'):
        '%d لینک دیگر در پس‌زمینه بررسی می‌شود؛ یک دقیقه دیگر صفحه را تازه کنید.',
    ('Checked %d competitor link.', 'Checked %d competitor links.'): 'تعداد %d لینک رقیب بررسی شد.',
    ('Rival Price Watch: a competitor is cheaper on %d product', 'Rival Price Watch: competitors are cheaper on %d products'):
        'ردیاب قیمت رقبا: رقبا در %d محصول ارزان‌ترند',
}

HEADER_STRINGS = [
    'Rival Price Watch',
    'Track competitor prices for your WooCommerce products. Add competitor product links, get daily price checks, price history and email alerts when a rival is cheaper.',
]


def scan():
    q = r"'((?:[^'\\]|\\.)*)'"
    single = re.compile(r"\b(?:__|_e|esc_html__|esc_html_e|esc_attr__|esc_attr_e)\(\s*" + q + r"\s*,\s*'" + DOMAIN + "'")
    plural = re.compile(r"\b_n\(\s*" + q + r"\s*,\s*" + q + r"\s*,")
    s, p = list(HEADER_STRINGS), []
    for f in sorted(glob.glob(os.path.join(ROOT, '**', '*.php'), recursive=True)):
        if os.sep + 'languages' + os.sep in f or os.sep + 'tests' + os.sep in f:
            continue
        text = open(f, encoding='utf-8').read()
        for m in single.finditer(text):
            v = m.group(1).replace("\\'", "'")
            if v not in s:
                s.append(v)
        for m in plural.finditer(text):
            v = (m.group(1).replace("\\'", "'"), m.group(2).replace("\\'", "'"))
            if v not in p:
                p.append(v)
    return s, p


def po_str(v):
    return '"' + v.replace('\\', '\\\\').replace('"', '\\"').replace('\n', '\\n') + '"'


def php_str(v):
    return "'" + v.replace('\\', '\\\\').replace("'", "\\'") + "'"


def write_po(path, s, p, tr, trp, lang):
    out = ['msgid ""', 'msgstr ""',
           '"Project-Id-Version: Rival Price Watch 0.2.5\\n"',
           '"MIME-Version: 1.0\\n"',
           '"Content-Type: text/plain; charset=UTF-8\\n"',
           '"Content-Transfer-Encoding: 8bit\\n"']
    if lang:
        out += ['"Language: %s\\n"' % lang, '"Plural-Forms: nplurals=1; plural=0;\\n"']
    else:
        out += ['"Plural-Forms: nplurals=INTEGER; plural=EXPRESSION;\\n"']
    out.append('"X-Domain: %s\\n"' % DOMAIN)
    out.append('')
    for v in s:
        if '%' in v:
            out.append('#, php-format')
        out += ['msgid ' + po_str(v), 'msgstr ' + po_str(tr.get(v, '') if lang else ''), '']
    for a, b in p:
        out += ['#, php-format', 'msgid ' + po_str(a), 'msgid_plural ' + po_str(b)]
        if lang:
            out.append('msgstr[0] ' + po_str(trp.get((a, b), '')))
        else:
            out += ['msgstr[0] ""', 'msgstr[1] ""']
        out.append('')
    open(path, 'w', encoding='utf-8', newline='\n').write('\n'.join(out))


def write_mo(path, pairs):
    """pairs: list of (msgid bytes, msgstr bytes), header included with empty msgid."""
    pairs = sorted(pairs, key=lambda x: x[0])
    n = len(pairs)
    ids = b''
    strs = b''
    id_tab = []
    str_tab = []
    for k, v in pairs:
        id_tab.append((len(k), len(ids)))
        ids += k + b'\0'
        str_tab.append((len(v), len(strs)))
        strs += v + b'\0'
    start = 7 * 4
    ids_off = start + n * 16
    data_off = ids_off
    id_start = data_off
    str_start = id_start + len(ids)
    out = struct.pack('<7I', 0x950412de, 0, n, start, start + n * 8, 0, 0)
    for l, o in id_tab:
        out += struct.pack('<2I', l, id_start + o)
    for l, o in str_tab:
        out += struct.pack('<2I', l, str_start + o)
    out += ids + strs
    open(path, 'wb').write(out)


def main():
    os.makedirs(LANG, exist_ok=True)
    s, p = scan()
    missing = [v for v in s if v not in FA] + [str(v) for v in p if v not in FA_PLURAL]
    write_po(os.path.join(LANG, DOMAIN + '.pot'), s, p, {}, {}, None)
    write_po(os.path.join(LANG, DOMAIN + '-fa_IR.po'), s, p, FA, FA_PLURAL, 'fa_IR')

    header = 'Content-Type: text/plain; charset=UTF-8\nLanguage: fa_IR\nPlural-Forms: nplurals=1; plural=0;\n'
    pairs = [(b'', header.encode())]
    messages = {}
    for v in s:
        if v in FA:
            pairs.append((v.encode(), FA[v].encode()))
            messages[v] = FA[v]
    for a, b in p:
        if (a, b) in FA_PLURAL:
            pairs.append(((a + '\0' + b).encode(), FA_PLURAL[(a, b)].encode()))
            messages[a + '\0' + b] = FA_PLURAL[(a, b)]
    write_mo(os.path.join(LANG, DOMAIN + '-fa_IR.mo'), pairs)

    lines = ['<?php', "return ['domain'=>'%s','plural-forms'=>'nplurals=1; plural=0;','language'=>'fa_IR','messages'=>[" % DOMAIN]
    for k, v in messages.items():
        key = php_str(k).replace('\0', "'.\"\\0\".'") if '\0' in k else php_str(k)
        lines.append('%s=>%s,' % (key, php_str(v)))
    lines.append(']];')
    open(os.path.join(LANG, DOMAIN + '-fa_IR.l10n.php'), 'w', encoding='utf-8', newline='\n').write('\n'.join(lines) + '\n')

    print('strings:', len(s), 'plurals:', len(p), 'translated:', len(messages))
    if missing:
        print('MISSING TRANSLATIONS:')
        for m in missing:
            print('  ', m)


if __name__ == '__main__':
    main()
