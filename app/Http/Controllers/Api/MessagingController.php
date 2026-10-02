<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MessagingService;
use Illuminate\Http\Request;

/**
 * /api/v1/messaging/* — الكنترولر الموحّد لكل بورتال (زي Common\
 * MessagingController القديمة بالظبط: كنترولر واحد، الـ RBAC/قيود
 * المراسلة نفسها متحكم فيها جوّه MessagingService مش هنا). بند 18.
 *
 * المستخدم الفاعل دايمًا من uip_user_id (uip.auth middleware)، نفس
 * اتفاقية NotificationsApiController.
 */
class MessagingController extends Controller
{
    public function __construct(private MessagingService $messaging)
    {
    }

    private function uid(Request $request): int
    {
        return (int) $request->attributes->get('uip_user_id');
    }

    /** GET /api/v1/messaging/inbox — query: q (اختياري، بحث) */
    public function inbox(Request $request)
    {
        $q = trim((string) $request->input('q', ''));
        $rows = $q !== '' ? $this->messaging->searchInbox($this->uid($request), $q) : $this->messaging->inboxForUser($this->uid($request));
        return $this->apiSuccess($rows, 'Inbox retrieved successfully.');
    }

    /** GET /api/v1/messaging/recipients — query: q */
    public function recipients(Request $request)
    {
        $rows = $this->messaging->searchRecipients((string) $request->input('q', ''), $this->uid($request));
        return $this->apiSuccess($rows, 'Recipients retrieved successfully.');
    }

    /** GET /api/v1/messaging/search — query: q, conversation_id (اختياري) */
    public function search(Request $request)
    {
        return $this->handle(function () use ($request) {
            $conversationId = $request->filled('conversation_id') ? (int) $request->input('conversation_id') : null;
            $rows = $this->messaging->searchMessages($this->uid($request), (string) $request->input('q', ''), $conversationId);
            return $this->apiSuccess($rows, 'Search results retrieved successfully.');
        });
    }

    /** GET /api/v1/messaging/mentions — الرسايل اللي فيها mention لليوزر الحالي */
    public function mentions(Request $request)
    {
        return $this->apiSuccess($this->messaging->mentionsOfMe($this->uid($request)), 'Mentions retrieved successfully.');
    }

    /** GET /api/v1/messaging/categories */
    public function categories(Request $request)
    {
        return $this->apiSuccess($this->messaging->categoriesForUser($this->uid($request)), 'Categories retrieved successfully.');
    }

    /** POST /api/v1/messaging/conversations/direct — body: recipient_email, body */
    public function startDirect(Request $request)
    {
        return $this->handle(function () use ($request) {
            $id = $this->messaging->startConversation(
                $this->uid($request),
                (string) $request->input('recipient_email', ''),
                (string) $request->input('body', '')
            );
            $thread = $this->messaging->threadForUser($id, $this->uid($request));
            return $this->apiSuccess($thread, 'Conversation started successfully.', 201);
        });
    }

    /** POST /api/v1/messaging/conversations/group — body: name, member_ids[] */
    public function createGroup(Request $request)
    {
        return $this->handle(function () use ($request) {
            $memberIds = array_map('intval', (array) $request->input('member_ids', []));
            $thread = $this->messaging->createGroupConversation($this->uid($request), (string) $request->input('name', ''), $memberIds);
            return $this->apiSuccess($thread, 'Group created successfully.', 201);
        });
    }

    /** GET /api/v1/messaging/conversations/{id} — query: before_id (اختياري) */
    public function thread(Request $request, $id)
    {
        $beforeId = $request->filled('before_id') ? (int) $request->input('before_id') : null;
        $thread = $this->messaging->threadForUser((int) $id, $this->uid($request), $beforeId);
        if (!$thread) {
            return $this->apiError('Conversation not found.', null, 404);
        }
        return $this->apiSuccess($thread, 'Conversation retrieved successfully.');
    }

    /** POST /api/v1/messaging/conversations/{id}/rename — body: name */
    public function renameGroup(Request $request, $id)
    {
        return $this->handle(function () use ($request, $id) {
            $this->messaging->renameGroup((int) $id, $this->uid($request), (string) $request->input('name', ''));
            return $this->apiSuccess(null, 'Group renamed successfully.');
        });
    }

    /** POST /api/v1/messaging/conversations/{id}/members — body: user_id */
    public function addMember(Request $request, $id)
    {
        return $this->handle(function () use ($request, $id) {
            $this->messaging->addMember((int) $id, $this->uid($request), (int) $request->input('user_id'));
            return $this->apiSuccess(null, 'Member added successfully.');
        });
    }

