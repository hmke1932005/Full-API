# Exam & Assessment System — API Documentation

All routes are prefixed `/api/v1/exam-system/*`, sit behind the `uip.auth` middleware
(a valid session/token is required for every endpoint below), and follow the
project-wide response envelope:

```json
{ "success": true|false, "message": "...", "data": ..., "errors": ..., "meta": {} }
```

Role checks are enforced in the controller for every endpoint (not just at the route
level) — see the "Role" column. `academic_staff` endpoints are additionally
ownership-scoped: an instructor can only read/modify banks, questions, exams, pools,
attempts, and grades that belong to them.

This file is new — the project did not previously have a central Exam System API
reference (`README.md`/`AUTH_API_CONTRACT.md` do not cover it). It documents every
exam-system endpoint across all 8 build rounds, added at Phase 44 (Round 8) alongside
the analytics/dashboards/export work.

---

## Round 1 — Question Banks, Questions, Exams (Foundation)

Role: `academic_staff`

| Method | Path | Description |
|---|---|---|
| GET | `question-banks` | List the instructor's question banks (with question counts). |
| POST | `question-banks` | Create a question bank. |
| GET | `question-banks/{id}` | Show one bank (owned). |
| PATCH | `question-banks/{id}` | Update a bank (owned). |
| DELETE | `question-banks/{id}` | Delete a bank (owned). |
| POST | `question-banks/{bankId}/questions` | Add a question (mcq/true_false in Round 1; short_answer/essay from Round 6) to a bank. |
| PATCH | `questions/{id}` | Update a question (owned via its bank). |
| DELETE | `questions/{id}` | Delete a question (owned via its bank). |
| GET | `exams` | List the instructor's exams. |
| POST | `exams` | Create an exam (starts as `draft`). |
| GET | `exams/{id}` | Show one exam (owned), with manual questions + pool configs. |
| PATCH | `exams/{id}` | Update exam metadata (owned). |
| DELETE | `exams/{id}` | Delete an exam (owned). |
| POST | `exams/{id}/questions` | Attach a manual question to an exam. |
| PATCH | `exams/{id}/questions/reorder` | Reorder an exam's manual questions. |
| DELETE | `exams/{id}/questions/{examQuestionId}` | Detach a manual question from an exam. |

## Round 6 — Rubrics (Question-level, AI-gradable types only)

Role: `academic_staff`

| Method | Path | Description |
|---|---|---|
| GET | `questions/{questionId}/rubric` | Show the rubric + model answer for a short_answer/essay question. |
| PUT | `questions/{questionId}/rubric` | Create/replace the rubric for a question. |
| DELETE | `questions/{questionId}/rubric` | Remove a question's rubric. |

## Round 7 — Question Pools + Randomization

Role: `academic_staff`

| Method | Path | Description |
|---|---|---|
| GET | `question-banks/{bankId}/pools` | List pools in a bank. |
| POST | `question-banks/{bankId}/pools` | Create a pool. |
| GET | `pools/{id}` | Show one pool + its member question IDs. |
| PATCH | `pools/{id}` | Update a pool. |
| DELETE | `pools/{id}` | Delete a pool. |
| PUT | `pools/{id}/questions` | Sync a pool's full question membership. |
| GET | `exams/{id}/pools` | List an exam's pool configurations. |
| POST | `exams/{id}/pools` | Attach a pool to an exam (`questions_to_select`, optional `distribution`). |
| PATCH | `exams/{id}/pools/{configId}` | Update an exam's pool configuration. |
| DELETE | `exams/{id}/pools/{configId}` | Detach a pool from an exam. |

## Round 2 — Targeting + Publish

Role: `academic_staff`

| Method | Path | Description |
|---|---|---|
| GET | `exams/{id}/targets` | Show an exam's saved targeting rules. |
| PUT | `exams/{id}/targets` | Replace targeting rules. **Round 8:** if the exam is already `published`/`scheduled`, students newly made eligible are notified `exam_assigned`. |
| POST | `exams/{id}/targets/preview` | Live count of students matching unsaved targeting rules. |
| GET | `exams/{id}/targets/students` | Sample (up to 200) of students matching saved targeting rules. |
| POST | `exams/{id}/publish` | Publish (`draft`/`scheduled` → `published` or `scheduled`, depending on `start_at`). **Round 8:** notifies every eligible student `exam_scheduled` or `exam_available`. |
| POST | `exams/{id}/unpublish` | Revert to `draft`. |

