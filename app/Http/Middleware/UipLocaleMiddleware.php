<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ⚠️ فرق تصميم متعمّد عن القديم — Stateless.
 *
 * القديم (app/Middleware/LocaleMiddleware.php) كان بيقرا ?lang=en|ar،
 * يخزّنه في الـ PHP session، وبعد كده بيرجع لقيمة الـ session المخزّنة
 * لو الريكوست مبعتش ?lang. الـ API الجديد Stateless بالكامل (Bearer JWT،
 * مفيش session cookie خالص) فمفيش حاجة يتخزن فيها locale بين الريكوستس.
 *
 * البديل هنا: كل ريكوست لازم يبعت لغته بنفسه — إما ?lang=en|ar أو هيدر
 * Accept-Language — وإلا بيرجع لـ config('app.locale'). الفرونت (React
 * SPA) هو المسؤول عن تخزين اختيار المستخدم للغة (localStorage مثلًا)
 * وبعتها مع كل ريكوست، بدل ما السيرفر يفتكرها. App::setLocale() هنا
 * بيتحط لمدة الريكوست بس (Laravel بيعمل reset لكل ريكوست جديد أصلًا).
 */
class UipLocaleMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $requested = $request->query('lang');
        $available = array_keys((array) config('languages', ['en' => [], 'ar' => []]));

        $locale = ($requested && in_array($requested, $available, true))
            ? $requested
            : (string) config('app.locale', 'en');

        app()->setLocale($locale);

        return $next($request);
    }
}