    /** DELETE /api/v1/messaging/conversations/{id}/members/{userId} */
    public function removeMember(Request $request, $id, $userId)
    {
        return $this->handle(function () use ($request, $id, $userId) {
            $this->messaging->removeMember((int) $id, $this->uid($request), (int) $userId);
            return $this->apiSuccess(null, 'Member removed successfully.');
        });
    }

    /** POST /api/v1/messaging/conversations/{id}/members/{userId}/role — body: role */
    public function setMemberRole(Request $request, $id, $userId)
    {
        return $this->handle(function () use ($request, $id, $userId) {
            $this->messaging->setMemberRole((int) $id, $this->uid($request), (int) $userId, (string) $request->input('role', 'member'));
            return $this->apiSuccess(null, 'Member role updated successfully.');
        });
    }

    /** POST /api/v1/messaging/conversations/{id}/flags/{flag} — body: value (bool) */
    public function setFlag(Request $request, $id, $flag)
    {
        return $this->handle(function () use ($request, $id, $flag) {
            $this->messaging->toggleMemberFlag((int) $id, $this->uid($request), (string) $flag, (bool) $request->input('value', true));
            return $this->apiSuccess(null, 'Conversation updated successfully.');
        });
    }

    /** POST /api/v1/messaging/conversations/{id}/category — body: category */
    public function setCategory(Request $request, $id)
    {
        return $this->handle(function () use ($request, $id) {
            $this->messaging->setCategory((int) $id, $this->uid($request), $request->input('category'));
            return $this->apiSuccess(null, 'Category updated successfully.');
        });
    }

    /** POST /api/v1/messaging/conversations/{id}/messages — multipart: body, parent_message_id?, forwarded_from_id?, mentioned_user_ids[]?, attachments[]? */
    public function send(Request $request, $id)
    {
        return $this->handle(function () use ($request, $id) {
            $message = $this->messaging->sendMessage((int) $id, $this->uid($request), (string) $request->input('body', ''), [
                'parent_message_id'   => $request->filled('parent_message_id') ? (int) $request->input('parent_message_id') : null,
                'forwarded_from_id'   => $request->filled('forwarded_from_id') ? (int) $request->input('forwarded_from_id') : null,
                'mentioned_user_ids'  => (array) $request->input('mentioned_user_ids', []),
                'attachments'         => $request->file('attachments', []),
            ]);
            return $this->apiSuccess($message, 'Message sent successfully.', 201);
        });
    }

    /** GET /api/v1/messaging/conversations/{id}/pinned */
    public function pinned(Request $request, $id)
    {
        return $this->handle(function () use ($request, $id) {
            return $this->apiSuccess($this->messaging->pinnedMessages((int) $id, $this->uid($request)), 'Pinned messages retrieved successfully.');
        });
    }

    /** POST /api/v1/messaging/conversations/{id}/read */
    public function markRead(Request $request, $id)
    {
        return $this->handle(function () use ($request, $id) {
            $this->messaging->markConversationRead((int) $id, $this->uid($request));
            return $this->apiSuccess(null, 'Conversation marked as read.');
        });
    }

    /** GET /api/v1/messaging/conversations/{id}/poll — query: since_id */
    public function poll(Request $request, $id)
    {
        return $this->handle(function () use ($request, $id) {
            $sinceId = (int) $request->input('since_id', 0);
            return $this->apiSuccess($this->messaging->poll((int) $id, $this->uid($request), $sinceId), 'Poll retrieved successfully.');
        });
    }

    /** POST /api/v1/messaging/conversations/{id}/poll-message — body: question, options[] */
    public function createPoll(Request $request, $id)
    {
        return $this->handle(function () use ($request, $id) {
            $message = $this->messaging->createPoll((int) $id, $this->uid($request), (string) $request->input('question', ''), (array) $request->input('options', []));
            return $this->apiSuccess($message, 'Poll created successfully.', 201);
        });
    }

    /** POST /api/v1/messaging/messages/{id}/edit — body: body */
    public function edit(Request $request, $id)
    {
        return $this->handle(function () use ($request, $id) {
            $message = $this->messaging->editMessage((int) $id, $this->uid($request), (string) $request->input('body', ''));
            return $this->apiSuccess($message, 'Message updated successfully.');
        });
    }

    /** DELETE /api/v1/messaging/messages/{id}/for-me */
    public function deleteForMe(Request $request, $id)
    {
        return $this->handle(function () use ($request, $id) {
            $this->messaging->deleteForMe((int) $id, $this->uid($request));
            return $this->apiSuccess(null, 'Message deleted for you.');
        });
    }

    /** DELETE /api/v1/messaging/messages/{id}/for-everyone */
    public function deleteForEveryone(Request $request, $id)
    {
        return $this->handle(function () use ($request, $id) {
            $this->messaging->deleteForEveryone((int) $id, $this->uid($request));
            return $this->apiSuccess(null, 'Message deleted for everyone.');
        });
    }

