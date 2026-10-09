import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { api, errorMessage } from '../../api/client';
import Icon from '../../components/Icon';
import AvatarUploader from '../../components/insight/AvatarUploader';
import SettingsShell from '../../components/settings/SettingsShell';
import { ROW_BETWEEN, SavedBadge, NotificationsCard, AppearanceCard, LanguageCard } from '../settings/shared';
import { useTranslations, useLanguage } from '../../context/LanguageContext';
import i18nCommon from '../../i18n/common';
import i18nPage from '../../i18n/university/settings';

const translations = { ...i18nCommon, ...i18nPage };

/**
 * Mirrors app/Views/university/settings.php, talking to the real JSON API —
 * NOT a new controller. UniversitySettingsController (web) is session-only
 * and always 302-redirects, unusable for this Bearer-auth SPA, but its
 * exact same logic already has a JSON twin at UniversitiesApiController's
 * self-service section (see routes/api.php '/universities/me*'):
 *   - GET   /api/v1/universities/me                    → profile + prefs
 *   - PATCH /api/v1/universities/me                     → profile fields
 *   - POST  /api/v1/universities/me/logo                → logo upload
 *   - PATCH /api/v1/universities/me/preferences          → approval workflow
 *   - PATCH /api/v1/universities/me/notification-preferences
 * No password/2FA/team routes exist for this portal,
 * so this page only has the cards those endpoints actually back.
 */

const BASE = '/api/v1/universities/me';

export default function UniversitySettings() {
  const t = useTranslations(translations);
  const { locale } = useLanguage();
  const [data, setData] = useState(null);
  const [loadError, setLoadError] = useState(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    let cancelled = false;
    api.get(BASE)
      .then((json) => { if (!cancelled) setData(json.data); })
      .catch((err) => { if (!cancelled) setLoadError(errorMessage(err)); })
      .finally(() => { if (!cancelled) setLoading(false); });
    return () => { cancelled = true; };
  }, []);

  if (loading) return <p className="text-small" style={{ padding: 'var(--space-5)' }}>{t('Loading settings…')}</p>;
  if (loadError) return <p style={{ color: 'var(--color-danger)', padding: 'var(--space-5)' }}>{loadError}</p>;
  if (!data) return null;

  // No /preferences-for-theme endpoint on this portal's API: Appearance and
  // Language apply live (and are remembered on this device).
  const sections = [
    {
      key: 'profile', label: { en: 'Profile', ar: 'الملف الشخصي' }, icon: 'user',
      content: (
        <>
          <div className="card glass-panel">
            <h2 className="text-h3" style={{ marginBottom: 4 }}>{locale === 'ar' ? 'الصورة الشخصية' : 'Profile photo'}</h2>
            <p className="text-small" style={{ marginBottom: 20 }}>{locale === 'ar' ? 'صورة حسابك الشخصي (ليست شعار الجامعة).' : 'Your personal account photo (not the university logo).'}</p>
            <AvatarUploader
              name={data.university?.official_name_en || data.university?.official_name_ar}
              endpoint="/api/v1/universities/me/avatar"
              profileEndpoint="/api/v1/universities/me"
            />
          </div>
          <ProfileCard university={data.university} />
          <LogoCard logoPath={data.university?.logo_path} />
        </>
      ),
    },
    {
      key: 'workflow', label: { en: 'Approval Workflow', ar: 'مسار الاعتماد' }, icon: 'check-circle',
      content: (
        <>
          <PreferencesCard
            autoApproveThreshold={data.auto_approve_threshold}
            notifyOnSubmission={data.notify_on_submission}
          />
          <Link to="/university/supervisors" className="card glass-panel" style={{ display: 'block', textDecoration: 'none', color: 'inherit' }}>
            <h2 className="text-h3">{locale === 'ar' ? 'إدارة المشرفين' : 'Supervisor Management'}</h2>
            <p className="text-small">{locale === 'ar' ? 'دعوة وإدارة صلاحيات أعضاء هيئة التدريس على المنصة.' : 'Invite and manage faculty member permissions on the platform.'}</p>
            <span className="text-caption" style={{ color: 'var(--color-primary)', fontWeight: 600 }}>{locale === 'ar' ? 'فتح ←' : 'Open →'}</span>
          </Link>
        </>
      ),
    },
    { key: 'appearance', label: { en: 'Appearance', ar: 'المظهر' }, icon: 'palette', content: <AppearanceCard /> },
    { key: 'language', label: { en: 'Language', ar: 'اللغة' }, icon: 'globe', content: <LanguageCard /> },
    {
      key: 'notifications', label: { en: 'Notification Preferences', ar: 'تفضيلات الإشعارات' }, icon: 'bell',
      content: (
        <NotificationsCard
          base={BASE}
          path="/notification-preferences"
          categories={data.notification_categories}
          mutedInitial={data.muted_categories}
          digestInitial={data.digest_frequency}
          quietHoursInitial={data.quiet_hours}
        />
      ),
    },
  ];

  return (
    <SettingsShell
      title={t('Settings')}
      subtitle={locale === 'ar' ? 'ملف الجامعة والشعار وتفضيلات مسار الاعتماد.' : 'University profile, logo, and approval-workflow preferences.'}
      sections={sections}
    />
  );
}

