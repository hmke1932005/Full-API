import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { api, errorMessage } from '../../api/client';
import Icon from '../../components/Icon';
import { useTranslations, useLanguage } from '../../context/LanguageContext';
import i18nCommon from '../../i18n/common';
import i18nPage from '../../i18n/university/dashboard';

const translations = { ...i18nCommon, ...i18nPage };

/**
 * Mirrors app/Views/university/dashboard.php, talking to the real JSON API
 * (GET /api/v1/dashboards/overview — App\Controllers\Api\
 * DashboardsApiController::overview() → universityOverview(), the exact
 * same source-of-truth list the web controller's own dashboard.php
 * consumes: ProjectRepository::countByStatusForUniversity/
 * monthlyActivityForUniversity/facultyCategoryBreakdownForUniversity/
 * departmentBreakdownForUniversity/academicYearBreakdownForUniversity/
 * mostViewedForUniversity/recentForUniversity, ProjectAnalyticsRepository::
 * mostInteractedForUniversity, ProjectApprovalService::queueForUniversity,
 * AIAnalysisRepository::readinessForProjects). Nothing here is invented —
 * every widget below reads a key the API already returns.
 *
 * The PHP view's CSS-only mobile recomposition (kpi-scroll strip, three
 * mobile tab groups, collapsed Innovation Statistics accordion) is
 * preserved via explicit React tab state, same convention as
 * StudentDashboard.jsx.
 *
 * Round 8 (Phase 26/28) addition — a separate, best-effort pair of calls
 * to GET /api/v1/exam-system/university/dashboard (ExamAnalyticsApiController
 * ::universityDashboard -> ExamAnalyticsService::scopedDashboard(), scoped
 * to this university via UniversityRepository::findByUserId(), never from
 * client input) and GET /api/v1/exam-system/university/exams (every
 * faculty, per ExamRepository::forUniversityScope()) feed the new "Exams"
 * section below — same independent-request pattern as
 * FacultyDashboard.jsx/StudentDashboard.jsx; a failure just hides that one
 * section.
 */

const EXAM_STATUS_META = {
  draft: { cls: 'badge-neutral', en: 'Draft', ar: 'مسودة' },
  scheduled: { cls: 'badge-primary', en: 'Scheduled', ar: 'مجدول' },
  published: { cls: 'badge-success', en: 'Published', ar: 'منشور' },
  closed: { cls: 'badge-neutral', en: 'Closed', ar: 'مغلق' },
};

function StatCard({ label, value, icon, accent, trend }) {
  return (
    <div className="card glass-panel" style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-4)' }}>
      <span style={{ width: 44, height: 44, borderRadius: 'var(--radius-md)', flexShrink: 0, background: accent, color: '#fff', display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
        <Icon name={icon} size={20} />
      </span>
      <div>
        <p className="text-caption" style={{ margin: 0 }}>{label}</p>
        <p className="text-h2" style={{ margin: 0 }}>{value}</p>
        {trend && <p className="text-caption" style={{ margin: 0 }}>{trend}</p>}
      </div>
    </div>
  );
}

function BarRow({ label, total, max, colorFrom, colorTo }) {
  const pct = Math.round((total / max) * 100);
  return (
    <div style={{ marginBottom: 'var(--space-3)' }}>
      <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: 'var(--space-1)' }}>
        <span className="text-small">{label}</span>
        <span className="text-caption text-mono">{total}</span>
      </div>
      <div style={{ height: 8, borderRadius: 'var(--radius-full)', background: 'var(--glass-bg)', overflow: 'hidden' }}>
        <div style={{ height: '100%', width: `${pct}%`, borderRadius: 'var(--radius-full)', background: `linear-gradient(90deg, ${colorFrom}, ${colorTo})` }} />
      </div>
    </div>
  );
}

