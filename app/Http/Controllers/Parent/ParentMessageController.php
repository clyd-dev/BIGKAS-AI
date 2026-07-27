<?php

namespace App\Http\Controllers\Parent;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Learner;
use App\Models\Message;
use App\Models\User;
use Illuminate\Http\Request;

class ParentMessageController extends Controller
{
    /**
     * Inbox — list all conversation threads for the parent.
     */
    public function index()
    {
        $user = auth()->user();

        $threads = Message::threads($user->id)
            ->with(['sender', 'receiver', 'learner'])
            ->withCount(['replies'])
            ->latest()
            ->paginate(20);

        $unreadCount = Message::where('receiver_id', $user->id)
            ->whereNull('read_at')
            ->count();

        return view('parent.messages.index', compact('threads', 'unreadCount'));
    }

    /**
     * View a conversation thread (original message + all replies).
     */
    public function show(Message $message)
    {
        $user = auth()->user();
        abort_unless(
            $message->sender_id === $user->id || $message->receiver_id === $user->id,
            403
        );

        // If this is a reply, redirect to the parent thread
        if ($message->parent_message_id) {
            return redirect()->route('parent.messages.show', $message->parent_message_id);
        }

        // Load replies
        $message->load(['sender', 'receiver', 'learner', 'replies.sender']);

        // Mark unread messages in this thread as read
        if ($message->receiver_id === $user->id && !$message->read_at) {
            $message->markAsRead();
        }
        $message->replies()
            ->where('receiver_id', $user->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        $otherParticipant = $message->getOtherParticipant($user->id);

        return view('parent.messages.show', compact('message', 'otherParticipant'));
    }

    /**
     * Show the compose form.
     */
    public function create()
    {
        $user = auth()->user();
        $learners = $user->learners()->orderBy('last_name')->get();

        // Get teachers linked to parent's children
        $learnerIds = $learners->pluck('id');
        $teachers = User::where('role', 'teacher')
            ->whereHas('learners', fn($q) => $q->whereIn('learners.id', $learnerIds))
            ->orderBy('name')
            ->get();

        return view('parent.messages.compose', compact('learners', 'teachers'));
    }

    /**
     * Send a new message.
     */
    public function store(Request $request)
    {
        $request->validate([
            'receiver_id' => 'required|exists:users,id',
            'learner_id'  => 'nullable|exists:learners,id',
            'subject'     => 'required|string|max:255',
            'body'        => 'required|string|max:5000',
        ]);

        $user = auth()->user();

        // Verify the receiver is a teacher linked to the parent's learners
        $learnerIds = $user->learners()->pluck('learners.id');
        $receiverValid = User::where('id', $request->receiver_id)
            ->where(function ($q) use ($learnerIds) {
                $q->where('role', 'teacher')
                  ->whereHas('learners', fn($q2) => $q2->whereIn('learners.id', $learnerIds));
            })
            ->orWhere(function ($q) {
                $q->where('role', 'admin');
            })
            ->exists();
        // Also allow replying to admins
        if (!$receiverValid) {
            $receiverValid = User::where('id', $request->receiver_id)->where('role', 'admin')->exists();
        }

        abort_unless($receiverValid, 403, 'You can only message teachers linked to your children.');

        $message = Message::create([
            'sender_id'   => $user->id,
            'receiver_id' => $request->receiver_id,
            'learner_id'  => $request->learner_id,
            'subject'     => $request->subject,
            'body'        => $request->body,
        ]);

        ActivityLog::log('parent_send_message', "Parent sent message to teacher #{$message->receiver_id}", 'message', $message->id);

        return redirect()->route('parent.messages.show', $message)->with('success', 'Message sent.');
    }

    /**
     * Reply to a conversation thread.
     */
    public function reply(Request $request, Message $message)
    {
        $request->validate([
            'body' => 'required|string|max:5000',
        ]);

        $user = auth()->user();
        abort_unless(
            $message->sender_id === $user->id || $message->receiver_id === $user->id,
            403
        );

        // Get the root thread
        $thread = $message->parent_message_id ? $message->parentMessage : $message;

        // Determine receiver (the other participant)
        $receiverId = $thread->sender_id === $user->id ? $thread->receiver_id : $thread->sender_id;

        $reply = Message::create([
            'sender_id'         => $user->id,
            'receiver_id'       => $receiverId,
            'learner_id'        => $thread->learner_id,
            'subject'           => 'Re: ' . $thread->subject,
            'body'              => $request->body,
            'parent_message_id' => $thread->id,
        ]);

        ActivityLog::log('parent_reply_message', "Parent replied to message thread", 'message', $reply->id);

        return redirect()->route('parent.messages.show', $thread)->with('success', 'Reply sent.');
    }
}
