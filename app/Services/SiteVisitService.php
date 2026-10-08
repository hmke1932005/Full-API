<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * عدّاد زوار المنصة + إحصائيات الـ landing الحقيقية (كلها أرقام من الـ DB،
 * مفيش أي قيمة ثابتة). الزيارة بتتسجّل مرة لكل "جلسة متصفح" (الفرونت بيبعت
 * مرة واحدة لكل تاب/جلسة) — مش على كل ريفريش — عشان الرقم ميتضخّمش.
 */
class SiteVisitService
{
    /** @return array{total_visits:int, unique_visitors:int} */
    public function counts(): array
    {
        $rows = DB::table('site_counters')
            ->whereIn('name', ['total_visits', 'unique_visitors'])
            ->pluck('value', 'name');

        return [
            'total_visits'    => (int) ($rows['total_visits'] ?? 0),
            'unique_visitors' => (int) ($rows['unique_visitors'] ?? 0),
        ];
    }

    /** يسجّل زيارة جديدة لـ visitorId (معرّف عشوائي من المتصفح) ويرجّع العدّادات المحدّثة. */
    public function record(string $visitorId): array
    {
        $hash = hash('sha256', $visitorId);

        DB::transaction(function () use ($hash) {
            $existing = DB::table('site_visitors')->where('visitor_hash', $hash)->lockForUpdate()->first();

            if ($existing) {
                DB::table('site_visitors')->where('id', $existing->id)->update([
                    'visits_count'  => DB::raw('visits_count + 1'),
                    'last_visit_at' => now(),
                ]);
            } else {
                DB::table('site_visitors')->insert([
                    'visitor_hash'   => $hash,
                    'visits_count'   => 1,
                    'first_visit_at' => now(),
                    'last_visit_at'  => now(),
                ]);
                $this->bump('unique_visitors');
            }

            $this->bump('total_visits');
        });

        return $this->counts();
    }

    private function bump(string $name): void
    {
        DB::table('site_counters')->updateOrInsert(['name' => $name], ['updated_at' => now()]);
        DB::table('site_counters')->where('name', $name)->update([
            'value'      => DB::raw('value + 1'),
            'updated_at' => now(),
        ]);
    }

    /**
     * أرقام صفحة الـ landing — كلها محسوبة لايف من الجداول الفعلية.
     * - projects_scored: مشاريع ليها تقييم جاهزية AI حقيقي (is_demo_data=0).
     * - published_projects: مشاريع منشورة.
     * - partner_universities: جامعات موثّقة (verified).
     * - active_students: طلاب حساباتهم active.
     * @return array<string,int>
     */
    public function landingStats(): array
    {
        return [
            'projects_scored'      => (int) DB::table('ai_readiness_scores')->where('is_demo_data', 0)->count(),
            'published_projects'   => (int) DB::table('projects')->where('status', 'published')->count(),
            'partner_universities' => (int) DB::table('universities')->where('verification_status', 'verified')->count(),
            'active_students'      => (int) DB::table('students as s')
                ->join('users as u', 'u.id', '=', 's.user_id')
                ->where('u.status', 'active')
                ->count(),
        ];
    }
}