// -- Profile (official_name_ar/en, country, city, website) -----------------

function ProfileCard({ university }) {
  const t = useTranslations(translations);
  const [nameAr, setNameAr] = useState(university?.official_name_ar || '');
  const [nameEn, setNameEn] = useState(university?.official_name_en || '');
  const [country, setCountry] = useState(university?.country || '');
  const [city, setCity] = useState(university?.city || '');
  const [website, setWebsite] = useState(university?.website || '');
  const [saving, setSaving] = useState(false);
  const [saved, setSaved] = useState(false);
  const [error, setError] = useState(null);

  async function handleSubmit(e) {
    e.preventDefault();
    setSaving(true);
    setError(null);
    setSaved(false);
    try {
      await api.patch(BASE, {
        official_name_ar: nameAr,
        official_name_en: nameEn,
        country,
        city,
        website,
      });
      setSaved(true);
    } catch (err) {
      setError(errorMessage(err));
    } finally {
      setSaving(false);
    }
  }

  return (
    <div className="card glass-panel">
      <h2 className="text-h3" style={{ marginBottom: 'var(--space-4)' }}>
        {t('University Profile')} <SavedBadge show={saved} />
      </h2>
      {university?.verification_status && (
        <p className="text-caption" style={{ marginBottom: 'var(--space-3)' }}>
          {t('Verification status')}: <strong>{university.verification_status}</strong>
        </p>
      )}
      <form onSubmit={handleSubmit} style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-3)' }}>
        <div className="form-group">
          <label className="form-label">{t('Official Name (Arabic)')}</label>
          <input className="form-input" type="text" value={nameAr} onChange={(e) => { setNameAr(e.target.value); setSaved(false); }} />
        </div>
        <div className="form-group">
          <label className="form-label">{t('Official Name (English)')}</label>
          <input className="form-input" type="text" value={nameEn} onChange={(e) => { setNameEn(e.target.value); setSaved(false); }} />
        </div>
        <div className="form-group">
          <label className="form-label">{t('Country')}</label>
          <input className="form-input" type="text" value={country} onChange={(e) => { setCountry(e.target.value); setSaved(false); }} />
        </div>
        <div className="form-group">
          <label className="form-label">{t('City')}</label>
          <input className="form-input" type="text" value={city} onChange={(e) => { setCity(e.target.value); setSaved(false); }} />
        </div>
        <div className="form-group">
          <label className="form-label">{t('Website')}</label>
          <input className="form-input" type="url" value={website} onChange={(e) => { setWebsite(e.target.value); setSaved(false); }} placeholder="https://" />
        </div>
        {error && <p className="text-caption" style={{ color: 'var(--color-danger)' }}>{error}</p>}
        <button type="submit" className="btn btn-primary" disabled={saving} style={{ alignSelf: 'flex-start' }}>
          <Icon name="check" size={18} /> {saving ? t('Saving…') : t('Save Profile')}
        </button>
      </form>
    </div>
  );
}

// -- Logo upload (multipart) -------------------------------------------------

