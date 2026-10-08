import { useEffect, useRef, useState } from 'react';
import Icon from '../Icon';
import { Avatar } from './ConversationRail';
import { fmtTime, peerLabel } from './format';

/** Generic modal shell — ported from openModal()/closeModal() in messaging.js. */
export function Modal({ title, onClose, children, footer }) {
  const backdropRef = useRef(null);

  useEffect(() => {
    const onKey = (e) => e.key === 'Escape' && onClose();
    document.addEventListener('keydown', onKey);
    return () => document.removeEventListener('keydown', onKey);
  }, [onClose]);

  return (
    <div
      className="msg-modal-backdrop"
      ref={backdropRef}
      onClick={(e) => e.target === backdropRef.current && onClose()}
    >
      <div className="msg-modal">
        <div className="msg-modal__head">
          <h3>{title}</h3>
          <button type="button" className="msg-icon-btn" onClick={onClose} aria-label="Close">
            <Icon name="x" size={16} />
          </button>
        </div>
        <div className="msg-modal__body">{children}</div>
        {footer && <div className="msg-modal__foot">{footer}</div>}
      </div>
    </div>
  );
}


const ROLE_LABELS = {
  student: 'Student', university: 'University', faculty: 'Faculty', supervisor: 'Supervisor',
  academic_staff: 'Academic staff', admin: 'Admin', super_admin: 'Admin', security_admin: 'Security',
  security_officer: 'Security', data_analyst: 'Data analyst', company: 'Company', investor: 'Investor',
  researcher: 'Researcher', designer: 'Designer',
};
const roleLabel = (r) => (r ? ROLE_LABELS[r] || String(r).replace(/_/g, ' ').replace(/^./, (c) => c.toUpperCase()) : '');

/** Search-result row: photo (or initials when the person hides it), name, role, org, email. */
function PersonRow({ p, selected, onClick }) {
  const org = p.org_en || p.org_ar || '';
  return (
    <div className={`msg-picker-item msg-person${selected ? ' is-selected' : ''}`} onClick={onClick}>
      <Avatar name={p.full_name} src={p.avatar_path} size="sm" />
      <span style={{ minWidth: 0, flex: 1 }}>
        <strong style={{ fontSize: 13, display: 'block' }}>{p.full_name}</strong>
        <span className="msg-person__meta">
          {[roleLabel(p.role), org].filter(Boolean).join(' · ')}
          {p.email ? `${roleLabel(p.role) || org ? ' · ' : ''}${p.email}` : ''}
        </span>
      </span>
    </div>
  );
}

/** Debounced recipient search box shared by the direct/group modals. */
function useRecipientSearch(searchRecipients) {
  const [query, setQuery] = useState('');
  const [results, setResults] = useState([]);
  const timerRef = useRef(null);

  const onChange = (value) => {
    setQuery(value);
    clearTimeout(timerRef.current);
    const q = value.trim();
    if (q.length < 2) {
      setResults([]);
      return;
    }
    timerRef.current = setTimeout(async () => {
      try {
        setResults(await searchRecipients(q));
      } catch {
        setResults([]);
      }
    }, 250);
  };

  return { query, setQuery, results, setResults, onChange };
}

