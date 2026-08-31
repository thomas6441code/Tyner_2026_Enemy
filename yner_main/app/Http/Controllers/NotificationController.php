<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Inertia\Inertia;
use Inertia\Response;

class NotificationController extends Controller
{
    /**
     * Display the current user's notification inbox.
     */
    public function index(Request $request): Response
    {
        $notifications = $request->user()
            ->notifications()
            ->latest()
            ->paginate(20)
            ->through(fn(DatabaseNotification $notification) => [
                'id' => $notification->id,
                'type' => $notification->type,
                'title' => $notification->data['title'] ?? class_basename($notification->type),
                'message' => $notification->data['message'] ?? '',
                'action_url' => $notification->data['action_url'] ?? null,
                'action_label' => $notification->data['action_label'] ?? null,
                'meta' => $notification->data['meta'] ?? [],
                'read_at' => $notification->read_at?->toDateTimeString(),
                'created_at' => $notification->created_at?->toDateTimeString(),
                'is_unread' => $notification->read_at === null,
            ]);

        return Inertia::render('notifications/index', [
            'notifications' => $notifications,
        ]);
    }

    /**
     * Mark a single notification as read.
     */
    public function markAsRead(Request $request, string $notificationId): RedirectResponse
    {
        $notification = $request->user()->notifications()->whereKey($notificationId)->firstOrFail();
        $notification->markAsRead();

        return back()->with('status', 'Notification marked as read.');
    }

    /**
     * Mark all notifications as read.
     */
    public function markAllAsRead(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return back()->with('status', 'All notifications marked as read.');
    }
}
