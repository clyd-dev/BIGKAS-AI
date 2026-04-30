<?php

namespace App\Http\Controllers;

use App\Models\Learner;
use App\Models\Message;
use App\Models\User;
use Illuminate\Http\Request;

class MessageController extends Controller
{
    /**
     * Inbox — list all conversation threads for teacher/admin.
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

        return view('messages.index', compact('threads', 'unreadCount'));
    }

    /**
     * View a conversation thread.
     */
    public function show(Message $message)
    {
        $user = auth()->user();
        abort_unless(
            $message->sender_id === $user->id || $message->receiver_id === $user->id,
            403
        );

        // If this is a reply, redirect to parent thread
        if ($message->parent_message_id) {
            return redirect()->route('messages.show', $message->parent_message_id);
        }

        $message->load(['sender', 'receiver', 'learner', 'replies.sender']);

        // Mark unread messages as read
        if ($message->receiver_id === $user->id && !$message->read_at) {
            $message->markAsRead();
        }
        $message->replies()
            ->where('receiver_id', $user->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        $otherParticipant = $message->getOtherParticipant($user->id);

        return view('messages.show', compact('message', 'otherParticipant'));
    }

    /**
     * Compose form.
     */
    public function create()
    {
        $user = auth()->user();

        // Teachers can message parents of their learners
        $learnerIds = $user->learners()->pluck('learners.id');
        $parents = User::where('role', 'parent')
            ->whereHas('learners', fn($q) => $q->whereIn('learners.id', $learnerIds))
            ->orderBy('name')
            ->get();

        $learners = $user->learners()->orderBy('last_name')->get();

        return view('messages.compose', compact('parents', 'learners'));
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

        $message = Message::create([
            'sender_id'   => $user->id,
            'receiver_id' => $request->receiver_id,
            'learner_id'  => $request->learner_id,
            'subject'     => $request->subject,
            'body'        => $request->body,
        ]);

        return redirect()->route('messages.show', $message)->with('success', 'Message sent.');
    }

    /**
     * Reply to a thread.
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

        $thread = $message->parent_message_id ? $message->parentMessage : $message;
        $receiverId = $thread->sender_id === $user->id ? $thread->receiver_id : $thread->sender_id;

        Message::create([
            'sender_id'         => $user->id,
            'receiver_id'       => $receiverId,
            'learner_id'        => $thread->learner_id,
            'subject'           => 'Re: ' . $thread->subject,
            'body'              => $request->body,
            'parent_message_id' => $thread->id,
        ]);

        return redirect()->route('messages.show', $thread)->with('success', 'Reply sent.');
    }
}
