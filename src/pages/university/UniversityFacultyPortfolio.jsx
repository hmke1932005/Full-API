import { useCallback, useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { api, errorMessage } from '../../api/client';
import Icon from '../../components/Icon';
import { CredentialsModal, PasswordInput, generatePassword } from '../../components/people/credentials';
import { useTranslations, useLanguage } from '../../context/LanguageContext';
import i18nCommon from '../../i18n/common';
import i18nPage from '../../i18n/university/faculty-portfolio';

const translations = { ...i18nCommon, ...i18nPage };

/**
 * Mirrors app/Views/university/faculty-portfolio.php, talking to the real
 * JSON API — GET/PATCH /api/v1/faculty/{id} (App\Controllers\Api\
 * FacultyApiController::show()/update(), same FacultyRepository::
 * withProfileStats() the Blade page uses), POST /api/v1/faculty/{id}/
 * departments (department create) and PATCH /api/v1/departments/{id}
 * (visibility toggle), and the login-provisioning endpoints
 * (/faculty/{id}/login, /faculty/{id}/login/reset, plus manual
 * PATCH /faculty/{id}/login/email and /faculty/{id}/login/password).
 */

export default function UniversityFacultyPortfolio() {
  const { id } = useParams();
  const t = useTranslations(translations);
  const { locale } = useLanguage();
  const [faculty, setFaculty] = useState(null);
  const [departments, setDepartments] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [actionError, setActionError] = useState(null);
  const [actionOk, setActionOk] = useState(null);

  const load = useCallback(() => {
    setLoading(true);
    setError(null);
    api.get(`/api/v1/faculty/${id}`)
      .then((json) => {
        setFaculty(json.data?.faculty || null);
        setDepartments(json.data?.departments || []);
      })
      .catch((err) => setError(errorMessage(err)))
      .finally(() => setLoading(false));
  }, [id]);

  useEffect(() => { load(); }, [load]);

  async function handleVisibilityToggle(e) {
    e.preventDefault();
    setActionError(null);
    setActionOk(null);
    try {
      await api.patch(`/api/v1/faculty/${id}`, { is_public: e.target.elements.is_public.checked ? 1 : 0 });
      setActionOk(t('Save'));
      load();
    } catch (err) {
      setActionError(errorMessage(err));
    }
  }

  async function handleDetailsSave(nameEn, nameAr, description) {
    await api.patch(`/api/v1/faculty/${id}`, { name_en: nameEn, name_ar: nameAr, description });
    load();
  }

  async function handleArchive() {
    const confirmMsg = locale === 'ar'
      ? 'أرشفة الكلية؟ هتختفي من قائمة الكليات، وأقسامها وطلابها وأعضاء هيئة تدريسها هيفضلوا محفوظين وتقدر ترجعها في أي وقت.'
      : "Archive this faculty? It'll disappear from the faculties list; its departments, students and staff stay saved and you can restore it anytime.";
    if (!window.confirm(confirmMsg)) return;
    setActionError(null);
    try {
      await api.post(`/api/v1/faculty/${id}/archive`, {});
      load();
    } catch (err) {
      setActionError(errorMessage(err));
    }
  }

  async function handleUnarchive() {
    setActionError(null);
    try {
      await api.post(`/api/v1/faculty/${id}/unarchive`, {});
      load();
    } catch (err) {
      setActionError(errorMessage(err));
    }
  }

  async function handleAddDepartment(nameEn, nameAr) {
    setActionError(null);
    await api.post(`/api/v1/faculty/${id}/departments`, { name_en: nameEn, name_ar: nameAr });
    load();
  }

  async function handleDepartmentVisibility(dept) {
    setActionError(null);
    try {
      await api.patch(`/api/v1/departments/${dept.id}`, { is_public: dept.is_public ? 0 : 1 });
      load();
    } catch (err) {
      setActionError(errorMessage(err));
    }
  }

  if (loading) return <p className="text-small" style={{ padding: 'var(--space-5)' }}>{t('Loading…')}</p>;
  if (error) return <p style={{ color: 'var(--color-danger)', padding: 'var(--space-5)' }}>{error}</p>;
  if (!faculty) return null;

  const name = locale === 'ar' ? (faculty.name_ar || '') : (faculty.name_en || '');

  return (
    <>
      <div className="page-header animate-rise-in">
        <div className="page-header__title">
          <h1 className="text-h1"><Icon name="building" size={26} /> {name}</h1>
          <p className="text-small">
            {locale === 'ar'
              ? 'الملف العام للكلية — إحصاءاتها وأقسامها وظهورها العام.'
              : "The faculty's portfolio — its stats, departments, and public visibility."}
          </p>
        </div>
        <div className="page-header__actions" style={{ display: 'flex', gap: 'var(--space-2)', alignItems: 'center' }}>
          {faculty.status === 'archived' && (
            <span className="badge badge-neutral"><Icon name="archive" size={12} /> {locale === 'ar' ? 'مؤرشفة' : 'Archived'}</span>
          )}
          {faculty.is_public
            ? <span className="badge badge-success"><Icon name="eye" size={12} /> {t('Public')}</span>
            : <span className="badge badge-neutral"><Icon name="eye-off" size={12} /> {t('Private')}</span>}
          {faculty.status === 'archived' ? (
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

      {faculty.status === 'archived' && (
        <div className="card glass-panel" style={{ padding: 'var(--space-4)', marginBottom: 'var(--space-5)', borderInlineStart: '3px solid var(--color-warning, #e0a800)' }}>
          <p className="text-small" style={{ margin: 0 }}>
            {locale === 'ar'
              ? 'الكلية دي مؤرشفة — مش هتظهر في القوائم أو الدليل العام، ومش هتقدر تضيف أقسام جديدة ليها. كل بياناتها (الأقسام/الطلاب/أعضاء هيئة التدريس) لسه محفوظة. اضغط "استرجاع" عشان ترجّعها.'
              : 'This faculty is archived — it won\'t show in lists or the public directory, and you can\'t add new departments to it. All its data (departments/students/staff) is still saved. Click "Restore" to bring it back.'}
          </p>
        </div>
      )}

      <div className="grid-4" style={{ marginBottom: 'var(--space-6)' }}>
        <StatCard label={t('Departments')} value={faculty.departments_count} icon="layers" />
        <StatCard label={locale === 'ar' ? 'الطلاب' : 'Students'} value={faculty.students_count} icon="users" />
        <StatCard label={locale === 'ar' ? 'أعضاء هيئة التدريس' : 'Academic Staff'} value={faculty.academic_staff_count} icon="user" />
        <StatCard label={locale === 'ar' ? 'مشاريع منشورة' : 'Published Projects'} value={faculty.published_projects_count} icon="folder" />
      </div>

      <div className="grid-2">
        <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-5)' }}>
          <div className="card glass-panel" style={{ padding: 'var(--space-6)' }}>
            <h2 className="text-h3" style={{ marginBottom: 'var(--space-4)' }}>{t('Faculty Details')}</h2>
            <EditFacultyForm faculty={faculty} onSave={handleDetailsSave} />
          </div>

          <div className="card glass-panel" style={{ padding: 'var(--space-6)' }}>
            <h2 className="text-h3" style={{ marginBottom: 'var(--space-4)' }}>{t('Profile Visibility')}</h2>
            <form onSubmit={handleVisibilityToggle}>
              <div className="form-group" style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
                <div>
                  <label className="form-label" style={{ margin: 0 }}>{t('Make profile public')}</label>
                  <p className="text-caption" style={{ margin: '2px 0 0' }}>
                    {locale === 'ar'
                      ? 'عند التفعيل، يقدر أي حد معاه الرابط يشوف مشاريع الكلية المنشورة.'
                      : "When on, anyone with the link can view this faculty's published projects."}
                  </p>
                </div>
                <input type="checkbox" className="form-checkbox" name="is_public" defaultChecked={!!faculty.is_public} />
              </div>
              <button type="submit" className="btn btn-primary"><Icon name="check" size={18} /> {t('Save')}</button>
              {actionOk && <span className="text-caption" style={{ marginInlineStart: 'var(--space-2)', color: 'var(--color-success)' }}>✓</span>}
            </form>
          </div>

          <div>
            <h2 className="text-h3" style={{ marginBottom: 'var(--space-3)' }}>{t('Departments')}</h2>
            {departments.length === 0 && (
              <div className="card glass-panel" style={{ textAlign: 'center', padding: 'var(--space-8)', marginBottom: 'var(--space-5)' }}>
                <p className="text-small text-muted">{t('No departments added to this faculty yet.')}</p>
              </div>
            )}
            {departments.length > 0 && (
              <div className="table-responsive card glass-panel" style={{ marginBottom: 'var(--space-5)' }}>
                <table className="data-table">
                  <thead>
                    <tr>
                      <th>{locale === 'ar' ? 'القسم' : 'Department'}</th>
                      <th>{locale === 'ar' ? 'الحالة' : 'Status'}</th>
                      <th>{locale === 'ar' ? 'الظهور' : 'Visibility'}</th>
                      <th></th>
                    </tr>
                  </thead>
                  <tbody>
                    {departments.map((d) => {
                      const dName = locale === 'ar' ? (d.name_ar || d.name_en) : (d.name_en || d.name_ar);
                      return (
                        <tr key={d.id}>
                          <td><strong>{dName}</strong></td>
                          <td><span className={`badge ${d.status === 'active' ? 'badge-success' : 'badge-neutral'}`}>{t(d.status)}</span></td>
                          <td>
                            <button type="button" className="btn btn-outline btn-sm" onClick={() => handleDepartmentVisibility(d)}>
                              {d.is_public
                                ? <><Icon name="eye" size={12} /> {t('Public')}</>
                                : <><Icon name="eye-off" size={12} /> {t('Private')}</>}
                            </button>
                          </td>
                          <td>
                            <Link to={`/university/departments/${d.id}/portfolio`} className="btn btn-outline btn-sm">{t('View Portfolio')}</Link>
                          </td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
            )}

            <div className="card glass-panel">
              <h2 className="text-h4" style={{ marginBottom: 'var(--space-3)' }}>{t('Add Department')}</h2>
              {faculty.status === 'archived' && (
                <p className="text-small text-muted" style={{ marginTop: 0 }}>
                  {locale === 'ar'
                    ? 'استرجع الكلية الأول عشان تقدر تضيف أقسام جديدة.'
                    : 'Restore this faculty first to add new departments.'}
                </p>
              )}
              <AddDepartmentForm onAdd={handleAddDepartment} disabled={faculty.status === 'archived'} />
            </div>
          </div>
        </div>

        <aside style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-5)' }}>
          {faculty.logo_path && (
            <div className="card glass-panel" style={{ textAlign: 'center', padding: 'var(--space-6)' }}>
              <img src={faculty.logo_path} alt={`${name} logo`} style={{ width: 80, height: 80, borderRadius: 'var(--radius-lg)', objectFit: 'cover', margin: '0 auto var(--space-3)' }} />
            </div>
          )}
          {faculty.description && (
            <div className="card glass-panel">
              <h2 className="text-h3" style={{ marginBottom: 'var(--space-3)' }}>{t('About')}</h2>
              <p className="text-small">{faculty.description}</p>
            </div>
          )}
          <div className="card glass-panel">
            <h2 className="text-h3" style={{ marginBottom: 'var(--space-3)' }}>{t('Programs')}</h2>
            <p className="text-small">{faculty.programs_count ?? 0} {t("academic program(s) across this faculty's departments.")}</p>
          </div>

          <FacultyLoginCard facultyId={id} hasLogin={!!faculty.user_id} loginEmail={faculty.login_email || ''} onChanged={load} />
        </aside>
      </div>
    </>
  );
}

function StatCard({ label, value, icon }) {
  return (
    <div className="card glass-panel" style={{ padding: 'var(--space-5)' }}>
      <div className="text-caption" style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-1)' }}><Icon name={icon} size={14} /> {label}</div>
      <div className="text-h2">{value ?? 0}</div>
    </div>
  );
}

function EditFacultyForm({ faculty, onSave }) {
  const t = useTranslations(translations);
  const { locale } = useLanguage();
  const [nameEn, setNameEn] = useState(faculty.name_en || '');
  const [nameAr, setNameAr] = useState(faculty.name_ar || '');
  const [description, setDescription] = useState(faculty.description || '');
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
          <label className="form-label">{t('Faculty Name (English)')}</label>
          <input className="form-input" value={nameEn} onChange={(e) => setNameEn(e.target.value)} required maxLength={200} />
        </div>
        <div className="form-group">
          <label className="form-label">{t('Faculty Name (Arabic)')}</label>
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

function AddDepartmentForm({ onAdd, disabled }) {
  const t = useTranslations(translations);
  const { locale } = useLanguage();
  const [nameEn, setNameEn] = useState('');
  const [nameAr, setNameAr] = useState('');
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState(null);

  async function handleSubmit(e) {
    e.preventDefault();
    setSaving(true);
    setError(null);
    try {
      await onAdd(nameEn, nameAr);
      setNameEn(''); setNameAr('');
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
            <label className="form-label">{t('Department Name (English)')}</label>
            <input className="form-input" value={nameEn} onChange={(e) => setNameEn(e.target.value)} required maxLength={200} />
          </div>
          <div className="form-group">
            <label className="form-label">{t('Department Name (Arabic)')}</label>
            <input className="form-input" value={nameAr} onChange={(e) => setNameAr(e.target.value)} required maxLength={200} />
          </div>
        </div>
        {error && <p className="form-error">{error}</p>}
        <button type="submit" className="btn btn-primary btn-sm" style={{ marginTop: 'var(--space-2)' }} disabled={saving || disabled}>
          <Icon name="plus" size={16} /> {saving ? (locale === 'ar' ? 'جارٍ الإضافة…' : 'Adding…') : t('Add Department')}
        </button>
      </fieldset>
    </form>
  );
}

function FacultyLoginCard({ facultyId, hasLogin, loginEmail, onChanged }) {
  const t = useTranslations(translations);
  const { locale } = useLanguage();
  const [email, setEmail] = useState('');
  const [provPassword, setProvPassword] = useState('');
  const [created, setCreated] = useState(null);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState(null);
  const [ok, setOk] = useState(null);

  // Manual email/password change (in addition to Reset Password) —
  // separate fields so editing one doesn't clear the other.
  const [newEmail, setNewEmail] = useState(loginEmail || '');
  const [newPassword, setNewPassword] = useState('');
  const [emailSaving, setEmailSaving] = useState(false);
  const [passwordSaving, setPasswordSaving] = useState(false);

  useEffect(() => { setNewEmail(loginEmail || ''); }, [loginEmail]);

  async function handleProvision(e) {
    e.preventDefault();
    setSaving(true);
    setError(null);
    try {
      // Always send a known password so the admin can hand it over (email delivery isn't guaranteed).
      const password = provPassword.trim() || generatePassword();
      await api.post(`/api/v1/faculty/${facultyId}/login`, { email, password, locale });
      setCreated({ email, password });
    } catch (err) {
      setError(errorMessage(err));
    } finally {
      setSaving(false);
    }
  }

  async function handleReset() {
    if (!window.confirm(t('Generate a new password and email it?'))) return;
    setSaving(true);
    setError(null);
    setOk(null);
    try {
      await api.post(`/api/v1/faculty/${facultyId}/login/reset`, {});
      onChanged();
    } catch (err) {
      setError(errorMessage(err));
    } finally {
      setSaving(false);
    }
  }

  async function handleUpdateEmail(e) {
    e.preventDefault();
    setEmailSaving(true);
    setError(null);
    setOk(null);
    try {
      await api.patch(`/api/v1/faculty/${facultyId}/login/email`, { email: newEmail });
      setOk(t('Email updated'));
      onChanged();
    } catch (err) {
      setError(errorMessage(err));
    } finally {
      setEmailSaving(false);
    }
  }

  async function handleUpdatePassword(e) {
    e.preventDefault();
    setPasswordSaving(true);
    setError(null);
    setOk(null);
    try {
      await api.patch(`/api/v1/faculty/${facultyId}/login/password`, { password: newPassword });
      setNewPassword('');
      setOk(t('Password updated'));
      onChanged();
    } catch (err) {
      setError(errorMessage(err));
    } finally {
      setPasswordSaving(false);
    }
  }

  return (
    <div className="card glass-panel">
      {created && (
        <CredentialsModal
          title={locale === 'ar' ? 'تم إنشاء حساب الكلية' : 'Faculty login created'}
          email={created.email}
          password={created.password}
          message={locale === 'ar' ? 'سلّم بيانات الدخول لمسؤول الكلية.' : 'Hand these login details to the faculty administrator.'}
          onClose={() => { setCreated(null); onChanged(); }}
        />
      )}
      <h2 className="text-h3" style={{ marginBottom: 'var(--space-3)' }}>{t('Faculty Login')}</h2>
      {error && <p className="form-error">{error}</p>}
      {ok && <p className="text-small" style={{ color: 'var(--color-success, green)' }}>{ok}</p>}
      {hasLogin ? (
        <>
          <p className="text-small" style={{ margin: '0 0 var(--space-4)' }}>
            <span className="badge badge-success"><Icon name="check" size={12} /> {t('Account active')}</span>
          </p>

          <button type="button" className="btn btn-outline btn-sm" disabled={saving} onClick={handleReset} style={{ marginBottom: 'var(--space-4)' }}>
            <Icon name="mail" size={14} /> {t('Reset Password')}
          </button>

          <form onSubmit={handleUpdateEmail} className="form-group" style={{ marginBottom: 'var(--space-4)' }}>
            <label className="form-label">{t('Change Login Email')}</label>
            <div style={{ display: 'flex', gap: 'var(--space-2)', flexWrap: 'wrap' }}>
              <input
                className="form-input"
                type="email"
                required
                maxLength={190}
                placeholder={t('Faculty email')}
                value={newEmail}
                onChange={(e) => setNewEmail(e.target.value)}
                style={{ flex: 1, minWidth: 160 }}
              />
              <button type="submit" className="btn btn-primary btn-sm" disabled={emailSaving || !newEmail || newEmail === loginEmail}>
                <Icon name="check" size={14} /> {t('Update Email')}
              </button>
            </div>
          </form>

          <form onSubmit={handleUpdatePassword} className="form-group">
            <label className="form-label">{t('Change Login Password')}</label>
            <div style={{ display: 'flex', gap: 'var(--space-2)', flexWrap: 'wrap' }}>
              <input
                className="form-input"
                type="password"
                required
                minLength={8}
                maxLength={190}
                autoComplete="new-password"
                placeholder={t('New password')}
                value={newPassword}
                onChange={(e) => setNewPassword(e.target.value)}
                style={{ flex: 1, minWidth: 160 }}
              />
              <button type="submit" className="btn btn-primary btn-sm" disabled={passwordSaving || !newPassword}>
                <Icon name="lock" size={14} /> {t('Update Password')}
              </button>
            </div>
          </form>
        </>
      ) : (
        <>
          <p className="text-caption" style={{ marginBottom: 'var(--space-4)' }}>
            {locale === 'ar'
              ? 'أنشئ حساب دخول مستقل للكلية — هيقدر يدخل لوحة تحكم خاصة بيه (طلابه، اعتماد مشاريعه) بمعزل عن باقي الجامعة.'
              : "Create a standalone login for this faculty — it'll get its own dashboard (its students, its project approvals) separate from the rest of the university."}
          </p>
          <form onSubmit={handleProvision}>
            <div className="form-group">
              <label className="form-label">{t('Faculty email')}</label>
              <input className="form-input" type="email" required maxLength={190} value={email} onChange={(e) => setEmail(e.target.value)} />
            </div>
            <PasswordInput value={provPassword} onChange={setProvPassword} />
            <p className="text-caption">{locale === 'ar' ? 'يجب أن تحتوي على حرف كبير وصغير ورقم ورمز خاص.' : 'Must include upper and lower case letters, a number and a special character.'}</p>
            <button type="submit" className="btn btn-primary btn-sm" disabled={saving}><Icon name="plus" size={14} /> {t('Create Login')}</button>
          </form>
        </>
      )}
    </div>
  );
}
