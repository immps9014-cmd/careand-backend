<?php

namespace Tests\Feature;

use App\Models\AnomalyAlert;
use App\Models\HealthTimeseries;
use App\Models\Senior;
use App\Models\VitalRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\AuthHelpers;
use Tests\TestCase;

class HealthTest extends TestCase
{
    use RefreshDatabase, AuthHelpers;

    public function test_guardian_can_record_vitals_for_own_senior(): void
    {
        [, $guardian, $token] = $this->createGuardianUser();
        $senior = $this->createSeniorFor($guardian);

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/v1/seniors/{$senior->id}/vitals", [
                'blood_pressure_sys' => 120,
                'blood_pressure_dia' => 80,
                'heart_rate' => 72,
                'measured_at' => now()->toIso8601String(),
            ]);

        $response->assertStatus(201)->assertJson(['success' => true]);
        $this->assertDatabaseCount('vital_records', 1);
    }

    public function test_guardian_cannot_record_vitals_for_other_senior(): void
    {
        [, , $token] = $this->createGuardianUser();
        [, $otherGuardian] = $this->createGuardianUser();
        $foreignSenior = $this->createSeniorFor($otherGuardian);

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/v1/seniors/{$foreignSenior->id}/vitals", [
                'blood_pressure_sys' => 120,
                'measured_at' => now()->toIso8601String(),
            ]);

        $response->assertStatus(403);
    }

    public function test_vitals_index_returns_records_in_descending_time(): void
    {
        [, $guardian, $token] = $this->createGuardianUser();
        $senior = $this->createSeniorFor($guardian);
        VitalRecord::create(['senior_id' => $senior->id, 'heart_rate' => 70, 'measured_at' => now()->subDays(2)]);
        VitalRecord::create(['senior_id' => $senior->id, 'heart_rate' => 75, 'measured_at' => now()]);

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/v1/seniors/{$senior->id}/vitals");

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(2, $data);
        $this->assertEquals(75, $data[0]['heart_rate']);
    }

    public function test_validation_rejects_out_of_range_blood_pressure(): void
    {
        [, $guardian, $token] = $this->createGuardianUser();
        $senior = $this->createSeniorFor($guardian);

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/v1/seniors/{$senior->id}/vitals", [
                'blood_pressure_sys' => 999,
                'measured_at' => now()->toIso8601String(),
            ]);

        $response->assertStatus(422);
    }

    public function test_timeseries_returns_metric_points(): void
    {
        [, $guardian, $token] = $this->createGuardianUser();
        $senior = $this->createSeniorFor($guardian);
        HealthTimeseries::create(['senior_id' => $senior->id, 'metric_name' => 'meal_pct', 'value' => 80, 'recorded_at' => now()]);
        HealthTimeseries::create(['senior_id' => $senior->id, 'metric_name' => 'meal_pct', 'value' => 65, 'recorded_at' => now()->subDay()]);

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/v1/seniors/{$senior->id}/health-timeseries?metric=meal_pct&days=7");

        $response->assertStatus(200)->assertJsonPath('metric', 'meal_pct');
        $this->assertCount(2, $response->json('data'));
    }

    public function test_anomaly_alert_can_be_resolved(): void
    {
        [, $guardian, $token] = $this->createGuardianUser();
        $senior = $this->createSeniorFor($guardian);
        $alert = AnomalyAlert::create([
            'senior_id' => $senior->id,
            'risk_type' => 'nutrition',
            'risk_score' => 78,
            'severity' => 'high',
            'trigger_pattern' => ['식사량 저하'],
            'status' => 'new',
            'detected_at' => now(),
        ]);

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/v1/anomaly-alerts/{$alert->id}/resolve", ['resolution_note' => '의료진 상담 완료']);

        $response->assertStatus(200);
        $this->assertEquals('resolved', $alert->fresh()->status);
        $this->assertNotNull($alert->fresh()->resolved_at);
    }

    public function test_anomaly_alerts_filter_by_severity(): void
    {
        [, $guardian, $token] = $this->createGuardianUser();
        $senior = $this->createSeniorFor($guardian);
        AnomalyAlert::create(['senior_id' => $senior->id, 'risk_type' => 'fall', 'risk_score' => 30, 'severity' => 'low', 'trigger_pattern' => [], 'status' => 'new', 'detected_at' => now()]);
        AnomalyAlert::create(['senior_id' => $senior->id, 'risk_type' => 'fall', 'risk_score' => 90, 'severity' => 'critical', 'trigger_pattern' => [], 'status' => 'new', 'detected_at' => now()]);

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/v1/anomaly-alerts?severity=critical');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals('critical', $data[0]['severity']);
    }
}
