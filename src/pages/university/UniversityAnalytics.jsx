import { useEffect, useState } from 'react';
import { api, errorMessage } from '../../api/client';
import Icon from '../../components/Icon';
import { useLanguage, useTranslations } from '../../context/LanguageContext';
import i18nCommon from '../../i18n/common';
import i18nPage from '../../i18n/university/analytics';

const translations = { ...i18nCommon, ...i18nPage };

/**
 * Mirrors app/Views/university/analytics.php, talking to the real JSON API
 * (app/Controllers/Api/AnalyticsApiController.php::overview()/trends() —
 * already built, university branch reuses ProjectRepository's
 * *ForUniversity() aggregates exactly as UniversityAnalyticsController
 * already does; readiness_buckets/readiness_analyzed added to the API
 * response as a small addition — same AIAnalysisRepository::
 * readinessDistributionForUniversity() query the PHP view already used).
 */

function StatCard({ label, value, icon, accent }) {
  return (
    <div className="card glass-panel" style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-4)' }}>
      <span style={{ width: 44, height: 44, borderRadius: 'var(--radius-md)', flexShrink: 0, background: accent, color: '#fff', display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
        <IconGlyph name={icon} />
      </span>
      <div>
        <p className="text-caption" style={{ margin: 0 }}>{label}</p>
        <p className="text-h2" style={{ margin: 0 }}>{value}</p>
      </div>
    </div>
  );
}

function IconGlyph({ name }) { return <Icon name={name} size={20} />; }

export default function UniversityAnalytics() {
  const t = useTranslations(translations);
  const { locale } = useLanguage();
  const [data, setData] = useState(null);
  const [trends, setTrends] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  useEffect(() => {
    setLoading(true);
    Promise.all([
      api.get('/api/v1/analytics/overview'),
      api.get('/api/v1/analytics/trends', { days: 30, limit: 8 }),
    ])
      .then(([overviewJson, trendsJson]) => {
        setData(overviewJson.data);
        setTrends(trendsJson.data?.category_trends || []);
      })
      .catch((err) => setError(errorMessage(err)))
      .finally(() => setLoading(false));
  }, []);

  if (loading) return <p className="text-small">{t('Loading…')}</p>;
  if (error) return <p style={{ color: 'var(--color-danger)' }}>{error}</p>;
  if (!data) return null;

  const MONTH_LABELS = {
    en: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
    ar: ['يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو', 'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر'],
  };
  // Raw shape from ProjectRepository::monthlyActivityForUniversity() is
  // {month:'YYYY-MM', total}, unlike the PHP view's already-labeled
  // {label:{en,ar}, value} — the API returns the repository row as-is.
  const activity = (data.activity || []).map((point) => {
    const monthNum = parseInt(String(point.month).slice(5, 7), 10) - 1;
    return { value: point.total, label: { en: MONTH_LABELS.en[monthNum] || point.month, ar: MONTH_LABELS.ar[monthNum] || point.month } };
  });

  const facultyTotal = data.faculty_total || 0;
  // Same story: {faculty, total} raw rows, not {label, count, pct}.
  const facultyBreakdown = (data.faculty_breakdown || []).map((f) => ({
    label: { en: f.faculty, ar: f.faculty },
    count: f.total,
    pct: facultyTotal > 0 ? Math.round((f.total / facultyTotal) * 100) : 0,
  }));

  const approvalRate = data.approval_rate || 0;
  const avgDecisionDays = data.avg_decision_days;
  const readinessBuckets = data.readiness_buckets || [];
  const readinessAnalyzed = data.readiness_analyzed || 0;
  const ranking = data.ranking;

  const maxActivity = activity.length ? Math.max(1, ...activity.map((p) => p.value)) : 1;
  const maxFacultyPct = facultyBreakdown.length ? Math.max(1, ...facultyBreakdown.map((f) => f.pct)) : 1;
  const maxReadiness = readinessBuckets.length ? Math.max(1, ...readinessBuckets.map((b) => b.count)) : 1;
  const totalActivity = activity.reduce((sum, p) => sum + (p.value || 0), 0);

  return (
    <>
      <div className="page-header animate-rise-in">
        <div className="page-header__title">
          <h1 className="text-h1">{t('Analytics')}</h1>
          <p className="text-small">{locale === 'ar' ? 'نظرة عامة على أداء الابتكار في جامعتك.' : "An overview of your university's innovation performance."}</p>
        </div>
      </div>

      <div className="grid-4" style={{ marginBottom: 'var(--space-6)' }}>
        <StatCard label={locale === 'ar' ? 'المشاريع (٦ أشهر)' : 'Projects (6 mo.)'} value={totalActivity} icon="projects" accent="var(--uip-indigo-600)" />
        <StatCard label={locale === 'ar' ? 'معدل الاعتماد' : 'Approval Rate'} value={`${approvalRate}%`} icon="check-circle" accent="var(--uip-teal-600)" />
        <StatCard label={locale === 'ar' ? 'متوسط زمن القرار' : 'Avg. Time to Decision'} value={avgDecisionDays !== null && avgDecisionDays !== undefined ? `${avgDecisionDays} ${t('days')}` : '—'} icon="clock" accent="var(--uip-gold-600)" />
        <StatCard label={locale === 'ar' ? 'كليات نشطة' : 'Active Faculties'} value={facultyBreakdown.length} icon="building" accent="var(--uip-coral-600)" />
      </div>

      <div className="grid-2">
        <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-5)' }}>
          <div className="card glass-panel">
            <h2 className="text-h2" style={{ marginBottom: 'var(--space-5)' }}>{t('Projects Submitted per Month')}</h2>
            {activity.length ? (
              <div className="chart-bars">
                {activity.map((point, i) => {
                  const h = Math.max(6, Math.round((point.value / maxActivity) * 100));
                  return (
                    <div className="chart-bars__col" key={i}>
                      <span className="text-caption text-mono">{point.value}</span>
                      <div style={{ width: '100%', maxWidth: 36, height: `${h}%`, borderRadius: 'var(--radius-md) var(--radius-md) 0 0', background: 'linear-gradient(180deg, var(--color-primary), var(--color-accent))' }} />
                      <span className="text-caption">{point.label?.[locale] || point.label?.en}</span>
                    </div>
                  );
                })}
              </div>
            ) : <p className="text-caption">{t('Not enough data yet.')}</p>}
          </div>

          <div className="card glass-panel">
            <h3 className="text-h3" style={{ marginBottom: 'var(--space-4)' }}>{t('Readiness Score Distribution')}</h3>
            {readinessAnalyzed > 0 ? (
              <>
                <div className="chart-bars">
                  {readinessBuckets.map((bucket, i) => {
                    const h = Math.max(6, Math.round((bucket.count / maxReadiness) * 100));
                    return (
                      <div className="chart-bars__col" key={i}>
                        <span className="text-caption text-mono">{bucket.count}</span>
                        <div style={{ width: '100%', maxWidth: 36, height: `${h}%`, borderRadius: 'var(--radius-md) var(--radius-md) 0 0', background: 'linear-gradient(180deg, var(--color-primary), var(--color-accent))' }} />
                        <span className="text-caption">{bucket.label}</span>
                      </div>
                    );
                  })}
                </div>
                <p className="text-caption" style={{ marginTop: 'var(--space-3)' }}>
                  {locale === 'ar'
                    ? `مبني على ${readinessAnalyzed} مشروع تم تحليله بالذكاء الاصطناعي.`
                    : `Based on ${readinessAnalyzed} AI-analyzed project${readinessAnalyzed === 1 ? '' : 's'}.`}
                </p>
              </>
            ) : <p className="text-caption">{t('No projects have been AI-analyzed yet. Scores will appear here once students request a readiness analysis for their projects.')}</p>}
          </div>
        </div>

        <aside style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-5)' }}>
          <div className="card glass-panel">
            <h3 className="text-h3" style={{ marginBottom: 'var(--space-4)' }}>{t('Breakdown by Faculty')}</h3>
            {facultyBreakdown.length ? facultyBreakdown.map((f, i) => {
              const pct = Math.round((f.pct / maxFacultyPct) * 100);
              return (
                <div key={i} style={{ marginBottom: 'var(--space-4)' }}>
                  <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: 'var(--space-1)' }}>
                    <span className="text-small">{f.label?.[locale] || f.label?.en}</span>
                    <span className="text-caption text-mono">{f.pct}%</span>
                  </div>
                  <div style={{ height: 8, borderRadius: 'var(--radius-full)', background: 'var(--glass-bg)', overflow: 'hidden' }}>
                    <div style={{ height: '100%', width: `${pct}%`, borderRadius: 'var(--radius-full)', background: 'linear-gradient(90deg, var(--color-primary), var(--color-accent))' }} />
                  </div>
                </div>
              );
            }) : <p className="text-caption">{t('Not enough faculty data yet.')}</p>}
          </div>

          <div className="card glass-panel">
            <h3 className="text-h3" style={{ marginBottom: 'var(--space-4)' }}>{t('Innovation Statistics')}</h3>
            {ranking ? (
              <>
                <div style={{ display: 'flex', alignItems: 'baseline', gap: 'var(--space-2)', marginBottom: 'var(--space-2)' }}>
                  <span className="text-h2 text-mono">#{ranking.rank}</span>
                  <span className="text-caption">{locale === 'ar' ? `من أصل ${ranking.total} جامعة` : `of ${ranking.total} universities`}</span>
                </div>
                <p className="text-small">
                  {locale === 'ar'
                    ? `جامعتك في أعلى ${ranking.percentile}٪ من حيث عدد المشاريع المُقدَّمة (${ranking.projects} مشروع).`
                    : `Your university ranks in the top ${ranking.percentile}% by submitted projects (${ranking.projects} total).`}
                </p>
              </>
            ) : <p className="text-caption">{t('Not enough data yet to compare.')}</p>}
          </div>

          <div className="card glass-panel">
            <h3 className="text-h3" style={{ marginBottom: 'var(--space-4)' }}>{t('Trend Analysis')}</h3>
            {trends && trends.length ? (
              <>
                {trends.map((trend, i) => (
                  <div key={i} style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 'var(--space-3)' }}>
                    <span className="text-small">{t(trend.category)}</span>
                    <span className="text-caption text-mono" style={{ color: trend.growth_pct === null ? 'var(--color-text-secondary)' : (trend.growth_pct >= 0 ? 'var(--uip-teal-600)' : 'var(--uip-coral-600)') }}>
                      {trend.growth_pct === null ? '—' : `${trend.growth_pct >= 0 ? '+' : ''}${trend.growth_pct}%`}
                    </span>
                  </div>
                ))}
                <p className="text-caption">{t('Last 30 days vs the prior 30, by submission count.')}</p>
              </>
            ) : <p className="text-caption">{t('Not enough data yet.')}</p>}
          </div>
        </aside>
      </div>
    </>
  );
}
