<?php
/**
 * Vendor-area PREVIEW — DEVELOPMENT ONLY, NOT part of the plugin or the ZIP.
 *
 * The vendor dashboard does not exist yet. This renders how it is proposed to
 * look, so the design can be rejected or accepted BEFORE anything is built
 * (owner's instruction: preview first, desktop and mobile).
 *
 * Two things this file is careful about:
 *  1. It is NOT wp-admin. The vendor area has its own front-end shell and its
 *     own route (/vendor/...), which is the whole point of showing it.
 *  2. Every value on the page is invented. Each one carries a «نمونه» marker
 *     and the page carries a banner, so no screenshot of this can ever be
 *     mistaken for real data or for a working feature.
 */
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$outDir = $root . '/docs/evidence/preview';
@mkdir($outDir, 0o755, true);

$css = 'file://' . $root . '/assets/admin/tmc-admin.css';

/** A value that does not exist yet, shown so the layout can be judged. */
function sample(string $text): string
{
    return '<span class="tv-sample" title="نمونه">' . htmlspecialchars($text, ENT_QUOTES) . '<span class="tv-sample__tag">نمونه</span></span>';
}

function page(string $title, string $current, string $body): string
{
    $nav = [
        'dashboard' => 'پیشخوان',
        'products' => 'محصولات',
        'orders' => 'سفارش‌ها',
        'staff' => 'پرسنل',
        'documents' => 'مدارک',
        'settings' => 'تنظیمات فروشگاه',
    ];
    $soon = ['products', 'orders'];
    $items = '';
    foreach ($nav as $slug => $label) {
        $isCurrent = $slug === $current;
        $items .= '<li><a class="tv-nav__link' . ($isCurrent ? ' is-current' : '') . '" href="#"'
            . ($isCurrent ? ' aria-current="page"' : '') . '>' . $label
            . (in_array($slug, $soon, true) ? ' <span class="tv-soon">ساخته نشده</span>' : '')
            . '</a></li>';
    }
    global $css;
    return <<<HTML
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{$title} — پیش‌نمایش داشبورد فروشنده</title>
<link rel="stylesheet" href="{$css}">
<style>
/* Preview-only shell. The vendor area is NOT wp-admin: it has its own route
   (/vendor/...), its own header and its own navigation. Tokens come from the
   plugin stylesheet above so the two areas stay one product. */
body { margin: 0; background: #EDF2F5; }
.tv { font-family: var(--tmc-font); color: var(--tmc-text); }
.tv-banner { background: #143C4D; color: #fff; padding: 10px 16px; font-weight: 700; text-align: center; font-size: 0.9375rem; }
.tv-banner span { font-weight: 400; opacity: 0.9; }
.tv-url { background: #0F2E3B; color: #BFE3F4; font-family: var(--tmc-font-mono); font-size: 0.8125rem; padding: 6px 16px; direction: ltr; text-align: left; }
.tv-head { background: var(--tmc-header); color: var(--tmc-primary); padding: 16px; display: grid; gap: 12px; }
.tv-head__row { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; }
.tv-brand { font-weight: 700; font-size: 1.25rem; }
.tv-brand small { display: block; font-size: 0.875rem; font-weight: 600; opacity: 0.85; }
.tv-store { display: flex; align-items: center; gap: 8px; background: rgba(255,255,255,0.72); border-radius: 999px; padding: 6px 14px; font-weight: 600; }
.tv-nav ul { list-style: none; margin: 0; padding: 0; display: grid; grid-template-columns: repeat(2, minmax(0,1fr)); gap: 8px; }
.tv-nav__link { display: flex; align-items: center; gap: 6px; min-height: 44px; padding: 0 14px; border-radius: 999px; background: rgba(255,255,255,0.72); color: var(--tmc-primary); text-decoration: none; font-weight: 600; }
.tv-nav__link.is-current { background: var(--tmc-primary); color: #fff; }
.tv-soon { font-size: 0.75rem; font-weight: 600; background: rgba(20,60,77,0.12); border-radius: 999px; padding: 1px 8px; }
.tv-nav__link.is-current .tv-soon { background: rgba(255,255,255,0.25); }
.tv-main { padding: 16px; max-width: 1200px; margin-inline: auto; }
.tv-sample { background: #FFF4D8; border-bottom: 1px dashed #C9A23A; padding: 0 2px; }
.tv-sample__tag { font-size: 0.6875rem; color: #6B5314; margin-inline-start: 4px; vertical-align: 2px; }
.tv-grid { display: grid; gap: 16px; grid-template-columns: 1fr; }
.tv-foot { color: var(--tmc-muted); font-size: 0.875rem; padding: 16px; max-width: 1200px; margin-inline: auto; }
.tv-steps { display: flex; flex-wrap: wrap; gap: 8px; list-style: none; margin: 0 0 16px; padding: 0; }
.tv-steps li { border: 1px solid var(--tmc-border); border-radius: 999px; padding: 4px 14px; background: #fff; font-size: 0.9375rem; }
.tv-steps li[aria-current] { background: var(--tmc-primary); color: #fff; border-color: var(--tmc-primary); font-weight: 700; }
.tv-blocked { border: 2px dashed var(--tmc-warning); border-radius: var(--tmc-radius); padding: 16px; background: #FFFBF2; }
@media (min-width: 860px) {
  .tv-nav ul { display: flex; flex-wrap: wrap; }
  .tv-grid--2 { grid-template-columns: 1.4fr 1fr; }
}
</style>
</head>
<body class="tv">
<div class="tv-banner">پیش‌نمایش طراحی — هنوز ساخته نشده است<br><span>همه اعداد، نام‌ها و وضعیت‌های این صفحه ساختگی‌اند و با برچسب «نمونه» مشخص شده‌اند.</span></div>
<div class="tv-url">https://tecteb.example/vendor/{$current}/ &nbsp;— مسیر اختصاصی فروشنده، جدا از /wp-admin/</div>
<header class="tv-head">
  <div class="tv-head__row">
    <div class="tv-brand">بازارگاه تک‌طب<small>داشبورد فروشنده</small></div>
    <div class="tv-store">داروخانه {$title} <span class="tv-sample__tag">نمونه</span></div>
  </div>
  <nav class="tv-nav" aria-label="بخش‌های فروشنده"><ul>{$items}</ul></nav>
</header>
<main class="tv-main tmc-admin" style="background:transparent;padding:0;max-width:none">
{$body}
</main>
<p class="tv-foot">نمونه رابط (خارج WordPress) — این تصویر اثبات کارکرد نیست. طرح: docs/phase-2-vendor-plan.md</p>
</body>
</html>
HTML;
}

// ---------------------------------------------------------------- dashboard
$storeName = 'شفا';
$dashboard = <<<'HTML'
<div class="tv-grid tv-grid--2">
  <section class="tmc-card" aria-labelledby="p-state">
    <div class="tmc-module__head">
      <h2 id="p-state" class="tmc-card__title">وضعیت فروشگاه شما</h2>
      <span class="tmc-status tmc-status--warning"><span class="tmc-status__icon" aria-hidden="true">!</span><span class="tmc-status__text">در بررسی</span></span>
    </div>
    <p>درخواست شما در تاریخ {$s1} ثبت شد و در نوبت بررسی مدیر بازارگاه است. تا اعلام نتیجه، می‌توانید پروفایل و پرسنل را کامل کنید؛ فروش هنوز باز نشده است.</p>
    <dl class="tmc-datalist">
      <div class="tmc-datalist__row"><dt>اجازه فروش</dt><dd>هنوز صادر نشده</dd></div>
      <div class="tmc-datalist__row"><dt>اجازه انتشار مستقیم محصول</dt><dd>هنوز صادر نشده</dd></div>
      <div class="tmc-datalist__row"><dt>آخرین به‌روزرسانی</dt><dd>{$s2}</dd></div>
    </dl>
  </section>

  <section class="tmc-card" aria-labelledby="p-todo">
    <h2 id="p-todo" class="tmc-card__title">کارهای باز</h2>
    <ul class="tmc-list">
      <li class="tmc-list__item"><span class="tmc-list__name">تکمیل نشانی و تماس عمومی</span><span class="tmc-status tmc-status--warning"><span class="tmc-status__icon" aria-hidden="true">!</span><span class="tmc-status__text">ناقص</span></span></li>
      <li class="tmc-list__item"><span class="tmc-list__name">بارگذاری مدارک</span><span class="tmc-status tmc-status--neutral"><span class="tmc-status__icon" aria-hidden="true">…</span><span class="tmc-status__text">فهرست تعریف نشده</span></span><span class="tmc-list__reason">مدیر بازارگاه هنوز تعیین نکرده چه مدارکی لازم است. تا آن زمان چیزی از شما خواسته نمی‌شود.</span></li>
      <li class="tmc-list__item"><span class="tmc-list__name">تأیید شماره موبایل</span><span class="tmc-status tmc-status--neutral"><span class="tmc-status__icon" aria-hidden="true">…</span><span class="tmc-status__text">در دسترس نیست</span></span><span class="tmc-list__reason">سرویس پیامک هنوز به بازارگاه وصل نشده است. شماره شما ثبت شده اما تأیید نشده.</span></li>
    </ul>
  </section>
</div>

<div class="tv-grid tv-grid--2" style="margin-top:16px">
  <section class="tmc-card" aria-labelledby="p-staff">
    <h2 id="p-staff" class="tmc-card__title">پرسنل</h2>
    <p>{$s3} نفر از سقف {$s4} نفر.</p>
    <ul class="tmc-list">
      <li class="tmc-list__item"><span class="tmc-list__name">{$s5}</span><span class="tmc-status tmc-status--success"><span class="tmc-status__icon" aria-hidden="true">✓</span><span class="tmc-status__text">فعال</span></span><span class="tmc-list__reason">نقش: مدیر فروشگاه</span></li>
      <li class="tmc-list__item"><span class="tmc-list__name">{$s6}</span><span class="tmc-status tmc-status--warning"><span class="tmc-status__icon" aria-hidden="true">!</span><span class="tmc-status__text">در انتظار فعال‌سازی</span></span><span class="tmc-list__reason">دعوت ساخته شد اما ارسال نشد: سرویس پیامک وصل نیست.</span></li>
    </ul>
    <div class="tmc-actions"><a class="tmc-button tmc-button--primary" href="#">افزودن پرسنل</a></div>
  </section>

  <section class="tmc-card tmc-card--muted" aria-labelledby="p-notyet">
    <h2 id="p-notyet" class="tmc-card__title">آنچه هنوز در داشبورد نیست</h2>
    <p>فروش، سفارش، موجودی، کمیسیون و تسویه هنوز ساخته نشده‌اند. به‌جای نمایش صفر یا نمودار خالی، این بخش‌ها اصلاً نشان داده نمی‌شوند تا با داده واقعی اشتباه نشوند.</p>
  </section>
</div>
HTML;

// ------------------------------------------------------------- application
$application = <<<'HTML'
<ol class="tv-steps">
  <li>۱. اطلاعات پایه</li>
  <li>۲. نشانی و تماس</li>
  <li aria-current="step">۳. مدارک</li>
  <li>۴. بازبینی و ارسال</li>
</ol>

<div class="tv-grid tv-grid--2">
  <section class="tmc-card" aria-labelledby="a-docs">
    <h2 id="a-docs" class="tmc-card__title">مدارک</h2>
    <div class="tv-blocked">
      <p><strong>مدیر بازارگاه هنوز فهرست مدارک را تعریف نکرده است.</strong></p>
      <p>تا وقتی این فهرست تعریف نشود، هیچ مدرکی از شما خواسته نمی‌شود و هیچ درخواستی هم به‌صورت خودکار تأیید نمی‌شود. می‌توانید بقیه فرم را پر کنید و پیش‌نویس را ذخیره کنید؛ دکمه ارسال تا تعریف فهرست غیرفعال می‌ماند.</p>
      <p class="tmc-card__note">وضعیت این درخواست: پیش‌نویس · شناسه: {$s7}</p>
    </div>
    <div class="tmc-actions">
      <a class="tmc-button tmc-button--primary" href="#">ذخیره پیش‌نویس</a>
      <span class="tmc-button tmc-button--secondary" aria-disabled="true" style="opacity:.55;cursor:not-allowed">ارسال برای بررسی</span>
    </div>
    <p class="tmc-field__desc">«ارسال برای بررسی» غیرفعال است چون فهرست مدارک تعریف نشده. این یک خطا نیست؛ وقتی مدیر فهرست را تعریف کند، همین‌جا فعال می‌شود.</p>
  </section>

  <section class="tmc-card" aria-labelledby="a-identity">
    <h2 id="a-identity" class="tmc-card__title">هویت و تماس</h2>
    <div class="tmc-field">
      <label class="tmc-field__label" for="a-mail">ایمیل (اجباری)</label>
      <div class="tmc-field__control"><input class="tmc-input" id="a-mail" type="email" dir="ltr" value="pharmacy@example.test" readonly></div>
      <p class="tmc-field__desc">همان ایمیل حساب کاربری شما در تک‌طب.</p>
    </div>
    <div class="tmc-field">
      <label class="tmc-field__label" for="a-mobile">موبایل (اجباری)</label>
      <div class="tmc-field__control">
        <input class="tmc-input" id="a-mobile" type="tel" dir="ltr" value="09120000000" readonly>
        <span class="tmc-status tmc-status--neutral"><span class="tmc-status__icon" aria-hidden="true">…</span><span class="tmc-status__text">ثبت‌شده، تأییدنشده</span></span>
      </div>
      <p class="tmc-field__desc">ثبت شماره به‌معنای تأیید آن نیست. تأیید با کد یکبارمصرف انجام می‌شود و سرویس پیامک هنوز به بازارگاه وصل نشده است؛ تا آن زمان این شماره «تأییدنشده» می‌ماند و هیچ دسترسی‌ای بر پایه آن داده نمی‌شود.</p>
      <div class="tmc-actions"><span class="tmc-button tmc-button--secondary" aria-disabled="true" style="opacity:.55;cursor:not-allowed">ارسال کد تأیید</span></div>
    </div>
    <p class="tmc-card__note">ورود شما به حساب، مثل همیشه از همان صفحه ورود تک‌طب انجام می‌شود. این بخش جایگزین ورود نیست.</p>
  </section>
</div>
HTML;

$s = [
    'dash' => strtr($dashboard, [
        '{$s1}' => sample('۲۰ شهریور ۱۴۰۵'), '{$s2}' => sample('۲۱ شهریور ۱۴۰۵'),
        '{$s3}' => sample('۲'), '{$s4}' => sample('۱۰'),
        '{$s5}' => sample('م. رضایی'), '{$s6}' => sample('ن. کاظمی'),
    ]),
    'app' => strtr($application, ['{$s7}' => sample('VA-1042')]),
];

file_put_contents($outDir . '/vendor-dashboard.html', page($storeName, 'dashboard', $s['dash']));
file_put_contents($outDir . '/vendor-application.html', page($storeName, 'documents', $s['app']));
echo "wrote:\n  {$outDir}/vendor-dashboard.html\n  {$outDir}/vendor-application.html\n";
