<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 관리자 - 후기·CS 관리 (#24)
 * 후기(reviews)와 CS 챗봇 상담(chatbot_sessions)을 통합 조회한다.
 */
class CsController extends Controller
{
    /**
     * GET /v1/admin/cs/stats
     * CS 개요 통계
     */
    public function stats(Request $request): JsonResponse
    {
        $ratingRows = DB::table('reviews')
            ->select('rating', DB::raw('COUNT(*) as cnt'))
            ->groupBy('rating')
            ->pluck('cnt', 'rating');

        $distribution = [];
        for ($i = 1; $i <= 5; $i++) {
            $distribution[$i] = (int) ($ratingRows[$i] ?? 0);
        }

        $reviewsTotal = array_sum($distribution);
        $reviewsAvg = $reviewsTotal > 0
            ? round(DB::table('reviews')->avg('rating'), 2)
            : 0;
        $reviewsNegative = $distribution[1] + $distribution[2];

        $chatbotTotal = DB::table('chatbot_sessions')->count();
        $chatbotOpen = DB::table('chatbot_sessions')->whereNull('ended_at')->count();

        return response()->json([
            'success' => true,
            'data' => [
                'reviews_total' => $reviewsTotal,
                'reviews_avg' => (float) $reviewsAvg,
                'reviews_negative' => $reviewsNegative,
                'rating_distribution' => $distribution,
                'chatbot_total' => $chatbotTotal,
                'chatbot_open' => $chatbotOpen,
            ],
            'updated_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * GET /v1/admin/cs/reviews
     * 후기 목록 (필터: rating, role, negative / 페이지네이션)
     */
    public function reviews(Request $request): JsonResponse
    {
        $perPage = min((int) $request->input('per_page', 20), 100);

        $query = DB::table('reviews as r')
            ->leftJoin('users as u', 'u.id', '=', 'r.reviewer_id')
            ->select(
                'r.id',
                'r.match_id',
                'r.reviewer_id',
                'u.name as reviewer_name',
                'r.reviewer_role',
                'r.rating',
                'r.comment',
                'r.tags',
                'r.created_at'
            );

        if ($request->filled('rating')) {
            $query->where('r.rating', (int) $request->input('rating'));
        }
        if ($request->filled('role')) {
            $query->where('r.reviewer_role', $request->input('role'));
        }
        if ($request->boolean('negative')) {
            $query->where('r.rating', '<=', 2);
        }

        $paginated = $query->orderByDesc('r.created_at')->paginate($perPage);

        $items = collect($paginated->items())->map(function ($row) {
            $tags = $row->tags ? json_decode($row->tags, true) : [];

            return [
                'id' => $row->id,
                'match_id' => $row->match_id,
                'reviewer_id' => $row->reviewer_id,
                'reviewer_name' => $row->reviewer_name ?? '(탈퇴/미상)',
                'reviewer_role' => $row->reviewer_role,
                'rating' => (int) $row->rating,
                'comment' => $row->comment,
                'tags' => is_array($tags) ? $tags : [],
                'is_negative' => (int) $row->rating <= 2,
                'created_at' => $row->created_at,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $items,
            'meta' => [
                'total' => $paginated->total(),
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
            ],
        ]);
    }

    /**
     * GET /v1/admin/cs/chatbot-sessions
     * CS 챗봇 상담 세션 목록 (메시지 수·최근 시각 포함)
     */
    public function chatbotSessions(Request $request): JsonResponse
    {
        $perPage = min((int) $request->input('per_page', 20), 100);

        $msgAgg = DB::table('chatbot_messages')
            ->select('session_id', DB::raw('COUNT(*) as cnt'), DB::raw('MAX(created_at) as last_at'))
            ->groupBy('session_id');

        $query = DB::table('chatbot_sessions as s')
            ->leftJoin('users as u', 'u.id', '=', 's.guardian_id')
            ->leftJoinSub($msgAgg, 'm', 'm.session_id', '=', 's.id')
            ->select(
                's.id',
                's.guardian_id',
                'u.name as guardian_name',
                's.topic',
                's.started_at',
                's.ended_at',
                DB::raw('COALESCE(m.cnt, 0) as message_count'),
                'm.last_at'
            );

        if ($request->input('status') === 'open') {
            $query->whereNull('s.ended_at');
        } elseif ($request->input('status') === 'closed') {
            $query->whereNotNull('s.ended_at');
        }

        $paginated = $query->orderByDesc('s.started_at')->paginate($perPage);

        $items = collect($paginated->items())->map(fn ($row) => [
            'id' => $row->id,
            'guardian_id' => $row->guardian_id,
            'guardian_name' => $row->guardian_name ?? '(탈퇴/미상)',
            'topic' => $row->topic ?? '일반 문의',
            'message_count' => (int) $row->message_count,
            'status' => $row->ended_at ? 'closed' : 'open',
            'started_at' => $row->started_at,
            'last_at' => $row->last_at,
            'ended_at' => $row->ended_at,
        ]);

        return response()->json([
            'success' => true,
            'data' => $items,
            'meta' => [
                'total' => $paginated->total(),
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
            ],
        ]);
    }
}
