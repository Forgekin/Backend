"""Generate a PDF test report from PHPUnit junit.xml + Laravel route list.

Usage: run from project root:
    python storage/test-reports/generate_report.py
"""
from __future__ import annotations

import json
import os
import re
import sys
import xml.etree.ElementTree as ET
from collections import defaultdict
from datetime import datetime
from pathlib import Path

from reportlab.lib import colors
from reportlab.lib.pagesizes import A4, landscape
from reportlab.lib.styles import getSampleStyleSheet, ParagraphStyle
from reportlab.lib.units import mm
from reportlab.platypus import (
    SimpleDocTemplate,
    Paragraph,
    Spacer,
    Table,
    TableStyle,
    PageBreak,
    KeepTogether,
)

ROOT = Path(__file__).resolve().parents[2]
REPORT_DIR = ROOT / "storage" / "test-reports"
JUNIT = REPORT_DIR / "junit.xml"
ROUTES = REPORT_DIR / "routes.json"
OUT_PDF = REPORT_DIR / "endpoint-test-report.pdf"
TESTS_DIR = ROOT / "tests"


# ---------------------------------------------------------------------------
# Parse junit.xml
# ---------------------------------------------------------------------------

def parse_junit(path: Path):
    tree = ET.parse(path)
    root = tree.getroot()
    cases = []

    def walk(node):
        for child in node:
            if child.tag == "testsuite":
                walk(child)
            elif child.tag == "testcase":
                name = child.attrib.get("name", "")
                classname = child.attrib.get("classname", "")
                file_ = child.attrib.get("file", "")
                time = float(child.attrib.get("time", "0") or 0)
                status = "passed"
                message = ""
                for ev in child:
                    if ev.tag in ("failure", "error"):
                        status = "failed" if ev.tag == "failure" else "errored"
                        message = (ev.attrib.get("message") or ev.text or "").strip()
                    elif ev.tag == "skipped":
                        status = "skipped"
                cases.append({
                    "name": name,
                    "classname": classname,
                    "file": file_,
                    "time": time,
                    "status": status,
                    "message": message,
                })

    walk(root)

    totals = root.attrib
    return cases, {
        "tests": int(totals.get("tests", 0)),
        "assertions": int(totals.get("assertions", 0)),
        "failures": int(totals.get("failures", 0)),
        "errors": int(totals.get("errors", 0)),
        "skipped": int(totals.get("skipped", 0)),
        "time": float(totals.get("time", 0)),
    }


# ---------------------------------------------------------------------------
# Parse routes.json
# ---------------------------------------------------------------------------

def parse_routes(path: Path):
    data = json.loads(path.read_text(encoding="utf-8"))
    routes = []
    for r in data:
        uri = r.get("uri", "")
        method = r.get("method", "")
        action = r.get("action", "")
        middleware = r.get("middleware", []) or []
        # split method (sometimes "GET|HEAD")
        methods = [m for m in method.split("|") if m and m != "HEAD"] or [method]
        auth = any("Authenticate" in m for m in middleware)
        perm = next((m for m in middleware if "Permission" in m or "Role" in m), "")
        routes.append({
            "method": methods[0],
            "uri": uri,
            "action": action,
            "auth": auth,
            "perm": perm,
        })
    return routes


# ---------------------------------------------------------------------------
# Scan test files for HTTP calls -> endpoint coverage
# ---------------------------------------------------------------------------

HTTP_CALL_RE = re.compile(
    r"->(?:getJson|postJson|putJson|patchJson|deleteJson|get|post|put|patch|delete|json)\s*\(\s*['\"]([^'\"]+)['\"]",
)
METHOD_MAP = {
    "getJson": "GET", "get": "GET",
    "postJson": "POST", "post": "POST",
    "putJson": "PUT", "put": "PUT",
    "patchJson": "PATCH", "patch": "PATCH",
    "deleteJson": "DELETE", "delete": "DELETE",
}


def scan_test_http_calls():
    """Return {(METHOD, normalized_uri): [test_files...]} — best-effort."""
    coverage = defaultdict(set)
    for tf in TESTS_DIR.rglob("*.php"):
        text = tf.read_text(encoding="utf-8", errors="ignore")
        for line in text.splitlines():
            m_call = re.search(r"->(getJson|postJson|putJson|patchJson|deleteJson|get|post|put|patch|delete|json)\s*\(\s*['\"]([^'\"]+)['\"]", line)
            if not m_call:
                continue
            verb, uri = m_call.group(1), m_call.group(2)
            if verb == "json":
                # ->json('POST', '/api/...')
                m2 = re.search(r"->json\s*\(\s*['\"](GET|POST|PUT|PATCH|DELETE)['\"]\s*,\s*['\"]([^'\"]+)['\"]", line)
                if not m2:
                    continue
                method, uri = m2.group(1), m2.group(2)
            else:
                method = METHOD_MAP.get(verb)
            if not method or not uri:
                continue
            coverage[(method, uri)].add(tf.name)
    return coverage