function LogoCard({ logoPath }) {
  const t = useTranslations(translations);
  const [file, setFile] = useState(null);
  const [currentPath, setCurrentPath] = useState(logoPath || null);
  const [uploading, setUploading] = useState(false);
  const [saved, setSaved] = useState(false);
  const [error, setError] = useState(null);

  async function handleUpload(e) {
    e.preventDefault();
    if (!file) return;
    setUploading(true);
    setError(null);
    setSaved(false);
    try {
      const formData = new FormData();
      formData.append('logo', file);
      const json = await api.postForm(`${BASE}/logo`, formData);
      setCurrentPath(json.data?.logo_path || currentPath);
      setFile(null);
      setSaved(true);
    } catch (err) {
      setError(errorMessage(err));
    } finally {
      setUploading(false);
    }
  }

  return (
    <div className="card glass-panel">
      <h2 className="text-h3" style={{ marginBottom: 'var(--space-2)' }}>
        <Icon name="upload" size={18} /> {t('Logo')} <SavedBadge show={saved} />
      </h2>
      <p className="text-caption" style={{ marginBottom: 'var(--space-3)' }}>
        {currentPath ? `${t('Current logo')}: ${currentPath}` : t('No logo uploaded yet.')}
      </p>
      <form onSubmit={handleUpload} style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-3)' }}>
        <input
          type="file"
          accept="image/*"
          onChange={(e) => { setFile(e.target.files?.[0] || null); setSaved(false); }}
        />
        <button type="submit" className="btn btn-primary btn-sm" disabled={!file || uploading}>
          {uploading ? t('Uploading…') : t('Upload Logo')}
        </button>
      </form>
      {error && <p className="text-caption" style={{ color: 'var(--color-danger)', marginTop: 'var(--space-2)' }}>{error}</p>}
    </div>
  );
}

// -- Approval-workflow preferences (auto_approve_threshold, notify_on_submission)

function PreferencesCard({ autoApproveThreshold, notifyOnSubmission }) {
  const t = useTranslations(translations);
  const [threshold, setThreshold] = useState(autoApproveThreshold ?? 80);
  const [notify, setNotify] = useState(!!notifyOnSubmission);
  const [saving, setSaving] = useState(false);
  const [saved, setSaved] = useState(false);
  const [error, setError] = useState(null);

  async function handleSubmit(e) {
    e.preventDefault();
    setSaving(true);
    setError(null);
    setSaved(false);
    try {
      await api.patch(`${BASE}/preferences`, {
        auto_approve_threshold: threshold,
        notify_on_submission: notify,
      });
      setSaved(true);
    } catch (err) {
      setError(errorMessage(err));
    } finally {
      setSaving(false);
    }
  }

  return (
    <div className="card glass-panel">
      <h2 className="text-h3" style={{ marginBottom: 'var(--space-4)' }}>
        {t('Project Approval Preferences')} <SavedBadge show={saved} />
      </h2>
      <form onSubmit={handleSubmit}>
        <div className="form-group">
          <label className="form-label">{t('Auto-Approve Threshold (AI score, 0–100)')}</label>
          <input
            className="form-input"
            type="number"
            min={0}
            max={100}
            value={threshold}
            onChange={(e) => { setThreshold(Number(e.target.value)); setSaved(false); }}
          />
          <p className="text-caption" style={{ margin: '4px 0 0' }}>
            {t('Projects scoring at or above this AI-analysis threshold are auto-approved.')}
          </p>
        </div>
        <div className="form-group" style={ROW_BETWEEN}>
          <label className="form-label" style={{ margin: 0 }}>{t('Notify on New Submission')}</label>
          <span className="switch">
            <input
              type="checkbox"
              checked={notify}
              onChange={(e) => { setNotify(e.target.checked); setSaved(false); }}
              aria-label={t('Notify on New Submission')}
            />
            <span className="switch__track" aria-hidden="true" />
          </span>
        </div>
        {error && <p className="text-caption" style={{ color: 'var(--color-danger)' }}>{error}</p>}
        <button type="submit" className="btn btn-primary" disabled={saving}>
          <Icon name="check" size={18} /> {saving ? t('Saving…') : t('Save Preferences')}
        </button>
      </form>
    </div>
  );
}
