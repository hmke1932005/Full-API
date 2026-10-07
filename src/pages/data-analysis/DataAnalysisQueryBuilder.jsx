import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { api, errorMessage } from '../../api/client';
import Icon from '../../components/Icon';
import SqlEditor from '../../components/sql/SqlEditor';
import { tokenizeSql } from '../../components/sql/sqlHighlight';
import { useTranslations } from '../../context/LanguageContext';
import { useLanguage } from '../../context/LanguageContext';
import i18nCommon from '../../i18n/common';
import i18nPage from '../../i18n/data-analysis/query-builder/index';

const translations = { ...i18nCommon, ...i18nPage };

/**
 * SQL Query Builder — talks to the real JSON API
 * (/api/v1/data-analysis/queries/*, DataAnalysisQueriesApiController).
 * Table/column allow-lists, operators and the 500-row cap all come from the
 * backend contract; nothing here widens what the API accepts.
 * Read-only: SELECT only.
 *
 * UI notes: styles live in styles/css/data-analysis-queries.css (`qb-*`).
 * The visual builder shows a live, client-side SQL preview — it is only a
 * preview; the server builds and validates the real statement on run
 * (the executed SQL is shown under the results).
 */

const OPERATORS = [
  { value: 'eq', label: '=' },
  { value: 'neq', label: '!=' },
  { value: 'gt', label: '>' },
  { value: 'gte', label: '>=' },
  { value: 'lt', label: '<' },
  { value: 'lte', label: '<=' },
  { value: 'contains', label: 'LIKE %..%' },
  { value: 'starts', label: 'LIKE ..%' },
  { value: 'null', label: 'IS NULL' },
  { value: 'not_null', label: 'IS NOT NULL' },
];
const NO_VALUE_OPS = ['null', 'not_null'];
const LIMIT_PRESETS = [50, 100, 250, 500];
const COLUMN_FILTER_THRESHOLD = 12;

function downloadBlob(blob, filename) {
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = filename;
  a.click();
  URL.revokeObjectURL(url);
}

function sqlValue(v) {
  const s = String(v);
  return /^-?\d+(\.\d+)?$/.test(s) ? s : `'${s.replace(/'/g, "''")}'`;
}

/** Client-side preview only — the server builds the statement it actually runs. */
function buildPreviewSql({ table, columns, where, groupBy, orderBy, limit }) {
  if (!table) return '';
  const lines = [`SELECT ${columns.length ? columns.join(', ') : '*'}`, `FROM ${table}`];
  const conds = where
    .filter((w) => w.column && (NO_VALUE_OPS.includes(w.op) || w.value !== ''))
    .map((w) => {
      switch (w.op) {
        case 'contains': return `${w.column} LIKE ${sqlValue(`%${w.value}%`)}`;
        case 'starts': return `${w.column} LIKE ${sqlValue(`${w.value}%`)}`;
        case 'null': return `${w.column} IS NULL`;
        case 'not_null': return `${w.column} IS NOT NULL`;
        default: return `${w.column} ${OPERATORS.find((o) => o.value === w.op)?.label || '='} ${sqlValue(w.value)}`;
      }
    });
  if (conds.length) lines.push(`WHERE ${conds.join('\n  AND ')}`);
  if (groupBy.length) lines.push(`GROUP BY ${groupBy.join(', ')}`);
  const order = orderBy.filter((o) => o.column).map((o) => `${o.column} ${o.dir.toUpperCase()}`);
  if (order.length) lines.push(`ORDER BY ${order.join(', ')}`);
  lines.push(`LIMIT ${Number(limit) || 100};`);
  return lines.join('\n');
}

function SqlCode({ sql, tables }) {
  return tokenizeSql(sql, tables).map((tk, i) => (
    tk.t === 'ws' || tk.t === 'op' ? tk.v : <span key={i} className={`qb-tok-${tk.t}`}>{tk.v}</span>
  ));
}

function isNarrow() {
  return typeof window !== 'undefined' && window.matchMedia('(max-width: 1180px)').matches;
}

function Select({ value, onChange, className = '', children, ...rest }) {
  return (
    <span className={`qb-select ${className}`}>
      <select value={value} onChange={onChange} {...rest}>{children}</select>
      <Icon name="chevron-down" size={16} className="qb-select__icon" />
    </span>
  );
}

function Field({ label, hint, children }) {
  return (
    <div className="qb-field">
      <div className="qb-field__head">
        <span className="qb-field__label">{label}</span>
        {hint && <span className="qb-field__hint">{hint}</span>}
      </div>
      {children}
    </div>
  );
}