## Round 2 — Student "My Exams"

Role: `student`

| Method | Path | Description |
|---|---|---|
| GET | `my-exams` | List published/scheduled exams the student is eligible for. |
| GET | `my-exams/{id}` | Show one exam's metadata (no question content) if eligible. |

## Round 3 — Attempts, Timer, Auto-save

Role: `student`

| Method | Path | Description |
|---|---|---|
| GET | `my-exams/{id}/attempts` | List the student's own attempts on an exam. |
| POST | `my-exams/{id}/attempts` | Start (or resume) an attempt — draws pool questions if configured. |
| GET | `attempts/{id}` | Show attempt detail (questions for *this* attempt, server-computed remaining time). |
| PUT | `attempts/{id}/answers/{examQuestionId}` | Save/auto-save an answer. |
| DELETE | `attempts/{id}/answers/{examQuestionId}` | Clear a saved answer. |
| POST | `attempts/{id}/submit` | Submit the attempt (manual or auto). Triggers grading pipeline. |
| GET | `attempts/{id}/result` | Student's own result view, respecting `result_visibility`. |

## Round 5 — Secure Exam Mode / Security Events

| Method | Path | Role | Description |
|---|---|---|---|
| POST | `attempts/{id}/security-events` | `student` | Record a security violation (tab exit, copy/paste, fullscreen exit, etc.). Auto-submits on exceeding `max_violations`. |
| GET | `exams/{id}/attempts/{attemptId}/security-events` | `academic_staff` | Instructor's timeline of an attempt's security events. |

## Round 4/6 — Grading (Manual + Automatic + AI)

Role: `academic_staff`

| Method | Path | Description |
|---|---|---|
| GET | `exams/{id}/attempts` | List all attempts on an exam, with grading progress. |
| GET | `exams/{id}/attempts/{attemptId}` | Full attempt detail for grading (questions, answers, grades). |
| POST | `exams/{id}/attempts/{attemptId}/auto-grade` | Re-run automatic grading for objective questions only. |
| PUT | `exams/{id}/attempts/{attemptId}/grades/{examQuestionId}` | Manual grade / override. **Round 8:** logs `grade_changed`/`grade_overridden` to the audit trail; notifies the student `exam_grade_changed` if this overrides an existing score *and* results are already published to them. |
| GET | `exams/{id}/attempts/{attemptId}/grades/{examQuestionId}/history` | Full grade-change history for one question. |
| POST | `exams/{id}/attempts/{attemptId}/grades/{examQuestionId}/accept-ai` | Accept an AI-suggested grade as-is. **Round 8:** logs `ai_grade_accepted`. |
| POST | `exams/{id}/attempts/{attemptId}/grades/{examQuestionId}/ai-regrade` | Re-queue AI grading for one question. **Round 8:** logs `exam_regraded`. |
| POST | `exams/{id}/publish-results` | Publish results (for `result_visibility=manual` exams). **Round 8:** notifies every student with a graded attempt `exam_result_published`. |
| POST | `exams/{id}/unpublish-results` | Revoke published results. |

---

## Round 8 — Analytics, Dashboards, Notifications, Export

### Analytics & Dashboards (Phases 25–28)

| Method | Path | Role | Description |
|---|---|---|---|
| GET | `exams/{id}/analytics` | `academic_staff` | Exam-level stats (total/started/submitted/graded/average/median/highest/lowest/pass rate/failure rate/average time) + question-level stats (correct %, incorrect %, average score %, hardest/easiest question). Owned exams only. |
| GET | `dashboard/instructor` | `academic_staff` | My Exams / Active Exams / Pending Grading / Recent Results. |
| GET | `dashboard/student` | `student` | Upcoming/Active/Completed exams, recent results, average score. Recent results respect `result_visibility` exactly as `attempts/{id}/result` does. |
| GET | `faculty/exams` | `faculty` | Every exam in the caller's own faculty, with a quick summary per exam. `faculty_id` is resolved from the authenticated account, never from client input. |
| GET | `faculty/dashboard` | `faculty` | Total Exams / Total Students / Total Attempts / Average Performance / Pass Rate, scoped to the caller's faculty. |
| GET | `university/exams` | `university` | Every exam across the caller's own university (all faculties). |
| GET | `university/dashboard` | `university` | Same aggregate widgets as the faculty dashboard, scoped to the whole university. |

