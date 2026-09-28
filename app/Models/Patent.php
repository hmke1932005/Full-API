<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * موديل Patent — جدول patents (migration 028). كان فيه نسخة "قراءة بس"
 * هنا لبند 7 (عداد داشبورد الباحث) بس؛ دلوقتي بند 14/Future (Patent
 * Portal) بيضيف CRUD كامل، فالـ fillable اتوسّعت لتشمل باقي الأعمدة.
 */
class Patent extends Model
{
    protected $table = 'patents';

    protected $fillable = [
        'submitted_by', 'project_id', 'title', 'application_number', 'status', 'filed_at',
    ];
}
