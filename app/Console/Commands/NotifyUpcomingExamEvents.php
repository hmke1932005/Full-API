<?php

namespace App\Console\Commands;

use App\Models\Notification;
use App\Repositories\ExamRepository;
use App\Repositories\ExamTargetRepository;
use App\Services\NotificationService;
use Illuminate\Console\Command;

/**
 * Phase 29 backstop — الإشعارين الوحيدين في السبك اللي مش نتيجة فعل مدرس
 * مباشر (exam_scheduled/exam_available/exam_assigned/exam_result_published/
 * exam_grade_changed كلهم اتبعتوا من جوه ExamSystemService/ExamGradingService
 * وقت الفعل نفسه — راجع docblocks هناك): "Exam starts soon" و"Exam
 * deadline approaching" لازم sweep دوري لأنه مبني على الوقت الحالي نفسه،
 * مش على حدث. نفس نمط AutoSubmitExpiredExamAttempts بالظبط (command
 * مسجل، مش مفعّل تلقائيًا — يتضاف لـ crontab الحقيقي بتاع السيرفر).
 *
 * Dedup: notify() (NotificationService) نفسها لسه من غير dedup مدمج
 * (راجع docblock الكلاس هناك — مؤجل عمدًا)، فالـ command ده بيعمل فحص
 * وجود مباشر على Notification::link_url قبل أي notify() — كل تنبيه له
 * link_url فريد بصيغة "exam:{id}:starts_soon" أو "exam:{id}:deadline"،
 * فحتى لو الـ sweep اشتغل كذا مرة جوه نفس الشباك الزمني (كل 15 دقيقة
 * مثلًا) الطالب هياخد تنبيه واحد بس لكل امتحان لكل نوع.
 *
 * سجّلها في crontab حقيقي بتاع السيرفر (كل 15 دقيقة كافية للشبابيك
 * الزمنية المستخدمة تحت — مينت 0/15/30/45 كل ساعة):
 *   0,15,30,45 * * * * cd /path/to/project && php artisan exams:notify-upcoming >> storage/logs/cron.log 2>&1
 * أو عبر جدولة لارافيل نفسها في routes/console.php:
 *   Schedule::command('exams:notify-upcoming')->everyFifteenMinutes();
 */
class NotifyUpcomingExamEvents extends Command
{
    protected $signature = 'exams:notify-upcoming';

    protected $description = 'Notifies eligible students of exams starting soon (next 24h) or with an approaching submission deadline (next 24h).';

    /** الشباك الزمني بالساعات — امتحان جوه الشباك ده من دلوقتي بيتحسب "starts soon"/"deadline approaching". */
    private const WINDOW_HOURS = 24;

    public function handle(ExamRepository $exams, ExamTargetRepository $targets, NotificationService $notifications): int
    {
        $now = now();
        $windowEnd = $now->copy()->addHours(self::WINDOW_HOURS);

        $startingSoon = $exams->scheduledStartingBetween($now, $windowEnd);
        $deadlineApproaching = $exams->publishedEndingBetween($now, $windowEnd);

        $sentStarts = 0;
        foreach ($startingSoon as $exam) {
            $link = 'exam:' . $exam->id . ':starts_soon';
            foreach ($targets->userIdsForExamSaved($exam->id, $exam->university_id) as $userId) {
                if (Notification::where('user_id', $userId)->where('link_url', $link)->exists()) {
                    continue;
                }
                $notifications->notify(
                    $userId,
                    'exam_starts_soon',
                    'Exam starts soon: ' . $exam->title,
                    '"' . $exam->title . '" starts at ' . $exam->start_at->format('Y-m-d H:i') . '.',
                    $link,
                    'high'
                );
                $sentStarts++;
            }
        }

        $sentDeadlines = 0;
        foreach ($deadlineApproaching as $exam) {
            $link = 'exam:' . $exam->id . ':deadline';
            foreach ($targets->userIdsForExamSaved($exam->id, $exam->university_id) as $userId) {
                if (Notification::where('user_id', $userId)->where('link_url', $link)->exists()) {
                    continue;
                }
                $notifications->notify(
                    $userId,
                    'exam_deadline_approaching',
                    'Exam deadline approaching: ' . $exam->title,
                    '"' . $exam->title . '" closes at ' . $exam->end_at->format('Y-m-d H:i') . '.',
                    $link,
                    'high'
                );
                $sentDeadlines++;
            }
        }

        $this->info("Starts-soon notifications sent: {$sentStarts}. Deadline-approaching notifications sent: {$sentDeadlines}.");
        return self::SUCCESS;
    }
}
