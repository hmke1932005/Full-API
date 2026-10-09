import { useEffect, useLayoutEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import Icon from '../Icon';
import { PublicFilePreviewButton } from '../FilePreviewButton';
import MsgIcon from './msgIcons';
import { Avatar } from './ConversationRail';
import { fmtTime, fmtDayLabel, fmtBytes, fmtDuration, fmtClock, peerLabel, renderBody, attachmentUrl, roleLabel } from './format';
import { parseServerDate } from '../../utils/serverDate';
import { useLanguage } from '../../context/LanguageContext';
import MediaEditorModal, { isEditableMedia } from './MediaEditorModal';

/** Inline bilingual picker: const t = useT(); t('English', 'عربي'). */
function useT() {
  const { locale } = useLanguage();
  return (en, ar) => (locale === 'ar' ? ar : en);
}

const EMOJI_SET = [
  '😀', '😂', '🥰', '😎', '🤔', '😢', '😮', '😡', '👍', '👎',
  '🙏', '👏', '🔥', '🎉', '❤️', '💯', '✅', '❌', '🚀', '⭐',
  '😴', '🤝', '👀', '💡', '📌', '⚡', '🙌', '😅', '🤯', '🥳',
];

/**
 * Floating panel anchored to a button (message "More" menu / reaction picker).
 * Rendered fixed + in a portal so it is never clipped by the thread's
 * overflow (it used to be cut off for short bubbles near the left edge), and
 * it is clamped to the viewport / flipped above when there is no room below.
 * Closes on outside click, Escape, scroll and resize — before, it stayed open
 * until the same button was clicked again.
 */
function FloatingPanel({ anchorRef, onClose, className, children }) {
  const panelRef = useRef(null);
  const [pos, setPos] = useState(null);
  const host = anchorRef.current?.closest('.app-shell') || document.body;

  useLayoutEffect(() => {
    const a = anchorRef.current?.getBoundingClientRect();
    const p = panelRef.current?.getBoundingClientRect();
    if (!a || !p) return;
    const M = 8;
    const rtl = getComputedStyle(document.documentElement).direction === 'rtl' || document.dir === 'rtl';
    let left = rtl ? a.left : a.right - p.width;
    left = Math.max(M, Math.min(left, window.innerWidth - p.width - M));
    let top = a.bottom + 6;
    if (top + p.height > window.innerHeight - M) top = Math.max(M, a.top - p.height - 6);
    setPos({ left, top });
  }, [anchorRef]);

  useEffect(() => {
    const onDown = (e) => {
      if (panelRef.current?.contains(e.target) || anchorRef.current?.contains(e.target)) return;
      onClose();
    };
    const onKey = (e) => e.key === 'Escape' && onClose();
    document.addEventListener('mousedown', onDown);
    document.addEventListener('touchstart', onDown, { passive: true });
    document.addEventListener('keydown', onKey);
    window.addEventListener('resize', onClose);
    document.addEventListener('scroll', onClose, true);
    return () => {
      document.removeEventListener('mousedown', onDown);
      document.removeEventListener('touchstart', onDown);
      document.removeEventListener('keydown', onKey);
      window.removeEventListener('resize', onClose);
      document.removeEventListener('scroll', onClose, true);
    };
  }, [anchorRef, onClose]);

  return createPortal(
    <div
      ref={panelRef}
      className={className}
      style={{
        position: 'fixed',
        left: pos ? pos.left : 0,
        top: pos ? pos.top : 0,
        bottom: 'auto',
        margin: 0,
        zIndex: 2000,
        visibility: pos ? 'visible' : 'hidden',
      }}
    >
      {children}
    </div>,
    host
  );
}

function MessageBubble({ m, showAvatar, others, onOpenVideo, onRetryUpload, onDiscardUpload, onReply, onEdit, onDelete, onReact, onPin, onVote, onOpenLightbox, onForward, editingId, onStartEdit, onSaveEdit, onCancelEdit }) {
  const t = useT();
  const [showReactPicker, setShowReactPicker] = useState(false);
  const [showMoreMenu, setShowMoreMenu] = useState(false);
  const reactBtnRef = useRef(null);
  const moreBtnRef = useRef(null);
  const [editValue, setEditValue] = useState(m.body || '');
  const isEditing = editingId === m.id;

  if (m.is_deleted) {
    return (
      <div className={`msg-row${m.is_mine ? ' is-mine' : ' show-avatar'}`} data-msg-id={m.id}>
        {showAvatar && !m.is_mine ? <Avatar name={m.sender_name} size="sm" src={m.sender_avatar} /> : null}
        <div className="msg-bubble-col">
          <div className="msg-bubble msg-bubble--deleted">{t('This message was deleted', 'تم حذف هذه الرسالة')}</div>
        </div>
      </div>
    );
  }

  let isMedia = false;
  let body = null;
  if (m.message_type === 'poll' && m.metadata) {
    const results = m.pollResults || {};
    const total = Object.values(results).reduce((s, v) => s + v, 0);
    body = (
      <div className="msg-poll" data-poll-msg-id={m.id}>
        <div className="msg-poll__q">
          <MsgIcon name="poll" size={14} /> {m.metadata.question}
        </div>
        {(m.metadata.options || []).map((opt, idx) => {
          const count = results[idx] || 0;
          const pct = total ? Math.round((count / total) * 100) : 0;
          return (
            <button type="button" className="msg-poll__opt" key={idx} onClick={() => onVote(m.id, idx)}>
              <span className="msg-poll__opt-fill" style={{ width: pct + '%' }} />
              <span className="msg-poll__opt-label">
                <span>{opt}</span>
                <span>{count}</span>
              </span>
            </button>
          );
        })}
        <div className="msg-poll__total">{total} {t(total === 1 ? 'vote' : 'votes', 'صوت')}</div>
      </div>
    );
  } else if (m.message_type === 'gif' && m.metadata) {
    isMedia = true;
    body = <img className="msg-gif-img" src={m.metadata.url} alt={m.metadata.title || 'GIF'} loading="lazy" />;
  } else if (m.message_type === 'sticker' && m.metadata) {
    isMedia = true;
    body = <img className="msg-sticker-img" src={m.metadata.url} alt={m.metadata.label || t('Sticker', 'ملصق')} loading="lazy" />;
  } else {
    body = (
      <>
        {m.parent_message_id && (
          <div className="msg-reply-quote">
            <strong>{m.parent_sender_name || ''}</strong>
            <span>{(m.parent_body || '').slice(0, 80)}</span>
          </div>
        )}
        {m.forwarded_from_id && (
          <div className="msg-forward-tag">
            <MsgIcon name="forward" size={11} /> {t('Forwarded', 'معاد توجيهها')}
          </div>
        )}
        {m.body ? <div>{renderBody(m.body, others)}</div> : null}
        {m.attachments && m.attachments.length > 0 && (
          m.pending || m.failed
            ? <PendingAttachments attachments={m.attachments} pct={m.upload_progress || 0} uploading={!!m.pending} />
            : <MessageAttachments attachments={m.attachments} onOpenLightbox={onOpenLightbox} onOpenVideo={onOpenVideo} />
        )}
      </>
    );
  }

  // Attachment-only message (no text / reply quote / forward tag): tight frame
  // around the media instead of the full text-bubble padding.
  const attachOnly =
    !isMedia &&
    (!m.message_type || m.message_type === 'text') &&
    !m.body &&
    !m.parent_message_id &&
    !m.forwarded_from_id &&
    !!(m.attachments && m.attachments.length > 0);

  const canEdit = m.is_mine && (!m.message_type || m.message_type === 'text');

  return (
    <div className={`msg-row${m.is_mine ? ' is-mine' : ''}${showAvatar ? ' show-avatar' : ''}`} data-msg-id={m.id}>
      {!m.is_mine ? <Avatar name={m.sender_name} size="sm" src={m.sender_avatar} /> : null}
      <div className="msg-bubble-col">
        {showAvatar && !m.is_mine ? <span className="msg-sender-name">{m.sender_name}</span> : null}
        <div className={`msg-bubble${isMedia ? ' msg-bubble--media' : ''}${attachOnly ? ' msg-bubble--attach' : ''}`} style={{ position: 'relative' }}>
          <div className="msg-hover-actions" hidden={!!(m.pending || m.failed)}>
            <button type="button" title={t('React', 'تفاعل')} aria-label={t('React', 'تفاعل')} ref={reactBtnRef} onClick={() => { setShowMoreMenu(false); setShowReactPicker((s) => !s); }}>
              <MsgIcon name="react" size={14} />
            </button>
            <button type="button" title={t('Reply', 'رد')} aria-label={t('Reply', 'رد')} onClick={() => onReply(m)}>
              <MsgIcon name="reply" size={14} />
            </button>
            <button type="button" title={t('Forward', 'إعادة توجيه')} aria-label={t('Forward', 'إعادة توجيه')} onClick={() => onForward(m.id)}>
              <MsgIcon name="forward" size={14} />
            </button>
            {canEdit && (
              <button type="button" title={t('Edit', 'تعديل')} aria-label={t('Edit', 'تعديل')} onClick={() => onStartEdit(m.id)}>
                <Icon name="edit" size={14} />
              </button>
            )}
            <button type="button" title={m.is_pinned ? t('Unpin', 'إلغاء التثبيت') : t('Pin', 'تثبيت')} aria-label={m.is_pinned ? t('Unpin', 'إلغاء التثبيت') : t('Pin', 'تثبيت')} onClick={() => onPin(m)}>
              <MsgIcon name="pin" size={14} />
            </button>
            <button type="button" title={t('More', 'المزيد')} aria-label={t('More', 'المزيد')} ref={moreBtnRef} onClick={() => { setShowReactPicker(false); setShowMoreMenu((s) => !s); }}>
              <MsgIcon name="more" size={14} />
            </button>
          </div>

          {showReactPicker && (
            <FloatingPanel anchorRef={reactBtnRef} className="msg-reaction-picker" onClose={() => setShowReactPicker(false)}>
              {['👍', '❤️', '😂', '😮', '😢', '🙏'].map((e) => (
                <button
                  type="button"
                  key={e}
                  onClick={() => {
                    onReact(m.id, e);
                    setShowReactPicker(false);
                  }}
                >
                  {e}
                </button>
              ))}
            </FloatingPanel>
          )}

          {showMoreMenu && (
            <FloatingPanel anchorRef={moreBtnRef} className="msg-menu__panel is-open" onClose={() => setShowMoreMenu(false)}>
              {m.is_mine && (
                <button
                  type="button"
                  className="msg-menu__item"
                  onClick={() => {
                    onDelete(m.id, 'for-everyone');
                    setShowMoreMenu(false);
                  }}
                >
                  {t('Delete for everyone', 'حذف للجميع')}
                </button>
              )}
              <button
                type="button"
                className="msg-menu__item"
                onClick={() => {
                  onDelete(m.id, 'for-me');
                  setShowMoreMenu(false);
                }}
              >
                {t('Delete for me', 'حذف عندي')}
              </button>
            </FloatingPanel>
          )}

          {isEditing ? (
            <div>
              <textarea
                className="msg-edit-textarea"
                style={{ width: '100%', minHeight: 60, background: 'transparent', border: '1px solid currentColor', borderRadius: 8, padding: 6, color: 'inherit', font: 'inherit' }}
                value={editValue}
                onChange={(e) => setEditValue(e.target.value)}
                autoFocus
              />
              <div style={{ display: 'flex', gap: 6, marginTop: 4 }}>
                <button type="button" className="btn btn-sm btn-outline" onClick={onCancelEdit}>{t('Cancel', 'إلغاء')}</button>
                <button type="button" className="btn btn-sm btn-primary" onClick={() => onSaveEdit(m.id, editValue.trim())}>{t('Save', 'حفظ')}</button>
              </div>
            </div>
          ) : (
            body
          )}
        </div>

        {m.reactions && m.reactions.length > 0 && (
          <div className="msg-reactions">
            {m.reactions.map((r) => (
              <button
                type="button"
                key={r.emoji}
                className="msg-reaction-pill"
                title={(r.users || []).map((u) => u.full_name).join(', ')}
                onClick={() => onReact(m.id, r.emoji)}
              >
                {r.emoji} <span>{r.count}</span>
              </button>
            ))}
          </div>
        )}

        <div className="msg-meta-row">
          {m.is_pinned ? <MsgIcon name="pin" size={11} /> : null}
          {m.pending ? (
            <span>{(m.upload_progress || 0) >= 100 ? t('Processing…', 'جارٍ المعالجة…') : `${t('Uploading…', 'جارٍ الرفع…')} ${m.upload_progress || 0}%`}</span>
          ) : m.failed ? (
            <span className="msg-upload-failed">
              {m.upload_error || t('Upload failed.', 'فشل الرفع.')}{' '}
              <button type="button" className="msg-link-btn" onClick={() => onRetryUpload(m.id)}>{t('Retry', 'إعادة المحاولة')}</button>
              {' · '}
              <button type="button" className="msg-link-btn" onClick={() => onDiscardUpload(m.id)}>{t('Remove', 'إزالة')}</button>
            </span>
          ) : (
            <span>
              {fmtTime(m.created_at)}
              {m.is_edited ? <span className="msg-edited-tag">{t('(edited)', '(معدّلة)')}</span> : null}
            </span>
          )}
        </div>
      </div>
    </div>
  );
}

/** Circular progress ring shown over an attachment while it uploads. */
function UploadRing({ pct }) {
  const t = useT();
  const r = 24;
  const c = 2 * Math.PI * r;
  const processing = pct >= 100; // bytes are all sent, server is still working
  return (
    <div className="msg-upload-overlay" role="progressbar" aria-valuemin={0} aria-valuemax={100} aria-valuenow={pct} aria-label={t('Uploading', 'جارٍ الرفع')}>
      <div className={`msg-upload-ring${processing ? ' is-processing' : ''}`}>
        <svg viewBox="0 0 56 56" aria-hidden="true">
          <circle className="track" cx="28" cy="28" r={r} />
          <circle className="bar" cx="28" cy="28" r={r} strokeDasharray={c} strokeDashoffset={processing ? c * 0.75 : c * (1 - pct / 100)} />
        </svg>
        <span>{processing ? '…' : `${pct}%`}</span>
      </div>
    </div>
  );
}

/** Local (not-yet-uploaded) attachments: previews from the picked files. */
function PendingAttachments({ attachments, pct, uploading }) {
  return (
    <div className={`msg-upload-wrap${uploading ? ' is-uploading' : ''}`}>
      <div className="msg-attachments">
        {attachments.map((a, i) => {
          if (a.kind === 'image') {
            return (
              <div className="msg-attach-image-grid single" key={i}>
                <img src={a.url} alt={a.original_name} />
              </div>
            );
          }
          if (a.kind === 'video') {
            return (
              <div className="msg-attach-video msg-attach-video--tile" key={i}>
                <video src={a.url} preload="metadata" muted playsInline />
              </div>
            );
          }
          if (a.kind === 'audio') {
            return (
              <div className="msg-audio-player" key={i}>
                <audio src={a.url} preload="metadata" controls={!uploading} />
              </div>
            );
          }
          return (
            <div className="msg-attach-file" key={i}>
              <span className="msg-attach-file__icon"><Icon name="file" size={16} /></span>
              <span className="msg-attach-file__meta">
                <span className="msg-attach-file__name">{a.original_name}</span>
                <span className="msg-attach-file__size">{fmtBytes(a.size_bytes)}</span>
              </span>
            </div>
          );
        })}
      </div>
      {uploading && <UploadRing pct={pct} />}
    </div>
  );
}

/** Video attachment: poster tile that turns into an in-place player on tap. */
function InlineVideo({ src, poster, name, duration }) {
  const t = useT();
  const [playing, setPlaying] = useState(false);
  if (playing) {
    return (
      <div className="msg-attach-video msg-attach-video--inline">
        <video src={src} poster={poster} controls autoPlay playsInline preload="auto" />
      </div>
    );
  }
  return (
    <button
      type="button"
      className="msg-attach-video msg-attach-video--tile"
      onClick={() => setPlaying(true)}
      aria-label={`${t('Play video', 'تشغيل الفيديو')} ${name || ''}`}
    >
      <video src={src} poster={poster} preload="metadata" muted playsInline tabIndex={-1} />
      <span className="msg-attach-video__play" aria-hidden="true"><Icon name="play" size={22} /></span>
      {duration ? <span className="msg-attach-video__duration">{fmtDuration(duration)}</span> : null}
    </button>
  );
}

function MessageAttachments({ attachments, onOpenLightbox, onOpenVideo }) {
  const t = useT();
  const images = attachments.filter((a) => a.kind === 'image');
  const others = attachments.filter((a) => a.kind !== 'image');
  // Tapping an image enlarges it in place inside the bubble (tap again to shrink).
  const [expanded, setExpanded] = useState(() => new Set());
  const toggle = (i) =>
    setExpanded((prev) => {
      const next = new Set(prev);
      if (next.has(i)) next.delete(i);
      else next.add(i);
      return next;
    });
  return (
    <div className="msg-attachments">
      {images.length > 0 && (
        <div className={`msg-attach-image-grid${images.length === 1 ? ' single' : ''}${expanded.size ? ' has-expanded' : ''}`}>
          {images.map((a, i) => (
            <span key={i} className={`msg-attach-img${expanded.has(i) ? ' is-expanded' : ''}`}>
              <img
                src={attachmentUrl(a.url)}
                alt={a.original_name}
                onClick={() => toggle(i)}
                style={{ cursor: expanded.has(i) ? 'zoom-out' : 'zoom-in' }}
              />
              <button
                type="button"
                className="msg-attach-img__full"
                title={t('Open full screen', 'فتح بملء الشاشة')}
                aria-label={t('Open full screen', 'فتح بملء الشاشة')}
                onClick={(e) => {
                  e.stopPropagation();
                  onOpenLightbox(attachmentUrl(a.url));
                }}
              >
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
                  <path d="M15 3h6v6M9 21H3v-6M21 3l-7 7M3 21l7-7" />
                </svg>
              </button>
            </span>
          ))}
        </div>
      )}
      {others.map((a, i) => {
        if (a.kind === 'video') {
          // Compact tile in the bubble; tapping it plays the video right
          // here inside the conversation (native controls incl. fullscreen).
          const src = attachmentUrl(a.url);
          const poster = a.thumbnail_url ? attachmentUrl(a.thumbnail_url) : undefined;
          return <InlineVideo key={i} src={src} poster={poster} name={a.original_name} duration={a.duration_seconds} />;
        }
        if (a.kind === 'audio') {
          return (
            <div className="msg-audio-player" key={i}>
              <audio preload="metadata" src={attachmentUrl(a.url)} controls />
            </div>
          );
        }
        return (
          <span key={i} style={{ display: 'inline-flex', alignItems: 'center', gap: 4 }}>
          <a className="msg-attach-file" href={attachmentUrl(a.url)} download={a.original_name} target="_blank" rel="noopener noreferrer">
            <span className="msg-attach-file__icon">
              <Icon name="file" size={16} />
            </span>
            <span className="msg-attach-file__meta">
              <span className="msg-attach-file__name">{a.original_name}</span>
              <span className="msg-attach-file__size">{fmtBytes(a.size_bytes)}</span>
            </span>
          </a>
          <PublicFilePreviewButton url={attachmentUrl(a.url)} filename={a.original_name} />
          </span>
        );
      })}
    </div>
  );
}

export default function ThreadPanel({
  conversation,
  others,
  messages,
  hasMoreOlder,
  onLoadOlder,
  pinnedCount,
  onShowPinned,
  presenceText,
  replyTarget,
  onSetReply,
  onCancelReply,
  pendingFiles,
  onAddFiles,
  onRemoveFile,
  onReplaceFile,
  onSend,
  onEditMessage,
  onDeleteMessage,
  onReact,
  onPin,
  onVote,
  typingUsers,
  onOpenInfo,
  onBack,
  onOpenLightbox,
  onOpenVideo,
  onNotifyTyping,
  onForwardMessage,
  onOpenPollModal,
  onSendVoice,
  onRetryUpload,
  onDiscardUpload,
  railCollapsed,
  onToggleRail,
  onError,
}) {
  const [text, setText] = useState('');
  const [showEmoji, setShowEmoji] = useState(false);
  const [editingId, setEditingId] = useState(null);
  const [isRecording, setIsRecording] = useState(false);
  const [recordingTime, setRecordingTime] = useState('00:00');
  const [editIdx, setEditIdx] = useState(null); // index into pendingFiles being previewed/edited
  const [dragging, setDragging] = useState(false);
  const dragDepth = useRef(0);
  const { locale } = useLanguage();
  const ar = locale === 'ar';
  const t = (en, arText) => (ar ? arText : en);
  const fileInputRef = useRef(null);
  const composerInputRef = useRef(null);
  const mediaRecorderRef = useRef(null);
  const recordingStreamRef = useRef(null);
  const recordingChunksRef = useRef([]);
  const recordingStartRef = useRef(0);
  const recordingTimerRef = useRef(null);
  const scrollRef = useRef(null);
  const bottomRef = useRef(null);
  const prevMessageCount = useRef(0);
  const emojiWrapRef = useRef(null);
  const emojiPopoverRef = useRef(null);

  // One thing plays at a time: starting any video / voice message pauses the
  // others. `play` doesn't bubble, so listen in the capture phase.
  useEffect(() => {
    const onPlay = (e) => {
      const el = e.target;
      if (!(el instanceof HTMLMediaElement)) return;
      document.querySelectorAll('video, audio').forEach((m) => {
        if (m !== el && !m.paused) m.pause();
      });
    };
    document.addEventListener('play', onPlay, true);
    return () => document.removeEventListener('play', onPlay, true);
  }, []);

  // Close the emoji popover on an outside click, and on Escape — without
  // this the only way to close it was toggling the same toolbar button
  // again, which is easy to miss (e.g. after sending a message the
  // popover just stays open with no obvious way out).
  useEffect(() => {
    if (!showEmoji) return;
    const onPointerDown = (e) => {
      if (
        !emojiWrapRef.current?.contains(e.target) &&
        !emojiPopoverRef.current?.contains(e.target)
      ) {
        setShowEmoji(false);
      }
    };
    const onKeyDown = (e) => {
      if (e.key === 'Escape') setShowEmoji(false);
    };
    document.addEventListener('mousedown', onPointerDown);
    document.addEventListener('keydown', onKeyDown);
    return () => {
      document.removeEventListener('mousedown', onPointerDown);
      document.removeEventListener('keydown', onKeyDown);
    };
  }, [showEmoji]);

  useEffect(() => {
    // Scroll to bottom on conversation switch / new own message. Skip when
    // a "load older" prepended messages (handled by ThreadPanel's parent
    // keeping scroll position naturally since older messages render above).
    if (messages.length > prevMessageCount.current) {
      const box = scrollRef.current;
      if (box) {
        const wasNearBottom = box.scrollHeight - box.scrollTop - box.clientHeight < 150;
        if (wasNearBottom || prevMessageCount.current === 0) {
          bottomRef.current?.scrollIntoView({ block: 'end' });
        }
      }
    }
    prevMessageCount.current = messages.length;
  }, [messages]);

  // Stop any in-progress recording if the thread unmounts / switches.
  useEffect(() => {
    return () => {
      clearInterval(recordingTimerRef.current);
      recordingStreamRef.current?.getTracks().forEach((tr) => tr.stop());
    };
  }, [conversation]);

  if (!conversation) {
    return (
      <section className="msg-thread" data-thread-panel>
        <div className="msg-thread__empty" data-thread-empty>
          <div className="msg-thread__empty-icon">
            <Icon name="message" size={32} />
          </div>
          <p>{t('Select a conversation to start messaging', 'اختر محادثة لبدء المراسلة')}</p>
        </div>
      </section>
    );
  }

  // Every way of attaching (clip button, paste, drag & drop) goes through here.
  // A single image/video opens the editor straight away so it can be previewed,
  // cropped / drawn on / trimmed before it is sent.
  const handleFiles = (list) => {
    const arr = Array.from(list || []);
    if (!arr.length) return;
    onAddFiles(arr);
    if (arr.length === 1 && isEditableMedia(arr[0])) setEditIdx(pendingFiles.length);
  };

  const hasFiles = (e) => Array.from(e.dataTransfer?.types || []).includes('Files');
  const onDragEnter = (e) => {
    if (!hasFiles(e)) return;
    e.preventDefault();
    dragDepth.current += 1;
    setDragging(true);
  };
  const onDragOver = (e) => {
    if (hasFiles(e)) e.preventDefault();
  };
  const onDragLeave = (e) => {
    if (!hasFiles(e)) return;
    dragDepth.current = Math.max(0, dragDepth.current - 1);
    if (dragDepth.current === 0) setDragging(false);
  };
  const onDrop = (e) => {
    if (!hasFiles(e)) return;
    e.preventDefault();
    dragDepth.current = 0;
    setDragging(false);
    handleFiles(e.dataTransfer.files);
  };
  const onPaste = (e) => {
    const files = Array.from(e.clipboardData?.files || []);
    if (!files.length) return; // plain text paste: leave to the browser
    e.preventDefault();
    handleFiles(files);
  };

  const editPf = editIdx != null ? pendingFiles[editIdx] : null;

  const handleSend = () => {
    const trimmed = text.trim();
    if (!trimmed && pendingFiles.length === 0) return;
    onSend(trimmed);
    setText('');
    setShowEmoji(false);
  };

  // ------------------------------------------------------- bold/markdown
  // Ported from wrapSelection() in messaging.js, adapted for a plain
  // <textarea> composer (the original wrapped a contenteditable div's
  // live browser selection via document.execCommand("insertText", ...)).
  // Same net effect: wrap the current selection in `marker`, or insert
  // an empty `marker+marker` pair with the cursor left in the middle
  // when nothing is selected — renderBody() in format.jsx already turns
  // **text** into <strong>.
  const wrapSelection = (marker) => {
    const el = composerInputRef.current;
    const start = el ? el.selectionStart ?? text.length : text.length;
    const end = el ? el.selectionEnd ?? text.length : text.length;
    const selected = text.slice(start, end);
    const next = text.slice(0, start) + marker + selected + marker + text.slice(end);
    setText(next);
    const cursor = start + marker.length + selected.length + (selected ? marker.length : 0);
    requestAnimationFrame(() => {
      el?.focus();
      el?.setSelectionRange(cursor, cursor);
    });
  };

  // ------------------------------------------------------- voice recording
  // Ported from startRecording()/stopRecording() in messaging.js.
  const startRecording = () => {
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      onError?.(new Error(t('Voice messages need microphone access, which this browser cannot provide here.', 'الرسائل الصوتية تحتاج إلى الوصول للميكروفون، وهذا المتصفح لا يوفّره هنا.')));
      return;
    }
    navigator.mediaDevices
      .getUserMedia({ audio: true })
      .then((stream) => {
        recordingStreamRef.current = stream;
        const recorder = new MediaRecorder(stream);
        recordingChunksRef.current = [];
        recorder.ondataavailable = (e) => {
          if (e.data.size) recordingChunksRef.current.push(e.data);
        };
        recorder.start();
        mediaRecorderRef.current = recorder;
        recordingStartRef.current = Date.now();
        setIsRecording(true);
        setRecordingTime('00:00');
        recordingTimerRef.current = setInterval(() => {
          setRecordingTime(fmtClock((Date.now() - recordingStartRef.current) / 1000));
        }, 500);
      })
      .catch(() => {
        onError?.(new Error(t('Microphone permission was denied.', 'تم رفض إذن الميكروفون.')));
      });
  };

  const stopRecording = (send) => {
    const recorder = mediaRecorderRef.current;
    if (!recorder) return;
    clearInterval(recordingTimerRef.current);
    setIsRecording(false);
    recorder.onstop = () => {
      recordingStreamRef.current?.getTracks().forEach((tr) => tr.stop());
      if (!send) return;
      const blob = new Blob(recordingChunksRef.current, { type: 'audio/webm' });
      if (blob.size < 300) {
        onError?.(new Error(t('Recording was too short.', 'التسجيل قصير جدًا.')));
        return;
      }
      const file = new File([blob], `voice-message-${Date.now()}.webm`, { type: 'audio/webm' });
      onSendVoice(file);
    };
    recorder.stop();
  };

  let lastDay = null;
  let lastSenderId = null;
  let lastTs = 0;

  return (
    <section className="msg-thread" data-thread-panel>
      <div
        className="msg-thread__active"
        data-thread-active
        onDragEnter={onDragEnter}
        onDragOver={onDragOver}
        onDragLeave={onDragLeave}
        onDrop={onDrop}
      >
        {dragging && (
          <div className="msg-dropzone" aria-hidden="true">
            <div>{ar ? 'أفلت الملفات هنا لإرسالها' : 'Drop files here to send them'}</div>
          </div>
        )}
        <header className="msg-thread__header">
          <button type="button" className="msg-icon-btn msg-thread__back" onClick={onBack} aria-label={t('Back to conversations', 'العودة إلى المحادثات')}>
            <Icon name="arrow-left" size={20} />
          </button>
          {onToggleRail && (
            <button
              type="button"
              className="msg-icon-btn msg-rail-toggle"
              onClick={onToggleRail}
              title={railCollapsed ? t('Show conversation list', 'إظهار قائمة المحادثات') : t('Hide conversation list', 'إخفاء قائمة المحادثات')}
              aria-label={railCollapsed ? t('Show conversation list', 'إظهار قائمة المحادثات') : t('Hide conversation list', 'إخفاء قائمة المحادثات')}
              aria-pressed={!!railCollapsed}
            >
              <Icon name="panel-left" size={18} />
            </button>
          )}
          <div className="msg-thread__peer" data-action="open-info" onClick={onOpenInfo} style={{ cursor: 'pointer' }}>
            <Avatar name={peerLabel(conversation)} isGroup={conversation.is_group} src={others[0]?.avatar_path} />
            <div className="msg-thread__peer-meta">
              <strong>{peerLabel(conversation)}</strong>
              {!conversation.is_group && others[0]?.email && (
                <span className="msg-thread__status" dir="ltr" style={{ display: 'block' }}>
                  {others[0].email}
                  {others[0].account_role ? ` · ${roleLabel(others[0].account_role)}` : ''}
                </span>
              )}
              <span className="msg-thread__status">
                {conversation.is_group ? `${others.length + 1} ${t('members', 'أعضاء')}` : presenceText}
              </span>
            </div>
          </div>
          <div className="msg-thread__header-actions">
            <button type="button" className="msg-icon-btn" title={t('Pinned messages', 'الرسائل المثبّتة')} aria-label={t('Pinned messages', 'الرسائل المثبّتة')} onClick={onShowPinned}>
              <MsgIcon name="pin" size={17} />
            </button>
            <button type="button" className="msg-icon-btn" title={t('Info', 'معلومات')} aria-label={t('Info', 'معلومات')} onClick={onOpenInfo}>
              <Icon name="users" size={17} />
            </button>
          </div>
        </header>

        {pinnedCount > 0 && (
          <div className="msg-thread__pinned-bar" onClick={onShowPinned} style={{ cursor: 'pointer' }}>
            <MsgIcon name="pin" size={14} /> <strong>{pinnedCount}</strong> {pinnedCount > 1 ? t('pinned messages', 'رسائل مثبّتة') : t('pinned message', 'رسالة مثبّتة')}
            <button type="button" className="msg-icon-btn msg-icon-btn--sm" style={{ marginInlineStart: 'auto' }}>{t('View', 'عرض')}</button>
          </div>
        )}

        <div className="msg-thread__scroll" data-message-list ref={scrollRef}>
          {hasMoreOlder && (
            <button type="button" className="msg-load-older" onClick={onLoadOlder}>{t('Load older messages', 'تحميل رسائل أقدم')}</button>
          )}
          <div className="msg-messages" data-messages>
            {messages.length === 0 ? (
              <div className="msg-empty-state">{t('No messages yet — say hello!', 'لا توجد رسائل بعد — ابدأ بالسلام!')}</div>
            ) : (
              messages.map((m) => {
                const day = m.created_at ? m.created_at.slice(0, 10) : '';
                const nodes = [];
                if (day !== lastDay) {
                  nodes.push(
                    <div className="msg-day-sep" key={`day-${day}-${m.id}`}>
                      <span>{fmtDayLabel(m.created_at)}</span>
                    </div>
                  );
                  lastDay = day;
                  lastSenderId = null;
                }
                const ts = m.created_at ? parseServerDate(m.created_at).getTime() : 0;
                const showAvatar = m.sender_id !== lastSenderId || ts - lastTs > 5 * 60000;
                lastSenderId = m.sender_id;
                lastTs = ts;
                nodes.push(
                  <MessageBubble
                    key={m.id}
                    m={m}
                    showAvatar={showAvatar}
                    onRetryUpload={onRetryUpload}
                    onDiscardUpload={onDiscardUpload}
                    others={others}
                    onReply={onSetReply}
                    onDelete={onDeleteMessage}
                    onReact={onReact}
                    onPin={onPin}
                    onVote={onVote}
                    onOpenLightbox={onOpenLightbox}
                    onOpenVideo={onOpenVideo}
                    onForward={onForwardMessage}
                    editingId={editingId}
                    onStartEdit={setEditingId}
                    onCancelEdit={() => setEditingId(null)}
                    onSaveEdit={(id, body) => {
                      onEditMessage(id, body);
                      setEditingId(null);
                    }}
                  />
                );
                return nodes;
              })
            )}
          </div>
          {typingUsers.length > 0 && (
            <div className="msg-typing">
              <span className="msg-typing-dots"><span /><span /><span /></span>{' '}
              {typingUsers.map((u) => u.full_name).join(', ')} {t('typing…', 'يكتب…')}
            </div>
          )}
          <div ref={bottomRef} />
        </div>

        {replyTarget && (
          <div className="msg-reply-preview">
            <div className="msg-reply-preview__body">
              <strong>{t('Replying to', 'الرد على')} {replyTarget.sender_name}</strong>
              <span>{replyTarget.body || t('(attachment)', '(مرفق)')}</span>
            </div>
            <button type="button" className="msg-icon-btn msg-icon-btn--sm" onClick={onCancelReply}>
              <Icon name="x" size={14} />
            </button>
          </div>
        )}

        {pendingFiles.length > 0 && (
          <div className="msg-upload-preview msg-upload-preview--rich">
            {pendingFiles.map((pf, i) => {
              const type = pf.file.type || '';
              const editable = isEditableMedia(pf.file);
              const isVid = type.startsWith('video/');
              return (
                <div className={`msg-upload-item${editable ? ' is-editable' : ''}`} key={`${pf.file.name}-${pf.file.size}-${i}`}>
                  <button
                    type="button"
                    className="msg-upload-item__open"
                    disabled={!editable && !type.startsWith('image/')}
                    onClick={() => setEditIdx(i)}
                    title={ar ? 'معاينة / تعديل' : 'Preview / edit'}
                    aria-label={ar ? 'معاينة / تعديل' : 'Preview / edit'}
                  >
                    {isVid && pf.previewUrl ? (
                      <>
                        <video src={pf.previewUrl} preload="metadata" muted playsInline />
                        <span className="msg-upload-item__badge" aria-hidden="true"><Icon name="play" size={14} /></span>
                      </>
                    ) : pf.previewUrl ? (
                      <img src={pf.previewUrl} alt="" />
                    ) : (
                      <div className="msg-upload-item__name">{pf.file.name.slice(0, 14)}</div>
                    )}
                  </button>
                  {editable && (
                    <span className="msg-upload-item__edit" aria-hidden="true"><Icon name="edit" size={11} /></span>
                  )}
                  <button type="button" className="msg-upload-item__remove" aria-label={ar ? 'حذف' : 'Remove'} onClick={() => onRemoveFile(i)}>&times;</button>
                </div>
              );
            })}
            <button type="button" className="msg-upload-add" onClick={() => fileInputRef.current?.click()} title={ar ? 'إضافة المزيد' : 'Add more'} aria-label={ar ? 'إضافة المزيد' : 'Add more'}>+</button>
          </div>
        )}

        <form
          className="msg-composer"
          autoComplete="off"
          onSubmit={(e) => {
            e.preventDefault();
            handleSend();
          }}
        >
          <div className="msg-composer__toolbar">
            <button type="button" className="msg-icon-btn" title={t('Attach file', 'إرفاق ملف')} aria-label={t('Attach file', 'إرفاق ملف')} onClick={() => fileInputRef.current?.click()}>
              <MsgIcon name="paperclip" size={18} />
            </button>
            <input
              type="file"
              multiple
              hidden
              ref={fileInputRef}
              onChange={(e) => {
                if (e.target.files?.length) handleFiles(e.target.files);
                e.target.value = '';
              }}
            />
            <div className="msg-composer__popover-wrap" ref={emojiWrapRef}>
              <button
                type="button"
                className="msg-icon-btn"
                title={t('Emoji', 'إيموجي')} aria-label={t('Emoji', 'إيموجي')}
                onClick={() => setShowEmoji((s) => !s)}
              >
                <MsgIcon name="smile" size={18} />
              </button>
            </div>
            <button type="button" className="msg-icon-btn" title={t('Markdown', 'تنسيق Markdown')} aria-label={t('Markdown', 'تنسيق Markdown')} onClick={() => wrapSelection('**')}>
              <MsgIcon name="bold" size={18} />
            </button>
            <button type="button" className="msg-icon-btn" title={t('Poll', 'استطلاع')} aria-label={t('Poll', 'استطلاع')} onClick={onOpenPollModal}>
              <MsgIcon name="poll" size={18} />
            </button>
            <button
              type="button"
              className={`msg-icon-btn msg-composer__record${isRecording ? ' is-recording' : ''}`}
              title={t('Voice message', 'رسالة صوتية')} aria-label={t('Voice message', 'رسالة صوتية')}
              onClick={() => (isRecording ? stopRecording(false) : startRecording())}
            >
              <MsgIcon name="mic" size={18} />
            </button>
          </div>
          <div className="msg-composer__input-row">
            <textarea
              className="msg-composer__input"
              data-composer-input
              ref={composerInputRef}
              onPaste={onPaste}
              placeholder={t('Type a message…', 'اكتب رسالة…')}
              rows={1}
              value={text}
              onChange={(e) => {
                setText(e.target.value);
                onNotifyTyping();
              }}
              onKeyDown={(e) => {
                // Guard against IME composition (Arabic predictive-text /
                // any composed-input keyboard): while a candidate is being
                // composed, the virtual keyboard's "confirm suggestion" key
                // also fires a native Enter keydown. Without this check that
                // was sending the message mid-composition — before the rest
                // of what was typed even landed in the field — which is why
                // sent messages could come through as just one or two
                // characters. e.keyCode === 229 is the classic fallback for
                // browsers that don't set isComposing on the event itself.
                if (e.key === 'Enter' && !e.shiftKey && !e.nativeEvent.isComposing && e.keyCode !== 229) {
                  e.preventDefault();
                  handleSend();
                }
              }}
            />
            {showEmoji && (
              <div className="msg-emoji-popover" ref={emojiPopoverRef}>
                <button type="button" className="msg-popover__close" aria-label={t('Close', 'إغلاق')} onClick={() => setShowEmoji(false)}>
                  <Icon name="x" size={13} />
                </button>
                {EMOJI_SET.map((e) => (
                  <button type="button" key={e} onClick={() => setText((t) => t + e)}>{e}</button>
                ))}
              </div>
            )}
            <button type="submit" className="msg-send-btn" aria-label={t('Send', 'إرسال')}>
              <MsgIcon name="send" size={18} />
            </button>
          </div>
          {isRecording && (
            <div className="msg-composer__recording">
              <span className="msg-rec-dot" />
              <span>{recordingTime}</span>
              <span className="msg-waveform-live" />
              <button type="button" className="msg-icon-btn" title={t('Cancel recording', 'إلغاء التسجيل')} aria-label={t('Cancel recording', 'إلغاء التسجيل')} onClick={() => stopRecording(false)}>
                <Icon name="x" size={16} />
              </button>
              <button type="button" className="btn btn-primary btn-sm" onClick={() => stopRecording(true)}>{t('Send', 'إرسال')}</button>
            </div>
          )}
        </form>
      </div>

      {editPf && (
        <MediaEditorModal
          key={`${editIdx}-${editPf.file.name}-${editPf.file.size}`}
          file={editPf.file}
          initialCaption={text}
          onCancel={() => setEditIdx(null)}
          onDone={(f, cap) => {
            if (f !== editPf.file) onReplaceFile(editIdx, f);
            setText(cap);
            setEditIdx(null);
          }}
          onSend={(f, cap) => {
            const files = pendingFiles.map((p, i) => (i === editIdx ? f : p.file));
            setEditIdx(null);
            setText('');
            setShowEmoji(false);
            onSend(cap, files);
          }}
        />
      )}
    </section>
  );
}
