<?php

namespace App\Services;

use App\Repositories\BlockedIpRepository;
use App\Repositories\RiskScoreRepository;
use App\Repositories\SecurityAlertRepository;
use App\Repositories\SecurityIncidentRepository;
use App\Repositories\SecurityLogRepository;
use App\Repositories\SecurityNotificationRepository;
use App\Repositories\UserSessionRepository;
use App\Repositories\VulnerabilityRepository;

/**
 * منقولة من app/Services/SecurityDashboardService.php القديمة — بند 25
 * batch 1 (Security Portal — الداشبورد الرئيسية). نفس الميثودز الست
 * بالظبط (overview/recentIncidents/topRisks/notificationsFeed/
 * recentEvents/incidentsBySeverity)، بتجمّع من نفس الريبوهات الحقيقية
 * بس — مفيش أرقام مختلقة، تمامًا زي القديمة.
 */
class SecurityDashboardService
{
    public function __construct(
        private SecurityIncidentRepository $incidents,
        private VulnerabilityRepository $vulnerabilities,
        private UserSessionRepository $sessions,
        private RiskScoreRepository $riskScores,
        private SecurityNotificationRepository $notifications,
        private SecurityLogRepository $securityLogs,
        private BlockedIpRepository $blockedIps,
        private SecurityAlertRepository $alerts
    ) {
    }

    /** @return array<string,int|float> قيم كروت الإحصائيات */
    public function overview(): array
    {
        return [
            'active_alerts'         => $this->alerts->countOpen(),
            'open_incidents'        => $this->incidents->countOpen(),
            'critical_incidents'    => $this->incidents->countBySeverity('critical'),
            'open_vulnerabilities'  => $this->vulnerabilities->countOpen(),
            'active_sessions'       => $this->sessions->countActive(),
            'active_users'          => $this->sessions->countDistinctActiveUsers(),
            'blocked_ips'           => count($this->blockedIps->all()),
            'average_risk_score'    => round($this->riskScores->averageScore(), 1),
        ];
    }

    /** @return array<int,array<string,mixed>> أحدث الحوادث لفيد الداشبورد */
    public function recentIncidents(int $limit = 6): array
    {
        return $this->incidents->allWithAssignee($limit);
    }

    /** @return array<int,array<string,mixed>> */
    public function topRisks(int $limit = 5): array
    {
        return array_map(function ($row) {
            $row['factors'] = json_decode($row['factors'] ?? '{}', true) ?: [];
            return $row;
        }, $this->riskScores->topRisks($limit));
    }

    /** @return array<int,array<string,mixed>> */
    public function notificationsFeed(string $role, int $limit = 10): array
    {
        return $this->notifications->forRole($role, $limit);
    }

    /** @return array<int,array<string,mixed>> أحدث أحداث log الأمان الخام (ملف-محور) */
    public function recentEvents(int $limit = 8): array
    {
        return array_slice($this->securityLogs->recent(7, 200), 0, $limit);
    }

    /** @return array{low:int,medium:int,high:int,critical:int} توزيع الحوادث حسب الخطورة */
    public function incidentsBySeverity(): array
    {
        return [
            'low'      => $this->incidents->countBySeverity('low'),
            'medium'   => $this->incidents->countBySeverity('medium'),
            'high'     => $this->incidents->countBySeverity('high'),
            'critical' => $this->incidents->countBySeverity('critical'),
        ];
    }
}
