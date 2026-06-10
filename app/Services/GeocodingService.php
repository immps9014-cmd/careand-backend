<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 주소 → 좌표(위경도) 지오코딩.
 *
 * 티어드 폴백: 키 보유 제공자(Kakao → VWorld) → Nominatim(OSM, 키 불필요).
 * 키 있는 제공자만 시도하고 Nominatim은 항상 마지막 폴백.
 * 결과는 30일 캐시(동일 주소 재호출 방지 + Nominatim 레이트리밋 보호).
 */
class GeocodingService
{
    private array $cfg;

    public function __construct()
    {
        $this->cfg = config('services.geocoding', []);
    }

    /**
     * @return array{lat: float, lng: float, provider: string}|null
     */
    public function geocode(?string $address): ?array
    {
        $address = trim((string) $address);
        if ($address === '') {
            return null;
        }

        return Cache::remember(
            'geocode:' . md5($address),
            now()->addDays(30),
            fn () => $this->resolve($address)
        );
    }

    private function resolve(string $address): ?array
    {
        foreach ($this->providerOrder() as $provider) {
            try {
                $result = match ($provider) {
                    'kakao' => $this->kakao($address),
                    'vworld' => $this->vworld($address),
                    'nominatim' => $this->nominatim($address),
                    default => null,
                };
                if ($result) {
                    $result['provider'] = $provider;
                    Log::info('지오코딩 성공', ['provider' => $provider, 'address' => $address]);
                    return $result;
                }
            } catch (\Throwable $e) {
                Log::warning('지오코딩 실패', [
                    'provider' => $provider,
                    'address' => $address,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return null;
    }

    /** 키 보유 제공자 우선 + Nominatim 항상 마지막 폴백 */
    private function providerOrder(): array
    {
        $order = [];
        $preferred = $this->cfg['provider'] ?? null;

        if ($preferred && $preferred !== 'nominatim' && $this->hasKey($preferred)) {
            $order[] = $preferred;
        }
        if (!empty($this->cfg['kakao_key'])) {
            $order[] = 'kakao';
        }
        if (!empty($this->cfg['vworld_key'])) {
            $order[] = 'vworld';
        }
        $order[] = 'nominatim';

        return array_values(array_unique($order));
    }

    private function hasKey(string $provider): bool
    {
        return match ($provider) {
            'kakao' => !empty($this->cfg['kakao_key']),
            'vworld' => !empty($this->cfg['vworld_key']),
            'nominatim' => true,
            default => false,
        };
    }

    private function kakao(string $address): ?array
    {
        $res = Http::withHeaders(['Authorization' => 'KakaoAK ' . $this->cfg['kakao_key']])
            ->timeout(5)
            ->get('https://dapi.kakao.com/v2/local/search/address.json', ['query' => $address, 'size' => 1]);

        $doc = $res->json('documents.0');
        if (!$doc || !isset($doc['x'], $doc['y'])) {
            return null;
        }

        return ['lat' => (float) $doc['y'], 'lng' => (float) $doc['x']];
    }

    private function vworld(string $address): ?array
    {
        foreach (['road', 'parcel'] as $type) {
            $res = Http::timeout(5)->get('https://api.vworld.kr/req/address', [
                'service' => 'address',
                'request' => 'getcoord',
                'version' => '2.0',
                'crs' => 'epsg:4326',
                'address' => $address,
                'type' => $type,
                'format' => 'json',
                'key' => $this->cfg['vworld_key'],
            ]);

            if ($res->json('response.status') === 'OK') {
                $point = $res->json('response.result.point');
                if ($point && isset($point['x'], $point['y'])) {
                    return ['lat' => (float) $point['y'], 'lng' => (float) $point['x']];
                }
            }
        }

        return null;
    }

    private function nominatim(string $address): ?array
    {
        $ua = $this->cfg['nominatim_ua'] ?? 'CareAnd-Geocoder/1.0';

        $res = Http::withHeaders(['User-Agent' => $ua])
            ->timeout(7)
            ->get('https://nominatim.openstreetmap.org/search', [
                'format' => 'json',
                'limit' => 1,
                'countrycodes' => 'kr',
                'accept-language' => 'ko',
                'q' => $address,
            ]);

        $hit = $res->json('0');
        if (!$hit || !isset($hit['lat'], $hit['lon'])) {
            return null;
        }

        return ['lat' => (float) $hit['lat'], 'lng' => (float) $hit['lon']];
    }
}
