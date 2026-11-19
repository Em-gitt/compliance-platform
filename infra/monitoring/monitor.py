#!/usr/bin/env python3
"""
Availability Monitoring Service & CLI (CMP-42 / ADR-032 / NFR-012)
Monitors https://<domain>/api/health every 60s, checks for HTTP 200 and 'ok',
handles maintenance windows (Sunday 02:00-04:00 America/Mexico_City),
and dispatches DOWN/UP alert emails.
"""

from __future__ import annotations

import argparse
import datetime
import json
import os
import smtplib
from email.mime.text import MIMEText
import sys
import urllib.error
import urllib.request
from zoneinfo import ZoneInfo

MEXICO_CITY_TZ = ZoneInfo("America/Mexico_City")

DOWN_SUBJECT = "[Compliance Platform] Service DOWN"
UP_SUBJECT = "[Compliance Platform] Service UP"
MAINTENANCE_WINDOW_DAY = 6  # Sunday in datetime.weekday() (0=Monday, 6=Sunday)
MAINTENANCE_START_HOUR = 2  # 02:00
MAINTENANCE_END_HOUR = 4    # 04:00


def is_maintenance_window(dt: datetime.datetime | None = None) -> bool:
    """
    Check if a datetime falls within the ADR-032 maintenance window:
    Every Sunday between 02:00 and 04:00 America/Mexico_City.
    """
    if dt is None:
        dt = datetime.datetime.now(tz=MEXICO_CITY_TZ)
    elif dt.tzinfo is None:
        dt = dt.replace(tzinfo=datetime.timezone.utc).astimezone(MEXICO_CITY_TZ)
    else:
        dt = dt.astimezone(MEXICO_CITY_TZ)

    if dt.weekday() != MAINTENANCE_WINDOW_DAY:
        return False

    start = datetime.time(MAINTENANCE_START_HOUR, 0, 0)
    end = datetime.time(MAINTENANCE_END_HOUR, 0, 0)
    return start <= dt.time() < end


def calculate_availability(
    total_minutes: float,
    unscheduled_downtime_minutes: float,
    scheduled_maintenance_minutes: float = 0.0,
) -> float:
    """
    Calculate availability percentage using the formula from ADR-032:
    Availability (%) = (1 - (Unscheduled Downtime / (Total - Scheduled Maintenance))) * 100%
    """
    effective_minutes = total_minutes - scheduled_maintenance_minutes
    if effective_minutes <= 0:
        return 100.0
    availability = (1.0 - (unscheduled_downtime_minutes / effective_minutes)) * 100.0
    return max(0.0, min(100.0, round(availability, 4)))


class HealthCheckResult:
    def __init__(
        self,
        success: bool,
        status_code: int | None,
        contains_keyword: bool,
        latency_ms: float,
        is_maintenance: bool = False,
        error_message: str | None = None,
    ):
        self.success = success
        self.status_code = status_code
        self.contains_keyword = contains_keyword
        self.latency_ms = latency_ms
        self.is_maintenance = is_maintenance
        self.error_message = error_message

    def to_dict(self) -> dict:
        return {
            "success": self.success,
            "status_code": self.status_code,
            "contains_keyword": self.contains_keyword,
            "latency_ms": self.latency_ms,
            "is_maintenance": self.is_maintenance,
            "error_message": self.error_message,
        }


def probe_health_endpoint(
    url: str,
    timeout_seconds: float = 10.0,
    expected_keyword: str = "ok",
    dt: datetime.datetime | None = None,
) -> HealthCheckResult:
    """
    Perform a single HTTP probe against the health check endpoint.
    Expects HTTP 200 and expected_keyword in the response body.
    """
    in_maintenance = is_maintenance_window(dt)
    start_time = datetime.datetime.now()

    req = urllib.request.Request(
        url,
        headers={"User-Agent": "CompliancePlatform-AvailabilityMonitor/1.0", "Accept": "application/json"},
    )

    try:
        with urllib.request.urlopen(req, timeout=timeout_seconds) as response:
            latency_ms = (datetime.datetime.now() - start_time).total_seconds() * 1000.0
            status_code = response.status
            body = response.read().decode("utf-8", errors="replace")
            contains_keyword = expected_keyword in body

            success = (status_code == 200) and contains_keyword
            return HealthCheckResult(
                success=success,
                status_code=status_code,
                contains_keyword=contains_keyword,
                latency_ms=round(latency_ms, 2),
                is_maintenance=in_maintenance,
                error_message=None if success else f"Keyword '{expected_keyword}' not found in body",
            )
    except urllib.error.HTTPError as e:
        latency_ms = (datetime.datetime.now() - start_time).total_seconds() * 1000.0
        return HealthCheckResult(
            success=False,
            status_code=e.code,
            contains_keyword=False,
            latency_ms=round(latency_ms, 2),
            is_maintenance=in_maintenance,
            error_message=f"HTTP {e.code}: {e.reason}",
        )
    except Exception as e:
        latency_ms = (datetime.datetime.now() - start_time).total_seconds() * 1000.0
        return HealthCheckResult(
            success=False,
            status_code=None,
            contains_keyword=False,
            latency_ms=round(latency_ms, 2),
            is_maintenance=in_maintenance,
            error_message=str(e),
        )


