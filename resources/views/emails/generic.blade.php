<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $locale === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>{{ $heading }}</title>
</head>
<body style="margin:0; padding:0; background-color:#eef1f6; font-family: 'Segoe UI', Tahoma, Arial, sans-serif; -webkit-font-smoothing:antialiased;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#eef1f6; padding:40px 16px;">
        <tr>
            <td align="center">
                <table role="presentation" width="560" cellpadding="0" cellspacing="0"
                       style="max-width:560px; width:100%; background:#ffffff; border-radius:14px; overflow:hidden; box-shadow:0 4px 18px rgba(17,24,39,0.08);">

                    {{-- Header / brand --}}
                    <tr>
                        <td style="background:linear-gradient(135deg, {{ $brandColor }} 0%, {{ $brandColorDark }} 100%); padding:28px 32px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td style="vertical-align:middle;">
                                        @if ($logoUrl)
                                            <img src="{{ $logoUrl }}" alt="{{ $appName }}" height="32" style="display:block; height:32px;">
                                        @else
                                            <span style="display:inline-block; width:34px; height:34px; line-height:34px; text-align:center; background:rgba(255,255,255,0.16); border-radius:9px; color:#ffffff; font-size:16px; font-weight:800; vertical-align:middle;">
                                                {{ mb_substr($appName, 0, 1) }}
                                            </span>
                                            <span style="display:inline-block; color:#ffffff; font-size:17px; font-weight:700; letter-spacing:0.2px; padding:0 10px; vertical-align:middle;">
                                                {{ $appName }}
                                            </span>
                                        @endif
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- Body --}}
                    <tr>
                        <td style="padding:36px 32px 8px;">
                            <h1 style="margin:0 0 18px; font-size:21px; line-height:1.4; color:#0f172a; font-weight:700;">{{ $heading }}</h1>

                            @foreach ($lines as $line)
                                <p style="margin:0 0 14px; font-size:14.5px; line-height:1.8; color:#4b5563;">{{ $line }}</p>
                            @endforeach

                            @if (!empty($meta))
                                <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
                                       style="background:#f8fafc; border:1px solid #eef0f4; border-radius:10px; margin:18px 0 8px;">
                                    @foreach ($meta as $i => $row)
                                        <tr>
                                            <td style="padding:13px 18px; font-size:13px; color:#8a93a3; white-space:nowrap; {{ $i > 0 ? 'border-top:1px solid #eef0f4;' : '' }}">{{ $row['label'] }}</td>
                                            <td style="padding:13px 18px; font-size:14.5px; color:#0f172a; font-weight:700; direction:ltr; text-align:{{ $locale === 'ar' ? 'right' : 'left' }}; {{ $i > 0 ? 'border-top:1px solid #eef0f4;' : '' }}">{{ $row['value'] }}</td>
                                        </tr>
                                    @endforeach
                                </table>
                            @endif

                            @if ($button)
                                <table role="presentation" cellpadding="0" cellspacing="0" style="margin:24px 0 6px;">
                                    <tr>
                                        <td style="border-radius:8px; background:{{ $brandColor }};">
                                            <a href="{{ $button['url'] }}"
                                               style="display:inline-block; padding:13px 30px; font-size:14.5px; font-weight:700; color:#ffffff; text-decoration:none; border-radius:8px;">
                                                {{ $button['label'] }}
                                            </a>
                                        </td>
                                    </tr>
                                </table>
                                <p style="margin:14px 0 0; font-size:12px; color:#9aa2b1; word-break:break-all;">
                                    {{ $locale === 'ar' ? 'أو انسخ الرابط ده في المتصفح:' : 'Or paste this link into your browser:' }}
                                    <br>
                                    <a href="{{ $button['url'] }}" style="color:{{ $brandColor }}; text-decoration:none;">{{ $button['url'] }}</a>
                                </p>
                            @endif
                        </td>
                    </tr>

                    <tr><td style="padding:8px 32px;"><hr style="border:none; border-top:1px solid #eef0f4; margin:0;"></td></tr>

                    {{-- Footer --}}
                    <tr>
                        <td style="padding:20px 32px 28px;">
                            <p style="margin:0 0 4px; font-size:12px; color:#9aa2b1; line-height:1.7;">
                                {{ $locale === 'ar'
                                    ? 'هذه رسالة تلقائية، من فضلك لا ترد عليها.'
                                    : 'This is an automated message — please do not reply to it.' }}
                            </p>
                            <p style="margin:0; font-size:12px; color:#c2c8d2;">
                                &copy; {{ date('Y') }} {{ $appName }}. {{ $locale === 'ar' ? 'جميع الحقوق محفوظة.' : 'All rights reserved.' }}
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