function ExportMenu({ onExport, disabled, up, t }) {
  const [open, setOpen] = useState(false);
  const ref = useRef(null);

  useEffect(() => {
    if (!open) return undefined;
    const onDown = (e) => { if (ref.current && !ref.current.contains(e.target)) setOpen(false); };
    const onKey = (e) => { if (e.key === 'Escape') setOpen(false); };
    document.addEventListener('mousedown', onDown);
    document.addEventListener('keydown', onKey);
    return () => { document.removeEventListener('mousedown', onDown); document.removeEventListener('keydown', onKey); };
  }, [open]);

  const pick = (format) => { setOpen(false); onExport(format); };

  return (
    <div className={`qb-menu${up ? ' qb-menu--up' : ''}`} ref={ref}>
      <button type="button" className="btn btn-outline" disabled={disabled} aria-haspopup="menu" aria-expanded={open} aria-label={t('Export')} onClick={() => setOpen((o) => !o)}>
        <Icon name="download" size={16} />
        <span className="qb-btn-label">{t('Export')}</span>
      </button>
      {open && (
        <div className="qb-menu__list" role="menu">
          <button type="button" role="menuitem" onClick={() => pick('csv')}><Icon name="file" size={16} /> {t('Export as CSV')}</button>
          <button type="button" role="menuitem" onClick={() => pick('json')}><Icon name="file" size={16} /> {t('Export as JSON')}</button>
        </div>
      )}
    </div>
  );
}

function ResultCell({ value, t }) {
  if (value === null || value === undefined) return <span className="qb-null">NULL</span>;
  if (typeof value === 'object') return JSON.stringify(value);
  return String(value);
}