class AvailabilityMonitor:
    def __init__(
        self,
        url: str,
        smtp_host: str | None = None,
        smtp_port: int = 1025,
        alert_email_to: str = "team@compliance-platform.local",
        alert_email_from: str = "alerts@compliance-platform.local",
    ):
        self.url = url
        self.smtp_host = smtp_host
        self.smtp_port = smtp_port
        self.alert_email_to = alert_email_to
        self.alert_email_from = alert_email_from
        self.consecutive_failures = 0
        self.is_down = False
        self.notifications_sent: list[dict] = []

    def record_notification(self, subject: str, body: str) -> None:
        self.notifications_sent.append({
            "subject": subject,
            "body": body,
            "timestamp": datetime.datetime.now(tz=datetime.timezone.utc).isoformat(),
        })

        if not self.smtp_host:
            return

        try:
            msg = MIMEText(body)
            msg["Subject"] = subject
            msg["From"] = self.alert_email_from
            msg["To"] = self.alert_email_to

            with smtplib.SMTP(self.smtp_host, self.smtp_port, timeout=10) as server:
                server.send_message(msg)
        except Exception as e:
            sys.stderr.write(f"Warning: Failed to send alert email: {e}\n")

    def process_probe(self, result: HealthCheckResult) -> str:
        """
        Processes probe result and transitions state:
        - If maintenance: no downtime count or DOWN alert.
        - If failed twice: triggers DOWN email.
        - If recovered: triggers UP email.
        Returns: 'OK', 'MAINTENANCE', 'FAILED_ONCE', 'TRIGGERED_DOWN', 'TRIGGERED_UP'
        """
        if result.is_maintenance:
            # During maintenance, failures are expected and do not trigger emergency outage alerts
            return "MAINTENANCE"

        if result.success:
            self.consecutive_failures = 0
            if self.is_down:
                self.is_down = False
                subject = UP_SUBJECT
                body = (
                    f"Notice: Service recovered.\n"
                    f"Target: {self.url}\n"
                    f"Latency: {result.latency_ms}ms\n"
                    f"Status: UP"
                )
                self.record_notification(subject, body)
                return "TRIGGERED_UP"
            return "OK"
        else:
            self.consecutive_failures += 1
            if self.consecutive_failures >= 2 and not self.is_down:
                self.is_down = True
                subject = DOWN_SUBJECT
                body = (
                    f"Alert: Service health check failed 2 consecutive times.\n"
                    f"Target: {self.url}\n"
                    f"Status code: {result.status_code}\n"
                    f"Error: {result.error_message}\n"
                    f"Status: DOWN"
                )
                self.record_notification(subject, body)
                return "TRIGGERED_DOWN"
            return "FAILED_ONCE"


def main() -> None:
    parser = argparse.ArgumentParser(description="Availability Monitor CLI")
    subparsers = parser.add_subparsers(dest="command")

    check_parser = subparsers.add_parser("check")
    check_parser.add_argument("--url", default="http://localhost:8000/api/health")
    check_parser.add_argument("--keyword", default="ok")
    check_parser.add_argument("--timeout", type=float, default=10.0)

    calc_parser = subparsers.add_parser("calculate")
    calc_parser.add_argument("--total-min", type=float, required=True)
    calc_parser.add_argument("--downtime-min", type=float, required=True)
    calc_parser.add_argument("--maint-min", type=float, default=0.0)

    args = parser.parse_args()

    if args.command == "check":
        result = probe_health_endpoint(args.url, timeout_seconds=args.timeout, expected_keyword=args.keyword)
        print(json.dumps(result.to_dict(), indent=2))
        sys.exit(0 if result.success else 1)
    elif args.command == "calculate":
        pct = calculate_availability(args.total_min, args.downtime_min, args.maint_min)
        print(f"Availability: {pct:.4f}%")
        print(f"Target met (>=99.0%): {'YES' if pct >= 99.0 else 'NO'}")
    else:
        parser.print_help()


if __name__ == "__main__":
    main()