Only metrics reliably computable from real recorded data are exposed — e.g.
`correct_pct`/`incorrect_pct` are `null` for non-objective questions (essay/short_answer),
since "correct/incorrect" has no meaning for AI/manually-graded free text; `average_score_pct`
(marks/max_marks) is the unified difficulty metric used for both types.

### Notifications (Phase 29)

Integrated into the existing `Notification`/`NotificationService` system — no new
notification architecture. Triggered directly from the service layer at the point the
underlying fact becomes true (not re-derived in a controller):

| Type | Triggered by | Recipient(s) |
|---|---|---|
| `exam_scheduled` | `ExamSystemService::publishExam()`, when `start_at` is in the future | All eligible students |
| `exam_available` | `ExamSystemService::publishExam()`, when the exam is open immediately | All eligible students |
| `exam_assigned` | `ExamSystemService::replaceExamTargets()`, on an already-published/scheduled exam | Newly-eligible students only |
| `exam_result_published` | `ExamGradingService::publishResults()` | Students with a `graded` attempt |
| `exam_grade_changed` | `ExamGradingService::gradeManually()`, on a genuine override with results already visible | The affected student |
| `exam_starts_soon` | `php artisan exams:notify-upcoming` (scheduled sweep, next 24h window) | All eligible students |
| `exam_deadline_approaching` | `php artisan exams:notify-upcoming` (scheduled sweep, next 24h window) | All eligible students |

`exams:notify-upcoming` is registered but **not** auto-scheduled (matching the existing
`exams:auto-submit-expired` convention in this codebase) — add it to the real server
crontab or to `routes/console.php`'s `Schedule::command(...)` to activate it; see that
command's docblock for the exact line.

### Audit Trail (Phase 42)

Uses the existing `AuditLogService` — no new audit architecture. Newly-recorded actions
this round: `academic_staff.exam_system.grade_changed`, `...grade_overridden`,
`...ai_grade_accepted`, `...exam_regraded`, `...results_exported` (all previous Rounds
1/2/6/7 actions — bank/question/exam/pool/target/publish CRUD — were already audited).

"Exam Closed" and "Exam Archived" from Phase 42's suggested action list are not
implemented: the exam system has no close/archive action anywhere in the product (only
`draft` ⇄ `scheduled`/`published`, handled by publish/unpublish above) — nothing to
audit that doesn't exist as a real, callable operation.

### Export (Phase 43)

| Method | Path | Role | Description |
|---|---|---|---|
| GET | `exams/{id}/results/export?format=csv\|xlsx\|pdf\|json` | `academic_staff` | Per-student results export (Student, Exam, Score, Percentage, Status, Submission Time) for one owned exam. Defaults to `csv`. |

Formats match exactly what the rest of the project already supports (see
`DataExportService::ALLOWED_FORMATS` and `AdminAiCodeReviewExportController`) — no new
export formats were introduced. Every export call is audit-logged
(`academic_staff.exam_system.results_exported`).

## Attempt management (instructor) — cancel attempt / allow retake

All routes: `academic_staff` only, exam must belong to the instructor.

| Method | Path | Body | Notes |
|---|---|---|---|
| POST | `/exam-system/exams/{id}/attempts/{attemptId}/cancel` | `reason?`, `allow_retake?` (bool), `available_until?` (datetime) | Sets status `cancelled`. Kept in history and still counts as a used attempt unless `allow_retake=true` (grants +1). In-progress attempts are cut off immediately. |
| POST | `/exam-system/exams/{id}/students/{studentId}/retake` | `extra_attempts?` (1–10, default 1), `available_until?`, `reason?` | Adds attempts on top of `max_attempts` (cumulative). `available_until` opens a private window for that student even after the exam closed. |
| DELETE | `/exam-system/exams/{id}/students/{studentId}/retake` | — | Withdraws unused extra attempts only. |

Attempt rows in `GET /exams/{id}/attempts` now include `cancelled_at`, `cancel_reason`, `student_attempts_used`, `student_extra_attempts`, `student_available_until`.
Student `my-exams` payloads now include `extra_attempts`, `max_attempts_effective`, `retake_allowed`, `retake_available_until`.
Audit actions: `exam.attempt_cancelled`, `exam.retake_granted`, `exam.retake_revoked`. Student notifications: `exam_attempt_cancelled`, `exam_retake_granted`, `exam_retake_revoked`.
Run `php artisan migrate` (new migration `2026_10_05_200000_...`).
