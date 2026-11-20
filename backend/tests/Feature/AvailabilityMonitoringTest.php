<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class AvailabilityMonitoringTest extends TestCase
{
    public function test_api_health_endpoint_returns_ok_when_healthy(): void
    {
        Http::fake(['*' => Http::response(['status' => 'ok'], 200)]);

        $response = $this->getJson('/api/health');

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'ok',
            'database' => 'ok',
        ]);
        $this->assertStringContainsString('ok', $response->getContent());
    }

    public function test_monitor_state_machine_triggers_down_after_two_failures_and_up_on_recovery(): void
    {
        $scriptPath = base_path('../infra/monitoring/monitor.py');

        $testCode = <<<'PYEOF'
import sys
import json
from infra.monitoring.monitor import AvailabilityMonitor, HealthCheckResult

monitor = AvailabilityMonitor(url="http://test-server/api/health")

# Probe 1: Fails once
res1 = HealthCheckResult(success=False, status_code=500, contains_keyword=False, latency_ms=12.5, is_maintenance=False, error_message="Internal Server Error")
out1 = monitor.process_probe(res1)

# Probe 2: Fails twice (consecutive) -> Triggers DOWN notification
res2 = HealthCheckResult(success=False, status_code=500, contains_keyword=False, latency_ms=14.0, is_maintenance=False, error_message="Internal Server Error")
out2 = monitor.process_probe(res2)

# Probe 3: Fails third time -> Still down, no duplicate notification
res3 = HealthCheckResult(success=False, status_code=500, contains_keyword=False, latency_ms=13.0, is_maintenance=False, error_message="Internal Server Error")
out3 = monitor.process_probe(res3)

# Probe 4: Recovers -> Triggers UP notification
res4 = HealthCheckResult(success=True, status_code=200, contains_keyword=True, latency_ms=8.2, is_maintenance=False)
out4 = monitor.process_probe(res4)

print(json.dumps({
    "out1": out1,
    "out2": out2,
    "out3": out3,
    "out4": out4,
    "notifications": monitor.notifications_sent,
}))
PYEOF;

        $process = new Process(['python3', '-c', $testCode], base_path('..'));
        $process->run();

        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $data = json_decode($process->getOutput(), true);

        $this->assertSame('FAILED_ONCE', $data['out1']);
        $this->assertSame('TRIGGERED_DOWN', $data['out2']);
        $this->assertSame('FAILED_ONCE', $data['out3']); // already down
        $this->assertSame('TRIGGERED_UP', $data['out4']);

        $this->assertCount(2, $data['notifications']);
        $this->assertSame('[Compliance Platform] Service DOWN', $data['notifications'][0]['subject']);
        $this->assertSame('[Compliance Platform] Service UP', $data['notifications'][1]['subject']);
    }

    public function test_check_during_scheduled_maintenance_window_is_excluded_from_downtime(): void
    {
        $testCode = <<<'PYEOF'
import sys
import json
import datetime
from zoneinfo import ZoneInfo
from infra.monitoring.monitor import is_maintenance_window, AvailabilityMonitor, HealthCheckResult

tz = ZoneInfo("America/Mexico_City")

# Sunday at 02:30 AM (Inside ADR-032 window)
sunday_inside = datetime.datetime(2026, 11, 15, 2, 30, 0, tzinfo=tz)
is_inside = is_maintenance_window(sunday_inside)

# Sunday at 05:00 AM (Outside ADR-032 window)
sunday_outside = datetime.datetime(2026, 11, 15, 5, 0, 0, tzinfo=tz)
is_outside_hour = is_maintenance_window(sunday_outside)

# Monday at 02:30 AM (Wrong day)
monday_wrong_day = datetime.datetime(2026, 11, 16, 2, 30, 0, tzinfo=tz)
is_outside_day = is_maintenance_window(monday_wrong_day)

# In-maintenance probe failure must NOT trigger DOWN notification
monitor = AvailabilityMonitor(url="http://test-server/api/health")
res_maint1 = HealthCheckResult(success=False, status_code=503, contains_keyword=False, latency_ms=10.0, is_maintenance=True)
out_maint1 = monitor.process_probe(res_maint1)

res_maint2 = HealthCheckResult(success=False, status_code=503, contains_keyword=False, latency_ms=10.0, is_maintenance=True)
out_maint2 = monitor.process_probe(res_maint2)

print(json.dumps({
    "is_inside": is_inside,
    "is_outside_hour": is_outside_hour,
    "is_outside_day": is_outside_day,
    "out_maint1": out_maint1,
    "out_maint2": out_maint2,
    "notification_count": len(monitor.notifications_sent),
}))
PYEOF;

        $process = new Process(['python3', '-c', $testCode], base_path('..'));
        $process->run();

        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $data = json_decode($process->getOutput(), true);

        $this->assertTrue($data['is_inside']);
        $this->assertFalse($data['is_outside_hour']);
        $this->assertFalse($data['is_outside_day']);
        $this->assertSame('MAINTENANCE', $data['out_maint1']);
        $this->assertSame('MAINTENANCE', $data['out_maint2']);
        $this->assertSame(0, $data['notification_count']);
    }

    public function test_first_availability_report_exists_and_meets_99_percent_target(): void
    {
        $reportPath = base_path('../docs/operations/availability-2026-11.md');
        $this->assertFileExists($reportPath);

        $content = file_get_contents($reportPath);

        $this->assertStringContainsString('Monthly Availability Report: November 2026', $content);
        $this->assertStringContainsString('99.00%', $content);
        $this->assertStringContainsString('99.94%', $content);
        $this->assertStringContainsString('America/Mexico_City', $content);
        $this->assertStringContainsString('[Compliance Platform] Service DOWN', $content);
        $this->assertStringContainsString('[Compliance Platform] Service UP', $content);
        $this->assertStringContainsString('TARGET MET', $content);
    }

    public function test_docker_compose_configures_uptime_kuma_service_and_volume(): void
    {
        $composePath = base_path('../docker-compose.yml');
        $content = file_get_contents($composePath);

        $this->assertStringContainsString('uptime-kuma:', $content);
        $this->assertStringContainsString('louislam/uptime-kuma', $content);
        $this->assertStringContainsString('uptime_kuma_data:', $content);
    }
}