const STATUS_META = {
  pending: { cls: 'badge-primary', en: 'Pending', ar: 'قيد الانتظار' },
  approved: { cls: 'badge-success', en: 'Approved', ar: 'معتمد' },
  rejected: { cls: 'badge-danger', en: 'Rejected', ar: 'مرفوض' },
  changes_requested: { cls: 'badge-neutral', en: 'Changes Requested', ar: 'تعديلات مطلوبة' },
};

const PROJECT_STATUS_META = {
  submitted: { cls: 'badge-primary', en: 'Submitted', ar: 'مُقدَّم' },
  under_review: { cls: 'badge-primary', en: 'Under Review', ar: 'قيد المراجعة' },
  approved: { cls: 'badge-success', en: 'Approved', ar: 'معتمد' },
  rejected: { cls: 'badge-danger', en: 'Rejected', ar: 'مرفوض' },
  published: { cls: 'badge-success', en: 'Published', ar: 'منشور' },
  archived: { cls: 'badge-neutral', en: 'Archived', ar: 'مؤرشف' },
};

function projectTitle(row, locale) {
  const en = row.title_en || '';
  const ar = row.title_ar || '';
  return (locale === 'ar' ? (ar || en) : (en || ar)) || '';
}

export default function UniversityDashboard() {
  const t = useTranslations(translations);
  const { locale } = useLanguage();
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [activityTab, setActivityTab] = useState('activity');
  const [breakdownTab, setBreakdownTab] = useState('dept');
  const [listTab, setListTab] = useState('viewed');
  const [examStats, setExamStats] = useState(null);
  const [examList, setExamList] = useState([]);

  useEffect(() => {
    let cancelled = false;
    api.get('/api/v1/dashboards/overview')
      .then((json) => { if (!cancelled) setData(json.data); })
      .catch((err) => { if (!cancelled) setError(errorMessage(err)); })
      .finally(() => { if (!cancelled) setLoading(false); });
    return () => { cancelled = true; };
  }, []);

  useEffect(() => {
    let cancelled = false;
    Promise.all([
      api.get('/api/v1/exam-system/university/dashboard'),
      api.get('/api/v1/exam-system/university/exams'),
    ])
      .then(([statsJson, examsJson]) => {
        if (cancelled) return;
        setExamStats(statsJson.data || null);
        setExamList(examsJson.data || []);
      })
      .catch(() => { /* best-effort — exam widget just stays hidden */ });
    return () => { cancelled = true; };
  }, []);

  if (loading) return <p className="text-small" style={{ padding: 'var(--space-5)' }}>{t('Loading…')}</p>;
  if (error) return <p style={{ color: 'var(--color-danger)', padding: 'var(--space-5)' }}>{error}</p>;
  if (!data) return null;

  const university = data.university || null;
  const universityName = university
    ? (locale === 'ar' ? (university.official_name_ar || university.official_name_en) : (university.official_name_en || university.official_name_ar))
    : t('Your University');
  const isVerified = university ? university.verification_status === 'verified' : false;

  const totalStudents = data.total_students || 0;
  const underReview = data.under_review || 0;
  const approvalRate = data.approval_rate || 0;
  const activity = (data.activity || []).map((point) => ({
    label: point.label?.[locale] || point.label?.en || '',
    value: point.value,
  }));
  const pendingQueue = data.pending_queue || [];
  const avgReadiness = data.avg_readiness;
  const scoredCount = data.scored_count || 0;
  const disciplineTrends = data.discipline_trends || [];
  const departmentBreakdown = data.department_breakdown || [];
  const yearBreakdown = data.year_breakdown || [];
  const mostViewed = data.most_viewed || [];
  const mostInteracted = data.most_interacted || [];
  const recentProjects = data.recent_projects || [];

  const maxActivity = activity.length ? Math.max(1, ...activity.map((p) => p.value)) : 1;
  const maxDept = departmentBreakdown.length ? Math.max(1, ...departmentBreakdown.map((r) => Number(r.total))) : 1;
  const maxYear = yearBreakdown.length ? Math.max(1, ...yearBreakdown.map((r) => Number(r.total))) : 1;

  return (
    <>
      <div className="page-header animate-rise-in">
        <div className="page-header__title">
          <p className="text-caption" style={{ margin: 0 }}>{t('Welcome back')}</p>
          <h1 className="text-h1">{universityName} 🎓</h1>
          <p className="text-small">{locale === 'ar' ? 'إليك ملخص نشاط الابتكار في جامعتك اليوم.' : "Here's a snapshot of your university's innovation activity today."}</p>
        </div>
        <div className="page-header__actions">
          <Link to="/university/approvals" className="btn btn-primary">
            <Icon name="check-circle" size={18} /> {t('Review Approvals')}
          </Link>
        </div>
      </div>

      <div style={{ marginBottom: 'var(--space-4)' }}>
      </div>

      <div className="grid-4 kpi-scroll" style={{ marginBottom: 'var(--space-6)' }}>
        <StatCard label={locale === 'ar' ? 'إجمالي الطلاب' : 'Total Students'} value={totalStudents} icon="users" accent="var(--uip-indigo-600)" />
        <StatCard label={locale === 'ar' ? 'مشاريع قيد المراجعة' : 'Projects Under Review'} value={underReview} icon="clock" accent="var(--uip-gold-600)" />
        <StatCard label={locale === 'ar' ? 'معدل الاعتماد' : 'Approval Rate'} value={`${approvalRate}%`} icon="check-circle" accent="var(--uip-teal-600)" />
        <StatCard
          label={locale === 'ar' ? 'حالة التوثيق' : 'Verification Status'}
          value={isVerified ? t('Verified') : t('Unverified')}
          icon="shield"
          accent="var(--uip-coral-600)"
        />
        {avgReadiness !== null && avgReadiness !== undefined ? (
          <StatCard
            label={locale === 'ar' ? 'متوسط درجة الجاهزية بالذكاء الاصطناعي' : 'Avg. AI Readiness Score'}
            value={avgReadiness}
            icon="sparkles"
            accent="var(--uip-gold-600)"
            trend={locale === 'ar' ? `عبر ${scoredCount} مشروع مُحلَّل` : `across ${scoredCount} analyzed project(s)`}
          />
        ) : (
          <StatCard
            label={locale === 'ar' ? 'متوسط درجة الجاهزية بالذكاء الاصطناعي' : 'Avg. AI Readiness Score'}
            value="—"
            icon="sparkles"
            accent="var(--uip-gold-600)"
            trend={t('No projects analyzed yet')}
          />
        )}
      </div>

      <div data-tabs-scope>
        <div className="tabs" data-tabs data-mobile-tabs style={{ marginBottom: 'var(--space-5)' }}>
          <button type="button" className={`tab-link${activityTab === 'activity' ? ' is-active' : ''}`} onClick={() => setActivityTab('activity')}>{t('Activity')}</button>
          <button type="button" className={`tab-link${activityTab === 'approvals' ? ' is-active' : ''}`} onClick={() => setActivityTab('approvals')}>{t('Awaiting Approval')}</button>
        </div>

        <div className="grid-2">
          <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-5)' }}>
            <div className={`card glass-panel mobile-tab-panel${activityTab === 'activity' ? ' is-active' : ''}`}>
              <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 'var(--space-5)' }}>
                <h2 className="text-h2">{t('Innovation Activity')}</h2>
                <span className="text-caption">{t('Projects submitted per month')}</span>
              </div>
              {activity.length === 0 ? (
                <p className="text-caption">{t('Not enough data yet.')}</p>
              ) : (
                <div className="chart-bars">
                  {activity.map((point, i) => {
                    const h = Math.max(6, Math.round((point.value / maxActivity) * 100));
                    return (
                      <div className="chart-bars__col" key={i}>
                        <span className="text-caption text-mono">{point.value}</span>
                        <div style={{ width: '100%', maxWidth: 36, height: `${h}%`, borderRadius: 'var(--radius-md) var(--radius-md) 0 0', background: 'linear-gradient(180deg, var(--color-primary), var(--color-accent))' }} />
                        <span className="text-caption">{point.label}</span>
                      </div>
                    );
                  })}
                </div>
              )}
            </div>

            <div className={`card glass-panel mobile-tab-panel${activityTab === 'approvals' ? ' is-active' : ''}`}>
              <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 'var(--space-4)' }}>
                <h2 className="text-h2">{t('Awaiting Your Approval')}</h2>
                <Link to="/university/approvals" className="btn btn-ghost btn-sm">
                  {t('View all')} <Icon name={locale === 'ar' ? 'chevron-left' : 'chevron-right'} size={14} />
                </Link>
              </div>
              {pendingQueue.length === 0 ? (
                <p className="text-caption">{t('Nothing awaiting your approval right now.')}</p>
              ) : pendingQueue.map((p) => {
                const meta = STATUS_META[p.status] || STATUS_META.pending;
                return (
                  <div key={p.id} style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 'var(--space-3)', padding: 'var(--space-3) 0', borderBottom: '1px solid var(--border-subtle)' }}>
                    <div>
                      <p className="text-small" style={{ margin: 0, fontWeight: 600 }}>{p.title?.[locale] || p.title?.en}</p>
                      <span className="text-caption">{p.student?.[locale] || p.student?.en} · {p.faculty?.[locale] || p.faculty?.en}</span>
                    </div>
                    <span className={`badge ${meta.cls}`}>{meta[locale]}</span>
                  </div>
                );
              })}
            </div>
          </div>

          <aside style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-5)' }} className="hide-on-mobile-tabs">
            <div className="card glass-panel">
              <h3 className="text-h3" style={{ marginBottom: 'var(--space-3)' }}>{t('Project Approval Path')}</h3>
              <div className="timeline">
                <div className="timeline-item is-done"><div className="timeline-item__dot"><Icon name="check" size={16} /></div><p className="text-small" style={{ margin: 0 }}>{t('Student submission')}</p></div>
                <div className="timeline-item is-done"><div className="timeline-item__dot"><Icon name="check" size={16} /></div><p className="text-small" style={{ margin: 0 }}>{t('Supervisor approval')}</p></div>
                <div className="timeline-item"><div className="timeline-item__dot"><Icon name="clock" size={16} /></div><p className="text-small" style={{ margin: 0 }}>{t('University approval (you are here)')}</p></div>
                <div className="timeline-item"><div className="timeline-item__dot"><Icon name="sparkles" size={16} /></div><p className="text-small" style={{ margin: 0 }}>{t('AI review')}</p></div>
              </div>
            </div>

            <div className="card glass-panel">
              <details className="mobile-accordion">
                <summary style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 'var(--space-3)' }}>
                  <h3 className="text-h3" style={{ margin: 0 }}>{t('Innovation Statistics')}</h3>
                  <span className="mobile-accordion__chevron"><Icon name="chevron-down" size={18} /></span>
                </summary>
                <div style={{ marginTop: 'var(--space-3)' }}>
                  {disciplineTrends.length === 0 ? (
                    <p className="text-caption">{t('Not enough data yet.')}</p>
                  ) : (
                    <>
                      {disciplineTrends.map((row, i) => (
                        <div key={i} style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', padding: 'var(--space-2) 0', borderBottom: '1px solid var(--border-subtle)' }}>
                          <span className="text-small">{row.faculty} · {t(row.category)}</span>
                          <span className="text-caption text-mono">{row.total}</span>
                        </div>
                      ))}
                      <p className="text-caption" style={{ marginTop: 'var(--space-3)' }}>{t('Top faculty × field combinations by submissions.')}</p>
                    </>
                  )}
                </div>
              </details>
            </div>
          </aside>
        </div>
      </div>

      <div data-tabs-scope style={{ marginTop: 'var(--space-6)' }}>
        <div className="tabs" data-tabs data-mobile-tabs style={{ marginBottom: 'var(--space-5)' }}>
          <button type="button" className={`tab-link${breakdownTab === 'dept' ? ' is-active' : ''}`} onClick={() => setBreakdownTab('dept')}>{t('By Department')}</button>
          <button type="button" className={`tab-link${breakdownTab === 'year' ? ' is-active' : ''}`} onClick={() => setBreakdownTab('year')}>{t('By Year')}</button>
        </div>
        <div className="grid-2">
          <div className={`card glass-panel mobile-tab-panel${breakdownTab === 'dept' ? ' is-active' : ''}`}>
            <h3 className="text-h3" style={{ marginBottom: 'var(--space-4)' }}>{t('By Department')}</h3>
            {departmentBreakdown.length === 0 ? (
              <p className="text-caption">{t('Not enough data yet.')}</p>
            ) : departmentBreakdown.map((row, i) => (
              <BarRow key={i} label={row.department} total={Number(row.total)} max={maxDept} colorFrom="var(--uip-indigo-600)" colorTo="var(--color-accent)" />
            ))}
          </div>

          <div className={`card glass-panel mobile-tab-panel${breakdownTab === 'year' ? ' is-active' : ''}`}>
            <h3 className="text-h3" style={{ marginBottom: 'var(--space-4)' }}>{t('By Academic Year')}</h3>
            {yearBreakdown.length === 0 ? (
              <p className="text-caption">{t('Not enough data yet.')}</p>
            ) : yearBreakdown.map((row, i) => (
              <BarRow
                key={i}
                label={locale === 'ar' ? `السنة ${row.academic_year}` : `Year ${row.academic_year}`}
                total={Number(row.total)}
                max={maxYear}
                colorFrom="var(--uip-coral-600)"
                colorTo="var(--color-accent)"
              />
            ))}
          </div>
        </div>
      </div>

      <div data-tabs-scope style={{ marginTop: 'var(--space-6)' }}>
        <div className="tabs" data-tabs data-mobile-tabs style={{ marginBottom: 'var(--space-5)' }}>
          <button type="button" className={`tab-link${listTab === 'viewed' ? ' is-active' : ''}`} onClick={() => setListTab('viewed')}>{t('Most Viewed')}</button>
          <button type="button" className={`tab-link${listTab === 'interacted' ? ' is-active' : ''}`} onClick={() => setListTab('interacted')}>{t('Most Interacted')}</button>
          <button type="button" className={`tab-link${listTab === 'recent' ? ' is-active' : ''}`} onClick={() => setListTab('recent')}>{t('Recent Projects')}</button>
        </div>
        <div className="grid-3">
          <div className={`card glass-panel mobile-tab-panel${listTab === 'viewed' ? ' is-active' : ''}`}>
            <h2 className="text-h2" style={{ marginBottom: 'var(--space-4)' }}>{t('Most Viewed')}</h2>
            {mostViewed.length === 0 ? (
              <p className="text-caption">{t('No views recorded yet.')}</p>
            ) : mostViewed.map((p) => (
              <div key={p.id} style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 'var(--space-3)', padding: 'var(--space-3) 0', borderBottom: '1px solid var(--border-subtle)' }}>
                <Link to={`/projects/${encodeURIComponent(p.slug || p.uuid)}`} className="text-small" style={{ fontWeight: 600, textDecoration: 'none', color: 'inherit' }}>{projectTitle(p, locale)}</Link>
                <span className="badge badge-neutral"><Icon name="eye" size={14} /> {p.views_count}</span>
              </div>
            ))}
          </div>

          <div className={`card glass-panel mobile-tab-panel${listTab === 'interacted' ? ' is-active' : ''}`}>
            <h2 className="text-h2" style={{ marginBottom: 'var(--space-4)' }}>{t('Most Interacted')}</h2>
            {mostInteracted.length === 0 ? (
              <p className="text-caption">{t('No interactions recorded yet.')}</p>
            ) : mostInteracted.map((p) => (
              <div key={p.id} style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 'var(--space-3)', padding: 'var(--space-3) 0', borderBottom: '1px solid var(--border-subtle)' }}>
                <Link to={`/projects/${encodeURIComponent(p.slug || p.uuid)}`} className="text-small" style={{ fontWeight: 600, textDecoration: 'none', color: 'inherit' }}>{projectTitle(p, locale)}</Link>
                <span className="badge badge-neutral"><Icon name="link" size={14} /> {p.interactions}</span>
              </div>
            ))}
          </div>

          <div className={`card glass-panel mobile-tab-panel${listTab === 'recent' ? ' is-active' : ''}`}>
            <h2 className="text-h2" style={{ marginBottom: 'var(--space-4)' }}>{t('Recent Projects')}</h2>
            {recentProjects.length === 0 ? (
              <p className="text-caption">{t('No projects yet.')}</p>
            ) : recentProjects.map((p) => {
              const meta = PROJECT_STATUS_META[p.status] || PROJECT_STATUS_META.submitted;
              return (
                <div key={p.id} style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 'var(--space-3)', padding: 'var(--space-3) 0', borderBottom: '1px solid var(--border-subtle)' }}>
                  <span className="text-small" style={{ fontWeight: 600 }}>{projectTitle(p, locale)}</span>
                  <span className={`badge ${meta.cls}`}>{meta[locale]}</span>
                </div>
              );
            })}
          </div>
        </div>
      </div>

      {examStats && (
        <div style={{ marginTop: 'var(--space-6)' }}>
          <h2 className="text-h2" style={{ marginBottom: 'var(--space-4)' }}>{t('Exams')}</h2>
          <div className="grid-4 kpi-scroll" style={{ marginBottom: 'var(--space-5)' }}>
            <StatCard label={t('Total Exams')} value={examStats.total_exams ?? 0} icon="file" accent="var(--uip-indigo-600)" />
            <StatCard label={t('Total Students')} value={examStats.total_students ?? 0} icon="users" accent="var(--uip-teal-600)" />
            <StatCard label={t('Total Attempts')} value={examStats.total_attempts ?? 0} icon="edit" accent="var(--uip-coral-600)" />
            <StatCard label={t('Average Performance')} value={examStats.average_performance !== null && examStats.average_performance !== undefined ? `${examStats.average_performance}%` : '—'} icon="chart" accent="var(--uip-gold-600)" />
          </div>
          <div className="card glass-panel">
            <h3 className="text-h3" style={{ marginBottom: 'var(--space-3)' }}>{t('University Exams')}</h3>
            {examList.length === 0 ? (
              <p className="text-caption">{t('No exams yet.')}</p>
            ) : (
              <div className="table-responsive">
                <table className="data-table">
                  <thead>
                    <tr>
                      <th>{t('Exam')}</th>
                      <th>{t('Status')}</th>
                      <th>{t('Attempts')}</th>
                      <th>{t('Graded')}</th>
                      <th>{t('Average Score')}</th>
                    </tr>
                  </thead>
                  <tbody>
                    {examList.slice(0, 8).map((e) => {
                      const meta = EXAM_STATUS_META[e.status] || EXAM_STATUS_META.draft;
                      return (
                        <tr key={e.id}>
                          <td>{e.title}</td>
                          <td><span className={`badge ${meta.cls}`}>{meta[locale]}</span></td>
                          <td>{e.total_attempts ?? 0}</td>
                          <td>{e.graded_count ?? 0}</td>
                          <td>{e.average_score !== null && e.average_score !== undefined ? `${e.average_score}%` : '—'}</td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
            )}
          </div>
        </div>
      )}
    </>
  );
}
