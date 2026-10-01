import re
from datetime import timedelta

import pytest
from fastapi.testclient import TestClient

from app.db import connect, get_user_by_email, list_projects, monday
from app.main import app


def csrf_from(html: str) -> str:
    match = re.search(r'name="csrf" value="([^"]+)"', html)
    assert match, html[:400]
    return match.group(1)


def login(client: TestClient, email: str, password: str) -> None:
    page = client.get("/login")
    response = client.post(
        "/login",
        data={
            "csrf": csrf_from(page.text),
            "email": email,
            "password": password,
            "next": "/",
        },
        follow_redirects=False,
    )
    assert response.status_code == 303


@pytest.fixture
def client():
    with TestClient(app) as test_client:
        yield test_client


def user_id(email: str) -> int:
    with connect() as conn:
        return get_user_by_email(conn, email)["id"]


def project_id() -> int:
    with connect() as conn:
        return list_projects(conn, active_only=True)[0]["id"]


def test_employee_cannot_manage_people_or_open_a_lead_sheet(client):
    login(client, "sara@timesheet.local", "employee123")
    dashboard = client.get("/")
    assert "Log your hours" in dashboard.text
    assert client.get("/people", follow_redirects=False).status_code == 303
    lead = user_id("arjun@timesheet.local")
    denied = client.get(f"/timesheet?user_id={lead}", follow_redirects=False)
    assert denied.status_code == 303


def test_lead_can_enter_for_mapped_employee_and_not_for_manager(client):
    login(client, "arjun@timesheet.local", "lead123")
    sara = user_id("sara@timesheet.local")
    priya = user_id("priya@timesheet.local")
    assert client.get(f"/timesheet?user_id={sara}").status_code == 200
    denied = client.get(f"/timesheet?user_id={priya}", follow_redirects=False)
    assert denied.status_code == 303
    page = client.get(f"/timesheet?user_id={sara}")
    assert "Sara Iqbal" in page.text
    assert "Approve hours" in page.text or "Sent" in page.text


def test_manager_sees_nested_reports_and_approves_direct_report(client):
    login(client, "priya@timesheet.local", "manager123")
    home = client.get("/")
    assert "Arjun Mehta" in home.text
    assert "Sara Iqbal" in home.text
    assert "Nina Rao" in home.text
    nina = user_id("nina@timesheet.local")
    page = client.get(f"/timesheet?user_id={nina}")
    assert "Approve" in page.text


def test_submit_and_approve_cycle(client):
    login(client, "dev@timesheet.local", "employee123")
    week = monday(__import__("datetime").date.today()) + timedelta(days=7)
    page = client.get(f"/timesheet?week={week.isoformat()}")
    token = csrf_from(page.text)
    dev = user_id("dev@timesheet.local")
    response = client.post(
        "/timesheet",
        data={
            "csrf": token,
            "user_id": str(dev),
            "week": week.isoformat(),
            "action": "submit",
            "note": "Next week",
            f"h_{project_id()}_{week.isoformat()}": "8",
        },
        follow_redirects=False,
    )
    assert response.status_code == 303
    client.post("/logout", data={"csrf": token}, follow_redirects=False)

    login(client, "arjun@timesheet.local", "lead123")
    approvals = client.get("/approvals")
    assert "Dev Patel" in approvals.text
    review = client.get(f"/timesheet?user_id={dev}&week={week.isoformat()}")
    decision = csrf_from(review.text)
    week_id = re.search(r'name="week_id" value="(\d+)"', review.text).group(1)
    done = client.post(
        "/timesheet/decision",
        data={"csrf": decision, "week_id": week_id, "decision": "approved", "review_note": "OK"},
        follow_redirects=False,
    )
    assert done.status_code == 303
    approved = client.get(f"/timesheet?user_id={dev}&week={week.isoformat()}")
    assert "Approved" in approved.text


def test_admin_adds_project_and_person(client):
    login(client, "admin@timesheet.local", "admin123")
    page = client.get("/projects")
    token = csrf_from(page.text)
    created = client.post(
        "/projects",
        data={"csrf": token, "code": "PRJ-9999", "name": "Pilot", "description": "Trial"},
        follow_redirects=False,
    )
    assert created.status_code == 303
    assert "PRJ-9999" in client.get("/projects").text

    people = client.get("/people")
    token = csrf_from(people.text)
    lead = user_id("arjun@timesheet.local")
    added = client.post(
        "/people",
        data={
            "csrf": token,
            "name": "Leela Nair",
            "email": "leela@timesheet.local",
            "password": "employee123",
            "role": "employee",
            "reports_to_id": str(lead),
        },
        follow_redirects=False,
    )
    assert added.status_code == 303
    assert "Leela Nair" in client.get("/people").text
