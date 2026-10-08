<?php

namespace App\Http\Controllers\Parent;

use App\Support\Directory;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Learner;
use App\Models\Message;
use App\Models\User;
use App\Notifications\NewMessageReceived;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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
        $learners = Directory::sortLearners($user->accessibleLearnersQuery()->get());

        // Teachers who teach classes containing the parent's children
        $classIds = $learners->pluck('class_id')->filter()->unique();
        $teachers = Directory::sortUsers(User::where('role', 'teacher')
            ->whereHas('taughtClasses', fn ($q) => $q->whereIn('classes.id', $classIds))
            ->with('learners:id')
            ->get());

        // Admins (principal) — always available to any parent
        $admins = Directory::sortUsers(User::where('role', 'admin')->with('learners:id')->get());

        $recipients = collect(['teacher' => $teachers, 'admin' => $admins])
            ->filter(fn ($group) => $group->isNotEmpty());

        return view('parent.messages.compose', compact('learners', 'recipients'));
    }

    /**
     * Send a new message.
     */
    public function store(Request $request)
    {
        $user = auth()->user();

        $request->validate([
            'receiver_id' => 'required|exists:users,id',
            'learner_id'  => [
                'nullable',
                Rule::exists('learner_user', 'learner_id')->where('user_id', $user->id),
            ],
            'subject'     => 'required|string|max:255',
            'body'        => 'required|string|max:5000',
        ]);

        // Verify the receiver is a teacher of the parent's children's classes, or an admin
        $classIds = $user->accessibleLearnersQuery()
            ->pluck('class_id')->filter()->unique();

        $receiverValid = User::where('id', $request->receiver_id)
            ->where(function ($q) use ($classIds) {
                $q->where(function ($tq) use ($classIds) {
                    $tq->where('role', 'teacher')
                       ->whereHas('taughtClasses', fn($cq) => $cq->whereIn('classes.id', $classIds));
                })->orWhere('role', 'admin');
            })
            ->exists();

        abort_unless($receiverValid, 403, 'You can only message teachers of your children\'s classes or the admin.');

        $message = Message::create([
            'sender_id'   => $user->id,
            'receiver_id' => $request->receiver_id,
            'learner_id'  => $request->learner_id,
            'subject'     => $request->subject,
            'body'        => $request->body,
        ]);

        ActivityLog::log('parent_send_message', "Parent sent message to user #{$message->receiver_id}", 'message', $message->id);

        $message->receiver->notify(new NewMessageReceived($message));

        return redirect()->route('parent.messages.show', $message)->with('success', 'Message sent.');
    }

    /**
     * Send a child's reading report to the class teacher (or admin) as a message.
     */
    public function sendReport(Request $request, Learner $learner)
    {
        $user = auth()->user();
        abort_unless($learner->users()->where('users.id', $user->id)->exists(), 403, 'You are not linked to this learner.');

        $recipients = $learner->reportRecipients()->flatten(1);

        $data = $request->validate([
            'receiver_id' => ['required', Rule::in($recipients->pluck('id')->all())],
            'note'        => 'nullable|string|max:2000',
        ]);

        $latest = $learner->getLatestAssessment()?->result;
        $lines  = [];
        if (!empty($data['note'])) {
            $lines[] = $data['note'];
            $lines[] = '';
        }
        $lines[] = "I am sharing {$learner->full_name}'s reading report with you.";
        if ($latest) {
            $level   = ucfirst((string) $latest->reading_level);
            $lines[] = "Latest result: {$level} level, " . round($latest->accuracy_rate) . '% of words read correctly, about ' . round($latest->words_per_minute) . ' words per minute.';
        }
        $lines[] = 'Full report: ' . route('reports.learner', $learner);

        $message = Message::create([
            'sender_id'   => $user->id,
            'receiver_id' => $data['receiver_id'],
            'learner_id'  => $learner->id,
            'subject'     => "Reading report for {$learner->full_name}",
            'body'        => implode("\n", $lines),
        ]);

        ActivityLog::log('parent_send_report', "Parent sent report for learner #{$learner->id} to user #{$message->receiver_id}", 'message', $message->id);

        $message->receiver->notify(new NewMessageReceived($message));

        return back()->with('success', 'Report sent to ' . $message->receiver->name . '. You can follow the conversation in Messages.');
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

        $reply->receiver->notify(new NewMessageReceived($reply));

        return redirect()->route('parent.messages.show', $thread)->with('success', 'Reply sent.');
    }
}
