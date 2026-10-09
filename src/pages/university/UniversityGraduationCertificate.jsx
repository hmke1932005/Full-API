import { useEffect, useState } from 'react';
import { useParams } from 'react-router-dom';
import { api, errorMessage } from '../../api/client';
import { useTranslations, useLanguage } from '../../context/LanguageContext';
import i18nPage from '../../i18n/student/graduation-certificate';

/**
 * University/faculty view of one student's certificate — same printer-
 * friendly layout as pages/student/StudentGraduationCertificate.jsx (kept
 * as a separate file since it's parameterized by :studentId from the URL
 * instead of resolving "my own" record), reusing that page's translations
 * since the certificate content itself is identical either way. Data
 * comes from GET /api/v1/graduation/{studentId}/certificate
 * (App\Controllers\Api\GraduationApiController::certificate(), scoped to
 * the caller's own university/faculty via StudentRepository::findOwned()).
 */
export default function UniversityGraduationCertificate() {
  const { id } = useParams();
  const t = useTranslations(i18nPage);
  const { locale } = useLanguage();

  const [student, setStudent] = useState(null);
  const [graduation, setGraduation] = useState(null);
  const [university, setUniversity] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  useEffect(() => {
    let cancelled = false;
    api.get(`/api/v1/graduation/${id}/certificate`)
      .then((json) => {
        if (cancelled) return;
        setStudent(json.data.student);
        setGraduation(json.data.graduation);
        setUniversity(json.data.university);
      })
      .catch((err) => { if (!cancelled) setError(errorMessage(err)); })
      .finally(() => { if (!cancelled) setLoading(false); });
    return () => { cancelled = true; };
  }, [id]);

  const dir = locale === 'ar' ? 'rtl' : 'ltr';

  if (loading) {
    return <div style={{ padding: 60, fontFamily: 'Arial, sans-serif', textAlign: 'center' }}>{locale === 'ar' ? 'جارٍ التحميل…' : 'Loading…'}</div>;
  }

  if (error || !graduation || !student) {
    return (
      <div style={{ padding: 60, fontFamily: 'Arial, sans-serif', textAlign: 'center' }}>
        {t('Certificate data could not be found.')}
      </div>
    );
  }

  const universityName = university
    ? (locale === 'ar' ? (university.official_name_ar || university.official_name_en) : (university.official_name_en || university.official_name_ar))
    : t('The University');
  const degreeTitle = locale === 'ar' ? (graduation.degree_title_ar || graduation.degree_title_en) : (graduation.degree_title_en || graduation.degree_title_ar);
  const facultyName = locale === 'ar' ? (graduation.faculty_name_ar || graduation.faculty_name_en) : (graduation.faculty_name_en || graduation.faculty_name_ar);
  const programName = locale === 'ar' ? (graduation.program_name_ar || graduation.program_name_en) : (graduation.program_name_en || graduation.program_name_ar);
  const verifyUrl = `${window.location.origin}/verify-certificate?number=${encodeURIComponent(String(graduation.certificate_number || ''))}`;
  const fmtDate = (v) => (v ? new Date(v).toLocaleDateString('en-GB') : '—');

  return (
    <div style={{ margin: 0, background: '#e9e6df', color: '#1c1c1c', fontFamily: "Georgia, 'Times New Roman', serif", minHeight: '100vh' }} dir={dir}>
      <div className="no-print" style={{ padding: '14px 24px', background: '#fff', borderBottom: '1px solid #ddd', display: 'flex', justifyContent: locale === 'ar' ? 'flex-end' : 'flex-start', gap: 10, fontFamily: 'Arial, sans-serif' }}>
        <button
          type="button"
          onClick={() => window.print()}
          style={{ padding: '8px 18px', fontSize: 13, fontWeight: 600, cursor: 'pointer', borderRadius: 6, border: '1px solid #1a3a5c', background: '#1a3a5c', color: '#fff', letterSpacing: '.3px' }}
        >
          {t('🖨 Print Certificate')}
        </button>
      </div>

      <div style={{ width: 1000, maxWidth: '100%', minHeight: 700, margin: '36px auto', background: '#fdfcf8', boxShadow: '0 8px 40px rgba(0,0,0,.18)', position: 'relative', padding: 26 }} className="cert-page">
        <div style={{ position: 'absolute', inset: 14, border: '2px solid #1a3a5c', pointerEvents: 'none' }} />
        <div style={{ position: 'absolute', inset: 22, border: '6px double #c9a24b', pointerEvents: 'none' }} />

        <svg viewBox="0 0 200 200" style={{ position: 'absolute', top: '50%', left: '50%', transform: 'translate(-50%, -50%)', width: 480, height: 480, opacity: 0.05, pointerEvents: 'none', zIndex: 0 }}>
          <circle cx="100" cy="100" r="92" fill="none" stroke="#1a3a5c" strokeWidth="2" />
          <circle cx="100" cy="100" r="78" fill="none" stroke="#1a3a5c" strokeWidth="1" />
          <path d="M100 30 L112 70 L154 70 L120 94 L132 134 L100 110 L68 134 L80 94 L46 70 L88 70 Z" fill="#1a3a5c" />
        </svg>

        <div style={{ position: 'relative', zIndex: 1, padding: '54px 72px 40px', textAlign: 'center' }}>
          <div style={{ letterSpacing: 4, fontSize: 11, color: '#c9a24b', textTransform: 'uppercase', marginBottom: 10, fontFamily: 'Arial, sans-serif', fontWeight: 700 }}>
            {t('Official Accredited Graduation Certificate')}
          </div>
          <div style={{ fontSize: 27, fontWeight: 'bold', color: '#1a3a5c', marginBottom: 3, letterSpacing: '.3px' }}>{universityName}</div>
          {facultyName && <div style={{ fontSize: 14, color: '#6b6250', marginBottom: 6, fontFamily: 'Arial, sans-serif' }}>{facultyName}</div>}

          <div style={{ width: 120, height: 3, margin: '18px auto 22px', background: 'linear-gradient(90deg, transparent, #c9a24b, transparent)' }} />
          <div style={{ fontSize: 32, fontWeight: 'bold', marginBottom: 26, color: '#1a1a1a', letterSpacing: '.5px' }}>{t('Certificate of Graduation')}</div>

          <div style={{ fontSize: '15.5px', lineHeight: 2, color: '#3a3a3a', fontFamily: 'Arial, sans-serif' }}>
            {locale === 'ar' ? `تشهد إدارة ${universityName} بأن الطالب/ة` : 'This is to certify that'}
            <div style={{ fontSize: 30, fontWeight: 'bold', color: '#1a3a5c', margin: '10px 0 4px', fontFamily: 'Georgia, serif', paddingBottom: 6, borderBottom: '1px solid #c9a24b', display: 'inline-block', minWidth: 340 }}>
              {student.full_name || ''}
            </div>
            {degreeTitle ? (
              <>
                <div>{t('has successfully fulfilled all prescribed academic requirements and has accordingly been conferred the degree of')}</div>
                <div style={{ fontSize: 19, fontWeight: 'bold', margin: '12px 0 4px', color: '#1a1a1a' }}>{degreeTitle}</div>
                {programName && <div style={{ fontSize: 14, color: '#6b6250', fontFamily: 'Arial, sans-serif' }}>{programName}</div>}
              </>
            ) : (
              <div>{t('has successfully fulfilled all prescribed graduation requirements.')}</div>
            )}
          </div>

          <div style={{ display: 'flex', alignItems: 'flex-end', justifyContent: 'space-between', marginTop: 50, padding: '0 10px' }}>
            <div style={{ textAlign: 'center', width: 220 }}>
              <div style={{ borderTop: '1px solid #999', marginBottom: 6, height: 34 }} />
              <div style={{ fontSize: 13, color: '#1a1a1a', fontWeight: 600, fontFamily: 'Georgia, serif' }}>{universityName}</div>
              <div style={{ fontSize: 11, color: '#666', fontFamily: 'Arial, sans-serif', letterSpacing: '.3px' }}>{t('University Seal & Signature')}</div>
            </div>

            <svg viewBox="0 0 100 100" style={{ width: 92, height: 92, flexShrink: 0 }}>
              <circle cx="50" cy="50" r="46" fill="none" stroke="#c9a24b" strokeWidth="2" />
              <circle cx="50" cy="50" r="38" fill="none" stroke="#c9a24b" strokeWidth="1" />
              <path d="M50 16 L58 40 L84 40 L63 55 L71 79 L50 64 L29 79 L37 55 L16 40 L42 40 Z" fill="#1a3a5c" />
              <text x="50" y="94" textAnchor="middle" fontSize="6" fill="#1a3a5c" fontFamily="Arial" letterSpacing="1">{t('VERIFIED')}</text>
            </svg>

            <div style={{ textAlign: 'center', width: 220 }}>
              <div style={{ borderTop: '1px solid #999', marginBottom: 6, height: 34 }} />
              <div style={{ fontSize: 13, color: '#1a1a1a', fontWeight: 600, fontFamily: 'Georgia, serif' }}>{t('Dean / Registrar')}</div>
              <div style={{ fontSize: 11, color: '#666', fontFamily: 'Arial, sans-serif', letterSpacing: '.3px' }}>{t('Authorized Academic Signature')}</div>
            </div>
          </div>

          <div style={{ display: 'flex', justifyContent: 'center', gap: 46, marginTop: 34, paddingTop: 18, borderTop: '1px solid #e3ddca', fontSize: 12, color: '#555', fontFamily: 'Arial, sans-serif' }}>
            <div style={{ textAlign: 'center' }}>
              <span style={{ display: 'block', color: '#8a8272', fontSize: '10.5px', textTransform: 'uppercase', letterSpacing: '.5px', marginBottom: 3 }}>{t('Graduation Date')}</span>
              <strong style={{ display: 'block', fontSize: '13.5px', color: '#111' }}>{fmtDate(graduation.graduation_date)}</strong>
            </div>
            {graduation.final_gpa !== null && graduation.final_gpa !== undefined && (
              <div style={{ textAlign: 'center' }}>
                <span style={{ display: 'block', color: '#8a8272', fontSize: '10.5px', textTransform: 'uppercase', letterSpacing: '.5px', marginBottom: 3 }}>{t('Final GPA')}</span>
                <strong style={{ display: 'block', fontSize: '13.5px', color: '#111' }}>{graduation.final_gpa}</strong>
              </div>
            )}
            <div style={{ textAlign: 'center' }}>
              <span style={{ display: 'block', color: '#8a8272', fontSize: '10.5px', textTransform: 'uppercase', letterSpacing: '.5px', marginBottom: 3 }}>{t('Certificate No.')}</span>
              <strong style={{ display: 'block', fontSize: '13.5px', color: '#111' }}>{graduation.certificate_number}</strong>
            </div>
            <div style={{ textAlign: 'center' }}>
              <span style={{ display: 'block', color: '#8a8272', fontSize: '10.5px', textTransform: 'uppercase', letterSpacing: '.5px', marginBottom: 3 }}>{t('Issue Date')}</span>
              <strong style={{ display: 'block', fontSize: '13.5px', color: '#111' }}>{fmtDate(graduation.approved_at)}</strong>
            </div>
          </div>

          <div style={{ marginTop: 20, fontSize: '10.5px', color: '#8a8272', fontFamily: 'Arial, sans-serif', wordBreak: 'break-all' }}>
            {t('This certificate can be verified electronically at: ')}{verifyUrl}
          </div>
        </div>

        <div style={{ position: 'absolute', bottom: 30, [locale === 'ar' ? 'left' : 'right']: 42, fontSize: '9.5px', color: '#b7ae94', fontFamily: "'Courier New', monospace", letterSpacing: 1 }}>
          #{graduation.certificate_number}
        </div>
      </div>

      <style>{'@media print { .no-print { display: none; } .cert-page { margin: 0 !important; box-shadow: none !important; } body { background: #fff; } @page { size: A4 landscape; margin: 0; } }'}</style>
    </div>
  );
}