    /** POST /api/v1/messaging/messages/{id}/restore */
    public function restore(Request $request, $id)
    {
        return $this->handle(function () use ($request, $id) {
            $this->messaging->restoreMessage((int) $id, $this->uid($request));
            return $this->apiSuccess(null, 'Message restored successfully.');
        });
    }

    /** DELETE /api/v1/messaging/messages/{id}/undo-send */
    public function undoSend(Request $request, $id)
    {
        return $this->handle(function () use ($request, $id) {
            $this->messaging->undoSend((int) $id, $this->uid($request));
            return $this->apiSuccess(null, 'Message undone.');
        });
    }

    /** GET /api/v1/messaging/messages/{id}/history */
    public function history(Request $request, $id)
    {
        return $this->handle(function () use ($request, $id) {
            return $this->apiSuccess($this->messaging->history((int) $id, $this->uid($request)), 'Message history retrieved successfully.');
        });
    }

    /** POST /api/v1/messaging/messages/{id}/pin */
    public function pin(Request $request, $id)
    {
        return $this->handle(function () use ($request, $id) {
            $this->messaging->setPinned((int) $id, $this->uid($request), true);
            return $this->apiSuccess(null, 'Message pinned.');
        });
    }

    /** POST /api/v1/messaging/messages/{id}/unpin */
    public function unpin(Request $request, $id)
    {
        return $this->handle(function () use ($request, $id) {
            $this->messaging->setPinned((int) $id, $this->uid($request), false);
            return $this->apiSuccess(null, 'Message unpinned.');
        });
    }

    /** POST /api/v1/messaging/messages/{id}/react — body: emoji */
    public function react(Request $request, $id)
    {
        return $this->handle(function () use ($request, $id) {
            $reactions = $this->messaging->toggleReaction((int) $id, $this->uid($request), (string) $request->input('emoji', ''));
            return $this->apiSuccess(['reactions' => $reactions], 'Reaction updated successfully.');
        });
    }

    /** GET /api/v1/messaging/messages/{id}/receipts */
    public function receipts(Request $request, $id)
    {
        return $this->handle(function () use ($request, $id) {
            return $this->apiSuccess($this->messaging->readReceipts((int) $id, $this->uid($request)), 'Read receipts retrieved successfully.');
        });
    }

    /** POST /api/v1/messaging/messages/{id}/vote — body: option_index */
    public function votePoll(Request $request, $id)
    {
        return $this->handle(function () use ($request, $id) {
            $results = $this->messaging->votePoll((int) $id, $this->uid($request), (int) $request->input('option_index'));
            return $this->apiSuccess($results, 'Vote recorded successfully.');
        });
    }

    /** POST /api/v1/messaging/presence/heartbeat */
    public function heartbeat(Request $request)
    {
        $this->messaging->heartbeat($this->uid($request));
        return $this->apiSuccess(null, 'Heartbeat recorded.');
    }

    /** POST /api/v1/messaging/presence/typing — body: conversation_id (nullable) */
    public function typing(Request $request)
    {
        $conversationId = $request->filled('conversation_id') ? (int) $request->input('conversation_id') : null;
        $this->messaging->setTyping($this->uid($request), $conversationId);
        return $this->apiSuccess(null, 'Typing status updated.');
    }

    /** POST /api/v1/messaging/presence/lookup — body: user_ids[] */
    public function presence(Request $request)
    {
        $userIds = array_map('intval', (array) $request->input('user_ids', []));
        return $this->apiSuccess($this->messaging->presenceFor($userIds), 'Presence retrieved successfully.');
    }

    /** GET /api/v1/messaging/attachments/{id}/download */
    public function downloadAttachment(Request $request, $id)
    {
        $row = $this->messaging->attachmentAuthorized((int) $id, $this->uid($request));
        if (!$row) {
            return $this->apiError('Attachment not found.', null, 404);
        }
        $path = public_path(ltrim(str_replace('uploads/', 'uploads/', $row['stored_path']), '/'));
        if (!is_file($path)) {
            return $this->apiError('File not found on server.', null, 404);
        }
        return response()->download($path, $row['original_name']);
    }

    /** DELETE /api/v1/messaging/attachments/{id} */
    public function deleteAttachment(Request $request, $id)
    {
        return $this->handle(function () use ($request, $id) {
            $this->messaging->deleteAttachment((int) $id, $this->uid($request));
            return $this->apiSuccess(null, 'Attachment deleted successfully.');
        });
    }

    /** بيلف أي فعل بيرمي RuntimeException بشكل ودّي (400) بدل ما يبوظ الطلب — نفس اتفاقية try/catch في كل كنترولر تاني في المشروع. */
    private function handle(\Closure $action)
    {
        try {
            return $action();
        } catch (\RuntimeException | \InvalidArgumentException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        }
    }
}
