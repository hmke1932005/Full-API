<?php

namespace App\Services\Export;

/**
 * يترجم عناوين التقارير وأسماء الأعمدة للعربي لما العميل يطلب lang=ar
 * (الفرونت بيبعتها من قائمة التصدير). بيترجم الـ labels بس — القيم
 * نفسها (أسماء الامتحانات والطلاب والجامعات) بتفضل زي ما هي في الداتا.
 * أي نص مالوش ترجمة بيرجع زي ما هو، فمفيش تصدير ممكن يفشل بسببه.
 */
final class ExportLocalizer
{
    private const AR = [
        // عناوين التقارير
        'Platform KPIs' => 'مؤشرات أداء المنصة',
        'User Growth (12 months)' => 'نمو المستخدمين (١٢ شهرًا)',
        'Category Distribution' => 'توزيع الفئات',
        'University Leaderboard' => 'ترتيب الجامعات',
        'Everything (Projects + Universities)' => 'كل البيانات (المشاريع + الجامعات)',
        'Exam Overview (KPIs)' => 'نظرة عامة على الامتحانات (المؤشرات)',
        'Exam Scores (every graded attempt)' => 'درجات الامتحانات (كل محاولة مصححة)',
        'Student Exam Performance' => 'أداء الطلاب في الامتحانات',
        'Exams Summary (per exam)' => 'ملخص كل امتحان',
        'Doctors Exam Performance' => 'أداء الدكاترة في الامتحانات',
        // أعمدة
        'metric' => 'المؤشر', 'value' => 'القيمة', 'month' => 'الشهر', 'new_users' => 'مستخدمون جدد',
        'category' => 'الفئة', 'projects' => 'المشاريع', 'university' => 'الجامعة',
        'Type' => 'النوع', 'Name' => 'الاسم', 'Category / Country' => 'الفئة / الدولة',
        'Owner / Stats' => 'المالك / الإحصائيات', 'Status' => 'الحالة', 'Created At' => 'تاريخ الإنشاء',
        'Exam' => 'الامتحان', 'Exam Type' => 'نوع الامتحان', 'University' => 'الجامعة', 'Faculty' => 'الكلية',
        'Course' => 'المقرر', 'Student' => 'الطالب', 'Student Number' => 'الرقم الجامعي',
        'Email' => 'البريد الإلكتروني', 'Percentage' => 'النسبة %', 'Result' => 'النتيجة', 'Late' => 'متأخر',
        'Violations' => 'المخالفات', 'Submitted At' => 'وقت التسليم', 'Exams Taken' => 'الامتحانات المؤداة',
        'Average %' => 'المتوسط %', 'Best %' => 'الأعلى %', 'Lowest %' => 'الأدنى %', 'Highest %' => 'الأعلى %',
        'Pass Rate %' => 'نسبة النجاح %', 'Trend (pts)' => 'الاتجاه (نقاط)', 'Flagged Attempts' => 'محاولات مُبلَّغ عنها',
        'Level' => 'المستوى', 'Exam ID' => 'رقم الامتحان', 'Graded Attempts' => 'المحاولات المصححة',
        'Students' => 'الطلاب', 'Last Submission' => 'آخر تسليم', 'Doctor' => 'الدكتور',
        'Exams Created' => 'الامتحانات المنشأة', 'Exams With Graded Attempts' => 'امتحانات بها محاولات مصححة',
    ];

    public static function isArabic(): bool
    {
        return app()->getLocale() === 'ar';
    }

    public static function text(string $en): string
    {
        return self::isArabic() ? (self::AR[$en] ?? $en) : $en;
    }

    /** @param array<int|string,mixed> $header */
    public static function headers(array $header): array
    {
        return self::isArabic()
            ? array_map(fn ($h) => is_string($h) ? (self::AR[$h] ?? $h) : $h, $header)
            : $header;
    }

    public static function recordsLabel(int $n): string
    {
        if (!self::isArabic()) {
            return number_format($n) . ($n === 1 ? ' record' : ' records');
        }
        return number_format($n) . ($n === 1 ? ' سجل' : ' سجلات');
    }
}
