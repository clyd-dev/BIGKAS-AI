<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Tells the admin (principal) that someone created an account, so a teacher can be given a
 * grade and section straight away. Shown in the bell menu and on the Notifications page.
 */
class NewUserRegistered extends Notification
{
    use Queueable;

    public function __construct(protected User $newUser) {}

    /** Notify every active admin about a self-registered account. */
    public static function notifyAdmins(User $newUser): void
    {
        User::where('role', 'admin')->where('is_active', true)->get()
            ->each(fn (User $admin) => $admin->notify(new self($newUser)));
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $isTeacher = $this->newUser->role === 'teacher';

        return [
            'title'   => $isTeacher ? 'New teacher account' : 'New ' . $this->newUser->role . ' account',
            'message' => $isTeacher
                ? "{$this->newUser->name} registered and needs a grade & section."
                : "{$this->newUser->name} registered as a {$this->newUser->role}.",
            'url'     => $isTeacher
                ? route('admin.users', ['role' => 'teacher', 'unassigned' => 1])
                : route('admin.users', ['role' => $this->newUser->role]),
        ];
    }
}
