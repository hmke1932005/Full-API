<?php

namespace App\Services;

use App\Services\Push\FcmClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Push للموبايل: تسجيل أجهزة اليوزر + إرسال أي إشعار داخلي (notifications) كـ push.
 * NotificationService::notify() بيستدعيها بعد ما يحفظ الإشعار، فكل مكان في المشروع بيبعت إشعار
 * بيطلع له push تلقائيًا. بتحترم كتم الفئات (Settings → Notifications) وساعات الهدوء؛
 * الأولوية high/urgent بتتخطاهم. فشل Firebase مبيكسرش حاجة (اتسجّل في اللوج بس).
 */
class PushNotificationService
{
    private const MAX_DEVICES_PER_USER = 10;

    public function __construct(private FcmClient $fcm, private NotificationPreferencesService $prefs)
    {
    }

    public function registerDevice(int $userId, string $token, ?string $deviceId, string $platform = 'android', ?string $locale = null, ?string $appVersion = null): void
    {
        $now = now();
        // نفس التثبيت اتغيّر له التوكن: امسح القديم.
        if ($deviceId) {
            DB::table('push_devices')->where('device_id', $deviceId)->where('token', '!=', $token)->delete();
        }
        $values = [
            'user_id'      => $userId,
            'device_id'    => $deviceId,
            'platform'     => $platform,
            'locale'       => $locale === 'en' ? 'en' : ($locale === 'ar' ? 'ar' : null),
            'app_version'  => $appVersion ? mb_substr($appVersion, 0, 32) : null,
            'last_seen_at' => $now,
        ];
        if (DB::table('push_devices')->where('token', $token)->exists()) {
            DB::table('push_devices')->where('token', $token)->update($values);   // بينقل الجهاز لليوزر الحالي لو اتغيّر
        } else {
            DB::table('push_devices')->insert($values + ['token' => $token, 'created_at' => $now]);
        }
        // سقف الأجهزة لكل يوزر: الأقدم يتشال.
        $extra = DB::table('push_devices')->where('user_id', $userId)->orderByDesc('last_seen_at')->orderByDesc('id')
            ->skip(self::MAX_DEVICES_PER_USER)->take(100)->pluck('id');
        if ($extra->isNotEmpty()) {
            DB::table('push_devices')->whereIn('id', $extra)->delete();
        }
    }

    public function unregisterDevice(int $userId, string $token): void
    {
        DB::table('push_devices')->where('user_id', $userId)->where('token', $token)->delete();
    }

    /** بيبعت الإشعار الداخلي رقم $notificationId لكل أجهزة صاحبه. مبيرميش استثناء. */
    public function sendForNotification(int $notificationId): int
    {
        try {
            if (!$this->fcm->isConfigured()) {
                return 0;
            }
            $n = DB::table('notifications')->where('id', $notificationId)->first();
            if (!$n || $n->is_deleted || $n->is_archived) {
                return 0;
            }
            $userId = (int) $n->user_id;
            $active = DB::table('users')->where('id', $userId)->whereNull('deleted_at')->where('status', 'active')->exists();
            if (!$active) {
                return 0;
            }
            $urgent = in_array((string) $n->priority, ['high', 'urgent'], true);
            if (!$urgent) {
                if ($n->category && in_array((string) $n->category, $this->prefs->mutedCategoriesFor($userId), true)) {
                    return 0;
                }
                if ($this->inQuietHours($userId)) {
                    return 0;
                }
            }

            $devices = DB::table('push_devices')->where('user_id', $userId)->orderByDesc('last_seen_at')->limit(self::MAX_DEVICES_PER_USER)->get(['id', 'token']);
            if ($devices->isEmpty()) {
                return 0;
            }
            $unread = (int) DB::table('notifications')->where('user_id', $userId)->where('is_read', 0)->where('is_deleted', 0)->where('is_archived', 0)->count();
            $data = [
                'notification_id' => $n->id,
                'type'            => $n->type,
                'category'        => $n->category ?? '',
                'link_url'        => $this->safeLink($n->link_url),
                'unread'          => $unread,
            ];
            $title = (string) $n->title;
            $body = (string) ($n->body ?? '');

            // تنبيهات أعطال التطبيق: قناة "alerts" (أهمية قصوى) ولون أحمر، ونفس الـ tag بيستبدل القديم بدل ما يتكدّس.
            $opts = [];
            if (str_starts_with((string) $n->type, 'system_app_failure')) {
                $opts = ['channel_id' => 'uip-alerts', 'color' => '#E5484D', 'tag' => 'uip-appfail-' . substr(md5((string) $n->link_url), 0, 8)];
            }

            // رسائل الشات بشكل الواتساب: العنوان اسم المرسل، المتن نص الرسالة، وتاج لكل محادثة (الرسالة الجديدة
            // بتحل محل القديمة من نفس المحادثة بدل ما الإشعارات تتكدّس)، وصلاحية 7 أيام عشان تتسلّم لما الموبايل يرجع أونلاين.
            if ((string) $n->type === 'new_message') {
                if (preg_match('/^New message from\s+(.+)$/u', $title, $mm)) {
                    $title = trim($mm[1]);
                }
                $opts = ['tag' => 'uip-msg-' . substr(md5((string) $n->link_url), 0, 12), 'ttl' => '604800s'];
            }

            $sent = 0;
            foreach ($devices as $d) {
                $r = $this->fcm->send($d->token, $title, $body !== '' ? $body : $title, $data, $opts);
                if ($r === FcmClient::OK) {
                    $sent++;
                } elseif ($r === FcmClient::INVALID_TOKEN) {
                    DB::table('push_devices')->where('id', $d->id)->delete();   // التطبيق اتمسح / التوكن انتهى
                }
            }
            return $sent;
        } catch (\Throwable $e) {
            Log::warning('PushNotificationService::sendForNotification failed', ['error' => $e->getMessage(), 'notification_id' => $notificationId]);
            return 0;
        }
    }

    /** لينك داخلي بس (يبدأ بـ / وليس //)، غير كده مبيتبعتش عشان التطبيق مايفتحش عناوين خارجية. */
    private function safeLink(?string $link): string
    {
        $link = trim((string) $link);
        if ($link === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $link)) {
            $base = rtrim((string) (config('app.frontend_url') ?: config('app.url', '')), '/');
            if ($base !== '' && str_starts_with($link, $base . '/')) {
                return substr($link, strlen($base));
            }
            return '';
        }
        return ($link[0] === '/' && !str_starts_with($link, '//')) ? $link : '';
    }

    private function inQuietHours(int $userId): bool
    {
        $q = $this->prefs->quietHoursFor($userId);
        if (!$q['start'] || !$q['end']) {
            return false;
        }
        $now = now()->format('H:i');
        return $q['start'] <= $q['end']
            ? ($now >= $q['start'] && $now < $q['end'])
            : ($now >= $q['start'] || $now < $q['end']);
    }
}