export function NewDirectModal({ onClose, onSearchRecipients, onSubmit }) {
  const { query, setQuery, results, setResults, onChange } = useRecipientSearch(onSearchRecipients);
  const [selected, setSelected] = useState(null);
  const [searched, setSearched] = useState(false);
  const [body, setBody] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState('');

  const pick = (p) => {
    setSelected(p);
    setResults([]);
    setError('');
  };
  const reset = () => {
    setSelected(null);
    setQuery('');
    setResults([]);
    setBody('');
    setError('');
  };

  const submit = async () => {
    if (!selected) return setError('Pick a recipient first.');
    if (!body.trim()) return setError('Write a message first.');
    setError('');
    setSubmitting(true);
    try {
      await onSubmit({ recipient_email: selected.email, body: body.trim() });
    } catch (err) {
      setError(err?.message || 'Something went wrong.');
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <Modal
      title={selected ? 'Send message' : 'Find people'}
      onClose={onClose}
      footer={
        <>
          <button type="button" className="btn btn-outline btn-sm" onClick={onClose}>Cancel</button>
          {selected && (
            <button type="button" className="btn btn-primary btn-sm" onClick={submit} disabled={submitting}>
              Start conversation
            </button>
          )}
        </>
      }
    >
      {!selected ? (
        <>
          <input
            type="text"
            autoFocus
            placeholder="Search people by name or email…"
            value={query}
            onChange={(e) => {
              setSearched(e.target.value.trim().length >= 2);
              onChange(e.target.value);
            }}
          />
          <div className="msg-picker-list msg-picker-list--people">
            {results.length === 0 ? (
              <p className="text-small text-muted" style={{ padding: 'var(--space-3)', margin: 0 }}>
                {searched ? 'No matches.' : 'Type at least 2 letters of a name or email.'}
              </p>
            ) : (
              results.map((p) => <PersonRow key={p.id} p={p} onClick={() => pick(p)} />)
            )}
          </div>
        </>
      ) : (
        <>
          {/* Confirm it's the right person (photo, role, org, email) before writing. */}
          <div className="msg-person-card">
            <span className="msg-person-card__avatar"><Avatar name={selected.full_name} src={selected.avatar_path} /></span>
            <div className="msg-person-card__info">
              <strong>{selected.full_name}</strong>
              {roleLabel(selected.role) && <span className="msg-person-card__role">{roleLabel(selected.role)}</span>}
              {(selected.org_en || selected.org_ar) && <span>{selected.org_en || selected.org_ar}</span>}
              {selected.email && <span>{selected.email}</span>}
            </div>
            <button type="button" className="btn btn-outline btn-sm" onClick={reset}>Not them? Search again</button>
          </div>
          <textarea rows={3} autoFocus placeholder="Write your first message…" value={body} onChange={(e) => setBody(e.target.value)} />
        </>
      )}
      {error && <p className="text-small" style={{ color: 'var(--danger, #e5484d)' }}>{error}</p>}
    </Modal>
  );
}

export function NewGroupModal({ onClose, onSearchRecipients, onSubmit }) {
  const { query, results, onChange, setResults } = useRecipientSearch(onSearchRecipients);
  const [name, setName] = useState('');
  const [picked, setPicked] = useState({}); // id -> full_name
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState('');

  const pick = (p) => {
    setPicked((prev) => ({ ...prev, [p.id]: p.full_name }));
    setResults([]);
  };
  const unpick = (id) => setPicked((prev) => { const n = { ...prev }; delete n[id]; return n; });

  const submit = async () => {
    const ids = Object.keys(picked);
    if (!name.trim()) return setError('Group name is required.');
    if (ids.length < 1) return setError('Add at least one member.');
    setError('');
    setSubmitting(true);
    try {
      await onSubmit({ name: name.trim(), member_ids: ids });
    } catch (err) {
      setError(err?.message || 'Something went wrong.');
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <Modal
      title="New group"
      onClose={onClose}
      footer={
        <>
          <button type="button" className="btn btn-outline btn-sm" onClick={onClose}>Cancel</button>
          <button type="button" className="btn btn-primary btn-sm" onClick={submit} disabled={submitting}>
            Create group
          </button>
        </>
      }
    >
      <input type="text" placeholder="Group name" value={name} onChange={(e) => setName(e.target.value)} />
      <input type="text" placeholder="Add people…" value={query} onChange={(e) => onChange(e.target.value)} />
      <div className="msg-selected-chips">
        {Object.entries(picked).map(([id, fullName]) => (
          <span className="msg-selected-chip" key={id}>
            {fullName}
            <button type="button" onClick={() => unpick(id)}>&times;</button>
          </span>
        ))}
      </div>
      <div className="msg-picker-list">
        {results.map((p) => (
          <PersonRow key={p.id} p={p} selected={!!picked[p.id]} onClick={() => pick(p)} />
        ))}
      </div>
      {error && <p className="text-small" style={{ color: 'var(--danger, #e5484d)' }}>{error}</p>}
    </Modal>
  );
}

export function AddMemberModal({ onClose, onSearchRecipients, onSubmit }) {
  const { query, results, onChange, setResults } = useRecipientSearch(onSearchRecipients);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState('');

  const pick = async (p) => {
    setSubmitting(true);
    setError('');
    try {
      await onSubmit(p.id);
      setResults([]);
      onClose();
    } catch (err) {
      setError(err?.message || 'Something went wrong.');
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <Modal title="Add member" onClose={onClose}>
      <input
        type="text"
        placeholder="Search people by name or email…"
        value={query}
        onChange={(e) => onChange(e.target.value)}
        disabled={submitting}
      />
      <div className="msg-picker-list">
        {results.map((p) => (
          <PersonRow key={p.id} p={p} onClick={() => !submitting && pick(p)} />
        ))}
      </div>
      {error && <p className="text-small" style={{ color: 'var(--danger, #e5484d)' }}>{error}</p>}
    </Modal>
  );
}

export function PinnedMessagesModal({ pinned, onClose, onGoToMessage }) {
  return (
    <Modal title="Pinned messages" onClose={onClose}>
      {pinned.length === 0 ? (
        <p className="text-small text-muted">No pinned messages yet.</p>
      ) : (
        pinned.map((m) => (
          <div className="msg-picker-item" key={m.id} onClick={() => onGoToMessage(m.id)}>
            <Avatar name={m.sender_name} size="sm" />
            <span style={{ minWidth: 0, flex: 1 }}>
              <strong style={{ display: 'block', fontSize: 12 }}>{m.sender_name}</strong>
              <span
                style={{
                  fontSize: 12,
                  color: 'var(--text-secondary)',
                  whiteSpace: 'nowrap',
                  overflow: 'hidden',
                  textOverflow: 'ellipsis',
                  display: 'block',
                }}
              >
                {m.body || ''}
              </span>
            </span>
            <span className="msg-conv__time">{fmtTime(m.created_at)}</span>
          </div>
        ))
      )}
    </Modal>
  );
}

/** Ported from openForwardModal() in messaging.js — pick a conversation to forward a message into. */
export function ForwardModal({ conversations, onClose, onForward }) {
  const [busyId, setBusyId] = useState(null);
  const [error, setError] = useState('');

  const pick = async (c) => {
    setBusyId(c.id);
    setError('');
    try {
      await onForward(c.id);
    } catch (err) {
      setError(err?.message || 'Something went wrong.');
    } finally {
      setBusyId(null);
    }
  };

  return (
    <Modal title="Forward message" onClose={onClose}>
      <div className="msg-picker-list">
        {conversations.length === 0 ? (
          <p className="text-small text-muted">No conversations yet.</p>
        ) : (
          conversations.map((c) => (
            <div
              className="msg-picker-item"
              key={c.id}
              onClick={() => busyId === null && pick(c)}
              style={{ opacity: busyId && busyId !== c.id ? 0.5 : 1 }}
            >
              <Avatar name={peerLabel(c)} isGroup={c.is_group} size="sm" />
              <span>{peerLabel(c)}</span>
            </div>
          ))
        )}
      </div>
      {error && <p className="text-small" style={{ color: 'var(--danger, #e5484d)' }}>{error}</p>}
    </Modal>
  );
}

/** Ported from openPollModal() in messaging.js — question + 2+ options, options growable. */
export function PollModal({ onClose, onSubmit }) {
  const [question, setQuestion] = useState('');
  const [options, setOptions] = useState(['', '']);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState('');

  const setOption = (i, value) => setOptions((prev) => prev.map((o, idx) => (idx === i ? value : o)));
  const addOption = () => setOptions((prev) => [...prev, '']);

  const submit = async () => {
    const q = question.trim();
    const opts = options.map((o) => o.trim()).filter(Boolean);
    if (!q || opts.length < 2) {
      setError('Add a question and at least two options.');
      return;
    }
    setError('');
    setSubmitting(true);
    try {
      await onSubmit({ question: q, options: opts });
    } catch (err) {
      setError(err?.message || 'Something went wrong.');
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <Modal
      title="Create poll"
      onClose={onClose}
      footer={
        <>
          <button type="button" className="btn btn-outline btn-sm" onClick={onClose}>Cancel</button>
          <button type="button" className="btn btn-primary btn-sm" onClick={submit} disabled={submitting}>
            Create poll
          </button>
        </>
      }
    >
      <input type="text" placeholder="Ask a question…" value={question} onChange={(e) => setQuestion(e.target.value)} />
      <div data-poll-options>
        {options.map((opt, i) => (
          <div className="msg-poll-option-row" key={i}>
            <input type="text" placeholder={`Option ${i + 1}`} value={opt} onChange={(e) => setOption(i, e.target.value)} />
          </div>
        ))}
      </div>
      <button type="button" className="btn btn-outline btn-sm" onClick={addOption}>+ Add option</button>
      {error && <p className="text-small" style={{ color: 'var(--danger, #e5484d)' }}>{error}</p>}
    </Modal>
  );
}

/**
 * Image lightbox — ported from openLightbox()/renderLightbox() in
 * messaging.js. `images` is every image attachment url in the current
 * thread (for prev/next); `initialSrc` is the one that was clicked.
 */
export function Lightbox({ images, initialSrc, onClose }) {
  const list = images && images.length ? images : [initialSrc];
  const [index, setIndex] = useState(Math.max(0, list.indexOf(initialSrc)));
  const [zoom, setZoom] = useState(1);
  const [rotation, setRotation] = useState(0);

  const src = list[index];

  const goPrev = () => {
    setIndex((i) => (i - 1 + list.length) % list.length);
    setZoom(1);
    setRotation(0);
  };
  const goNext = () => {
    setIndex((i) => (i + 1) % list.length);
    setZoom(1);
    setRotation(0);
  };
  const zoomIn = () => setZoom((z) => Math.min(3, z + 0.25));
  const zoomOut = () => setZoom((z) => Math.max(0.5, z - 0.25));
  const rotate = () => setRotation((r) => (r + 90) % 360);

  useEffect(() => {
    const onKey = (e) => {
      if (e.key === 'Escape') onClose();
      else if (e.key === 'ArrowLeft' && list.length > 1) goPrev();
      else if (e.key === 'ArrowRight' && list.length > 1) goNext();
    };
    document.addEventListener('keydown', onKey);
    return () => document.removeEventListener('keydown', onKey);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [list.length, onClose]);

  return (
    <div className="msg-lightbox" data-lightbox onClick={onClose}>
      <button type="button" className="msg-icon-btn msg-lightbox__close" onClick={onClose} aria-label="Close" title="Close">
        <Icon name="x" size={20} />
      </button>
      {list.length > 1 && (
        <>
          <button
            type="button"
            className="msg-icon-btn msg-lightbox__nav msg-lightbox__nav--prev"
            onClick={(e) => { e.stopPropagation(); goPrev(); }}
            aria-label="Previous"
            title="Previous"
          >
            <Icon name="chevron-left" size={22} />
          </button>
          <button
            type="button"
            className="msg-icon-btn msg-lightbox__nav msg-lightbox__nav--next"
            onClick={(e) => { e.stopPropagation(); goNext(); }}
            aria-label="Next"
            title="Next"
          >
            <Icon name="chevron-right" size={22} />
          </button>
        </>
      )}
      <div className="msg-lightbox__stage" data-lightbox-stage onClick={(e) => e.stopPropagation()}>
        <img src={src} alt="" style={{ transform: `scale(${zoom}) rotate(${rotation}deg)` }} />
      </div>
      <div className="msg-lightbox__tools" onClick={(e) => e.stopPropagation()}>
        <button type="button" className="msg-icon-btn" onClick={zoomOut} aria-label="Zoom out" title="Zoom out">
          <Icon name="search" size={16} />−
        </button>
        <button type="button" className="msg-icon-btn" onClick={zoomIn} aria-label="Zoom in" title="Zoom in">
          <Icon name="search" size={16} />+
        </button>
        <button type="button" className="msg-icon-btn" onClick={rotate} aria-label="Rotate" title="Rotate">
          <Icon name="refresh" size={16} />
        </button>
        <a className="msg-icon-btn" href={src} download aria-label="Download" title="Download">
          <Icon name="download" size={16} />
        </a>
      </div>
    </div>
  );
}

/**
 * "Chat privacy" — per-user switches that apply to every role (the old
 * read-receipts card only existed in the Student settings page). Loads and
 * saves through GET/PATCH /api/v1/messaging/privacy.
 */
export function ChatPrivacyModal({ onClose, loadPrivacy, savePrivacy }) {
  const [state, setState] = useState(null); // { show_avatar, read_receipts_enabled }
  const [saving, setSaving] = useState('');
  const [error, setError] = useState('');

  useEffect(() => {
    let alive = true;
    loadPrivacy()
      .then((d) => alive && setState(d))
      .catch((err) => alive && setError(err?.message || 'Could not load your privacy settings.'));
    return () => {
      alive = false;
    };
  }, [loadPrivacy]);

  const toggle = async (key) => {
    if (!state || saving) return;
    const next = !state[key];
    setSaving(key);
    setError('');
    try {
      setState(await savePrivacy({ [key]: next }));
    } catch (err) {
      setError(err?.message || 'Could not save.');
    } finally {
      setSaving('');
    }
  };

  const rows = [
    {
      key: 'show_avatar',
      label: 'Show my profile photo',
      hint: 'When off, other people see your initials instead of your photo in chats.',
    },
    {
      key: 'read_receipts_enabled',
      label: 'Read receipts',
      hint: "Let others see when you've read their messages.",
    },
  ];

  return (
    <Modal
      title="Chat privacy"
      onClose={onClose}
      footer={<button type="button" className="btn btn-primary btn-sm" onClick={onClose}>Done</button>}
    >
      {!state && !error && <p className="text-small text-muted">Loading…</p>}
      {state &&
        rows.map((r) => (
          <div className="msg-info__toggle-row" key={r.key} style={{ alignItems: 'flex-start', gap: 12 }}>
            <span>
              <strong style={{ display: 'block', fontSize: 13 }}>{r.label}</strong>
              <span className="text-small text-muted">{r.hint}</span>
            </span>
            <button
              type="button"
              className={`msg-switch${state[r.key] ? ' is-on' : ''}`}
              onClick={() => toggle(r.key)}
              aria-pressed={!!state[r.key]}
              aria-label={r.label}
              disabled={saving === r.key}
            />
          </div>
        ))}
      {error && <p className="text-small" style={{ color: 'var(--danger, #e5484d)' }}>{error}</p>}
    </Modal>
  );
}

/**
 * Full-size video player. The chat bubble only shows a small tile; opening it
 * plays the video using the whole viewport (letterboxed, never cropped).
 */
export function VideoViewer({ video, onClose }) {
  useEffect(() => {
    const onKey = (e) => e.key === 'Escape' && onClose();
    document.addEventListener('keydown', onKey);
    return () => document.removeEventListener('keydown', onKey);
  }, [onClose]);

  return (
    <div className="msg-videoviewer" role="dialog" aria-modal="true" aria-label="Video player" onClick={onClose}>
      <button type="button" className="msg-icon-btn msg-videoviewer__close" onClick={onClose} aria-label="Close" title="Close">
        <Icon name="x" size={22} />
      </button>
      <video
        className="msg-videoviewer__video"
        src={video.src}
        poster={video.poster}
        controls
        autoPlay
        playsInline
        onClick={(e) => e.stopPropagation()}
      />
    </div>
  );
}

