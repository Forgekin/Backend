<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class NotificationController extends Controller
{
    /**
     * List the authenticated account's in-app notifications.
     *
     * Works for any notifiable account type (freelancer or employer) — the
     * authenticated model is resolved by Sanctum and its polymorphic
     * notifications are returned newest-first. Each item is normalised to a
     * flat shape the frontend can render directly.
     *
     * @group Notifications
     *
     * @queryParam per_page integer Items per page (default 20, max 50). Example: 20
     * @queryParam filter string "all" or "unread" (default all). Example: unread
     *
     * @response 200 scenario="Success" {"success":true,"unread_count":2,"data":{"data":[],"current_page":1,"total":0}}
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $perPage = min((int) $request->query('per_page', 20), 50);

        $query = $request->query('filter') === 'unread'
            ? $user->unreadNotifications()
            : $user->notifications();

        $paginator = $query->paginate($perPage);

        $paginator->getCollection()->transform(fn ($n) => $this->present($n));

        return response()->json([
            'success' => true,
            'unread_count' => $user->unreadNotifications()->count(),
            'data' => $paginator,
        ]);
    }

    /**
     * Unread notification count
     *
     * Lightweight endpoint for the bell badge.
     *
     * @group Notifications
     * @response 200 {"success":true,"unread_count":3}
     */
    public function unreadCount(Request $request)
    {
        return response()->json([
            'success' => true,
            'unread_count' => $request->user()->unreadNotifications()->count(),
        ]);
    }

    /**
     * Mark a single notification as read.
     *
     * @group Notifications
     * @urlParam id string required The notification UUID.
     * @response 200 {"success":true,"unread_count":1}
     * @response 404 {"success":false,"message":"Notification not found"}
     */
    public function markRead(Request $request, string $id)
    {
        $notification = $request->user()->notifications()->find($id);

        if (! $notification) {
            return response()->json(['success' => false, 'message' => 'Notification not found'], 404);
        }

        $notification->markAsRead();

        return response()->json([
            'success' => true,
            'unread_count' => $request->user()->unreadNotifications()->count(),
        ]);
    }

    /**
     * Mark all notifications as read.
     *
     * @group Notifications
     * @response 200 {"success":true,"unread_count":0}
     */
    public function markAllRead(Request $request)
    {
        $request->user()->unreadNotifications->markAsRead();

        return response()->json(['success' => true, 'unread_count' => 0]);
    }

    /**
     * Delete a single notification.
     *
     * @group Notifications
     * @urlParam id string required The notification UUID.
     * @response 200 {"success":true,"unread_count":0}
     * @response 404 {"success":false,"message":"Notification not found"}
     */
    public function destroy(Request $request, string $id)
    {
        $notification = $request->user()->notifications()->find($id);

        if (! $notification) {
            return response()->json(['success' => false, 'message' => 'Notification not found'], 404);
        }

        $notification->delete();

        return response()->json([
            'success' => true,
            'unread_count' => $request->user()->unreadNotifications()->count(),
        ]);
    }

    /**
     * Flatten a database notification into the shape the frontend renders.
     */
    protected function present($n): array
    {
        $data = $n->data ?? [];

        return [
            'id' => $n->id,
            'type' => $data['type'] ?? 'general',
            'title' => $data['title'] ?? 'Notification',
            'message' => $data['message'] ?? '',
            'url' => $data['url'] ?? null,
            'read' => ! is_null($n->read_at),
            'created_at' => $n->created_at?->toIso8601String(),
        ];
    }
}
