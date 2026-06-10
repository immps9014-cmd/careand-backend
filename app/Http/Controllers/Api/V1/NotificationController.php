<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    /**
     * GET /v1/notifications
     */
    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $query = Notification::where('user_id', $userId)
            ->orderByDesc('created_at');

        if ($request->boolean('unread_only')) {
            $query->where('is_read', false);
        }

        $notifications = $query->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $notifications->map(fn ($n) => [
                'id' => $n->id,
                'type' => $n->type,
                'title' => $n->title,
                'body' => $n->body,
                'data' => $n->data,
                'is_read' => $n->is_read,
                'read_at' => $n->read_at?->toIso8601String(),
                'created_at' => $n->created_at->toIso8601String(),
                'created_ago' => $n->created_at->diffForHumans(),
            ]),
            'meta' => [
                'total' => $notifications->total(),
                'unread_count' => Notification::where('user_id', $userId)
                    ->where('is_read', false)->count(),
            ],
        ]);
    }

    /**
     * POST /v1/notifications/{id}/read
     */
    public function markAsRead(Request $request, int $id): JsonResponse
    {
        $notification = Notification::where('user_id', $request->user()->id)
            ->findOrFail($id);

        $notification->update([
            'is_read' => true,
            'read_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => '읽음 처리되었습니다.',
        ]);
    }

    /**
     * POST /v1/notifications/read-all
     */
    public function markAllAsRead(Request $request): JsonResponse
    {
        $count = Notification::where('user_id', $request->user()->id)
            ->where('is_read', false)
            ->update([
                'is_read' => true,
                'read_at' => now(),
            ]);

        return response()->json([
            'success' => true,
            'message' => "{$count}개 알림을 읽음 처리했습니다.",
        ]);
    }

    /**
     * POST /v1/notifications/fcm-token
     * FCM 토큰 등록/갱신
     */
    public function updateFcmToken(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'fcm_token' => ['required', 'string', 'max:500'],
        ]);

        $request->user()->update(['fcm_token' => $validated['fcm_token']]);

        return response()->json([
            'success' => true,
            'message' => 'FCM 토큰이 등록되었습니다.',
        ]);
    }
}