def normalize_uri(uri: str) -> str:
    """Strip leading / and api/ prefix, collapse {param} variants."""
    u = uri.strip("/")
    u = re.sub(r"\{[^}]+\}", "{x}", u)
    return u


def match_coverage(routes, coverage):
    """For each route, find tests whose URI shape matches it."""
    # Build normalized map of test calls
    test_map = defaultdict(set)
    for (method, uri), files in coverage.items():
        key = (method.upper(), normalize_uri(uri))
        test_map[key].update(files)

    per_route = []
    for r in routes:
        key = (r["method"].upper(), normalize_uri(r["uri"]))
        files = test_map.get(key, set())
        per_route.append({**r, "test_files": sorted(files)})
    return per_route


# ---------------------------------------------------------------------------
# Build PDF
# ---------------------------------------------------------------------------

def wrap(txt, style):
    return Paragraph(str(txt).replace("<", "&lt;").replace(">", "&gt;"), style)


def build_pdf(cases, totals, per_route):
    doc = SimpleDocTemplate(
        str(OUT_PDF),
        pagesize=A4,
        leftMargin=12 * mm,
        rightMargin=12 * mm,
        topMargin=15 * mm,
        bottomMargin=15 * mm,
        title="ForgeKin Backend — Endpoint Test Report",
    )

    styles = getSampleStyleSheet()
    h1 = ParagraphStyle("h1", parent=styles["Heading1"], fontSize=18, spaceAfter=8)
    h2 = ParagraphStyle("h2", parent=styles["Heading2"], fontSize=13, spaceAfter=6, textColor=colors.HexColor("#1a365d"))
    normal = styles["BodyText"]
    small = ParagraphStyle("sm", parent=styles["BodyText"], fontSize=8, leading=10)
    mono = ParagraphStyle("mono", parent=styles["BodyText"], fontName="Courier", fontSize=8, leading=10)
    tiny = ParagraphStyle("tiny", parent=styles["BodyText"], fontSize=7, leading=9)

    story = []

    # Title block
    story.append(Paragraph("ForgeKin Backend — Endpoint Test Report", h1))
    story.append(Paragraph(
        f"Generated {datetime.now().strftime('%Y-%m-%d %H:%M')} · PHPUnit suite run on in-memory SQLite",
        normal,
    ))
    story.append(Spacer(1, 6 * mm))

    # Summary
    covered = sum(1 for r in per_route if r["test_files"])
    covered_pct = (covered / len(per_route) * 100) if per_route else 0
    pass_ct = sum(1 for c in cases if c["status"] == "passed")
    fail_ct = sum(1 for c in cases if c["status"] in ("failed", "errored"))
    skip_ct = sum(1 for c in cases if c["status"] == "skipped")

    summary_rows = [
        ["Metric", "Value"],
        ["Total tests executed", str(totals["tests"])],
        ["Assertions", str(totals["assertions"])],
        ["Passed", str(pass_ct)],
        ["Failed / Errored", str(fail_ct)],
        ["Skipped", str(skip_ct)],
        ["Duration (s)", f"{totals['time']:.2f}"],
        ["Total routes", str(len(per_route))],
        ["Routes with direct test coverage", f"{covered} ({covered_pct:.0f}%)"],
        ["Routes without direct test coverage", str(len(per_route) - covered)],
    ]
    t = Table(summary_rows, colWidths=[80 * mm, 60 * mm])
    t.setStyle(TableStyle([
        ("BACKGROUND", (0, 0), (-1, 0), colors.HexColor("#1a365d")),
        ("TEXTCOLOR", (0, 0), (-1, 0), colors.white),
        ("GRID", (0, 0), (-1, -1), 0.3, colors.grey),
        ("FONTNAME", (0, 0), (-1, 0), "Helvetica-Bold"),
        ("FONTSIZE", (0, 0), (-1, -1), 9),
        ("ROWBACKGROUNDS", (0, 1), (-1, -1), [colors.whitesmoke, colors.white]),
        ("VALIGN", (0, 0), (-1, -1), "MIDDLE"),
    ]))
    story.append(KeepTogether([Paragraph("Summary", h2), t]))
    story.append(Spacer(1, 6 * mm))

    # Endpoint coverage table
    story.append(PageBreak())
    story.append(Paragraph("Endpoint Coverage Matrix", h2))
    story.append(Paragraph(
        "Each route is matched against HTTP calls made in tests/ (same method + URI shape). "
        "Covered = a test calls this exact route. Uncovered routes may still be indirectly exercised.",
        tiny,
    ))
    story.append(Spacer(1, 3 * mm))

    header = ["Method", "URI", "Auth", "Permission / Role", "Tests"]
    rows = [header]
    for r in per_route:
        status = "✓" if r["test_files"] else "—"
        perm = r["perm"]
        perm_disp = perm.split(":")[-1] if ":" in perm else perm
        rows.append([
            r["method"],
            wrap(r["uri"], mono),
            "yes" if r["auth"] else "no",
            wrap(perm_disp or "—", small),
            wrap(f"{status} {len(r['test_files'])}", small),
        ])
    tbl = Table(rows, colWidths=[16 * mm, 78 * mm, 12 * mm, 50 * mm, 20 * mm], repeatRows=1)
    style_cmds = [
        ("BACKGROUND", (0, 0), (-1, 0), colors.HexColor("#1a365d")),
        ("TEXTCOLOR", (0, 0), (-1, 0), colors.white),
        ("GRID", (0, 0), (-1, -1), 0.25, colors.grey),
        ("FONTNAME", (0, 0), (-1, 0), "Helvetica-Bold"),
        ("FONTSIZE", (0, 0), (-1, -1), 8),
        ("VALIGN", (0, 0), (-1, -1), "TOP"),
    ]
    for i, r in enumerate(per_route, start=1):
        if not r["test_files"]:
            style_cmds.append(("BACKGROUND", (0, i), (-1, i), colors.HexColor("#fff5f5")))
        else:
            style_cmds.append(("BACKGROUND", (0, i), (-1, i), colors.HexColor("#f0fff4")))
    tbl.setStyle(TableStyle(style_cmds))
    story.append(tbl)

    # Per-file test results
    story.append(PageBreak())
    story.append(Paragraph("Test Results by File", h2))

    by_file = defaultdict(list)
    for c in cases:
        fname = os.path.basename(c["file"]) if c["file"] else c["classname"]
        by_file[fname].append(c)

    for fname in sorted(by_file):
        tests = by_file[fname]
        passed = sum(1 for x in tests if x["status"] == "passed")
        failed = sum(1 for x in tests if x["status"] in ("failed", "errored"))
        total_time = sum(x["time"] for x in tests)
        header_p = Paragraph(
            f"<b>{fname}</b> — {len(tests)} tests · {passed} passed · {failed} failed · {total_time:.2f}s",
            normal,
        )
        trows = [["Test", "Status", "Time (s)"]]
        for c in tests:
            trows.append([
                wrap(c["name"].replace("test_", "").replace("_", " "), small),
                c["status"],
                f"{c['time']:.3f}",
            ])
        tt = Table(trows, colWidths=[125 * mm, 25 * mm, 25 * mm], repeatRows=1)
        cmds = [
            ("BACKGROUND", (0, 0), (-1, 0), colors.HexColor("#2d3748")),
            ("TEXTCOLOR", (0, 0), (-1, 0), colors.white),
            ("GRID", (0, 0), (-1, -1), 0.2, colors.lightgrey),
            ("FONTNAME", (0, 0), (-1, 0), "Helvetica-Bold"),
            ("FONTSIZE", (0, 0), (-1, -1), 8),
            ("VALIGN", (0, 0), (-1, -1), "TOP"),
        ]
        for i, c in enumerate(tests, start=1):
            if c["status"] == "passed":
                cmds.append(("TEXTCOLOR", (1, i), (1, i), colors.HexColor("#22543d")))
            elif c["status"] in ("failed", "errored"):
                cmds.append(("BACKGROUND", (0, i), (-1, i), colors.HexColor("#fff5f5")))
                cmds.append(("TEXTCOLOR", (1, i), (1, i), colors.HexColor("#c53030")))
            elif c["status"] == "skipped":
                cmds.append(("TEXTCOLOR", (1, i), (1, i), colors.HexColor("#975a16")))
        tt.setStyle(TableStyle(cmds))
        story.append(KeepTogether([header_p, Spacer(1, 1 * mm), tt, Spacer(1, 4 * mm)]))

    # Failures detail
    failures = [c for c in cases if c["status"] in ("failed", "errored")]
    if failures:
        story.append(PageBreak())
        story.append(Paragraph("Failures & Errors", h2))
        for c in failures:
            story.append(Paragraph(f"<b>{c['classname']}::{c['name']}</b>", normal))
            story.append(Paragraph(f"<font face='Courier' size='8'>{c['message'][:2000]}</font>", small))
            story.append(Spacer(1, 3 * mm))

    # Footer page with methodology
    story.append(PageBreak())
    story.append(Paragraph("Methodology", h2))
    bullets = [
        "Test suite: PHPUnit 11 executed via vendor/phpunit/phpunit/phpunit with JUnit XML logging.",
        "Database: in-memory SQLite (phpunit.xml env overrides). No hits to the production MySQL.",
        "Mail: array driver. Queue: sync. Cache: array.",
        "Endpoint coverage is computed by regex-scanning tests/ for HTTP verb calls "
        "(get/post/put/patch/delete/*Json/json) and matching normalized URIs against php artisan route:list.",
        "'Uncovered' means no direct HTTP call was found in a test. The route may still be "
        "exercised indirectly (e.g. through a related flow).",
    ]
    for b in bullets:
        story.append(Paragraph("• " + b, small))

    doc.build(story)
    print(f"Wrote {OUT_PDF}")


def main():
    cases, totals = parse_junit(JUNIT)
    routes = parse_routes(ROUTES)
    cov = scan_test_http_calls()
    per_route = match_coverage(routes, cov)
    build_pdf(cases, totals, per_route)


if __name__ == "__main__":
    main()
