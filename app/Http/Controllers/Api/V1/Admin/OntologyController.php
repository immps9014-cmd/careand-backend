<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\External\AiService;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * 관리자 온톨로지 분석 화면 백엔드
 *
 *   GET /v1/admin/ontology/overview                 질병 커버리지 · 특기 공급 · 인력 목록
 *   GET /v1/admin/ontology/caregivers/{id}/impact   인력 이탈 영향분석 + 대체 후보
 *
 * 이 컨트롤러는 **프록시만 한다.** SPARQL 은 AI 서비스(careand-ai-service/ontology.py)의
 * 화이트리스트 질의로만 실행되고, 여기로는 질의 문자열이 오가지 않는다.
 * 그래프는 DB 의 읽기 투영(매시 :10 재적재)이라 이 화면은 조회 전용이다 —
 * 여기에 쓰기 동작을 붙이지 말 것. 업무 상태 변경은 기존 엔드포인트로만 한다.
 *
 * AI 서비스가 죽으면 502 대신 available=false 를 내려 화면이 살아 있게 한다
 * (매칭·STT 의 fail-open 원칙과 동일).
 */
class OntologyController extends Controller
{
    public function __construct(private readonly AiService $ai)
    {
    }

    public function overview(): JsonResponse
    {
        try {
            $data = $this->ai->ontologyOverview();
        } catch (RuntimeException $e) {
            $data = ['available' => false, 'status' => null,
                     'diseases' => [], 'specialties' => [], 'caregivers' => [],
                     'error' => 'AI 서비스에 연결할 수 없습니다.'];
        }

        return response()->json(['success' => true, 'data' => $data]);
    }

    public function caregiverImpact(int $id): JsonResponse
    {
        try {
            $data = $this->ai->ontologyCaregiverImpact($id);
        } catch (RuntimeException $e) {
            $data = ['available' => false, 'found' => false,
                     'error' => 'AI 서비스에 연결할 수 없습니다.'];
        }

        return response()->json(['success' => true, 'data' => $data]);
    }
}
