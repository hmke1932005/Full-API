import { useCallback, useEffect, useMemo, useState } from 'react';
import { api, errorMessage } from '../../api/client';
import Icon from '../Icon';
import { CourseGradebook } from './CourseGradebook';
import { useLanguage } from '../../context/LanguageContext';
import { EmptyState, Skeleton } from '../student/stUi';
import { PersonAvatar, StaffHead } from '../staff/stfUi';
import { ExKpi } from '../exam/examUi';
import { Field, FieldGrid, FormModal, FormSection } from '../people/formUi';

/**
 * Courses (المواد) management — one component for three portals:
 *   role="university"      → all courses of the university, can pick the faculty
 *   role="faculty"         → courses of the faculty, assigns teachers
 *   role="academic_staff"  → courses of the doctor's faculty; creates courses and manages the
 *                            ones they teach (see who is enrolled, add / remove students)
 * API: /api/v1/courses (see CourseApiController). Students pick their courses themselves from
 * pages/student/StudentCourses.jsx.
 */

const YEARS = [1, 2, 3, 4, 5, 6, 7, 8];

export default function CoursesManager({ role }) {
  const { locale } = useLanguage();
  const ar = locale === 'ar';
  const isUni = role === 'university';
  const isStaff = role === 'academic_staff';
  const canAssign = !isStaff; // teachers are assigned by the faculty / university

  const [courses, setCourses] = useState([]);
  const [meta, setMeta] = useState({ faculties: [], departments: [] });
  const faculties = meta.faculties;
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [notice, setNotice] = useState(null);

  const [q, setQ] = useState('');
  const [status, setStatus] = useState('active');
  const [facultyFilter, setFacultyFilter] = useState('');
  const [mine, setMine] = useState(false);

  const [editing, setEditing] = useState(null); // null | 'new' | course
  const [managing, setManaging] = useState(null); // course
  const [busyId, setBusyId] = useState(null);

  const load = useCallback(() => {
    const params = { status, locale };
    if (q.trim()) params.q = q.trim();
    if (isUni && facultyFilter) params.faculty_id = facultyFilter;
    if (isStaff && mine) params.mine = 1;
    return api.get('/api/v1/courses', params).then((j) => setCourses(j.data || []));
  }, [q, status, facultyFilter, mine, isUni, isStaff, locale]);

  useEffect(() => {
    setLoading(true);
    const h = setTimeout(() => {
      load().catch((err) => setError(errorMessage(err))).finally(() => setLoading(false));
    }, q ? 250 : 0);
    return () => clearTimeout(h);
  }, [load, q]);

  useEffect(() => {
    api.get('/api/v1/courses/meta').then((j) => setMeta({ faculties: j.data?.faculties || [], departments: j.data?.departments || [] })).catch(() => {});
  }, []);

  const kpis = useMemo(() => ({
    total: courses.length,
    students: courses.reduce((a, c) => a + (c.students_count || 0), 0),
    noTeacher: courses.filter((c) => (c.staff || []).length === 0).length,
  }), [courses]);

  async function toggleArchive(c) {
    const archiving = c.status === 'active';
    if (archiving && !window.confirm(ar ? 'أرشفة هذه المادة؟ لن تظهر للطلاب بعد الآن.' : 'Archive this course? Students will no longer see it.')) return;
    setBusyId(c.id);
    setError(null);
    try {
      const json = await api.post(`/api/v1/courses/${c.id}/${archiving ? 'archive' : 'restore'}`, { locale });
      setNotice(json.message);
      await load();
    } catch (err) {
      setError(errorMessage(err));
    } finally {
      setBusyId(null);
    }
  }


  if (isStaff) {
    const modals = (
      <>
        {editing && (
          <CourseFormModal
            role={role}
            course={editing === 'new' ? null : editing}
            meta={meta}
            onClose={() => setEditing(null)}
            onDone={(msg) => { setEditing(null); setNotice(msg); load(); }}
          />
        )}
        {managing && (
          <CourseManageModal
            course={managing}
            canAssign={canAssign}
            onClose={() => { setManaging(null); load(); }}
          />
        )}
      </>
    );
    const myCount = courses.filter((c) => c.can_manage).length;
    return (
      <div className="ex-page">
        <StaffHead
          eyebrow={ar ? 'مساحة التدريس' : 'Teaching workspace'}
          title={ar ? 'المواد' : 'Courses'}
          subtitle={ar ? 'أضف موادك وشاهد الطلاب المسجّلين في كل مادة.' : 'Add your courses and see exactly which students are enrolled in each.'}
          actions={(
            <button type="button" className="btn btn-primary" onClick={() => setEditing('new')}>
              <Icon name="plus" size={16} /> {ar ? 'إضافة مادة' : 'Add course'}
            </button>
          )}
        />

        <div className="ex-kpis">
          <ExKpi label={ar ? 'إجمالي المواد' : 'Total courses'} value={kpis.total} sub={status === 'active' ? (ar ? 'مواد نشطة' : 'Active courses') : (ar ? 'مواد مؤرشفة' : 'Archived courses')} icon="file" />
          <ExKpi label={ar ? 'موادي' : 'My courses'} value={myCount} sub={ar ? 'يمكنك إدارتها' : 'You can manage'} icon="check-circle" tone="good" />
          <ExKpi label={ar ? 'تسجيلات الطلاب' : 'Student enrolments'} value={kpis.students} sub={ar ? 'عبر كل المواد' : 'Across all courses'} icon="users" />
          <ExKpi label={ar ? 'بلا مدرّس' : 'No teacher yet'} value={kpis.noTeacher} sub={ar ? 'تحتاج تعيين' : 'Need an assignment'} icon="alert-triangle" tone={kpis.noTeacher ? 'warn' : ''} />
        </div>

        {error && <div className="st-alert st-alert--danger"><Icon name="alert-triangle" size={16} /><span>{error}</span></div>}
        {notice && <div className="st-alert st-alert--success"><Icon name="check-circle" size={16} /><span>{notice}</span></div>}

        <div className="st-tabs" role="tablist">
          {[['active', ar ? 'النشطة' : 'Active'], ['archived', ar ? 'المؤرشفة' : 'Archived']].map(([k, label]) => (
            <button key={k} type="button" role="tab" aria-selected={status === k} className={`st-tab${status === k ? ' is-active' : ''}`} onClick={() => setStatus(k)}>{label}</button>
          ))}
        </div>

        <div className="st-toolbar xc-toolbar">
          <label className="st-search">
            <Icon name="search" size={16} />
            <input className="form-input" type="search" value={q} onChange={(e) => setQ(e.target.value)} placeholder={ar ? 'ابحث بالكود أو اسم المادة…' : 'Search by code or course name…'} aria-label={ar ? 'بحث' : 'Search'} />
          </label>
          <label className="text-small" style={{ display: 'inline-flex', alignItems: 'center', gap: 6 }}>
            <input type="checkbox" className="ex-check-input" checked={mine} onChange={(e) => setMine(e.target.checked)} />
            {ar ? 'موادي فقط' : 'My courses only'}
          </label>
        </div>

        {loading ? (
          <Skeleton h={120} count={3} />
        ) : courses.length === 0 ? (
          <EmptyState icon="file" title={q ? (ar ? 'لا توجد مواد مطابقة' : 'No courses match') : (ar ? 'لا توجد مواد بعد' : 'No courses yet')} text={q ? undefined : (ar ? 'أضف أول مادة ليتمكن الطلاب من التسجيل فيها.' : 'Add the first course so students can enrol.')}>
            {!q && status === 'active' && (
              <button type="button" className="btn btn-primary" onClick={() => setEditing('new')}><Icon name="plus" size={16} /> {ar ? 'إضافة مادة' : 'Add course'}</button>
            )}
          </EmptyState>
        ) : (
          <div className="st-cards st-ecards xc-grid">
            {courses.map((c) => {
              const meta2 = [
                c.credit_hours != null ? `${c.credit_hours} ${ar ? 'ساعات' : 'cr.'}` : null,
                c.academic_year ? (ar ? `السنة ${c.academic_year}` : `Year ${c.academic_year}`) : null,
                c.semester ? (ar ? `الفصل ${c.semester}` : `Sem ${c.semester}`) : null,
              ].filter(Boolean).join(' · ');
              const teachers = c.staff || [];
              return (
                <article key={c.id} className={`st-pcard st-ecard xc-card xc-card--${c.status === 'active' ? 'completed' : 'archived'}`}>
                  <div className="st-pcard__top">
                    <span className="xc-course-code">{c.code}</span>
                    {c.can_manage
                      ? <span className="ex-status ex-status--live">{ar ? 'موادي' : 'Mine'}</span>
                      : <span className="ex-status ex-status--idle">{ar ? 'عرض فقط' : 'View only'}</span>}
                  </div>
                  <div>
                    <h3>{ar ? (c.name_ar || c.name_en) : c.name_en}</h3>
                    <div className="xc-sub">{meta2 || '—'}</div>
                  </div>
                  <ul className="st-ecard__facts">
                    <li><Icon name="building" size={14} /><span>{c.faculty_name || (ar ? 'كل الجامعة' : 'Whole university')}{c.department_name ? ` · ${c.department_name}` : ''}</span></li>
                    <li><Icon name="users" size={14} /><span>{c.students_count} {ar ? 'طالب مسجّل' : 'enrolled students'}</span></li>
                  </ul>
                  <div className="xc-staff">
                    {teachers.length === 0
                      ? <span>{ar ? 'لم يُعيَّن مدرّس بعد' : 'No teacher assigned'}</span>
                      : teachers.slice(0, 3).map((s) => <span key={s.id ?? s.full_name} className="ex-inline"><PersonAvatar name={s.full_name} /> {s.full_name}</span>)}
                    {teachers.length > 3 && <span>+{teachers.length - 3}</span>}
                  </div>
                  <div className="st-pcard__actions xc-actions">
                    {c.can_manage ? (
                      <>
                        <button type="button" className="btn btn-primary btn-sm" onClick={() => setManaging(c)}>{ar ? 'الطلاب' : 'Students'}</button>
                        <span className="xc-tools">
                          <button type="button" className="ex-icon-btn" title={ar ? 'تعديل' : 'Edit'} aria-label={ar ? 'تعديل' : 'Edit'} onClick={() => setEditing(c)}><Icon name="edit" size={15} /></button>
                          <button type="button" className="ex-icon-btn" disabled={busyId === c.id} title={c.status === 'active' ? (ar ? 'أرشفة' : 'Archive') : (ar ? 'استعادة' : 'Restore')} aria-label={c.status === 'active' ? (ar ? 'أرشفة' : 'Archive') : (ar ? 'استعادة' : 'Restore')} onClick={() => toggleArchive(c)}>
                            <Icon name={c.status === 'active' ? 'archive' : 'refresh'} size={15} />
                          </button>
                        </span>
                      </>
                    ) : <span className="is-muted" style={{ fontSize: 12 }}>{ar ? 'ليس لديك صلاحية إدارة هذه المادة' : 'You cannot manage this course'}</span>}
                  </div>
                </article>
              );
            })}
          </div>
        )}
        {modals}
      </div>
    );
  }

  return (
    <>
      <div className="page-header animate-rise-in">
        <div className="page-header__title">
          <h1 className="text-h1">{ar ? 'المواد' : 'Courses'}</h1>
          <p className="text-small">
            {isStaff
              ? (ar ? 'أضف موادك وشاهد الطلاب المسجّلين في كل مادة.' : 'Add your courses and see exactly which students are enrolled in each.')
              : (ar ? 'أضف المواد وعيّن مدرّسيها ليسجّل الطلاب فيها من لوحاتهم.' : 'Add courses and assign their teachers — students enrol from their own dashboard.')}
          </p>
        </div>
        <div className="page-header__actions">
          <button type="button" className="btn btn-primary" onClick={() => setEditing('new')}>
            <Icon name="plus" size={16} /> {ar ? 'إضافة مادة' : 'Add course'}
          </button>
        </div>
      </div>

      <div className="ex-kpis" style={{ marginBottom: 'var(--space-4)' }}>
        <ExKpi label={ar ? 'إجمالي المواد' : 'Total courses'} value={kpis.total} sub={status === 'active' ? (ar ? 'مواد نشطة' : 'Active courses') : (ar ? 'مواد مؤرشفة' : 'Archived courses')} icon="file" />
        <ExKpi label={ar ? 'تسجيلات الطلاب' : 'Student enrolments'} value={kpis.students} sub={ar ? 'عبر كل المواد' : 'Across all courses'} icon="users" tone="good" />
        {!isStaff && <ExKpi label={ar ? 'بلا مدرّس' : 'No teacher yet'} value={kpis.noTeacher} sub={ar ? 'تحتاج تعيين' : 'Need an assignment'} icon="alert-triangle" tone={kpis.noTeacher ? 'warn' : ''} />}
      </div>

      {error && <div className="st-alert st-alert--danger"><Icon name="alert-triangle" size={16} /><span>{error}</span></div>}
      {notice && <p className="text-small" style={{ color: 'var(--color-success)' }}>{notice}</p>}

      <div className="ex-card">
        <div className="ex-tabs" role="tablist">
          {[['active', ar ? 'النشطة' : 'Active'], ['archived', ar ? 'المؤرشفة' : 'Archived']].map(([k, label]) => (
            <button key={k} type="button" role="tab" aria-selected={status === k} className={`ex-tab${status === k ? ' is-active' : ''}`} onClick={() => setStatus(k)}>{label}</button>
          ))}
        </div>
        <div className="ex-toolbar">
          <div className="ex-search">
            <Icon name="search" size={15} />
            <input className="ex-input" type="search" value={q} onChange={(e) => setQ(e.target.value)} placeholder={ar ? 'ابحث بالكود أو اسم المادة' : 'Search by code or course name'} aria-label={ar ? 'بحث' : 'Search'} />
          </div>
          {isUni && (
            <select className="ex-select" value={facultyFilter} onChange={(e) => setFacultyFilter(e.target.value)} aria-label={ar ? 'الكلية' : 'Faculty'}>
              <option value="">{ar ? 'كل الكليات' : 'All faculties'}</option>
              {faculties.map((f) => <option key={f.id} value={f.id}>{ar ? (f.name_ar || f.name_en) : f.name_en}</option>)}
            </select>
          )}
          {isStaff && (
            <label className="text-small" style={{ display: 'inline-flex', alignItems: 'center', gap: 6 }}>
              <input type="checkbox" className="ex-check-input" checked={mine} onChange={(e) => setMine(e.target.checked)} />
              {ar ? 'موادي فقط' : 'My courses only'}
            </label>
          )}
        </div>

        {loading ? (
          <div style={{ padding: 24 }}><Skeleton h={56} count={3} /></div>
        ) : courses.length === 0 ? (
          <div style={{ padding: 24 }}>
            <EmptyState icon="file" title={q ? (ar ? 'لا توجد مواد مطابقة' : 'No courses match') : (ar ? 'لا توجد مواد بعد' : 'No courses yet')} text={q ? undefined : (ar ? 'أضف أول مادة ليتمكن الطلاب من التسجيل فيها.' : 'Add the first course so students can enrol.')}>
              {!q && status === 'active' && (
                <button type="button" className="btn btn-primary" onClick={() => setEditing('new')}><Icon name="plus" size={16} /> {ar ? 'إضافة مادة' : 'Add course'}</button>
              )}
            </EmptyState>
          </div>
        ) : (
          <div className="ex-table-wrap">
            <table className="ex-table">
              <thead>
                <tr>
                  <th>{ar ? 'المادة' : 'Course'}</th>
                  <th>{ar ? 'الكلية / القسم' : 'Faculty / department'}</th>
                  <th>{ar ? 'المدرّسون' : 'Teachers'}</th>
                  <th>{ar ? 'الطلاب' : 'Students'}</th>
                  <th className="is-end">{ar ? 'إجراء' : 'Action'}</th>
                </tr>
              </thead>
              <tbody>
                {courses.map((c) => (
                  <tr key={c.id}>
                    <td style={{ minWidth: 220 }}>
                      <span className="ex-chip" style={{ marginBottom: 4 }}>{c.code}</span>
                      <div className="is-title">{ar ? (c.name_ar || c.name_en) : c.name_en}</div>
                      <div className="is-muted" style={{ fontSize: 12, marginTop: 2 }}>
                        {[c.credit_hours != null ? `${c.credit_hours} ${ar ? 'ساعات' : 'cr.'}` : null, c.academic_year ? (ar ? `السنة ${c.academic_year}` : `Year ${c.academic_year}`) : null, c.semester ? (ar ? `الفصل ${c.semester}` : `Sem ${c.semester}`) : null].filter(Boolean).join(' · ') || '—'}
                      </div>
                    </td>
                    <td className="is-muted">
                      {c.faculty_name || (ar ? 'كل الجامعة' : 'Whole university')}
                      {c.department_name && <div style={{ fontSize: 12 }}>{c.department_name}</div>}
                    </td>
                    <td className="is-muted">
                      {(c.staff || []).length === 0 ? '—' : c.staff.map((s) => s.full_name).join('، ')}
                    </td>
                    <td>
                      <button type="button" className="ex-link" style={{ background: 'none', border: 0, cursor: c.can_manage ? 'pointer' : 'default' }} disabled={!c.can_manage} onClick={() => setManaging(c)}>
                        <span className="ex-inline"><Icon name="users" size={14} />{c.students_count}</span>
                      </button>
                    </td>
                    <td className="is-end" style={{ whiteSpace: 'nowrap' }}>
                      {c.can_manage ? (
                        <>
                          <button type="button" className="ex-link" style={{ background: 'none', border: 0, cursor: 'pointer' }} onClick={() => setManaging(c)}>{ar ? 'الطلاب والمدرّسون' : (canAssign ? 'Students & teachers' : 'Students')}</button>
                          <button type="button" className="ex-icon-btn" style={{ marginInlineStart: 6 }} title={ar ? 'تعديل' : 'Edit'} aria-label={ar ? 'تعديل' : 'Edit'} onClick={() => setEditing(c)}><Icon name="edit" size={15} /></button>
                          <button type="button" className="ex-icon-btn" disabled={busyId === c.id} title={c.status === 'active' ? (ar ? 'أرشفة' : 'Archive') : (ar ? 'استعادة' : 'Restore')} aria-label={c.status === 'active' ? (ar ? 'أرشفة' : 'Archive') : (ar ? 'استعادة' : 'Restore')} onClick={() => toggleArchive(c)}>
                            <Icon name={c.status === 'active' ? 'archive' : 'refresh'} size={15} />
                          </button>
                        </>
                      ) : <span className="is-muted" style={{ fontSize: 12 }}>{ar ? 'عرض فقط' : 'View only'}</span>}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>

      {editing && (
        <CourseFormModal
          role={role}
          course={editing === 'new' ? null : editing}
          meta={meta}
          onClose={() => setEditing(null)}
          onDone={(msg) => { setEditing(null); setNotice(msg); load(); }}
        />
      )}
      {managing && (
        <CourseManageModal
          course={managing}
          canAssign={canAssign}
          onClose={() => { setManaging(null); load(); }}
        />
      )}
    </>
  );
}

/* ------------------------------------------------------------------------------------- */

function CourseFormModal({ role, course, meta, onClose, onDone }) {
  const faculties = meta.faculties;
  const { locale } = useLanguage();
  const ar = locale === 'ar';
  const isUni = role === 'university';
  const editing = !!course;

  const [f, setF] = useState({
    code: course?.code || '', name_en: course?.name_en || '', name_ar: course?.name_ar || '', description: course?.description || '',
    credit_hours: course?.credit_hours ?? '', academic_year: course?.academic_year ?? '', semester: course?.semester ?? '',
    faculty_id: course?.faculty_id ?? '', department_id: course?.department_id ?? '',
  });
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState(null);
  const set = (patch) => setF((p) => ({ ...p, ...patch }));

  // departments: university → of the chosen faculty; faculty / doctor → their own faculty (server already scoped)
  const departments = isUni ? meta.departments.filter((d) => String(d.faculty_id) === String(f.faculty_id)) : meta.departments;

  async function submit(e) {
    e.preventDefault();
    setSaving(true);
    setError(null);
    const num = (v) => (v === '' || v == null ? null : Number(v));
    const body = {
      code: f.code.trim(), name_en: f.name_en.trim(), name_ar: f.name_ar.trim() || null, description: f.description.trim() || null,
      credit_hours: num(f.credit_hours), academic_year: num(f.academic_year), semester: num(f.semester),
      department_id: num(f.department_id), locale,
    };
    if (isUni) body.faculty_id = num(f.faculty_id);
    try {
      const json = editing ? await api.patch(`/api/v1/courses/${course.id}`, body) : await api.post('/api/v1/courses', body);
      onDone(json.message);
    } catch (err) {
      setError(errorMessage(err));
      setSaving(false);
    }
  }

  return (
    <FormModal
      title={editing ? (ar ? 'تعديل المادة' : 'Edit course') : (ar ? 'إضافة مادة' : 'Add a course')}
      subtitle={ar ? 'سيتمكن طلاب الكلية من التسجيل في هذه المادة من لوحاتهم.' : 'Students of the faculty will be able to enrol in it from their dashboard.'}
      icon="file"
      onClose={onClose}
      onSubmit={submit}
      saving={saving}
      submitLabel={editing ? (ar ? 'حفظ' : 'Save') : (ar ? 'إضافة المادة' : 'Add course')}
      error={error}
    >
      <FormSection title={ar ? 'بيانات المادة' : 'Course details'}>
        <FieldGrid>
          <Field label={ar ? 'كود المادة' : 'Course code'} required hint={ar ? 'مثل CS101' : 'e.g. CS101'}>
            <input className="form-input" value={f.code} onChange={(e) => set({ code: e.target.value })} required maxLength={40} autoFocus />
          </Field>
          <Field label={ar ? 'الساعات المعتمدة' : 'Credit hours'}>
            <input className="form-input" type="number" min="0" max="30" value={f.credit_hours} onChange={(e) => set({ credit_hours: e.target.value })} />
          </Field>
          <Field label={ar ? 'الاسم (إنجليزي)' : 'Name (English)'} required>
            <input className="form-input" value={f.name_en} onChange={(e) => set({ name_en: e.target.value })} required maxLength={200} />
          </Field>
          <Field label={ar ? 'الاسم (عربي)' : 'Name (Arabic)'}>
            <input className="form-input" value={f.name_ar} onChange={(e) => set({ name_ar: e.target.value })} maxLength={200} dir="rtl" />
          </Field>
          <Field label={ar ? 'الوصف' : 'Description'} wide>
            <textarea className="form-input" rows={2} value={f.description} onChange={(e) => set({ description: e.target.value })} maxLength={2000} />
          </Field>
        </FieldGrid>
      </FormSection>

      <FormSection title={ar ? 'التسكين' : 'Placement'} hint={isUni ? (ar ? 'اتركها بلا كلية لتكون المادة متاحة لكل الجامعة.' : 'Leave the faculty empty to open the course to the whole university.') : (ar ? 'تُضاف المادة إلى كليتك.' : 'The course is added to your faculty.')}>
        <FieldGrid>
          {isUni && (
            <Field label={ar ? 'الكلية' : 'Faculty'}>
              <select className="form-input" value={f.faculty_id} onChange={(e) => set({ faculty_id: e.target.value, department_id: '' })}>
                <option value="">{ar ? '— كل الجامعة —' : '— Whole university —'}</option>
                {faculties.map((x) => <option key={x.id} value={x.id}>{ar ? (x.name_ar || x.name_en) : x.name_en}</option>)}
              </select>
            </Field>
          )}
          <Field label={ar ? 'القسم' : 'Department'}>
            <select className="form-input" value={f.department_id} onChange={(e) => set({ department_id: e.target.value })} disabled={isUni && !f.faculty_id}>
              <option value="">{ar ? '— بدون قسم —' : '— No department —'}</option>
              {departments.map((d) => <option key={d.id} value={d.id}>{ar ? (d.name_ar || d.name_en) : d.name_en}</option>)}
            </select>
          </Field>
          <Field label={ar ? 'السنة الدراسية' : 'Academic year'}>
            <select className="form-input" value={f.academic_year} onChange={(e) => set({ academic_year: e.target.value })}>
              <option value="">{ar ? '— أي سنة —' : '— Any year —'}</option>
              {YEARS.map((y) => <option key={y} value={y}>{ar ? `السنة ${y}` : `Year ${y}`}</option>)}
            </select>
          </Field>
          <Field label={ar ? 'الفصل' : 'Semester'}>
            <select className="form-input" value={f.semester} onChange={(e) => set({ semester: e.target.value })}>
              <option value="">{ar ? '— أي فصل —' : '— Any —'}</option>
              <option value="1">{ar ? 'الفصل الأول' : 'Semester 1'}</option>
              <option value="2">{ar ? 'الفصل الثاني' : 'Semester 2'}</option>
            </select>
          </Field>
        </FieldGrid>
      </FormSection>
    </FormModal>
  );
}

/* ------------------------------------------------------------------------------------- */

function CourseManageModal({ course, canAssign, onClose }) {
  const { locale } = useLanguage();
  const ar = locale === 'ar';
  const [tab, setTab] = useState('students');
  const [roster, setRoster] = useState([]);
  const [staff, setStaff] = useState(course.staff || []);
  const [allStaff, setAllStaff] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [flash, setFlash] = useState(null);
  const [adding, setAdding] = useState(false);

  const loadRoster = useCallback(() => api.get(`/api/v1/courses/${course.id}/students`, { locale }).then((j) => setRoster(j.data || [])), [course.id, locale]);

  useEffect(() => {
    loadRoster().catch((err) => setError(errorMessage(err))).finally(() => setLoading(false));
  }, [loadRoster]);

  useEffect(() => {
    if (!canAssign || tab !== 'teachers' || allStaff.length) return;
    api.get('/api/v1/academic-staff', { per_page: 200 }).then((j) => setAllStaff(j.data || [])).catch(() => {});
  }, [canAssign, tab, allStaff.length]);

  async function removeStudent(s) {
    if (!window.confirm(ar ? `إزالة ${s.full_name} من المادة؟` : `Remove ${s.full_name} from this course?`)) return;
    setError(null);
    try {
      await api.delete(`/api/v1/courses/${course.id}/students/${s.id}`);
      setRoster((r) => r.filter((x) => x.id !== s.id));
    } catch (err) { setError(errorMessage(err)); }
  }

  async function assign(id) {
    setError(null);
    try {
      await api.post(`/api/v1/courses/${course.id}/staff`, { academic_staff_id: Number(id), locale });
      const picked = allStaff.find((x) => String(x.id) === String(id));
      if (picked) setStaff((s) => (s.some((x) => x.id === picked.id) ? s : [...s, { id: picked.id, full_name: picked.full_name }]));
    } catch (err) { setError(errorMessage(err)); }
  }

  async function unassign(id) {
    setError(null);
    try {
      await api.delete(`/api/v1/courses/${course.id}/staff/${id}`);
      setStaff((s) => s.filter((x) => x.id !== id));
    } catch (err) { setError(errorMessage(err)); }
  }

  const availableStaff = allStaff.filter((a) => !staff.some((s) => s.id === a.id) && (!course.faculty_id || !a.faculty_id || Number(a.faculty_id) === Number(course.faculty_id)));

  return (
    <div className="modal-overlay" onClick={onClose}>
      <div className="pf-modal" onClick={(e) => e.stopPropagation()}>
        <header className="pf-head">
          <span className="pf-head__icon"><Icon name="users" size={20} /></span>
          <div className="pf-head__text">
            <h2>{course.code} — {ar ? (course.name_ar || course.name_en) : course.name_en}</h2>
            <p>{roster.length} {ar ? 'طالب مسجّل' : 'enrolled students'}</p>
          </div>
          <button type="button" className="pf-x" onClick={onClose} aria-label={ar ? 'إغلاق' : 'Close'}><Icon name="x" size={18} /></button>
        </header>

        <div className="ex-tabs" role="tablist" style={{ padding: '0 16px' }}>
          <button type="button" role="tab" aria-selected={tab === 'students'} className={`ex-tab${tab === 'students' ? ' is-active' : ''}`} onClick={() => setTab('students')}>{ar ? 'الطلاب' : 'Students'}<small>({roster.length})</small></button>
          <button type="button" role="tab" aria-selected={tab === 'grades'} className={`ex-tab${tab === 'grades' ? ' is-active' : ''}`} onClick={() => setTab('grades')}>{ar ? 'الدرجات' : 'Grades'}</button>
          {canAssign && <button type="button" role="tab" aria-selected={tab === 'teachers'} className={`ex-tab${tab === 'teachers' ? ' is-active' : ''}`} onClick={() => setTab('teachers')}>{ar ? 'المدرّسون' : 'Teachers'}<small>({staff.length})</small></button>}
        </div>

        <div className="pf-body" style={{ paddingTop: 16 }}>
          {error && <div className="pf-error" role="alert"><Icon name="alert-triangle" size={16} /><span>{error}</span></div>}
          {flash && <p className="text-small" style={{ color: 'var(--color-success)' }}>{flash}</p>}

          {tab === 'students' && (
            <>
              <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 12, marginBottom: 12, flexWrap: 'wrap' }}>
                <p className="text-small" style={{ margin: 0 }}>{ar ? 'الطلاب الذين سجّلوا في هذه المادة أو أضفتهم يدويًا.' : 'Students who enrolled in this course or were added by hand.'}</p>
                <button type="button" className="btn btn-outline btn-sm" onClick={() => setAdding(true)}><Icon name="plus" size={14} /> {ar ? 'إضافة طلاب' : 'Add students'}</button>
              </div>
              {loading ? <Skeleton h={44} count={3} /> : roster.length === 0 ? (
                <EmptyState icon="users" title={ar ? 'لا يوجد طلاب مسجّلون بعد' : 'No students enrolled yet'} text={ar ? 'سيظهر هنا كل طالب يختار هذه المادة من لوحته.' : 'Every student who picks this course from their dashboard will appear here.'} />
              ) : (
                <div className="ex-table-wrap">
                  <table className="ex-table">
                    <thead><tr><th>{ar ? 'الطالب' : 'Student'}</th><th>{ar ? 'الرقم الجامعي' : 'Student ID'}</th><th>{ar ? 'القسم' : 'Department'}</th><th>{ar ? 'المصدر' : 'Source'}</th><th /></tr></thead>
                    <tbody>
                      {roster.map((s) => (
                        <tr key={s.id}>
                          <td><div className="ex-student-cell"><PersonAvatar name={s.full_name} /><div style={{ minWidth: 0 }}><strong>{s.full_name}</strong><small>{s.email}</small></div></div></td>
                          <td className="is-muted">{s.student_number || '—'}</td>
                          <td className="is-muted">{s.department || s.faculty || '—'}</td>
                          <td className="is-muted">{s.source === 'staff' ? (ar ? 'أُضيف يدويًا' : 'Added by staff') : (ar ? 'سجّل بنفسه' : 'Self-enrolled')}</td>
                          <td className="is-end"><button type="button" className="ex-icon-btn ex-icon-btn--danger" title={ar ? 'إزالة' : 'Remove'} aria-label={ar ? 'إزالة' : 'Remove'} onClick={() => removeStudent(s)}><Icon name="trash" size={15} /></button></td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
            </>
          )}

          {tab === 'grades' && <CourseGradebook courseId={course.id} />}

          {tab === 'teachers' && (
            <>
              {staff.length === 0 ? (
                <p className="text-small">{ar ? 'لم يُعيَّن مدرّس لهذه المادة بعد.' : 'No teacher is assigned to this course yet.'}</p>
              ) : (
                <div style={{ display: 'flex', flexWrap: 'wrap', gap: 8, marginBottom: 16 }}>
                  {staff.map((s) => (
                    <span key={s.id} className="ex-status ex-status--submitted" style={{ gap: 8, padding: '5px 8px 5px 12px' }}>
                      {s.full_name}
                      <button type="button" onClick={() => unassign(s.id)} aria-label={ar ? 'إزالة' : 'Remove'} style={{ background: 'none', border: 0, cursor: 'pointer', color: 'inherit', display: 'grid' }}><Icon name="x" size={13} /></button>
                    </span>
                  ))}
                </div>
              )}
              <Field label={ar ? 'تعيين مدرّس' : 'Assign a teacher'}>
                <select className="form-input" value="" onChange={(e) => e.target.value && assign(e.target.value)}>
                  <option value="">{availableStaff.length ? (ar ? '— اختر دكتورًا / معيدًا —' : '— Select a doctor / TA —') : (ar ? 'لا يوجد أعضاء متاحون' : 'No staff available')}</option>
                  {availableStaff.map((a) => <option key={a.id} value={a.id}>{a.full_name}</option>)}
                </select>
              </Field>
            </>
          )}
        </div>
      </div>

      {adding && (
        <AddStudentsModal
          course={course}
          onClose={() => setAdding(false)}
          onDone={(msg) => { setAdding(false); setFlash(msg); loadRoster(); }}
        />
      )}
    </div>
  );
}

/* ------------------------------------------------------------------------------------- */

function AddStudentsModal({ course, onClose, onDone }) {
  const { locale } = useLanguage();
  const ar = locale === 'ar';
  const [q, setQ] = useState('');
  const [rows, setRows] = useState([]);
  const [picked, setPicked] = useState(new Set());
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState(null);

  useEffect(() => {
    setLoading(true);
    const h = setTimeout(() => {
      api.get(`/api/v1/courses/${course.id}/students/candidates`, q.trim() ? { q: q.trim() } : {})
        .then((j) => setRows(j.data || []))
        .catch((err) => setError(errorMessage(err)))
        .finally(() => setLoading(false));
    }, q ? 300 : 0);
    return () => clearTimeout(h);
  }, [q, course.id]);

  const toggle = (id) => setPicked((s) => { const n = new Set(s); if (n.has(id)) n.delete(id); else n.add(id); return n; });

  async function submit(e) {
    e.preventDefault();
    setSaving(true);
    setError(null);
    try {
      const json = await api.post(`/api/v1/courses/${course.id}/students`, { student_ids: [...picked], locale });
      onDone(json.message);
    } catch (err) {
      setError(errorMessage(err));
      setSaving(false);
    }
  }

  return (
    <FormModal
      title={ar ? 'إضافة طلاب إلى المادة' : 'Add students to the course'}
      subtitle={ar ? 'الطلاب غير المسجّلين في هذه المادة.' : 'Students who are not enrolled in this course yet.'}
      icon="users"
      onClose={onClose}
      onSubmit={submit}
      saving={saving}
      submitDisabled={picked.size === 0}
      submitLabel={ar ? `إضافة (${picked.size})` : `Add (${picked.size})`}
      error={error}
    >
      <div className="ex-search" style={{ marginBottom: 12 }}>
        <Icon name="search" size={15} />
        <input className="ex-input" type="search" value={q} onChange={(e) => setQ(e.target.value)} placeholder={ar ? 'ابحث بالاسم أو الرقم الجامعي' : 'Search by name or student ID'} autoFocus />
      </div>
      {loading ? <Skeleton h={40} count={3} /> : rows.length === 0 ? (
        <p className="text-small" style={{ textAlign: 'center', padding: 20 }}>{ar ? 'لا يوجد طلاب متاحون للإضافة.' : 'No students available to add.'}</p>
      ) : (
        <div className="ex-table-wrap">
          <table className="ex-table">
            <tbody>
              {rows.map((s) => (
                <tr key={s.id} onClick={() => toggle(s.id)} style={{ cursor: 'pointer' }}>
                  <td style={{ width: 40 }}><input type="checkbox" className="ex-check-input" checked={picked.has(s.id)} onChange={() => toggle(s.id)} aria-label={s.full_name} /></td>
                  <td><div className="ex-student-cell"><PersonAvatar name={s.full_name} /><div style={{ minWidth: 0 }}><strong>{s.full_name}</strong><small>{s.email}</small></div></div></td>
                  <td className="is-muted">{s.student_number || '—'}</td>
                  <td className="is-muted">{s.department || s.faculty || '—'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </FormModal>
  );
}
