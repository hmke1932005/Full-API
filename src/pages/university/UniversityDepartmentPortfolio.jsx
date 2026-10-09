import { useCallback, useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { api, errorMessage } from '../../api/client';
import Icon from '../../components/Icon';
import { useTranslations, useLanguage } from '../../context/LanguageContext';
import i18nCommon from '../../i18n/common';
import i18nPage from '../../i18n/university/department-portfolio';

const translations = { ...i18nCommon, ...i18nPage };

/**
 * Twin of UniversityFacultyPortfolio.jsx, one level deeper — talks to the
 * new GET/PATCH /api/v1/departments/{id}, POST /api/v1/departments/{id}/
 * archive|unarchive|programs, and PATCH /api/v1/programs/{id}
 * (FacultyApiController::showDepartment()/updateDepartment()/
 * archiveDepartment()/unarchiveDepartment()/storeProgramForDepartment()/
 * updateProgram()). Departments have no login of their own (no user_id
 * column on the Department model), so there's no login card here unlike
 * the faculty page.
 */
export default function UniversityDepartmentPortfolio() {
  const { id } = useParams();
  const t = useTranslations(translations);
  const { locale } = useLanguage();
  const [department, setDepartment] = useState(null);
  const [programs, setPrograms] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [actionError, setActionError] = useState(null);

  const load = useCallback(() => {
    setLoading(true);
    setError(null);
    api.get(`/api/v1/departments/${id}`)
      .then((json) => {
        setDepartment(json.data?.department || null);
        setPrograms(json.data?.programs || []);
      })
      .catch((err) => setError(errorMessage(err)))
      .finally(() => setLoading(false));
  }, [id]);

  useEffect(() => { load(); }, [load]);

  async function handleVisibilityToggle(e) {
    e.preventDefault();
    setActionError(null);
    try {
      await api.patch(`/api/v1/departments/${id}`, { is_public: e.target.elements.is_public.checked ? 1 : 0 });
      load();
    } catch (err) {
      setActionError(errorMessage(err));
    }
  }

  async function handleDetailsSave(nameEn, nameAr, description) {
    await api.patch(`/api/v1/departments/${id}`, { name_en: nameEn, name_ar: nameAr, description });
    load();
  }

  async function handleArchive() {
    const confirmMsg = locale === 'ar'
      ? 'أرشفة القسم؟ هيختفي من قائمة الأقسام، وبرامجه وطلابه وأعضاء هيئة تدريسه هيفضلوا محفوظين وتقدر ترجعه في أي وقت.'
      : "Archive this department? It'll disappear from the department list; its programs, students and staff stay saved and you can restore it anytime.";
    if (!window.confirm(confirmMsg)) return;
    setActionError(null);
    try {
      await api.post(`/api/v1/departments/${id}/archive`, {});
      load();
    } catch (err) {
      setActionError(errorMessage(err));
    }
  }

  async function handleUnarchive() {
    setActionError(null);
    try {
      await api.post(`/api/v1/departments/${id}/unarchive`, {});
      load();
    } catch (err) {
      setActionError(errorMessage(err));
    }
  }

  async function handleAddProgram(data) {
    setActionError(null);
    await api.post(`/api/v1/departments/${id}/programs`, data);
    load();
  }

  async function handleProgramVisibility(program) {
    setActionError(null);
    try {
      await api.patch(`/api/v1/programs/${program.id}`, { is_public: program.is_public ? 0 : 1 });
      load();
    } catch (err) {
      setActionError(errorMessage(err));
    }
  }

  if (loading) return <p className="text-small" style={{ padding: 'var(--space-5)' }}>{t('Loading…')}</p>;
  if (error) return <p style={{ color: 'var(--color-danger)', padding: 'var(--space-5)' }}>{error}</p>;
  if (!department) return null;

  const name = locale === 'ar' ? (department.name_ar || '') : (department.name_en || '');
  const facultyName = locale === 'ar' ? (department.faculty_name_ar || '') : (department.faculty_name_en || '');

  return (
    <>
      <div style={{ marginBottom: 'var(--space-3)' }}>
        <Link to={`/university/faculties/${department.faculty_id}/portfolio`} className="text-small" style={{ display: 'inline-flex', alignItems: 'center', gap: 'var(--space-1)' }}>
          <Icon name="arrow-left" size={14} /> {facultyName || (locale === 'ar' ? 'الرجوع لملف الكلية' : 'Back to Faculty Portfolio')}
        </Link>
      </div>

      <div className="page-header animate-rise-in">
        <div className="page-header__title">
          <h1 className="text-h1"><Icon name="layers" size={26} /> {name}</h1>
          <p className="text-small">
            {locale === 'ar'
              ? 'الملف العام للقسم — إحصاءاته وبرامجه وظهوره العام.'
              : "The department's portfolio — its stats, programs, and public visibility."}
          </p>
        </div>
        <div className="page-header__actions" style={{ display: 'flex', gap: 'var(--space-2)', alignItems: 'center' }}>
          {department.status === 'archived' && (
            <span className="badge badge-neutral"><Icon name="archive" size={12} /> {locale === 'ar' ? 'مؤرشف' : 'Archived'}</span>
          )}
          {department.is_public
            ? <span className="badge badge-success"><Icon name="eye" size={12} /> {t('Public')}</span>
            : <span className="badge badge-neutral"><Icon name="eye-off" size={12} /> {t('Private')}</span>}
          {department.status === 'archived' ? (
            <button type="button" className="btn btn-outline btn-sm" onClick={handleUnarchive}>
              <Icon name="refresh" size={14} /> {locale === 'ar' ? 'استرجاع' : 'Restore'}
            </button>
          ) : (
            <button type="button" className="btn btn-outline btn-sm" onClick={handleArchive}>
              <Icon name="archive" size={14} /> {locale === 'ar' ? 'أرشفة' : 'Archive'}
            </button>
          )}
        </div>
      </div>

      {actionError && <p style={{ color: 'var(--color-danger)' }}>{actionError}</p>}

      {department.status === 'archived' && (
        <div className="card glass-panel" style={{ padding: 'var(--space-4)', marginBottom: 'var(--space-5)', borderInlineStart: '3px solid var(--color-warning, #e0a800)' }}>
          <p className="text-small" style={{ margin: 0 }}>
            {locale === 'ar'
              ? 'القسم ده مؤرشف — مش هيظهر في القوائم أو الدليل العام، ومش هتقدر تضيف برامج جديدة ليه. كل بياناته (البرامج/الطلاب/أعضاء هيئة التدريس) لسه محفوظة. اضغط "استرجاع" عشان ترجّعه.'
              : 'This department is archived — it won\'t show in lists or the public directory, and you can\'t add new programs to it. All its data (programs/students/staff) is still saved. Click "Restore" to bring it back.'}
          </p>
        </div>
      )}

      <div className="grid-4" style={{ marginBottom: 'var(--space-6)' }}>
        <StatCard label={t('Programs')} value={department.programs_count} icon="layers" />
        <StatCard label={locale === 'ar' ? 'الطلاب' : 'Students'} value={department.students_count} icon="users" />
        <StatCard label={locale === 'ar' ? 'أعضاء هيئة التدريس' : 'Academic Staff'} value={department.academic_staff_count} icon="user" />
        <StatCard label={locale === 'ar' ? 'مشاريع منشورة' : 'Published Projects'} value={department.published_projects_count} icon="folder" />
      </div>

      <div className="grid-2">
        <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-5)' }}>
          <div className="card glass-panel" style={{ padding: 'var(--space-6)' }}>
            <h2 className="text-h3" style={{ marginBottom: 'var(--space-4)' }}>{t('Department Details')}</h2>
            <EditDepartmentForm department={department} onSave={handleDetailsSave} />
          </div>

          <div className="card glass-panel" style={{ padding: 'var(--space-6)' }}>
            <h2 className="text-h3" style={{ marginBottom: 'var(--space-4)' }}>{t('Profile Visibility')}</h2>
            <form onSubmit={handleVisibilityToggle}>
              <div className="form-group" style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
                <div>
                  <label className="form-label" style={{ margin: 0 }}>{t('Make profile public')}</label>
                  <p className="text-caption" style={{ margin: '2px 0 0' }}>
                    {locale === 'ar'
                      ? 'عند التفعيل، يقدر أي حد معاه الرابط يشوف برامج القسم المنشورة.'
                      : "When on, anyone with the link can view this department's published programs."}
                  </p>
                </div>
                <input type="checkbox" className="form-checkbox" name="is_public" defaultChecked={!!department.is_public} />
              </div>
              <button type="submit" className="btn btn-primary"><Icon name="check" size={18} /> {t('Save')}</button>
            </form>
          </div>

          <div>
            <h2 className="text-h3" style={{ marginBottom: 'var(--space-3)' }}>{t('Programs')}</h2>
            {programs.length === 0 && (
              <div className="card glass-panel" style={{ textAlign: 'center', padding: 'var(--space-8)', marginBottom: 'var(--space-5)' }}>
                <p className="text-small text-muted">{t('No programs added to this department yet.')}</p>
              </div>
            )}
            {programs.length > 0 && (
              <div className="table-responsive card glass-panel" style={{ marginBottom: 'var(--space-5)' }}>
                <table className="data-table">
                  <thead>
                    <tr>
                      <th>{locale === 'ar' ? 'البرنامج' : 'Program'}</th>
                      <th>{locale === 'ar' ? 'الدرجة' : 'Degree'}</th>
                      <th>{locale === 'ar' ? 'الحالة' : 'Status'}</th>
                      <th>{locale === 'ar' ? 'الظهور' : 'Visibility'}</th>
                    </tr>
                  </thead>
                  <tbody>
                    {programs.map((p) => {
                      const pName = locale === 'ar' ? (p.name_ar || p.name_en) : (p.name_en || p.name_ar);
                      return (
                        <tr key={p.id}>
                          <td><strong>{pName}</strong>{p.code ? <span className="text-caption"> ({p.code})</span> : null}</td>
                          <td>{degreeLabel(p.degree_type, locale)}</td>
                          <td><span className={`badge ${p.status === 'active' ? 'badge-success' : 'badge-neutral'}`}>{t(p.status)}</span></td>
                          <td>
                            <button type="button" className="btn btn-outline btn-sm" onClick={() => handleProgramVisibility(p)}>
                              {p.is_public
                                ? <><Icon name="eye" size={12} /> {t('Public')}</>
                                : <><Icon name="eye-off" size={12} /> {t('Private')}</>}
                            </button>
                          </td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
            )}

            <div className="card glass-panel">
              <h2 className="text-h4" style={{ marginBottom: 'var(--space-3)' }}>{locale === 'ar' ? 'إضافة برنامج' : 'Add Program'}</h2>
              {department.status === 'archived' && (
                <p className="text-small text-muted" style={{ marginTop: 0 }}>
                  {locale === 'ar'
                    ? 'استرجع القسم الأول عشان تقدر تضيف برامج جديدة.'
                    : 'Restore this department first to add new programs.'}
                </p>
              )}
              <AddProgramForm onAdd={handleAddProgram} disabled={department.status === 'archived'} />
            </div>
          </div>
        </div>

        <aside style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-5)' }}>
          {department.description && (
            <div className="card glass-panel">
              <h2 className="text-h3" style={{ marginBottom: 'var(--space-3)' }}>{t('About')}</h2>
              <p className="text-small">{department.description}</p>
            </div>
          )}
        </aside>
      </div>
    </>
  );
}

function degreeLabel(type, locale) {
  const labels = {
    diploma: locale === 'ar' ? 'دبلوم' : 'Diploma',
    bachelor: locale === 'ar' ? 'بكالوريوس' : 'Bachelor',
    master: locale === 'ar' ? 'ماجستير' : 'Master',
    phd: locale === 'ar' ? 'دكتوراه' : 'PhD',
    other: locale === 'ar' ? 'أخرى' : 'Other',
  };
  return labels[type] || type;
}

function StatCard({ label, value, icon }) {
  return (
    <div className="card glass-panel" style={{ padding: 'var(--space-5)' }}>
      <div className="text-caption" style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-1)' }}><Icon name={icon} size={14} /> {label}</div>
      <div className="text-h2">{value ?? 0}</div>
    </div>
  );
}

function EditDepartmentForm({ department, onSave }) {
  const t = useTranslations(translations);
  const { locale } = useLanguage();
  const [nameEn, setNameEn] = useState(department.name_en || '');
  const [nameAr, setNameAr] = useState(department.name_ar || '');
  const [description, setDescription] = useState(department.description || '');
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState(null);
  const [ok, setOk] = useState(false);

  async function handleSubmit(e) {
    e.preventDefault();
    setSaving(true);
    setError(null);
    setOk(false);
    try {
      await onSave(nameEn, nameAr, description);
      setOk(true);
    } catch (err) {
      setError(errorMessage(err));
    } finally {
      setSaving(false);
    }
  }

  return (
    <form onSubmit={handleSubmit}>
      <div className="grid-2">
        <div className="form-group">
          <label className="form-label">{t('Department Name (English)')}</label>
          <input className="form-input" value={nameEn} onChange={(e) => setNameEn(e.target.value)} required maxLength={200} />
        </div>
        <div className="form-group">
          <label className="form-label">{t('Department Name (Arabic)')}</label>
          <input className="form-input" value={nameAr} onChange={(e) => setNameAr(e.target.value)} required maxLength={200} />
        </div>
        <div className="form-group" style={{ gridColumn: 'span 2' }}>
          <label className="form-label">{t('Short Description')}</label>
          <textarea className="form-input" rows={3} value={description} onChange={(e) => setDescription(e.target.value)} />
        </div>
      </div>
      {error && <p className="form-error">{error}</p>}
      <button type="submit" className="btn btn-primary" disabled={saving}>
        <Icon name="check" size={18} /> {saving ? (locale === 'ar' ? 'جارٍ الحفظ…' : 'Saving…') : t('Save')}
      </button>
      {ok && <span className="text-caption" style={{ marginInlineStart: 'var(--space-2)', color: 'var(--color-success)' }}>✓</span>}
    </form>
  );
}

function AddProgramForm({ onAdd, disabled }) {
  const { locale } = useLanguage();
  const [nameEn, setNameEn] = useState('');
  const [nameAr, setNameAr] = useState('');
  const [degreeType, setDegreeType] = useState('bachelor');
  const [code, setCode] = useState('');
  const [durationYears, setDurationYears] = useState('');
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState(null);

  async function handleSubmit(e) {
    e.preventDefault();
    setSaving(true);
    setError(null);
    try {
      await onAdd({ name_en: nameEn, name_ar: nameAr, degree_type: degreeType, code: code || undefined, duration_years: durationYears || undefined });
      setNameEn(''); setNameAr(''); setCode(''); setDurationYears(''); setDegreeType('bachelor');
    } catch (err) {
      setError(errorMessage(err));
    } finally {
      setSaving(false);
    }
  }

  return (
    <form onSubmit={handleSubmit} style={disabled ? { opacity: 0.5, pointerEvents: 'none' } : undefined}>
      <fieldset disabled={disabled} style={{ border: 0, padding: 0, margin: 0 }}>
        <div className="grid-2">
          <div className="form-group">
            <label className="form-label">{locale === 'ar' ? 'اسم البرنامج (إنجليزي)' : 'Program Name (English)'}</label>
            <input className="form-input" value={nameEn} onChange={(e) => setNameEn(e.target.value)} required maxLength={200} />
          </div>
          <div className="form-group">
            <label className="form-label">{locale === 'ar' ? 'اسم البرنامج (عربي)' : 'Program Name (Arabic)'}</label>
            <input className="form-input" value={nameAr} onChange={(e) => setNameAr(e.target.value)} required maxLength={200} />
          </div>
          <div className="form-group">
            <label className="form-label">{locale === 'ar' ? 'الدرجة العلمية' : 'Degree Type'}</label>
            <select className="form-input" value={degreeType} onChange={(e) => setDegreeType(e.target.value)}>
              <option value="diploma">{locale === 'ar' ? 'دبلوم' : 'Diploma'}</option>
              <option value="bachelor">{locale === 'ar' ? 'بكالوريوس' : 'Bachelor'}</option>
              <option value="master">{locale === 'ar' ? 'ماجستير' : 'Master'}</option>
              <option value="phd">{locale === 'ar' ? 'دكتوراه' : 'PhD'}</option>
              <option value="other">{locale === 'ar' ? 'أخرى' : 'Other'}</option>
            </select>
          </div>
          <div className="form-group">
            <label className="form-label">{locale === 'ar' ? 'كود البرنامج (اختياري)' : 'Program Code (optional)'}</label>
            <input className="form-input" value={code} onChange={(e) => setCode(e.target.value)} maxLength={50} />
          </div>
          <div className="form-group">
            <label className="form-label">{locale === 'ar' ? 'مدة الدراسة بالسنوات (اختياري)' : 'Duration in Years (optional)'}</label>
            <input className="form-input" type="number" min="0" step="0.5" value={durationYears} onChange={(e) => setDurationYears(e.target.value)} />
          </div>
        </div>
        {error && <p className="form-error">{error}</p>}
        <button type="submit" className="btn btn-primary btn-sm" style={{ marginTop: 'var(--space-2)' }} disabled={saving || disabled}>
          <Icon name="plus" size={16} /> {saving ? (locale === 'ar' ? 'جارٍ الإضافة…' : 'Adding…') : (locale === 'ar' ? 'إضافة برنامج' : 'Add Program')}
        </button>
      </fieldset>
    </form>
  );
}
