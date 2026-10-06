<?php

namespace App\Services;

use App\Models\DataExport;
use App\Repositories\DataExportRepository;
use App\Repositories\ProjectRepository;
use App\Repositories\UniversityRepository;
use App\Services\Export\PdfWriter;
use App\Services\Export\SpreadsheetWriter;

/**
 * منقولة من app/Services/DataExportService.php القديمة — بند 24 batch 2
 * (Data Analysis Portal — Export Center، enhancement spec section 7).
 * توليد تصدير حقيقي (نفس writers CSV/Excel/PDF المبنية على
 * AnalyticsService اللي DataAnalysisExportsApiController::generateOne()
 * بتستخدمها)، متفصّلة هنا عشان ExportSchedulerService يقدر يعيد توليد
 * تصدير على جدول دوري من غير ما يحتاج Request/Session (مفيش cron
 * context). الكنترولر محتفظ بـ generateOne() بتاعته الخاصة لمسار
 * "Export Now" التزامني — السيرفيس ده إضافي، مش بديل.
 */
class DataExportService
{
    public const ALLOWED_TYPES = ['platform_kpis', 'user_growth', 'category_distribution', 'university_leaderboard', 'full_platform_export'];

    public const ALLOWED_FORMATS = ['csv', 'xlsx', 'pdf'];

    public const TYPE_LABELS = [
        'platform_kpis'           => 'Platform KPIs',
        'user_growth'             => 'User Growth (12 months)',
        'category_distribution'   => 'Category Distribution',
        'university_leaderboard'  => 'University Leaderboard',
        'full_platform_export'    => 'Everything (Projects + Universities)',
    ];

    public function __construct(
        private DataExportRepository $exports,
        private AnalyticsService $analytics,
        private ProjectRepository $projects,
        private UniversityRepository $universities,
        private MailService $mail
    ) {
    }

    /**
     * بتولّد تصدير واحد، تسجله في data_exports، تكتب الملف الحقيقي على
     * الديسك، وتبعت إيميل لكل مستلم. بترمي استثناء عند الفشل (صف
     * data_exports اللي اتعمل قبل الفشل بيفضل 'pending' — نفس سلوك
     * generateOne() في الكنترولر).
     * @param string[] $recipients عناوين إيميل اتحققت مسبقًا
     */
    public function generate(
        string $type,
        string $format,
        $userId,
        array $recipients = [],
        ?string $batchId = null,
        ?int $scheduleId = null
    ): DataExport {
        if (!in_array($type, self::ALLOWED_TYPES, true) || !in_array($format, self::ALLOWED_FORMATS, true)) {
            throw new \InvalidArgumentException('Unknown export type or format.');
        }

        $emailTo = $recipients ? implode(', ', $recipients) : null;

        $export = $this->exports->create([
            'user_id'     => $userId,
            'export_type' => $type,
            'format'      => $format,
            'filters'     => [],
            'status'      => 'pending',
            'email_to'    => $emailTo,
            'batch_id'    => $batchId,
            'schedule_id' => $scheduleId,
        ]);

        [$header, $rows] = $this->buildRows($type);

        $dir = public_path(config('upload.paths.reports', 'uploads/reports'));
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Could not prepare the exports folder.');
        }

        $filename = 'export_' . $type . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $format;
        $fullPath = $dir . '/' . $filename;

        switch ($format) {
            case 'xlsx':
                SpreadsheetWriter::write($fullPath, $header, $rows);
                break;

            case 'pdf':
                PdfWriter::writeTable($fullPath, self::TYPE_LABELS[$type] ?? $type, $header, $rows);
                break;

            default: // csv
                $handle = fopen($fullPath, 'w');
                if (!$handle) {
                    throw new \RuntimeException('Could not write the export file.');
                }
                fputcsv($handle, $header);
                foreach ($rows as $row) {
                    fputcsv($handle, $row);
                }
                fclose($handle);
        }

        $relativePath = rtrim(config('upload.paths.reports', 'uploads/reports'), '/') . '/' . $filename;
        $this->exports->markCompleted($export->id, $relativePath);
        $export->fill(['status' => 'completed', 'file_path' => $relativePath, 'completed_at' => now()]);

        if ($recipients) {
            $label = self::TYPE_LABELS[$type] ?? $type;
            // رابط تحميل مطلق حقيقي من APP_URL (زي app_url() القديمة) —
            // ده رابط بيروح جوّه إيميل، ومفيش request context خاص بيه
            // (ولا أي context خالص لما التصدير يجي من جدولة/cron)، فلازم
            // يكون absolute URL حقيقي مش relative path.
            $downloadUrl = rtrim((string) config('app.url', ''), '/') . '/' . ltrim($relativePath, '/');
            foreach ($recipients as $address) {
                $this->mail->sendDataExportReady($address, $label, $format, $downloadUrl);
            }
        }

        return $export;
    }

    /** @return array{0:string[],1:array<int,array<int,mixed>>} */
    private function buildRows(string $type): array
    {
        switch ($type) {
            case 'platform_kpis':
                $overview = $this->analytics->platformOverview();
                $rows = [];
                foreach ($overview as $metric => $value) {
                    $rows[] = [$metric, $value];
                }
                return [['metric', 'value'], $rows];

            case 'user_growth':
                $series = $this->analytics->userGrowthSeries(12);
                $rows = array_map(fn ($p) => [$p['label']['en'] ?? $p['label'], $p['value']], $series);
                return [['month', 'new_users'], $rows];

            case 'category_distribution':
                $dist = $this->analytics->categoryDistribution(20);
                $rows = array_map(fn ($p) => [$p['label']['en'] ?? $p['label'], $p['value']], $dist);
                return [['category', 'projects'], $rows];

            case 'university_leaderboard':
                $board = $this->analytics->universityLeaderboard(50);
                $rows = array_map(fn ($u) => [$u['university']['en'] ?? $u['university'], $u['projects']], $board);
                return [['university', 'projects'], $rows];

            case 'full_platform_export':
                return $this->buildFullPlatformRows();

            default:
                return [[], []];
        }
    }

    /** @return array{0:string[],1:array<int,array<int,mixed>>} */
    private function buildFullPlatformRows(): array
    {
        $rows = [];

        foreach ($this->projects->allWithOwners() as $p) {
            $rows[] = [
                'project',
                $p['title_en'] ?: $p['title_ar'],
                $p['category'] ?? '',
                $p['owner_name'] ?? '',
                $p['status'] ?? '',
                $p['created_at'] ?? '',
            ];
        }

        foreach ($this->universities->allWithStats() as $u) {
            $rows[] = [
                'university',
                $u['official_name_en'] ?: $u['official_name_ar'],
                $u['country'] ?? '',
                $u['students_count'] . ' students / ' . $u['projects_count'] . ' projects',
                $u['verification_status'] ?? '',
                $u['created_at'] ?? '',
            ];
        }

        return [['Type', 'Name', 'Category / Country', 'Owner / Stats', 'Status', 'Created At'], $rows];
    }
}