export default function DataAnalysisQueryBuilder() {
  const t = useTranslations(translations);
  const { locale } = useLanguage();
  const label = (obj) => obj?.[locale] || obj?.en || '';

  const [catalog, setCatalog] = useState({});
  const [templates, setTemplates] = useState([]);
  const [saved, setSaved] = useState([]);
  const [history, setHistory] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [actionError, setActionError] = useState(null);

  const [mode, setMode] = useState('raw'); // 'raw' | 'builder'
  const [sql, setSql] = useState('');
  const [table, setTable] = useState('');
  const [columns, setColumns] = useState([]);
  const [where, setWhere] = useState([]); // [{column, op, value}]
  const [groupBy, setGroupBy] = useState([]);
  const [orderBy, setOrderBy] = useState([]); // [{column, dir}]
  const [limit, setLimit] = useState(100);

  const [running, setRunning] = useState(false);
  const [result, setResult] = useState(null); // {ok, sql, rows, columns, row_count, execution_time_ms, error}
  const [savedQueryId, setSavedQueryId] = useState(null);
  const [saveOpen, setSaveOpen] = useState(false);

  // UI-only state
  const [colQuery, setColQuery] = useState('');
  const [schemaTable, setSchemaTable] = useState('');
  const [libTab, setLibTab] = useState('templates');
  const [copied, setCopied] = useState(false);

  const workRef = useRef(null);
  const resultsRef = useRef(null);
  const taRef = useRef(null);

  const load = useCallback(() => {
    setLoading(true);
    setError(null);
    api.get('/api/v1/data-analysis/queries')
      .then((json) => {
        setCatalog(json.data?.catalog || {});
        setTemplates(json.data?.templates || []);
        setSaved(json.data?.saved || []);
        setHistory(json.data?.history || []);
        const firstTable = Object.values(json.data?.catalog || {})[0];
        if (firstTable) {
          setTable((prev) => prev || firstTable.table);
          setSchemaTable((prev) => prev || firstTable.table);
        }
      })
      .catch((err) => setError(errorMessage(err)))
      .finally(() => setLoading(false));
  }, []);

  useEffect(() => { load(); }, [load]);

  const tables = Object.values(catalog);
  const tableSet = useMemo(() => new Set(Object.values(catalog).map((c) => String(c.table).toLowerCase())), [catalog]);
  const tableCols = tables.find((c) => c.table === table)?.columns || [];
  const schemaCols = tables.find((c) => c.table === schemaTable)?.columns || [];
  const visibleCols = colQuery.trim()
    ? tableCols.filter((c) => c.toLowerCase().includes(colQuery.trim().toLowerCase()))
    : tableCols;

  const previewSql = useMemo(
    () => buildPreviewSql({ table, columns, where, groupBy, orderBy, limit }),
    [table, columns, where, groupBy, orderBy, limit],
  );
  const canRun = mode === 'builder' ? !!table : sql.trim() !== '';

  function toggleColumn(c) {
    setColumns((prev) => (prev.includes(c) ? prev.filter((x) => x !== c) : [...prev, c]));
  }
  function selectAllVisible() { setColumns((prev) => [...new Set([...prev, ...visibleCols])]); }

  function addWhere() { setWhere((prev) => [...prev, { column: tableCols[0] || '', op: 'eq', value: '' }]); }
  function updateWhere(i, patch) { setWhere((prev) => prev.map((w, idx) => (idx === i ? { ...w, ...patch } : w))); }
  function removeWhere(i) { setWhere((prev) => prev.filter((_, idx) => idx !== i)); }

  function addOrderBy() { setOrderBy((prev) => [...prev, { column: tableCols[0] || '', dir: 'asc' }]); }
  function updateOrderBy(i, patch) { setOrderBy((prev) => prev.map((o, idx) => (idx === i ? { ...o, ...patch } : o))); }
  function removeOrderBy(i) { setOrderBy((prev) => prev.filter((_, idx) => idx !== i)); }

  function changeTable(next) {
    setTable(next);
    setColumns([]);
    setWhere([]);
    setGroupBy([]);
    setOrderBy([]);
    setColQuery('');
  }

  function buildSpec() {
    return {
      table,
      columns,
      where: where.filter((w) => w.column && (NO_VALUE_OPS.includes(w.op) || w.value !== '')),
      group_by: groupBy,
      order_by: orderBy.filter((o) => o.column),
      limit: Number(limit) || 100,
    };
  }

  function showWorkspace() {
    if (isNarrow()) workRef.current?.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  async function handleRun() {
    setRunning(true);
    setActionError(null);
    setResult(null);
    try {
      const payload = mode === 'builder'
        ? { mode: 'builder', spec: buildSpec(), saved_query_id: savedQueryId }
        : { mode: 'raw', sql, saved_query_id: savedQueryId };
      const json = await api.post('/api/v1/data-analysis/queries/run', payload);
      setResult(json.data);
      if (json.data?.sql) setSql(json.data.sql);
      load();
      if (isNarrow()) requestAnimationFrame(() => resultsRef.current?.scrollIntoView({ behavior: 'smooth', block: 'start' }));
    } catch (err) {
      setActionError(errorMessage(err));
    } finally {
      setRunning(false);
    }
  }

  async function handleExport(format) {
    setActionError(null);
    try {
      const payload = mode === 'builder'
        ? { format, mode: 'builder', spec: buildSpec() }
        : { format, mode: 'raw', sql };
      const res = await api.post('/api/v1/data-analysis/queries/export', payload);
      if (res instanceof Response) {
        const blob = await res.blob();
        downloadBlob(blob, `query_result.${format}`);
      } else {
        downloadBlob(new Blob([JSON.stringify(res, null, 2)], { type: 'application/json' }), 'query_result.json');
      }
    } catch (err) {
      setActionError(errorMessage(err));
    }
  }

  function loadSaved(q) {
    setActionError(null);
    api.get(`/api/v1/data-analysis/queries/saved/${q.id}`)
      .then((json) => {
        const data = json.data;
        setSavedQueryId(data.id);
        setSql(data.sql_text || '');
        if (data.builder_state && data.builder_state.table) {
          setMode('builder');
          const spec = data.builder_state;
          setTable(spec.table || '');
          setColumns(spec.columns || []);
          setWhere(spec.where || []);
          setGroupBy(spec.group_by || []);
          setOrderBy(spec.order_by || []);
          setLimit(spec.limit || 100);
        } else {
          setMode('raw');
        }
        setResult(null);
        showWorkspace();
      })
      .catch((err) => setActionError(errorMessage(err)));
  }

  function loadSql(text) {
    setMode('raw');
    setSql(text || '');
    setSavedQueryId(null);
    showWorkspace();
  }

  function deleteSaved(id) {
    if (!confirm(t('Delete'))) return;
    api.del(`/api/v1/data-analysis/queries/saved/${id}`)
      .then(() => load())
      .catch((err) => setActionError(errorMessage(err)));
  }

  function insertAtCursor(text) {
    const el = taRef.current;
    if (!el) { setSql((p) => `${p}${text}`); return; }
    const start = el.selectionStart ?? sql.length;
    const end = el.selectionEnd ?? start;
    const before = sql.slice(0, start);
    const lead = before && !/[\s(,]$/.test(before) ? ' ' : '';
    const insert = `${lead}${text}`;
    setSql(`${before}${insert}${sql.slice(end)}`);
    requestAnimationFrame(() => {
      el.focus();
      const pos = start + insert.length;
      el.setSelectionRange(pos, pos);
    });
  }

  async function copyPreview() {
    try {
      await navigator.clipboard.writeText(previewSql);
      setCopied(true);
      setTimeout(() => setCopied(false), 1500);
    } catch { /* clipboard unavailable — ignore */ }
  }

  const actions = (up) => (
    <>
      <button type="button" className="btn btn-outline" disabled={!canRun} aria-label={t('Save Query')} onClick={() => setSaveOpen(true)}>
        <Icon name="bookmark" size={16} />
        <span className="qb-btn-label">{t('Save Query')}</span>
      </button>
      <ExportMenu up={up} onExport={handleExport} disabled={!canRun} t={t} />
      <button type="button" className={`btn btn-primary qb-run${running ? ' is-loading' : ''}`} disabled={running || !canRun} onClick={handleRun}>
        <Icon name="play" size={16} />
        <span className="qb-btn-label">{t('Run')}</span>
      </button>
    </>
  );

  const resultColumns = result?.columns?.length ? result.columns : Object.keys(result?.rows?.[0] || {});

  return (
    <>
      <header className="page-header animate-rise-in">
        <div className="page-header__title">
          <h1 className="text-h1">{t('SQL Query Builder')}</h1>
          <p className="text-small">{t("Build SELECT queries visually or write raw SQL, restricted to the platform's allow-listed core datasets.")}</p>
        </div>
        <ul className="qb-guard" aria-label={t('Read-only')}>
          <li><Icon name="lock" size={14} /> {t('Read-only')}</li>
          <li><Icon name="shield" size={14} /> {t('SELECT only')}</li>
          <li><Icon name="layers" size={14} /> {t('Max 500 rows per run')}</li>
        </ul>
      </header>

      {actionError && (
        <div className="qb-alert" role="alert">
          <Icon name="alert-triangle" size={18} />
          <span>{actionError}</span>
          <button type="button" className="qb-icon-btn" style={{ width: 28, height: 28 }} aria-label={t('Dismiss')} onClick={() => setActionError(null)}><Icon name="x" size={14} /></button>
        </div>
      )}

      {loading && (
        <div className="qb" aria-busy="true" aria-label={t('Loading query builder')}>
          <div className="qb__main">
            <div className="qb-panel glass-panel" style={{ padding: 'var(--space-5)', display: 'grid', gap: 'var(--space-3)' }}>
              <span className="qb-skel" style={{ height: 40, width: '45%' }} />
              <span className="qb-skel" style={{ height: 160 }} />
              <span className="qb-skel" style={{ height: 44, width: '100%' }} />
            </div>
          </div>
          <div className="qb__aside"><div className="qb-panel glass-panel" style={{ padding: 'var(--space-5)' }}><span className="qb-skel" style={{ height: 220 }} /></div></div>
        </div>
      )}

      {error && (
        <div className="qb-panel glass-panel qb-state qb-state--error" role="alert">
          <span className="qb-state__icon"><Icon name="alert-triangle" size={24} /></span>
          <h3>{t('Query failed')}</h3>
          <p>{error}</p>
          <button type="button" className="btn btn-outline btn-sm" onClick={load}><Icon name="refresh" size={14} /> {t('Try again')}</button>
        </div>
      )}

      {!loading && !error && (
        <div className="qb">
          <div className="qb__main">
            {/* ---------------- Workspace ---------------- */}
            <section className="qb-panel glass-panel" ref={workRef}>
              <div className="qb-bar">
                <div className="qb-seg" role="tablist" aria-label={t('Compose')}>
                  <button type="button" role="tab" aria-selected={mode === 'raw'} onClick={() => setMode('raw')}><Icon name="terminal" size={15} /> {t('SQL Editor')}</button>
                  <button type="button" role="tab" aria-selected={mode === 'builder'} onClick={() => setMode('builder')}><Icon name="grid" size={15} /> {t('Visual Builder')}</button>
                </div>
                <div className="qb-actions qb-actions--top">{actions(false)}</div>
              </div>

              <div className="qb-body">
                {mode === 'raw' && (
                  <>
                    <SqlEditor
                      ref={taRef}
                      value={sql}
                      onChange={setSql}
                      onRun={() => { if (canRun && !running) handleRun(); }}
                      tables={tableSet}
                      ariaLabel={t('SQL Editor')}
                      linesLabel={t('lines')}
                      runLabel={t('to run')}
                      placeholder={`SELECT * FROM ${schemaTable || 'projects'} LIMIT 50;`}
                    />

                    {tables.length > 0 && (
                      <details className="qb-schema">
                        <summary>
                          <Icon name="layers" size={16} /> {t('Tables & Columns')}
                          <Icon name="chevron-down" size={16} className="qb-schema__caret" />
                        </summary>
                        <div className="qb-schema__body">
                          <div className="qb-chips" role="group" aria-label={t('Table')}>
                            {tables.map((c) => (
                              <button key={c.key} type="button" className={`qb-chip${schemaTable === c.table ? ' is-on' : ''}`} aria-pressed={schemaTable === c.table} onClick={() => setSchemaTable(c.table)}>
                                {c.table}
                              </button>
                            ))}
                          </div>
                          <span className="qb-field__hint">{t('Click to insert at the cursor')}</span>
                          <div className="qb-chips">
                            <button type="button" className="qb-chip is-on" onClick={() => insertAtCursor(schemaTable)}>{schemaTable}</button>
                            {schemaCols.map((c) => (
                              <button key={c} type="button" className="qb-chip" onClick={() => insertAtCursor(c)}>{c}</button>
                            ))}
                          </div>
                        </div>
                      </details>
                    )}
                  </>
                )}

                {mode === 'builder' && (
                  <div className="qb-stack">
                    <Field label={t('Base table')} hint={tableCols.length ? `${tableCols.length} ${t('columns')}` : null}>
                      <Select value={table} onChange={(e) => changeTable(e.target.value)} aria-label={t('Base table')}>
                        {tables.map((c) => <option key={c.key} value={c.table}>{label(c.label)}</option>)}
                      </Select>
                    </Field>

                    <Field label={t('Columns')} hint={columns.length ? `${columns.length} ${t('selected')}` : t('Leave empty to select all')}>
                      {(tableCols.length > COLUMN_FILTER_THRESHOLD || columns.length > 0) && (
                        <div className="qb-tools">
                          {tableCols.length > COLUMN_FILTER_THRESHOLD && (
                            <div className="qb-search">
                              <Icon name="search" size={16} />
                              <input className="qb-input" type="search" value={colQuery} onChange={(e) => setColQuery(e.target.value)} placeholder={t('Filter columns')} aria-label={t('Filter columns')} />
                            </div>
                          )}
                          <button type="button" className="qb-link" onClick={selectAllVisible}>{t('Select all')}</button>
                          {columns.length > 0 && <button type="button" className="qb-link" onClick={() => setColumns([])}>{t('Clear')}</button>}
                        </div>
                      )}
                      <div className="qb-chips" role="group" aria-label={t('Columns')}>
                        {visibleCols.map((c) => (
                          <label key={c} className="qb-chip">
                            <input type="checkbox" checked={columns.includes(c)} onChange={() => toggleColumn(c)} />
                            <span>{columns.includes(c) && <Icon name="check" size={12} />}{c}</span>
                          </label>
                        ))}
                        {visibleCols.length === 0 && <p className="qb-empty-note">{t('No columns match.')}</p>}
                      </div>
                    </Field>

                    <Field label={t('Filter rows')} hint={where.length > 1 ? t('Rows must match every condition') : null}>
                      {where.length > 0 && (
                        <div className="qb-rows">
                          {where.map((w, i) => {
                            const noVal = NO_VALUE_OPS.includes(w.op);
                            return (
                              <div key={i} className={`qb-row qb-row--where${noVal ? ' is-noval' : ''}`}>
                                <Select value={w.column} onChange={(e) => updateWhere(i, { column: e.target.value })} aria-label={t('Columns')}>
                                  {tableCols.map((c) => <option key={c} value={c}>{c}</option>)}
                                </Select>
                                <Select value={w.op} onChange={(e) => updateWhere(i, { op: e.target.value })} aria-label={t('Where')}>
                                  {OPERATORS.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
                                </Select>
                                {!noVal && <input className="qb-input" value={w.value} onChange={(e) => updateWhere(i, { value: e.target.value })} placeholder={t('Value')} aria-label={t('Value')} />}
                                <button type="button" className="qb-icon-btn qb-icon-btn--danger" aria-label={t('Delete')} onClick={() => removeWhere(i)}><Icon name="trash" size={16} /></button>
                              </div>
                            );
                          })}
                        </div>
                      )}
                      <button type="button" className="qb-add" onClick={addWhere}><Icon name="plus" size={16} /> {t('Add filter')}</button>
                    </Field>

                    <Field label={t('Group results by')}>
                      {groupBy.length > 0 && (
                        <div className="qb-chips">
                          {groupBy.map((c) => (
                            <button key={c} type="button" className="qb-chip qb-chip--removable is-on" aria-label={`${t('Delete')} ${c}`} onClick={() => setGroupBy((p) => p.filter((x) => x !== c))}>
                              {c} <Icon name="x" size={12} />
                            </button>
                          ))}
                        </div>
                      )}
                      <Select value="" onChange={(e) => { const v = e.target.value; if (v) setGroupBy((p) => [...p, v]); }} aria-label={t('Add column')}>
                        <option value="">{t('Add column')}…</option>
                        {tableCols.filter((c) => !groupBy.includes(c)).map((c) => <option key={c} value={c}>{c}</option>)}
                      </Select>
                    </Field>

                    <Field label={t('Sort by')}>
                      {orderBy.length > 0 && (
                        <div className="qb-rows">
                          {orderBy.map((o, i) => (
                            <div key={i} className="qb-row qb-row--sort">
                              <Select value={o.column} onChange={(e) => updateOrderBy(i, { column: e.target.value })} aria-label={t('Sort by')}>
                                {tableCols.map((c) => <option key={c} value={c}>{c}</option>)}
                              </Select>
                              <div className="qb-seg qb-seg--sm" role="group" aria-label={t('Sort by')}>
                                <button type="button" aria-pressed={o.dir === 'asc'} onClick={() => updateOrderBy(i, { dir: 'asc' })} title={t('Ascending')}>ASC</button>
                                <button type="button" aria-pressed={o.dir === 'desc'} onClick={() => updateOrderBy(i, { dir: 'desc' })} title={t('Descending')}>DESC</button>
                              </div>
                              <button type="button" className="qb-icon-btn qb-icon-btn--danger" aria-label={t('Delete')} onClick={() => removeOrderBy(i)}><Icon name="trash" size={16} /></button>
                            </div>
                          ))}
                        </div>
                      )}
                      <button type="button" className="qb-add" onClick={addOrderBy}><Icon name="plus" size={16} /> {t('Add sort')}</button>
                    </Field>

                    <Field label={t('Rows to return')} hint={t('Max 500 rows per run')}>
                      <div className="qb-limit">
                        <input className="qb-input" type="number" inputMode="numeric" min={1} max={500} value={limit} onChange={(e) => setLimit(e.target.value)} aria-label={t('Row limit')} />
                        <div className="qb-chips">
                          {LIMIT_PRESETS.map((n) => (
                            <button key={n} type="button" className={`qb-chip${Number(limit) === n ? ' is-on' : ''}`} aria-pressed={Number(limit) === n} onClick={() => setLimit(n)}>{n}</button>
                          ))}
                        </div>
                      </div>
                    </Field>

                    <div>
                      <div className="qb-code">
                        <div className="qb-code__head">
                          <span>{t('Generated SQL')}</span>
                          <div>
                            <button type="button" className="qb-code__btn" onClick={copyPreview} disabled={!previewSql}>
                              <Icon name={copied ? 'check' : 'copy'} size={14} /> {copied ? t('Copied') : t('Copy')}
                            </button>
                            <button type="button" className="qb-code__btn" disabled={!previewSql} onClick={() => loadSql(previewSql)}>
                              <Icon name="edit" size={14} /> {t('Edit as SQL')}
                            </button>
                          </div>
                        </div>
                        <pre dir="ltr"><code><SqlCode sql={previewSql || '-- '} /></code></pre>
                      </div>
                      <p className="qb-field__hint" style={{ marginTop: 'var(--space-2)' }}>{t('Updates as you build. The server validates it on run.')}</p>
                    </div>
                  </div>
                )}
              </div>
            </section>

            <div className="qb-dock">{actions(true)}</div>

            {/* ---------------- Results ---------------- */}
            <section className="qb-panel glass-panel qb-results" ref={resultsRef} aria-live="polite">
              <div className="qb-results__head">
                <h2 className="text-h3">{t('Results')}</h2>
                {result?.ok && (
                  <div className="qb-stats">
                    <span className="qb-stat"><Icon name="layers" size={13} /> {result.row_count} {t('rows')}</span>
                    <span className="qb-stat"><Icon name="grid" size={13} /> {resultColumns.length} {t('columns')}</span>
                    <span className="qb-stat"><Icon name="clock" size={13} /> {result.execution_time_ms} ms</span>
                  </div>
                )}
              </div>

              {running && (
                <div className="qb-results__body" aria-busy="true">
                  <span className="qb-skel" style={{ height: 38 }} />
                  {[0, 1, 2, 3, 4].map((i) => <span key={i} className="qb-skel" style={{ height: 30, opacity: 1 - i * 0.15 }} />)}
                </div>
              )}

              {!running && !result && (
                <div className="qb-state">
                  <span className="qb-state__icon"><Icon name="bar-chart" size={24} /></span>
                  <h3>{t('Run a query to see results here.')}</h3>
                  <p>{t('Results will appear here with row count and run time.')}</p>
                </div>
              )}

              {!running && result && !result.ok && (
                <div className="qb-state qb-state--error" role="alert">
                  <span className="qb-state__icon"><Icon name="alert-triangle" size={24} /></span>
                  <h3>{t('Query failed')}</h3>
                  <pre dir="ltr">{result.error}</pre>
                </div>
              )}

              {!running && result?.ok && (
                <div className="qb-results__body">
                  {result.sql && (
                    <details className="qb-sqlpeek">
                      <summary>{t('Preview generated SQL')}</summary>
                      <div className="qb-code" style={{ marginTop: 'var(--space-2)' }}>
                        <pre dir="ltr"><code><SqlCode sql={result.sql} tables={tableSet} /></code></pre>
                      </div>
                    </details>
                  )}
                  {(result.rows || []).length === 0 ? (
                    <div className="qb-state" style={{ padding: 'var(--space-6) var(--space-4)' }}>
                      <span className="qb-state__icon"><Icon name="inbox" size={24} /></span>
                      <h3>0 {t('rows')}</h3>
                    </div>
                  ) : (
                    <div className="qb-table-wrap" tabIndex={0} role="region" aria-label={t('Results')}>
                      <table className="qb-table">
                        <thead>
                          <tr>
                            <th className="qb-idx">#</th>
                            {resultColumns.map((c) => <th key={c}>{c}</th>)}
                          </tr>
                        </thead>
                        <tbody>
                          {result.rows.map((row, i) => (
                            <tr key={i}>
                              <td className="qb-idx">{i + 1}</td>
                              {resultColumns.map((c) => {
                                const v = row[c];
                                const text = v === null || v === undefined ? '' : typeof v === 'object' ? JSON.stringify(v) : String(v);
                                return (
                                  <td key={c} className={typeof v === 'number' ? 'is-num' : undefined} title={text || undefined}>
                                    <ResultCell value={v} t={t} />
                                  </td>
                                );
                              })}
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    </div>
                  )}
                </div>
              )}
            </section>
          </div>

          {/* ---------------- Library rail ---------------- */}
          <aside className="qb__aside">
            <section className="qb-panel glass-panel">
              <div className="qb-lib__head">
                <h2 className="text-h3">{t('Library')}</h2>
                <div className="qb-seg" role="tablist" aria-label={t('Library')}>
                  <button type="button" role="tab" aria-selected={libTab === 'templates'} onClick={() => setLibTab('templates')}>{t('Templates')} <span className="qb-count">{templates.length}</span></button>
                  <button type="button" role="tab" aria-selected={libTab === 'saved'} onClick={() => setLibTab('saved')}>{t('Saved')} <span className="qb-count">{saved.length}</span></button>
                  <button type="button" role="tab" aria-selected={libTab === 'history'} onClick={() => setLibTab('history')}>{t('History')} <span className="qb-count">{history.length}</span></button>
                </div>
              </div>

              <div className="qb-lib__list" role="tabpanel">
                {libTab === 'templates' && (
                  templates.length === 0
                    ? <p className="qb-lib__empty">{t('No templates yet.')}</p>
                    : templates.map((tpl, i) => (
                      <button key={i} type="button" className="qb-item" onClick={() => loadSql(tpl.sql_text)} aria-label={`${t('Load into editor')}: ${(locale === 'ar' && tpl.name_ar) || tpl.name}`}>
                        <span className="qb-item__title">
                          <span>{(locale === 'ar' && tpl.name_ar) || tpl.name}</span>
                          {tpl.category ? <span className="badge badge-neutral">{tpl.category}</span> : null}
                          <Icon name="arrow-right" size={15} className="icon-flip" />
                        </span>
                        {(tpl.description || tpl.description_ar) && <span className="qb-item__desc">{(locale === 'ar' && tpl.description_ar) || tpl.description}</span>}
                      </button>
                    ))
                )}

                {libTab === 'saved' && (
                  saved.length === 0
                    ? <p className="qb-lib__empty">{t('No saved queries yet.')}<br />{t('Save a query to reuse it later.')}</p>
                    : saved.map((q) => (
                      <div key={q.id} className="qb-item">
                        <span className="qb-item__title">
                          <span>{q.name}</span>
                          {Number(q.is_shared) ? <span className="badge badge-neutral">{t('shared')}</span> : null}
                        </span>
                        {q.description && <span className="qb-item__desc">{q.description}</span>}
                        <span className="qb-item__actions">
                          <button type="button" className="btn btn-outline btn-sm" onClick={() => loadSaved(q)}>{t('Load into editor')}</button>
                          <button type="button" className="qb-icon-btn qb-icon-btn--danger" style={{ width: 34, height: 34 }} aria-label={`${t('Delete')} ${q.name}`} onClick={() => deleteSaved(q.id)}><Icon name="trash" size={15} /></button>
                        </span>
                      </div>
                    ))
                )}

                {libTab === 'history' && (
                  history.length === 0
                    ? <p className="qb-lib__empty">{t('No runs yet.')}<br />{t('Runs appear here after you execute a query.')}</p>
                    : history.map((h, i) => (
                      <button key={i} type="button" className="qb-item" onClick={() => loadSql(h.sql_text)} aria-label={t('Load into editor')}>
                        <span className="qb-item__title">
                          <span className={`qb-dot${h.status === 'success' ? '' : ' is-error'}`} title={h.status === 'success' ? t('success') : t('error')} />
                          <span className={`qb-item__meta${h.status === 'success' ? '' : ' is-error'}`} style={{ flex: 1 }}>
                            {[h.status === 'success' ? null : t('error'), h.row_count != null ? `${h.row_count} ${t('rows')}` : null, h.execution_time_ms != null ? `${h.execution_time_ms} ms` : null, (h.executed_at || h.created_at) ? String(h.executed_at || h.created_at).slice(0, 16).replace('T', ' ') : null].filter(Boolean).join(' · ')}
                          </span>
                        </span>
                        <span className="qb-item__sql" dir="ltr">{h.sql_text}</span>
                        {h.error_message && <span className="qb-item__error">{h.error_message}</span>}
                      </button>
                    ))
                )}
              </div>
            </section>
          </aside>
        </div>
      )}

      {saveOpen && (
        <SaveQueryModal
          sql={mode === 'builder' ? (result?.sql || previewSql) : sql}
          builderState={mode === 'builder' ? buildSpec() : null}
          datasetKey={tables.find((c) => c.table === table)?.key || null}
          onClose={() => setSaveOpen(false)}
          onDone={(err) => { setSaveOpen(false); if (err) setActionError(err); else load(); }}
        />
      )}
    </>
  );
}

function SaveQueryModal({ sql, builderState, datasetKey, onClose, onDone }) {
  const t = useTranslations(translations);
  const [name, setName] = useState('');
  const [description, setDescription] = useState('');
  const [isShared, setIsShared] = useState(false);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState(null);
  const nameRef = useRef(null);

  useEffect(() => {
    nameRef.current?.focus();
    const onKey = (e) => { if (e.key === 'Escape') onClose(); };
    document.addEventListener('keydown', onKey);
    return () => document.removeEventListener('keydown', onKey);
  }, [onClose]);

  async function handleSubmit(e) {
    e.preventDefault();
    setSaving(true);
    setError(null);
    try {
      await api.post('/api/v1/data-analysis/queries/save', {
        name,
        sql,
        description: description || null,
        builder_state: builderState,
        dataset_key: datasetKey,
        is_shared: isShared,
      });
      onDone(null);
    } catch (err) {
      setError(errorMessage(err));
      setSaving(false);
    }
  }

  return (
    <div className="modal-overlay" onClick={onClose}>
      <div className="modal-box qb-modal-box card glass-panel qb-modal" role="dialog" aria-modal="true" aria-labelledby="qb-save-title" onClick={(e) => e.stopPropagation()}>
        <h2 className="modal-box__title text-h3" id="qb-save-title">{t('Save Query')}</h2>
        <form onSubmit={handleSubmit}>
          <div className="qb-field">
            <label className="qb-field__label" htmlFor="qb_name">{t('Name')}</label>
            <input id="qb_name" ref={nameRef} className="qb-input" value={name} onChange={(e) => setName(e.target.value)} required />
          </div>
          <div className="qb-field">
            <label className="qb-field__label" htmlFor="qb_desc">{t('Description (optional)')}</label>
            <textarea id="qb_desc" className="qb-input" value={description} onChange={(e) => setDescription(e.target.value)} />
          </div>
          <label className="qb-check" htmlFor="q_shared">
            <input type="checkbox" id="q_shared" checked={isShared} onChange={(e) => setIsShared(e.target.checked)} />
            {t('Share with other data analysts')}
          </label>
          {error && <p className="form-error" role="alert">{error}</p>}
          <div className="modal-box__actions">
            <button type="button" className="btn btn-outline" onClick={onClose}>{t('Cancel')}</button>
            <button type="submit" className={`btn btn-primary${saving ? ' is-loading' : ''}`} disabled={saving}>{t('Save')}</button>
          </div>
        </form>
      </div>
    </div>
  );
}
