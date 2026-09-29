<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\V1\GuardianController;
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

        // 낮은 평점 답변 SLA(기능 24) — 2점 이하 알림 시각부터 운영자 답변까지. 기준 24시간
        $slaHours = 24;
        $flagged = DB::table('reviews')->whereNotNull('flagged_at');
        $negativeOpen = (clone $flagged)->whereNull('admin_reply')->count();
        $slaOverdue = (clone $flagged)->whereNull('admin_reply')->where('flagged_at', '<', now()->subHours($slaHours))->count();
        $avgReplyHours = (clone $flagged)->whereNotNull('replied_at')
            ->selectRaw('AVG(TIMESTAMPDIFF(MINUTE, flagged_at, replied_at)) / 60 as h')->value('h');

        $chatbotTotal = DB::table('chatbot_sessions')->count();
        $chatbotOpen = DB::table('chatbot_sessions')->whereNull('ended_at')->count();

        return response()->json([
            'success' => true,
            'data' => [
                'reviews_total' => $reviewsTotal,
                'reviews_avg' => (float) $reviewsAvg,
                'reviews_negative' => $reviewsNegative,
                'rating_distribution' => $distribution,
                'negative_open' => $negativeOpen,
                'sla_hours' => $slaHours,
                'sla_overdue' => $slaOverdue,
                'avg_reply_hours' => $avgReplyHours !== null ? round((float) $avgReplyHours, 1) : null,
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
    public function replyReview(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate(['reply' => 'required|string|max:2000']);

        $review = DB::table('reviews')->where('id', $id)->first();
        if (!$review) {
            return response()->json(['success' => false, 'message' => '후기를 찾을 수 없습니다.'], 404);
        }

        DB::table('reviews')->where('id', $id)->update([
            'admin_reply' => $validated['reply'],
            'replied_at' => now(),
            'replied_by' => $request->user()->id,
            'updated_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => '답글이 저장되었습니다.',
            'data' => ['id' => $id, 'admin_reply' => $validated['reply'], 'replied_at' => now()->toIso8601String()],
        ]);
    }

    public function reviews(Request $request): JsonResponse
    {
        $perPage = min((int) $request->input('per_page', 20), 100);

        $query = DB::table('reviews as r')
            ->leftJoin('users as u', 'u.id', '=', 'r.reviewer_id')
            ->leftJoin('matches as m', 'm.id', '=', 'r.match_id')
            ->leftJoin('match_requests as mr', 'mr.id', '=', 'm.request_id')
            ->select(
                'r.scores',
                'r.flagged_at',
                'mr.service_domain',
                'r.id',
                'r.match_id',
                'r.reviewer_id',
                'u.name as reviewer_name',
                'r.reviewer_role',
                'r.rating',
                'r.comment',
                'r.tags',
                'r.admin_reply',
                'r.replied_at',
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
        if ($request->boolean('unanswered')) {
            $query->whereNotNull('r.flagged_at')->whereNull('r.admin_reply');
        }

        $paginated = $query->orderByDesc('r.created_at')->paginate($perPage);

        $items = collect($paginated->items())->map(function ($row) {
            $tags = $row->tags ? json_decode($row->tags, true) : [];
            $scores = $row->scores ? (json_decode($row->scores, true) ?: []) : [];
            $labels = array_column(GuardianController::criteria($row->service_domain), 'label', 'key');

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
                'service_domain' => $row->service_domain,
                'scores' => collect($scores)->map(fn ($v, $k) => ['key' => $k, 'label' => $labels[$k] ?? $k, 'score' => (int) $v])->values(),
                'flagged_at' => \App\Support\Kst::iso($row->flagged_at),
                // 답변 대기 시간(시간) — 2점 이하 미답변만
                'open_hours' => ($row->flagged_at && !$row->admin_reply)
                    ? round(\Illuminate\Support\Carbon::parse($row->flagged_at)->diffInMinutes(now()) / 60, 1) : null,
                'admin_reply' => $row->admin_reply,
                'replied_at' => \App\Support\Kst::iso($row->replied_at),
                'created_at' => \App\Support\Kst::iso($row->created_at),
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
            'started_at' => \App\Support\Kst::iso($row->started_at),
            'last_at' => \App\Support\Kst::iso($row->last_at),
            'ended_at' => \App\Support\Kst::iso($row->ended_at),
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
