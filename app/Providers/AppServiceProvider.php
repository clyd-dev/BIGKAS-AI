<?php

namespace App\Providers;

use App\Support\Directory;

use App\Models\Learner;
use App\Models\Message;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Every ->links() call uses the app's own touch-friendly pager. Laravel's
        // default view is Tailwind markup, which this Bootstrap app cannot style,
        // so pages that called ->links() without a view rendered a broken pager.
        Paginator::defaultView('partials.pagination');
        Paginator::defaultSimpleView('partials.pagination');

        // Users are found by the blind index of their encrypted email.
        Auth::provider('blind_eloquent', fn ($app, array $config) => new \App\Auth\BlindIndexUserProvider($app['hash'], $config['model']));

        // The password-reset table keys on the email's blind index (User::getEmailForPasswordReset), so the link
        // must carry the real email for the reset form to pre-fill.
        ResetPassword::createUrlUsing(fn ($notifiable, string $token) => url(route('password.reset', [
            'token' => $token,
            'email' => $notifiable->email,
        ], false)));

        // Admin / teacher shell: the role's links and the unread counts.
        View::composer('layouts.app', function ($view) {
            $user = auth()->user();
            if (!$user) {
                return;
            }

            $view->with([
                'navRole'         => $user->role,
                'navSections'     => \App\Support\StaffNav::sections($user->role),
                'navTabs'         => \App\Support\StaffNav::tabs($user->role),
                'navMore'         => \App\Support\StaffNav::more($user->role),
                'navUnreadMsgs'   => Message::where('receiver_id', $user->id)->whereNull('read_at')->count(),
                'navUnreadNotifs' => $user->unreadNotifications()->count(),
            ]);
        });

        // Data every parent page needs: the children, which one is "current",
        // and the unread counts for the tab bar.
        View::composer('layouts.parent', function ($view) {
            $user = auth()->user();
            if (!$user) {
                return;
            }

            $children = $user->learners()->get()->sortBy(fn ($l) => Directory::key($l->first_name))->values();

            // Current child: the one in the URL, else the last one viewed, else the first.
            $routeLearner = request()->route('learner');
            if ($routeLearner instanceof Learner && $children->contains('id', $routeLearner->id)) {
                session(['parent_child_id' => $routeLearner->id]);
            }
            $current = $children->firstWhere('id', session('parent_child_id')) ?? $children->first();

            $view->with([
                'navChildren'     => $children,
                'navChild'        => $current,
                'navUnreadMsgs'   => Message::where('receiver_id', $user->id)->whereNull('read_at')->count(),
                'navUnreadNotifs' => $user->unreadNotifications()->count(),
            ]);
        });
    }
}
