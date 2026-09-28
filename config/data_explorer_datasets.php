<?php
/**
 * Config: data_explorer_datasets
 *
 * Static CATALOG metadata for the Data Explorer (enhancement spec section
 * 2). The actual column lists are introspected live from the database
 * (INFORMATION_SCHEMA on MySQL, PRAGMA table_info on SQLite) by
 * DataExplorerRepository, so they never drift from the real schema —
 * this file only holds what introspection can't tell us: display
 * labels, an icon, which columns are sensitive and must be redacted
 * from any preview/export, and the declared relationships to other
 * datasets in the catalog (for the "Data Relationships" tab).
 *
 * Every `table` value here MUST be one of the six tables already
 * reachable via report_templates.data_source (migration 042) — this is
 * a read-only exploration surface, not a generic "browse any table"
 * tool, so the allow-list is deliberately closed.
 * @package UIP
 */

return [

    'projects' => [
        'table' => 'projects',
        'label' => ['en' => 'Projects', 'ar' => 'المشاريع'],
        'description' => [
            'en' => 'Graduation and innovation projects submitted across all universities.',
            'ar' => 'مشاريع التخرج والابتكار المقدَّمة من جميع الجامعات.',
        ],
        'icon' => 'projects',
        'primary_key' => 'id',
        'default_sort' => 'created_at',
        'default_sort_dir' => 'desc',
        'sensitive_columns' => [],
        'relationships' => [
            ['column' => 'owner_id', 'references' => 'users', 'references_column' => 'id',
                'label' => ['en' => 'Owner (student)', 'ar' => 'صاحب المشروع']],
            ['column' => 'id', 'references' => 'ai_analysis', 'references_column' => 'project_id',
                'label' => ['en' => 'AI analyses of this project', 'ar' => 'تحليلات الذكاء الاصطناعي لهذا المشروع']],
        ],
    ],

    'users' => [
        'table' => 'users',
        'label' => ['en' => 'Users', 'ar' => 'المستخدمون'],
        'description' => [
            'en' => 'Every platform account across all portals (students, universities, admins).',
            'ar' => 'كل حسابات المنصة عبر جميع البوابات (طلاب، جامعات، شركات، مستثمرون، مديرون).',
        ],
        'icon' => 'users',
        'primary_key' => 'id',
        'default_sort' => 'created_at',
        'default_sort_dir' => 'desc',
        // Never previewable/exportable from the Data Explorer, regardless
        // of the viewer's role — these are redacted at the repository
        // level, not just hidden in the view.
        'sensitive_columns' => ['password_hash', 'remember_token'],
        'relationships' => [
            ['column' => 'id', 'references' => 'projects', 'references_column' => 'owner_id',
                'label' => ['en' => 'Owned projects', 'ar' => 'المشاريع المملوكة']],
            ['column' => 'id', 'references' => 'security_logs', 'references_column' => 'user_id',
                'label' => ['en' => 'Security log events', 'ar' => 'أحداث سجل الأمان']],
        ],
    ],

    'analytics_records' => [
        'table' => 'analytics_records',
        'label' => ['en' => 'Analytics Records', 'ar' => 'سجلات التحليلات'],
        'description' => [
            'en' => 'Time-series platform metrics (metric_key/value per day) that power dashboards and charts.',
            'ar' => 'مقاييس زمنية للمنصة (مفتاح/قيمة لكل يوم) تُغذّي لوحات المعلومات والرسوم البيانية.',
        ],
        'icon' => 'bar-chart',
        'primary_key' => 'id',
        'default_sort' => 'recorded_for_date',
        'default_sort_dir' => 'desc',
        'sensitive_columns' => [],
        'relationships' => [],
    ],

    'innovation_statistics' => [
        'table' => 'innovation_statistics',
        'label' => ['en' => 'Innovation Statistics', 'ar' => 'إحصائيات الابتكار'],
        'description' => [
            'en' => 'Per-university, per-category rollups of project counts, approval counts, and average AI readiness score.',
            'ar' => 'ملخصات لكل جامعة وتصنيف لعدد المشاريع، عدد الموافقات، ومتوسط درجة جاهزية الذكاء الاصطناعي.',
        ],
        'icon' => 'trend',
        'primary_key' => 'id',
        'default_sort' => 'period_end',
        'default_sort_dir' => 'desc',
        'sensitive_columns' => [],
        'relationships' => [
            ['column' => 'university_id', 'references' => 'projects', 'references_column' => 'university_id',
                'label' => ['en' => 'Projects at this university', 'ar' => 'مشاريع هذه الجامعة']],
        ],
    ],

    'ai_analysis' => [
        'table' => 'ai_analysis',
        'label' => ['en' => 'AI Analysis', 'ar' => 'تحليلات الذكاء الاصطناعي'],
        'description' => [
            'en' => 'AI-generated results per project: readiness scores, classification, summaries, startup potential.',
            'ar' => 'نتائج الذكاء الاصطناعي لكل مشروع: درجات الجاهزية، التصنيف، الملخصات، إمكانية النجاح كشركة ناشئة.',
        ],
        'icon' => 'sparkles',
        'primary_key' => 'id',
        'default_sort' => 'created_at',
        'default_sort_dir' => 'desc',
        'sensitive_columns' => [],
        'relationships' => [
            ['column' => 'project_id', 'references' => 'projects', 'references_column' => 'id',
                'label' => ['en' => 'Analyzed project', 'ar' => 'المشروع المُحلَّل']],
            ['column' => 'requested_by', 'references' => 'users', 'references_column' => 'id',
                'label' => ['en' => 'Requested by', 'ar' => 'طلب التحليل']],
        ],
    ],

    'security_logs' => [
        'table' => 'security_logs',
        'label' => ['en' => 'Security Logs', 'ar' => 'سجلات الأمان'],
        'description' => [
            'en' => 'Append-only security/audit events (logins, password resets, 2FA changes) across the platform.',
            'ar' => 'أحداث أمنية للتدقيق (تسجيل الدخول، إعادة تعيين كلمة المرور، تغييرات التحقق بخطوتين) عبر المنصة.',
        ],
        'icon' => 'shield',
        'primary_key' => 'id',
        'default_sort' => 'created_at',
        'default_sort_dir' => 'desc',
        'sensitive_columns' => [],
        'relationships' => [
            ['column' => 'user_id', 'references' => 'users', 'references_column' => 'id',
                'label' => ['en' => 'User', 'ar' => 'المستخدم']],
        ],
    ],

];
