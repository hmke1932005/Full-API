<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// بند 22 — App\Console\Commands\PublishScheduledAnnouncements منقولة من
// cron/publish_scheduled_announcements.php القديمة. زي القديمة بالظبط،
// شغّلها من crontab حقيقي بتاع السيرفر كل 5 دقايق (مش مفعّلة هنا عبر
// Laravel's own scheduler عشان نفس آلية التشغيل القديمة تفضل زي ما هي):
//   php artisan announcements:publish-due
// لو حبيت تستخدم جدولة لارافيل نفسها بدل crontab يدوي، ضيف بدل التعليق ده:
//   Schedule::command('announcements:publish-due')->everyFiveMinutes();

// Exam & Assessment System — Round 3 (Phase 11 backstop). App\Console\
// Commands\AutoSubmitExpiredExamAttempts — نفس مبدأ السطرين فوق بالظبط.
// شغّلها من crontab كل دقيقة (مش مفعّلة هنا افتراضيًا):
//   php artisan exams:auto-submit-expired
// أو عبر جدولة لارافيل:
//   Schedule::command('exams:auto-submit-expired')->everyMinute();

// Exam & Assessment System — Round 8 (Phase 29). App\Console\Commands\
// NotifyUpcomingExamEvents — "exam starts soon"/"deadline approaching"،
// الإشعارين الوحيدين اللي وقت-مبني مش حدث-مبني (راجع docblock الكلاس).
// شغّلها من crontab كل 15 دقيقة (مش مفعّلة هنا افتراضيًا):
//   php artisan exams:notify-upcoming
// أو عبر جدولة لارافيل:
//   Schedule::command('exams:notify-upcoming')->everyFifteenMinutes();

// Meetings & Collaboration Platform — Round 3 (Signaling، بند 12).
// App\Console\Commands\SendMeetingStartingSoonReminders — تذكير
// "starting soon" قابل للتعديل من الأدمن (MeetingPolicyService::
// reminderMinutesBefore()). شغّلها من crontab كل دقيقة (مش مفعّلة هنا
// افتراضيًا):
//   php artisan meetings:send-starting-soon-reminders
// أو عبر جدولة لارافيل:
//   Schedule::command('meetings:send-starting-soon-reminders')->everyMinute();
