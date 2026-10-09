<?php

namespace App\Services;

use App\Models\MessagePollVote;
use App\Repositories\ConversationRepository;
use App\Repositories\MessageRepository;
use App\Repositories\MessagingPermissionRepository;
use App\Repositories\SettingRepository;
use App\Repositories\StudentRepository;
use App\Repositories\UniversityRepository;
use App\Repositories\FacultyRepository;
use App\Repositories\AcademicStaffRepository;
use App\Repositories\SupervisorRepository;
use App\Repositories\SupervisorAssignmentRepository;
use App\Repositories\UserRepository;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * منقولة (Core\Database -> DB facade / Eloquent) من app/Services/
 * MessagingService.php القديمة — النظام المركزي الوحيد اللي كل بورتال
 * (Student/University/...) بيستخدمه
 * لأي حاجة خاصة بالرسايل. الكنترولر الموحّد Http\Controllers\Api\
 * MessagingController (/api/v1/messaging/*) هو المستهلك الوحيد هنا (زي
 * Common\MessagingController القديمة بالظبط — كنترولر واحد، مش واحد لكل
 * بورتال).
 *
 * Real-time note: مفيش WebSocket process متاح على الاستضافة الحالية —
 * التحديث اللحظي (رسايل جديدة/typing/online) شغال بـ short-interval
 * polling على poll()/presenceFor()/typingIn()، مش socket push. نفس قرار
 * القديم بالظبط (config('messaging.presence')).
 *
 * فجوات متعمدة موثقة (مش استخدام فعلي من الفرونت الحالي، أو أجزاء من
 * بنود لاحقة):
 *   - GIFs/Stickers: بتحتاج GiphyService/StickerCatalogService القديمة —
 *     مش منقولين هنا، ومفيش استدعاء ليهم من Messages.jsx الحالي.
 *   - تشفير المرفقات وقت الحفظ (AttachmentEncryptionService) — بند 25.
 *   - Admin Messaging Oversight — بند 26 (Admin).
 */
class MessagingService
{
    public function __construct(
        private ConversationRepository $conversations,
        private MessageRepository $messages,
        private UserRepository $users,
        private NotificationService $notifications,
        private FileUploadService $uploads,
        private StudentRepository $students,
        private MessagingPermissionRepository $permissions,
        private UniversityRepository $universities,
        private FacultyRepository $faculties,
        private AcademicStaffRepository $academicStaff,
        private SupervisorRepository $supervisors,
        private SupervisorAssignmentRepository $supervisorAssignments,
        private SettingRepository $settings,
        private RoleService $roles,
    ) {
    }

    // -- Inbox / search --------------------------------------------------

    /** @return array<int,array<string,mixed>> صفوف الـ inbox، بشكل الفرونت المتوقع */
    public function inboxForUser($userId): array
    {
        $rows = $this->conversations->inboxForUser($userId);

        return array_map(function ($row) {
            $others = $this->applyAvatarPrivacy($row['other_participants']);
            $displayName = $row['subject'] ?: ($row['is_group'] ? $row['group_name'] : null) ?: ($others[0]['full_name'] ?? '—');
            $peer = !$row['is_group'] ? ($others[0] ?? null) : null;

            return [
                'id'                 => (int) $row['id'],
                'subject'            => $displayName,
                'is_group'           => (bool) $row['is_group'],
                'participants'       => array_map(fn ($p) => $p['full_name'], $others),
                'participant_emails' => array_map(fn ($p) => $p['email'] ?? '', $others),
                'participant_orgs'   => array_map(fn ($p) => $p['org'] ?? '', $others),
                // الطرف التاني في المحادثة المباشرة (إيميل/صورة/نوع حساب) — الصورة null لو هو مخفيها.
                'peer_email'         => $peer['email'] ?? null,
                'peer_avatar'        => $peer['avatar_path'] ?? null,
                'peer_role'          => $peer['account_role'] ?? null,
                'preview'            => $row['last_message_deleted'] ? '(message deleted)' : ($row['last_message_body'] ? mb_substr($row['last_message_body'], 0, 120) : null),
                'time'               => $row['last_message_at'] ?: $row['updated_at'],
                'is_unread'          => (bool) $row['is_unread'],
                'is_favorite'        => (bool) $row['is_favorite'],
                'is_pinned'          => (bool) $row['is_pinned'],
                'is_muted'           => (bool) $row['is_muted'],
                'is_archived'        => (bool) $row['is_archived'],
                'category'           => $row['category'],
                'my_role'            => $row['my_role'],
            ];
        }, $rows);
    }

    /** بحث نصي على قايمة الـ inbox نفسها (subject/participant name/email/org). */
    public function searchInbox($userId, string $query): array
    {
        $conversations = $this->inboxForUser($userId);
        $needle = mb_strtolower(trim($query));
        if ($needle === '') {
            return $conversations;
        }

        return array_values(array_filter($conversations, function ($c) use ($needle) {
            if (str_contains(mb_strtolower($c['subject'] ?? ''), $needle)) {
                return true;
            }
            foreach ($c['participants'] as $name) {
                if (str_contains(mb_strtolower($name), $needle)) {
                    return true;
                }
            }
            foreach ($c['participant_emails'] as $email) {
                if ($email && str_contains(mb_strtolower($email), $needle)) {
                    return true;
                }
            }
            foreach ($c['participant_orgs'] as $org) {
                if ($org && str_contains(mb_strtolower($org), $needle)) {
                    return true;
                }
            }
            return false;
        }));
    }

    /** @return array<int,array<string,mixed>> نتائج دليل المستخدمين لبدء رسالة جديدة */
    public function searchRecipients(string $query, $excludeUserId): array
    {
        $query = trim($query);
        if (mb_strlen($query) < 2) {
            return [];
        }
        $rows = $this->users->searchDirectory($query, $excludeUserId);
        // الصورة تتشال من أي شخص مخفيها — الفرونت يعرض الحروف الأولى بدلها.
        foreach ($rows as &$r) {
            if ($this->isAvatarHidden((int) $r['id'], $excludeUserId)) {
                $r['avatar_path'] = null;
            }
        }
        unset($r);
        return $rows;
    }

    // -- Thread ------------------------------------------------------------

    /** @return array{conversation:array,others:array,messages:array}|null null لو المحادثة مش موجودة أو اليوزر مش عضو فيها */
    public function threadForUser(int $conversationId, $userId, ?int $beforeId = null): ?array
    {
        if (!$this->conversations->isParticipant($conversationId, $userId)) {
            return null;
        }
        $conversation = $this->conversations->find($conversationId);
        if (!$conversation) {
            return null;
        }

        $this->conversations->markRead($conversationId, $userId);
        if ($beforeId === null) {
            $this->notifications->markConversationNotificationsRead($userId, $conversationId);
        }

        $others = $this->applyAvatarPrivacy($this->conversations->otherParticipants($conversationId, $userId));
        $messages = array_map(
            fn ($m) => $this->shapeMessage($m, $userId),
            $this->messages->forConversation($conversationId, $userId, $beforeId)
        );

        return [
            'conversation' => [
                'id'       => (int) $conversation->id,
                'subject'  => $conversation->subject ?: ($conversation->is_group ? $conversation->group_name : null) ?: ($others[0]['full_name'] ?? '—'),
                'is_group' => (bool) $conversation->is_group,
                'my_role'  => $this->conversations->memberRole($conversationId, $userId),
            ],
            'others'   => $others,
            'messages' => $messages,
        ];
    }

    public function isParticipant(int $conversationId, $userId): bool
    {
        return $this->conversations->isParticipant($conversationId, $userId);
    }

    public function allParticipants(int $conversationId): array
    {
        return $this->conversations->allParticipants($conversationId);
    }

    // -- Sending -------------------------------------------------------------

    /** @param array{parent_message_id?:int,forwarded_from_id?:int,message_type?:string,metadata?:array,mentioned_user_ids?:array,attachments?:UploadedFile[]} $options */
    public function sendMessage(int $conversationId, $userId, string $body, array $options = []): array
    {
        $body = trim($body);
        $hasAttachments = !empty($options['attachments']);
        if ($body === '' && !$hasAttachments && ($options['message_type'] ?? 'text') === 'text') {
            throw new \RuntimeException('Message cannot be empty.');
        }
        if (!$this->conversations->isParticipant($conversationId, $userId)) {
            throw new \RuntimeException('Conversation not found.');
        }

        $maxLen = (int) config('messaging.max_message_length', 8000);
        if (mb_strlen($body) > $maxLen) {
            throw new \RuntimeException("Message is too long (max {$maxLen} characters).");
        }

        $this->assertNotRateLimited($userId);

        // One transaction: a concurrent poll() must never see the message row
        // before its attachments exist (it would be delivered as an empty bubble
        // and never re-fetched, because the client's since_id moves past it).
        $message = DB::transaction(function () use ($conversationId, $userId, $body, $options, $hasAttachments) {
            $message = $this->messages->create(
                $conversationId,
                $userId,
                $body,
                null,
                $options['parent_message_id'] ?? null,
                $options['forwarded_from_id'] ?? null,
                $options['message_type'] ?? 'text',
                $options['metadata'] ?? null
            );

            if ($body !== '') {
                $this->messages->recordHashtags((int) $message->id, $body);
            }

            foreach ($this->resolveMentions($conversationId, $options['mentioned_user_ids'] ?? []) as $mentionedId) {
                $this->messages->recordMention((int) $message->id, $mentionedId);
            }

            $maxAttachments = (int) config('messaging.max_attachments_per_message', 10);
            if ($hasAttachments) {
                if (count($options['attachments']) > $maxAttachments) {
                    throw new \RuntimeException("You can attach at most {$maxAttachments} files per message.");
                }
                foreach ($options['attachments'] as $file) {
                    $this->storeAttachment((int) $message->id, $userId, $conversationId, $file);
                }
            }

            return $message;
        });

        $this->conversations->touchUpdatedAt($conversationId);
        $this->conversations->markRead($conversationId, $userId);

        $sender = $this->users->findById($userId);
        foreach ($this->conversations->otherParticipants($conversationId, $userId) as $participant) {
            if ($this->isMuted($conversationId, $participant['id'])) {
                continue;
            }
            $this->notifications->notify(
                $participant['id'],
                'new_message',
                'New message from ' . ($sender->full_name ?? 'a user'),
                $body !== '' ? mb_substr($body, 0, 140) : '(attachment)',
                $this->messagesLinkFor($participant['id'], $conversationId)
            );
        }

        return $this->shapeMessage($this->messages->withRelations((int) $message->id), $userId);
    }

    /** يبدأ محادثة مباشرة (find-or-create) بالإيميل، من غير ما يبعت رسالة. */
    public function openOrStartDirectByEmail($userId, string $recipientEmail): int
    {
        $recipient = $this->users->findByEmail(trim($recipientEmail));
        if (!$recipient) {
            throw new \RuntimeException('No user was found with that email.');
        }
        if ((string) $recipient->id === (string) $userId) {
            throw new \RuntimeException('You cannot start a conversation with yourself.');
        }

        $existing = $this->conversations->findDirectBetween($userId, $recipient->id);
        if ($existing) {
            return (int) $existing->id;
        }

        $this->assertGroupMessagingAllowed($userId, $recipient->id);

        $conversation = $this->conversations->createWithParticipants(null, [$userId, $recipient->id]);
        return (int) $conversation->id;
    }

    /** POST /conversations/direct — يبدأ (أو يستخدم القائمة) محادثة مباشرة، وبيبعت أول رسالة لو body مش فاضي. */
    public function startConversation($userId, string $recipientEmail, string $body): int
    {
        $recipient = $this->users->findByEmail(trim($recipientEmail));
        if (!$recipient) {
            throw new \RuntimeException('No user was found with that email.');
        }
        if ((string) $recipient->id === (string) $userId) {
            throw new \RuntimeException('You cannot start a conversation with yourself.');
        }

        $existing = $this->conversations->findDirectBetween($userId, $recipient->id);
        if (!$existing) {
            $this->assertGroupMessagingAllowed($userId, $recipient->id);
        }
        $conversation = $existing ?? $this->conversations->createWithParticipants(null, [$userId, $recipient->id]);

        $body = trim($body);
        if ($body !== '') {
            $this->assertNotRateLimited($userId);
            $message = $this->messages->create((int) $conversation->id, $userId, $body);
            $this->messages->recordHashtags((int) $message->id, $body);
            $this->conversations->touchUpdatedAt((int) $conversation->id);
            $this->conversations->markRead((int) $conversation->id, $userId);

            $sender = $this->users->findById($userId);
            $this->notifications->notify(
                $recipient->id,
                'new_message',
                'New message from ' . ($sender->full_name ?? 'a user'),
                mb_substr($body, 0, 140),
                $this->messagesLinkFor($recipient->id, (int) $conversation->id)
            );
        }

        return (int) $conversation->id;
    }

    /**
     * Student Portal spec section 2: طالب بيقدر يراسل بس أعضاء نفس الجروب
     * بتاعه إلا لو فيه صلاحية إضافية اتوافق عليها، أو المؤسسة/الكلية/
     * المشرف بتوعه. مقيّدة على إنشاء محادثة *جديدة* بس. أي مرسل مش طالب
     * (staff/supervisor/university/admin/...)
     * مقيّدش خالص.
     * @throws \RuntimeException لو ممنوع
     */
    private function assertGroupMessagingAllowed($userId, $recipientId): void
    {
        $senderStudent = $this->students->findByUserId($userId);
        if (!$senderStudent) {
            return;
        }

        $recipientStudent = $this->students->findByUserId($recipientId);
        if ($recipientStudent) {
            $this->assertStudentToStudentAllowed($userId, $recipientId, $senderStudent, $recipientStudent);
            return;
        }

        $this->assertStudentToInstitutionAllowed($recipientId, $senderStudent);
    }

    private function assertStudentToStudentAllowed($userId, $recipientId, $senderStudent, $recipientStudent): void
    {
        if ($senderStudent->group_id && (string) $senderStudent->group_id === (string) $recipientStudent->group_id) {
            return;
        }
        if ($this->permissions->hasApprovedGrant($userId, $recipientId)) {
            return;
        }
        throw new \RuntimeException(
            'You can only message members of your own project group. ' .
            'You can request permission to message this student from Messages > Permission Requests.'
        );
    }

    private function assertStudentToInstitutionAllowed($recipientId, $senderStudent): void
    {
        $university = $this->universities->findByUserId($recipientId);
        if ($university) {
            if ((string) $university->id === (string) $senderStudent->university_id) {
                return;
            }
            throw new \RuntimeException('You can only message your own university.');
        }

        $faculty = $this->faculties->findByUserId($recipientId);
        if ($faculty) {
            if ($senderStudent->faculty_id && (string) $faculty->id === (string) $senderStudent->faculty_id) {
                return;
            }
            throw new \RuntimeException('You can only message your own faculty.');
        }

        $staff = $this->academicStaff->findByUserId($recipientId);
        if ($staff) {
            if (
                ($staff->department_id && $senderStudent->department_id && (string) $staff->department_id === (string) $senderStudent->department_id)
                || ($staff->faculty_id && $senderStudent->faculty_id && (string) $staff->faculty_id === (string) $senderStudent->faculty_id)
                || ($staff->university_id && (string) $staff->university_id === (string) $senderStudent->university_id && !$staff->department_id && !$staff->faculty_id)
            ) {
                return;
            }
            throw new \RuntimeException('You can only message academic staff in your own department, faculty, or university.');
        }

        $supervisor = $this->supervisors->findByUserId($recipientId);
        if ($supervisor) {
            if ($this->supervisorAssignments->coversStudent($supervisor->id, $senderStudent->user_id)) {
                return;
            }
            throw new \RuntimeException('You can only message a supervisor assigned to you.');
        }

        // أي نوع حساب تاني (admin/security/data_analyst/...) من غير قيد.
    }

    /** رابط ثريد الرسايل مضبوط على بورتال المستلم نفسه (كل بورتال ليه /messages بتاعه). */
    private function messagesLinkFor($recipientId, int $conversationId): string
    {
        $role = $this->roles->primaryRoleFor((int) $recipientId);
        // البريفيكس لازم يطابق الراوتات في الفرونت (security / data-analysis ...)
        // مش اسم الدور (security_admin / data_analyst)، فنأخذه من home_route.
        $home = (string) config("roles.home_route.{$role}", '');
        $prefix = $home !== ''
            ? explode('/', trim($home, '/'))[0]
            : str_replace('_', '-', $role);
        return "/{$prefix}/messages/{$conversationId}";
    }

    // -- Shaping -------------------------------------------------------------

    private function shapeMessage(array $m, $viewerId): array
    {
        return [
            'id'                 => (int) $m['id'],
            'conversation_id'    => (int) $m['conversation_id'],
            'body'               => $m['is_deleted'] ? null : $m['body'],
            'message_type'       => $m['message_type'] ?? 'text',
            'metadata'           => isset($m['metadata']) && $m['metadata'] ? json_decode($m['metadata'], true) : null,
            'sender_id'          => $m['sender_id'],
            'sender_name'        => $m['sender_name'],
            'sender_avatar'      => $this->isAvatarHidden((int) $m['sender_id'], $viewerId) ? null : ($m['sender_avatar'] ?? null),
            'is_mine'            => (string) $m['sender_id'] === (string) $viewerId,
            'is_edited'          => (bool) ($m['is_edited'] ?? false),
            'is_pinned'          => (bool) ($m['is_pinned'] ?? false),
            'is_deleted'         => (bool) ($m['is_deleted'] ?? false),
            'created_at'         => $m['created_at'],
            'edited_at'          => $m['edited_at'] ?? null,
            'parent_message_id'  => $m['parent_message_id'] ?? null,
            'parent_body'        => $m['parent_body'] ?? null,
            'parent_sender_name' => $m['parent_sender_name'] ?? null,
            'forwarded_from_id'  => $m['forwarded_from_id'] ?? null,
            'attachments'        => array_map(fn ($a) => $this->shapeAttachment($a), $m['attachments'] ?? []),
            'reactions'          => $m['reactions'] ?? [],
            'mentions'           => $m['mentions'] ?? [],
        ];
    }

    private function shapeAttachment(array $a): array
    {
        return [
            'id'               => (int) $a['id'],
            'kind'             => $a['kind'],
            'url'              => '/uploads/' . ltrim(str_replace('uploads/', '', $a['stored_path']), '/'),
            'thumbnail_url'    => $a['thumbnail_path'] ? '/uploads/' . ltrim(str_replace('uploads/', '', $a['thumbnail_path']), '/') : null,
            'original_name'    => $a['original_name'],
            'mime_type'        => $a['mime_type'],
            'extension'        => $a['extension'],
            'size_bytes'       => (int) $a['size_bytes'],
            'width'            => $a['width'] !== null ? (int) $a['width'] : null,
            'height'           => $a['height'] !== null ? (int) $a['height'] : null,
            'duration_seconds' => $a['duration_seconds'] !== null ? (int) $a['duration_seconds'] : null,
            'waveform'         => $a['waveform_json'] ?? null,
        ];
    }

    private function classifyKind(string $extension): string
    {
        $extension = strtolower($extension);
        $map = [
            'image'    => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'tiff', 'svg'],
            'video'    => ['mp4', 'webm', 'mov', 'avi', 'mkv'],
            'audio'    => ['mp3', 'wav', 'ogg', 'm4a'],
            'archive'  => ['zip', 'rar', '7z'],
            'code'     => ['php', 'js', 'ts', 'py', 'java', 'c', 'cpp', 'cs', 'go', 'rb', 'html', 'css', 'sql', 'sh', 'yml', 'yaml'],
            'document' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'csv', 'json', 'xml', 'txt', 'md', 'log'],
        ];
        foreach ($map as $kind => $extensions) {
            if (in_array($extension, $extensions, true)) {
                return $kind;
            }
        }
        return 'file';
    }

    /** بيخزّن ملف واحد مرفوع (UploadedFile) عبر FileUploadService (فئة 'messages')، وبيسجّله كصف message_attachments. */
    private function storeAttachment(int $messageId, $uploaderId, int $conversationId, UploadedFile $file): void
    {
        $stored = $this->uploads->store($file, 'messages', 'conv_' . $conversationId);
        $kind = $this->classifyKind($stored['extension']);
        $absolutePath = public_path($stored['stored_path']);

        [$width, $height] = $kind === 'image' ? $this->imageDimensions($absolutePath) : [null, null];

        DB::table('message_attachments')->insert([
            'message_id'       => $messageId,
            'uploader_id'      => $uploaderId,
            'kind'             => $kind,
            'stored_path'      => $stored['stored_path'],
            'thumbnail_path'   => null,
            'original_name'    => $stored['original_name'],
            'mime_type'        => $stored['mime_type'],
            'extension'        => $stored['extension'],
            'size_bytes'       => $stored['size_bytes'],
            'width'            => $width,
            'height'           => $height,
            'duration_seconds' => null,
            'created_at'       => now(),
        ]);
    }

    private function imageDimensions(string $absolutePath): array
    {
        if (!is_file($absolutePath)) {
            return [null, null];
        }
        $size = @getimagesize($absolutePath);
        return $size ? [$size[0], $size[1]] : [null, null];
    }

    /** Rate Limiting / Spam Protection — بيرمي لو اليوزر بعت رسايل كتير أوي في النافذة الزمنية المضبوطة. */
    private function assertNotRateLimited($userId): void
    {
        $cfg = config('messaging.rate_limit', ['max_messages' => 30, 'window_seconds' => 60]);
        $since = date('Y-m-d H:i:s', time() - (int) $cfg['window_seconds']);
        if ($this->messages->countSentSince($userId, $since) >= (int) $cfg['max_messages']) {
            throw new \RuntimeException('You are sending messages too fast. Please wait a moment and try again.');
        }
    }

    /** @param array<int,int|string> $explicitMentionIds ids جاهزة من الـ tag-picker — بتتأكد إنهم فعلاً أعضاء في المحادثة بس */
    private function resolveMentions(int $conversationId, array $explicitMentionIds): array
    {
        if (!$explicitMentionIds) {
            return [];
        }
        $participantIds = array_column($this->allParticipants($conversationId), 'id');
        return array_values(array_intersect(array_map('strval', $explicitMentionIds), array_map('strval', $participantIds)));
    }

    private function isMuted(int $conversationId, $userId): bool
    {
        return $this->conversations->isMuted($conversationId, $userId);
    }

    // -- Message management (edit / delete / restore / undo / pin) ---------

    public function editMessage(int $messageId, $userId, string $newBody): array
    {
        $message = $this->messages->find($messageId);
        if (!$message || (string) $message->sender_id !== (string) $userId) {
            throw new \RuntimeException('Message not found.');
        }
        $newBody = trim($newBody);
        if ($newBody === '') {
            throw new \RuntimeException('Message cannot be empty.');
        }
        $editWindow = (int) config('messaging.edit_window_minutes', 0);
        if ($editWindow > 0 && strtotime($message->created_at) < time() - $editWindow * 60) {
            throw new \RuntimeException('This message can no longer be edited.');
        }

        $this->messages->edit($messageId, $newBody);
        $this->messages->recordHashtags($messageId, $newBody);

        return $this->shapeMessage($this->messages->withRelations($messageId), $userId);
    }

    /** "Delete For Me" — إخفاء لليوزر ده بس. */
    public function deleteForMe(int $messageId, $userId): void
    {
        $message = $this->messages->find($messageId);
        if (!$message || !$this->conversations->isParticipant((int) $message->conversation_id, $userId)) {
            throw new \RuntimeException('Message not found.');
        }
        $this->messages->hideForUser($messageId, $userId);
    }

    /** "Delete For Everyone (within configurable time window)". */
    public function deleteForEveryone(int $messageId, $userId): void
    {
        $message = $this->messages->find($messageId);
        if (!$message) {
            throw new \RuntimeException('Message not found.');
        }
        $isSender = (string) $message->sender_id === (string) $userId;
        $isConvoAdmin = in_array($this->conversations->memberRole((int) $message->conversation_id, $userId), ['owner', 'admin'], true);
        if (!$isSender && !$isConvoAdmin) {
            throw new \RuntimeException('You cannot delete this message.');
        }

        $window = (int) config('messaging.delete_for_everyone_window_minutes', 15);
        if ($isSender && !$isConvoAdmin && $window > 0 && strtotime($message->created_at) < time() - $window * 60) {
            throw new \RuntimeException('This message can no longer be deleted for everyone.');
        }

        $this->messages->deleteForEveryone($messageId, $userId);
    }

    /** "Restore Deleted Message" — صاحب الرسالة أو owner/admin بس. */
    public function restoreMessage(int $messageId, $userId): void
    {
        $message = $this->messages->find($messageId);
        if (!$message) {
            throw new \RuntimeException('Message not found.');
        }
        $isSender = (string) $message->sender_id === (string) $userId;
        $isConvoAdmin = in_array($this->conversations->memberRole((int) $message->conversation_id, $userId), ['owner', 'admin'], true);
        if (!$isSender && !$isConvoAdmin) {
            throw new \RuntimeException('You cannot restore this message.');
        }
        $this->messages->restore($messageId);
    }

    /** "Undo Send" — صاحب الرسالة بس، نافذة زمنية قصيرة، hard delete حقيقي (مش قابل للاسترجاع). */
    public function undoSend(int $messageId, $userId): void
    {
        $message = $this->messages->withRelations($messageId);
        if (!$message) {
            throw new \RuntimeException('Message not found.');
        }
        if ((string) $message['sender_id'] !== (string) $userId) {
            throw new \RuntimeException('You can only undo your own messages.');
        }
        if ((int) ($message['is_deleted'] ?? 0) === 1) {
            throw new \RuntimeException('This message was already deleted.');
        }

        $window = (int) config('messaging.undo_send_window_seconds', 10);
        if ($window > 0 && strtotime($message['created_at']) < time() - $window) {
            throw new \RuntimeException('This message can no longer be undone.');
        }

        foreach ($message['attachments'] ?? [] as $attachment) {
            if (!empty($attachment['stored_path'])) {
                $this->uploads->delete($attachment['stored_path']);
            }
            if (!empty($attachment['thumbnail_path'])) {
                $this->uploads->delete($attachment['thumbnail_path']);
            }
        }

        $this->messages->hardDelete($messageId);
    }

    public function history(int $messageId, $userId): array
    {
        $message = $this->messages->find($messageId);
        if (!$message || !$this->conversations->isParticipant((int) $message->conversation_id, $userId)) {
            throw new \RuntimeException('Message not found.');
        }
        return array_map(fn ($v) => ['body' => $v->body, 'edited_at' => $v->edited_at], $this->messages->history($messageId));
    }

    public function setPinned(int $messageId, $userId, bool $pinned): void
    {
        $message = $this->messages->find($messageId);
        if (!$message || !$this->conversations->isParticipant((int) $message->conversation_id, $userId)) {
            throw new \RuntimeException('Message not found.');
        }
        $this->messages->setPinned($messageId, $pinned);
    }

    public function pinnedMessages(int $conversationId, $userId): array
    {
        if (!$this->conversations->isParticipant($conversationId, $userId)) {
            throw new \RuntimeException('Conversation not found.');
        }
        return array_map(fn ($m) => $this->shapeMessage($m, $userId), $this->messages->pinnedForConversation($conversationId));
    }

    // -- Reactions -----------------------------------------------------------

    public function toggleReaction(int $messageId, $userId, string $emoji): array
    {
        $message = $this->messages->find($messageId);
        if (!$message || !$this->conversations->isParticipant((int) $message->conversation_id, $userId)) {
            throw new \RuntimeException('Message not found.');
        }

        $existing = DB::table('message_reactions')
            ->where('message_id', $messageId)->where('user_id', $userId)->where('emoji', $emoji)
            ->first();

        if ($existing) {
            DB::table('message_reactions')->where('id', $existing->id)->delete();
        } else {
            DB::table('message_reactions')->insert([
                'message_id' => $messageId, 'user_id' => $userId, 'emoji' => $emoji, 'created_at' => now(),
            ]);
        }

        $shaped = $this->messages->withRelations($messageId);
        return $shaped['reactions'] ?? [];
    }

    // -- Search / mentions -----------------------------------------------

    public function searchMessages($userId, string $query, ?int $conversationId = null): array
    {
        if ($conversationId !== null && !$this->conversations->isParticipant($conversationId, $userId)) {
            throw new \RuntimeException('Conversation not found.');
        }
        return array_map(fn ($m) => $this->shapeMessage($m, $userId), $this->messages->search($userId, $query, $conversationId));
    }

    public function mentionsOfMe($userId): array
    {
        return array_map(fn ($m) => $this->shapeMessage($m, $userId), $this->messages->mentionsOf($userId));
    }

    // -- Group / team conversations ----------------------------------------

    public function createGroupConversation($creatorId, string $groupName, array $memberIds): array
    {
        $groupName = trim($groupName);
        if ($groupName === '') {
            throw new \RuntimeException('Group name is required.');
        }
        $conversation = $this->conversations->createGroup($groupName, $creatorId, $memberIds);
        return $this->threadForUser((int) $conversation->id, $creatorId) ?? [];
    }

    private function assertConversationAdmin(int $conversationId, $actingUserId): void
    {
        if (!in_array($this->conversations->memberRole($conversationId, $actingUserId), ['owner', 'admin'], true)) {
            throw new \RuntimeException('Only conversation owners/admins can do that.');
        }
    }

    public function addMember(int $conversationId, $actingUserId, $newUserId): void
    {
        $this->assertConversationAdmin($conversationId, $actingUserId);
        $this->conversations->addMember($conversationId, $newUserId);
    }

    public function removeMember(int $conversationId, $actingUserId, $targetUserId): void
    {
        if ((string) $actingUserId !== (string) $targetUserId) {
            $this->assertConversationAdmin($conversationId, $actingUserId);
        }
        $this->conversations->removeMember($conversationId, $targetUserId);
    }

    public function setMemberRole(int $conversationId, $actingUserId, $targetUserId, string $role): void
    {
        if (!in_array($role, ['owner', 'admin', 'member'], true)) {
            throw new \RuntimeException('Invalid role.');
        }
        $this->assertConversationAdmin($conversationId, $actingUserId);
        $this->conversations->setMemberRole($conversationId, $targetUserId, $role);
    }

    public function renameGroup(int $conversationId, $actingUserId, string $groupName): void
    {
        $this->assertConversationAdmin($conversationId, $actingUserId);
        $this->conversations->renameGroup($conversationId, trim($groupName));
    }

    // -- Per-member conversation state (favorite/pin/mute/archive/category) --

    public function toggleMemberFlag(int $conversationId, $userId, string $flag, bool $value): void
    {
        if (!$this->conversations->isParticipant($conversationId, $userId)) {
            throw new \RuntimeException('Conversation not found.');
        }
        $this->conversations->setMemberFlag($conversationId, $userId, $flag, $value);
    }

    public function setCategory(int $conversationId, $userId, ?string $category): void
    {
        if (!$this->conversations->isParticipant($conversationId, $userId)) {
            throw new \RuntimeException('Conversation not found.');
        }
        $this->conversations->setMemberCategory($conversationId, $userId, $category !== '' ? $category : null);
    }

    public function categoriesForUser($userId): array
    {
        return $this->conversations->categoriesForUser($userId);
    }

    // -- Read receipts / typing / presence (polling-based) -----------------

    public function readReceiptsEnabled($userId): bool
    {
        return $this->settings->get('messaging_read_receipts_enabled', 'user', $userId, '1') === '1';
    }

    // -- Chat privacy (متاح لكل الأدوار، مش للطالب بس) -------------------------

    /** @var array<int,bool> كاش per-request: user_id => صورته مخفية؟ */
    private array $avatarHiddenCache = [];

    /** هل المستخدم ده مخفي صورته في الشات؟ (صاحب الصورة بيشوف صورته دايمًا). */
    public function isAvatarHidden(int $ownerId, $viewerId = null): bool
    {
        if ($viewerId !== null && (string) $ownerId === (string) $viewerId) {
            return false;
        }
        if (!array_key_exists($ownerId, $this->avatarHiddenCache)) {
            // مخفية لو قفلها من إعداد الشات، أو من إعداد "صورتي للعامة" (كل البوابات).
            $this->avatarHiddenCache[$ownerId] =
                $this->settings->get('messaging_show_avatar', 'user', $ownerId, '1') === '0'
                || $this->settings->get('profile_photo_public', 'user', $ownerId, '1') === '0';
        }
        return $this->avatarHiddenCache[$ownerId];
    }

    /**
     * يشيل avatar_path من أي مشارك مخفي صورته — الفرونت بيرجع للحروف الأولى
     * تلقائيًا لما avatar_path يبقى null.
     *
     * @param array<int,array<string,mixed>> $participants
     * @return array<int,array<string,mixed>>
     */
    private function applyAvatarPrivacy(array $participants): array
    {
        foreach ($participants as &$p) {
            if (isset($p['id']) && $this->isAvatarHidden((int) $p['id'])) {
                $p['avatar_path'] = null;
            }
        }
        unset($p);
        return $participants;
    }

    /** @return array{read_receipts_enabled:bool,show_avatar:bool} */
    public function chatPrivacyFor($userId): array
    {
        return [
            'read_receipts_enabled' => $this->readReceiptsEnabled($userId),
            'show_avatar'           => $this->settings->get('messaging_show_avatar', 'user', $userId, '1') === '1',
        ];
    }

    /**
     * @param array{read_receipts_enabled?:mixed,show_avatar?:mixed} $input أي مفتاح مش مبعوت بيفضل زي ما هو
     * @return array{read_receipts_enabled:bool,show_avatar:bool}
     */
    public function updateChatPrivacy($userId, array $input): array
    {
        if (array_key_exists('read_receipts_enabled', $input)) {
            $this->settings->set('messaging_read_receipts_enabled', filter_var($input['read_receipts_enabled'], FILTER_VALIDATE_BOOLEAN) ? '1' : '0', 'user', $userId);
        }
        if (array_key_exists('show_avatar', $input)) {
            $this->settings->set('messaging_show_avatar', filter_var($input['show_avatar'], FILTER_VALIDATE_BOOLEAN) ? '1' : '0', 'user', $userId);
            unset($this->avatarHiddenCache[(int) $userId]);
        }
        return $this->chatPrivacyFor($userId);
    }

    public function markConversationRead(int $conversationId, $userId): void
    {
        if (!$this->conversations->isParticipant($conversationId, $userId)) {
            throw new \RuntimeException('Conversation not found.');
        }
        $this->markMessagesRead($conversationId, $userId);
    }

    /** بتحدد كل الرسايل اللي لسه معلّمة unread من مرسلين تانيين في المحادثة كـ delivered+read لليوزر ده. */
    private function markMessagesRead(int $conversationId, $userId): void
    {
        $now = now();
        $readAt = $this->readReceiptsEnabled($userId) ? $now : null;

        $messageIds = DB::table('messages')->where('conversation_id', $conversationId)->where('sender_id', '!=', $userId)->pluck('id');
        foreach ($messageIds as $mid) {
            $existing = DB::table('message_read_receipts')->where('message_id', $mid)->where('user_id', $userId)->first();
            DB::table('message_read_receipts')->updateOrInsert(
                ['message_id' => $mid, 'user_id' => $userId],
                ['delivered_at' => $existing->delivered_at ?? $now, 'read_at' => $readAt]
            );
        }

        $this->conversations->markRead($conversationId, $userId);
        $this->notifications->markConversationNotificationsRead($userId, $conversationId);
    }

    /** حالة القراءة/التسليم لكل مستلم لرسالة واحدة (Sent/Delivered/Read). */
    public function readReceipts(int $messageId, $userId): array
    {
        $message = $this->messages->find($messageId);
        if (!$message || !$this->conversations->isParticipant((int) $message->conversation_id, $userId)) {
            throw new \RuntimeException('Message not found.');
        }
        return DB::table('message_read_receipts as rr')
            ->join('users as u', 'u.id', '=', 'rr.user_id')
            ->where('rr.message_id', $messageId)
            ->whereNotNull('rr.read_at')
            ->select('rr.user_id', 'u.full_name', 'rr.delivered_at', 'rr.read_at')
            ->get()
            ->all();
    }

    public function heartbeat($userId): void
    {
        DB::table('user_presence')->updateOrInsert(
            ['user_id' => $userId],
            ['last_seen_at' => now()]
        );
    }

    /** بيضبط/يمسح "بيكتب في المحادثة X" — مرّر $conversationId = null عشان تمسح. */
    public function setTyping($userId, ?int $conversationId): void
    {
        DB::table('user_presence')->updateOrInsert(
            ['user_id' => $userId],
            [
                'last_seen_at'      => now(),
                'is_typing_in'      => $conversationId,
                'typing_started_at' => $conversationId ? now() : null,
            ]
        );
    }

    /** مين (غير $viewerId) بيكتب دلوقتي في $conversationId، جوّه TTL الكتابة المضبوط. */
    public function typingIn(int $conversationId, $viewerId): array
    {
        $ttl = (int) config('messaging.presence.typing_ttl_s', 6);
        return DB::table('user_presence as up')
            ->join('users as u', 'u.id', '=', 'up.user_id')
            ->where('up.is_typing_in', $conversationId)
            ->where('up.user_id', '!=', $viewerId)
            ->where('up.typing_started_at', '>=', now()->subSeconds($ttl))
            ->select('up.user_id', 'u.full_name')
            ->get()
            ->all();
    }

    /** حالة أونلاين/آخر ظهور لمجموعة من الـ user ids. */
    public function presenceFor(array $userIds): array
    {
        if (!$userIds) {
            return [];
        }
        $threshold = (int) config('messaging.presence.online_threshold_s', 30);
        $rows = DB::table('user_presence')->whereIn('user_id', $userIds)->get(['user_id', 'last_seen_at']);

        $result = [];
        foreach ($rows as $row) {
            $result[$row->user_id] = [
                'last_seen_at' => $row->last_seen_at,
                'is_online'    => $row->last_seen_at && strtotime($row->last_seen_at) >= time() - $threshold,
            ];
        }
        return $result;
    }

    /** "إيه اللي اتغير من آخر poll" — رسايل جديدة في $conversationId بعد $sinceMessageId + مين بيكتب دلوقتي. */
    public function poll(int $conversationId, $userId, int $sinceMessageId = 0): array
    {
        if (!$this->conversations->isParticipant($conversationId, $userId)) {
            throw new \RuntimeException('Conversation not found.');
        }
        $rows = DB::select(
            "SELECT m.*, u.full_name AS sender_name, u.avatar_path AS sender_avatar,
                    p.body AS parent_body, pu.full_name AS parent_sender_name
             FROM messages m
             INNER JOIN users u ON u.id = m.sender_id
             LEFT JOIN messages p ON p.id = m.parent_message_id
             LEFT JOIN users pu ON pu.id = p.sender_id
             WHERE m.conversation_id = ? AND m.id > ?
               AND m.id NOT IN (SELECT message_id FROM message_hidden_for_user WHERE user_id = ?)
             ORDER BY m.id ASC",
            [$conversationId, $sinceMessageId, $userId]
        );
        $rows = array_map(fn ($r) => (array) $r, $rows);
        // attachments/reactions/mentions — poll used to skip this, so any message
        // with a file/video arrived as an empty bubble until the page was reloaded.
        $rows = $this->messages->attachRelations($rows);

        return [
            'messages' => array_map(fn ($m) => $this->shapeMessage($m, $userId), $rows),
            'typing'   => $this->typingIn($conversationId, $userId),
        ];
    }

    // -- Polls -----------------------------------------------------------

    /** @param array<int,string> $options 2+ خيار */
    public function createPoll(int $conversationId, $userId, string $question, array $options): array
    {
        $options = array_values(array_filter(array_map('trim', $options), fn ($o) => $o !== ''));
        if (trim($question) === '' || count($options) < 2) {
            throw new \RuntimeException('A poll needs a question and at least two options.');
        }
        return $this->sendMessage($conversationId, $userId, '', [
            'message_type' => 'poll',
            'metadata'     => ['question' => trim($question), 'options' => $options],
        ]);
    }

    public function votePoll(int $messageId, $userId, int $optionIndex): array
    {
        $message = $this->messages->find($messageId);
        if (!$message || $message->message_type !== 'poll' || !$this->conversations->isParticipant((int) $message->conversation_id, $userId)) {
            throw new \RuntimeException('Poll not found.');
        }
        $meta = $message->metadata ? json_decode($message->metadata, true) : ['options' => []];
        if (!isset($meta['options'][$optionIndex])) {
            throw new \RuntimeException('Invalid poll option.');
        }

        DB::transaction(function () use ($messageId, $userId, $optionIndex) {
            DB::table('message_poll_votes')->where('message_id', $messageId)->where('user_id', $userId)->delete();
            MessagePollVote::create(['message_id' => $messageId, 'user_id' => $userId, 'option_index' => $optionIndex]);
        });

        return $this->pollResults($messageId);
    }

    public function pollResults(int $messageId): array
    {
        $rows = DB::table('message_poll_votes')->where('message_id', $messageId)
            ->select('option_index', DB::raw('COUNT(*) as votes'))->groupBy('option_index')->get();
        $results = [];
        foreach ($rows as $row) {
            $results[(int) $row->option_index] = (int) $row->votes;
        }
        return $results;
    }

    // -- Attachments -----------------------------------------------------

    /** Secure File Access — المرفق يتحمّل بس لو اليوزر عضو في محادثة الرسالة اللي المرفق ده تابعلها. */
    public function attachmentAuthorized(int $attachmentId, $userId): ?array
    {
        $row = DB::table('message_attachments as a')
            ->join('messages as m', 'm.id', '=', 'a.message_id')
            ->where('a.id', $attachmentId)
            ->select('a.*', 'm.conversation_id')
            ->first();

        if (!$row || !$this->conversations->isParticipant((int) $row->conversation_id, $userId)) {
            return null;
        }
        return (array) $row;
    }

    /** حذف مرفق — صاحب الرسالة أو owner/admin بس. */
    public function deleteAttachment(int $attachmentId, $userId): void
    {
        $row = $this->attachmentAuthorized($attachmentId, $userId);
        if (!$row) {
            throw new \RuntimeException('Attachment not found.');
        }
        $message = $this->messages->find((int) $row['message_id']);
        $isSender = $message && (string) $message->sender_id === (string) $userId;
        $isConvoAdmin = in_array($this->conversations->memberRole((int) $row['conversation_id'], $userId), ['owner', 'admin'], true);
        if (!$isSender && !$isConvoAdmin) {
            throw new \RuntimeException('You cannot delete this attachment.');
        }

        if (!empty($row['stored_path'])) {
            $this->uploads->delete($row['stored_path']);
        }
        if (!empty($row['thumbnail_path'])) {
            $this->uploads->delete($row['thumbnail_path']);
        }
        DB::table('message_attachments')->where('id', $attachmentId)->delete();
    }

    // =====================================================================
    // -- Admin Messaging Oversight (بند 25 batch 5) ------------------------
    // =====================================================================
    // كل ميثود تحت دي platform-admin-only (الـ caller — AdminMessagingOversightApiController
    // + middleware uip.admin — هو اللي بيتأكد من ده) وبتتخطى عمدًا فحوصات
    // العضوية/الملكية اللي النظائر فوق بتفرضها: الأدمن يقدر يشوف ويشرف على
    // أي محادثة في المنصة، مش بس اللي هو عضو فيها. كل تعديل بيتسجل audit
    // من الكنترولر (AuditLogService) زي القديمة بالظبط.

    /** @return array{items:array,total:int,page:int,per_page:int} لشاشة Messaging Oversight، عبر كل البورتالات. */
    public function adminSearchConversations(array $filters = []): array
    {
        return $this->conversations->adminSearch($filters);
    }

    /** عرض ثريد قراءة-فقط لشاشة الإشراف — مفيش فحص عضوية، ومفيش تأثير markRead. */
    public function adminThread(int $conversationId): ?array
    {
        $found = $this->conversations->adminFind($conversationId);
        if (!$found) {
            return null;
        }

        $messages = array_map(
            fn ($m) => $this->shapeMessage($m, null),
            $this->messages->forConversationAdmin($conversationId)
        );

        return [
            'conversation' => $found['conversation'],
            'participants' => $found['participants'],
            'messages'     => $messages,
        ];
    }

    /**
     * حذف إجباري لرسالة للجميع — نسخة الأدمن من deleteForEveryone() من
     * غير فحص مرسل/عضو-إداري ومن غير نافذة زمنية، لإزالة محتوى لقاه
     * الأدمن على Messaging Oversight. نفس سلوك soft-delete/tombstone
     * بتاع النسخة اللي لليوزر (قابل للاسترجاع عبر restoreMessage() من
     * أي عضو، زي أي deleteForEveryone() تانية).
     * @throws \RuntimeException لو الرسالة مش موجودة
     */
    public function adminDeleteMessage(int $messageId, $adminUserId): int
    {
        $message = $this->messages->find($messageId);
        if (!$message) {
            throw new \RuntimeException('Message not found.');
        }
        $conversationId = (int) $message->conversation_id;

        $this->messages->deleteForEveryone($messageId, $adminUserId);

        return $conversationId;
    }

    /**
     * حذف إجباري لمرفق — نسخة الأدمن من deleteAttachment() من غير فحص
     * رافع/عضو-إداري، لإزالة ملف غير لائق لقاه الأدمن على Messaging
     * Oversight. حذف فعلي (hard delete)، زي النسخة اللي لليوزر.
     * @throws \RuntimeException لو المرفق مش موجود
     */
    public function adminDeleteAttachment(int $attachmentId, $adminUserId): array
    {
        $row = DB::table('message_attachments as a')
            ->join('messages as m', 'm.id', '=', 'a.message_id')
            ->where('a.id', $attachmentId)
            ->select('a.*', 'm.conversation_id')
            ->first();
        if (!$row) {
            throw new \RuntimeException('Attachment not found.');
        }
        $row = (array) $row;

        if (!empty($row['stored_path'])) {
            $this->uploads->delete($row['stored_path']);
        }
        if (!empty($row['thumbnail_path'])) {
            $this->uploads->delete($row['thumbnail_path']);
        }

        DB::table('message_attachments')->where('id', $attachmentId)->delete();

        return [
            'conversation_id' => (int) $row['conversation_id'],
            'message_id'      => (int) $row['message_id'],
            'attachment_id'   => $attachmentId,
        ];
    }

    /** @return array<string,mixed> مشكّلة لداشبورد Messaging Analytics */
    public function adminAnalytics(): array
    {
        return $this->conversations->adminAnalyticsSummary();
    }
}
