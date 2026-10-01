import secrets
from contextlib import asynccontextmanager
from datetime import date, datetime, timedelta
from pathlib import Path
from urllib.parse import quote

from fastapi import FastAPI, Request
from fastapi.responses import RedirectResponse
from fastapi.staticfiles import StaticFiles
from fastapi.templating import Jinja2Templates
from starlette.middleware.sessions import SessionMiddleware

from app.db import (
    DATA_DIR,
    DEMO_ACCOUNTS,
    AppError,
    can_manage_time,
    connect,
    create_project,
    create_user,
    decide_week,
    delete_project,
    delete_user,
    get_or_create_week,
    get_project,
    get_user,
    get_user_by_email,
    get_week,
    get_week_by_id,
    init_db,
    list_entries,
    list_projects,
    list_users,
    monday,
    pending_count,
    pending_weeks,
    people_for_entry,
    recent_weeks,
    save_week,
    submit_week,
    team_tree,
    total_hours,
    update_project,
    update_user,
    week_days,
    week_summaries,
)
from app.roles import REPORTING_TARGETS, ROLE_LABELS, ROLE_ORDER, STATUS_LABELS
from app.security import verify_password

ROOT = Path(__file__).resolve().parent.parent
templates = Jinja2Templates(directory=str(ROOT / "templates"))


def format_hours(value) -> str:
    if value is None:
        return "0"
    number = float(value)
    if abs(number - round(number)) < 0.001:
        return str(int(round(number)))
    return f"{number:.2f}".rstrip("0").rstrip(".")


def format_when(value) -> str:
    if not value:
        return ""
    try:
        parsed = datetime.fromisoformat(value)
    except ValueError:
        return value
    return parsed.strftime("%-d %b %Y, %H:%M")


def week_label(week_start: date) -> str:
    end = week_start + timedelta(days=6)
    if week_start.year == end.year:
        if week_start.month == end.month:
            return f"{week_start.strftime('%-d')}–{end.strftime('%-d %b %Y')}"
        return f"{week_start.strftime('%-d %b')} – {end.strftime('%-d %b %Y')}"
    return f"{week_start.strftime('%-d %b %Y')} – {end.strftime('%-d %b %Y')}"


def format_week(value) -> str:
    if isinstance(value, str):
        value = date.fromisoformat(value)
    return week_label(value)


templates.env.filters["hours"] = format_hours
templates.env.filters["when"] = format_when
templates.env.filters["role"] = lambda role: ROLE_LABELS.get(role, role)
templates.env.filters["status"] = lambda status: STATUS_LABELS.get(status, "Not started")
templates.env.filters["week"] = format_week


def load_secret() -> str:
    DATA_DIR.mkdir(parents=True, exist_ok=True)
    path = DATA_DIR / "secret.key"
    if not path.exists():
        path.write_text(secrets.token_hex(32))
    return path.read_text().strip()


@asynccontextmanager
async def lifespan(_app: FastAPI):
    init_db()
    yield


app = FastAPI(title="Timesheet", docs_url=None, redoc_url=None, lifespan=lifespan)
app.add_middleware(
    SessionMiddleware,
    secret_key=load_secret(),
    session_cookie="timesheet_session",
    max_age=60 * 60 * 24 * 14,
    same_site="lax",
    https_only=False,
)
app.mount("/static", StaticFiles(directory=str(ROOT / "static")), name="static")


def flash(request: Request, message: str, kind: str = "ok") -> None:
    request.session["flash"] = {"message": message, "kind": kind}


def pop_flash(request: Request):
    return request.session.pop("flash", None)


def ensure_csrf(request: Request) -> str:
    token = request.session.get("csrf")
    if not token:
        token = secrets.token_urlsafe(32)
        request.session["csrf"] = token
    return token


def csrf_ok(request: Request, token: str) -> bool:
    return bool(token) and token == request.session.get("csrf")


def safe_next(value: str | None) -> str:
    if not value or not value.startswith("/") or value.startswith("//"):
        return "/"
    return value


def redirect(url: str) -> RedirectResponse:
    return RedirectResponse(url, status_code=303)


def current_user(request: Request):
    user_id = request.session.get("user_id")
    if not user_id:
        return None
    with connect() as conn:
        user = get_user(conn, int(user_id))
    if not user:
        request.session.clear()
        return None
    return user


def login_redirect(request: Request) -> RedirectResponse:
    target = request.url.path
    if request.url.query:
        target = f"{target}?{request.url.query}"
    return redirect(f"/login?next={quote(target)}")


def render(request: Request, name: str, user, **extra):
    pending = 0
    if user and user["role"] in ("admin", "project_manager", "project_lead"):
        with connect() as conn:
            pending = pending_count(conn, user)
    context = {
        "request": request,
        "user": user,
        "title": extra.pop("title", "Timesheet"),
        "eyebrow": extra.pop("eyebrow", ""),
        "active": extra.pop("active", ""),
        "flash": pop_flash(request),
        "csrf": ensure_csrf(request),
        "role_labels": ROLE_LABELS,
        "status_labels": STATUS_LABELS,
        "pending_count": pending,
        "role_order": ROLE_ORDER,
        **extra,
    }
    return templates.TemplateResponse(request, name, context)


def form_value(form, name: str, default: str = "") -> str:
    value = form.get(name, default)
    if value is None:
        return default
    return str(value).strip()


def parse_week(value: str | None) -> date:
    if not value:
        return monday(date.today())
    try:
        return monday(date.fromisoformat(value))
    except ValueError:
        return monday(date.today())


def day_meta(days: list[date]):
    today = date.today()
    return [
        {
            "iso": day.isoformat(),
            "dow": day.strftime("%a"),
            "label": day.strftime("%-d %b"),
            "weekend": day.weekday() >= 5,
            "today": day == today,
        }
        for day in days
    ]


def parse_hours(raw: str):
    if raw is None or str(raw).strip() == "":
        return 0.0
    try:
        value = float(str(raw).strip())
    except ValueError as exc:
        raise AppError("Hours must be a number.") from exc
    if value < 0 or value > 24:
        raise AppError("Hours for one cell must be between 0 and 24.")
    quarters = round(value * 4)
    if abs(value * 4 - quarters) > 0.01:
        raise AppError("Enter hours in 15-minute steps, such as 7.5 or 8.")
    return quarters / 4


def collect_entries(form, projects, days):
    entries = []
    daily = {day.isoformat(): 0.0 for day in days}
    project_ids = {project["id"] for project in projects}
    for key, raw in form.multi_items():
        if not key.startswith("h_"):
            continue
        try:
            _, project_text, work_date = key.split("_", 2)
            project_id = int(project_text)
            date.fromisoformat(work_date)
        except (ValueError, IndexError):
            continue
        if project_id not in project_ids or work_date not in daily:
            continue
        hours = parse_hours(str(raw))
        if hours == 0:
            continue
        daily[work_date] += hours
        entries.append((project_id, work_date, hours))
    for work_date, total in daily.items():
        if total > 24:
            raise AppError(f"Hours on {work_date} add up to more than 24.")
    return entries


def timesheet_url(user_id: int, week_start: date) -> str:
    return f"/timesheet?user_id={user_id}&week={week_start.isoformat()}"


@app.get("/login")
def login_page(request: Request, next: str = "/"):
    user = current_user(request)
    if user:
        return redirect(safe_next(next))
    return render(
        request,
        "login.html",
        None,
        title="Sign in",
        next_url=safe_next(next),
        demo_accounts=DEMO_ACCOUNTS,
    )


@app.post("/login")
async def login_submit(request: Request):
    form = await request.form()
    if not csrf_ok(request, form_value(form, "csrf")):
        flash(request, "The form expired. Try signing in again.", "error")
        return redirect("/login")
    email = form_value(form, "email").lower()
    password = form.get("password") or ""
    password = str(password)
    with connect() as conn:
        user = get_user_by_email(conn, email)
    if not user or not verify_password(password, user["password_hash"]):
        flash(request, "Email or password is incorrect.", "error")
        return redirect("/login")
    request.session["user_id"] = user["id"]
    return redirect(safe_next(form_value(form, "next", "/")))


@app.post("/logout")
async def logout(request: Request):
    form = await request.form()
    if csrf_ok(request, form_value(form, "csrf")):
        request.session.clear()
    return redirect("/login")


@app.get("/")
def dashboard(request: Request):
    user = current_user(request)
    if not user:
        return login_redirect(request)
    week_start = monday(date.today())
    month_start = date(date.today().year, date.today().month, 1)
    if month_start.month == 12:
        month_end = date(month_start.year + 1, 1, 1)
    else:
        month_end = date(month_start.year, month_start.month + 1, 1)
    with connect() as conn:
        own_week = get_week(conn, user["id"], week_start)
        own_entries = list_entries(conn, own_week["id"]) if own_week else []
        own_hours = sum(entry["hours"] for entry in own_entries)
        manager = get_user(conn, user["reports_to_id"]) if user["reports_to_id"] else None
        history = recent_weeks(conn, user["id"])
        tree = team_tree(conn, user["id"]) if user["role"] in ("project_manager", "project_lead") else []
        team_ids = [node["user"]["id"] for node in tree]
        summaries = week_summaries(conn, team_ids + [user["id"]], week_start)
        team_hours = sum(summaries.get(user_id, {}).get("hours", 0) for user_id in team_ids)
        if user["role"] == "admin":
            waiting = pending_weeks(conn, None)
        elif user["role"] in ("project_manager", "project_lead"):
            waiting = pending_weeks(conn, user["id"])
        else:
            waiting = []
        stats = {
            "people": len(list_users(conn)),
            "projects": len(list_projects(conn, active_only=True)),
            "week_hours": total_hours(conn, week_start, week_start + timedelta(days=7)),
            "month_hours": total_hours(conn, month_start, month_end),
        }
    return render(
        request,
        "dashboard.html",
        user,
        title="Dashboard",
        eyebrow=ROLE_LABELS[user["role"]],
        active="dashboard",
        week_start=week_start,
        week_label=week_label(week_start),
        own_week=own_week,
        own_hours=own_hours,
        manager=manager,
        history=history,
        tree=tree,
        summaries=summaries,
        team_hours=team_hours,
        waiting=waiting[:6],
        stats=stats,
    )


@app.get("/timesheet")
def timesheet_page(request: Request, user_id: int | None = None, week: str | None = None):
    actor = current_user(request)
    if not actor:
        return login_redirect(request)
    week_start = parse_week(week)
    target_id = user_id or actor["id"]
    with connect() as conn:
        target = get_user(conn, target_id)
        if not target or not can_manage_time(conn, actor, target):
            flash(request, "You cannot open that timesheet.", "error")
            return redirect("/")
        people = people_for_entry(conn, actor)
        week_row = get_week(conn, target["id"], week_start)
        entries = list_entries(conn, week_row["id"]) if week_row else []
        entry_project_ids = {entry["project_id"] for entry in entries}
        projects = [
            project
            for project in list_projects(conn)
            if project["active"] or project["id"] in entry_project_ids
        ]
        manager = get_user(conn, target["reports_to_id"]) if target["reports_to_id"] else None
        reviewer = get_user(conn, week_row["reviewer_id"]) if week_row and week_row["reviewer_id"] else None
        reviewed_by = get_user(conn, week_row["reviewed_by_id"]) if week_row and week_row["reviewed_by_id"] else None
    days = week_days(week_start)
    grid = {(entry["project_id"], entry["work_date"]): entry["hours"] for entry in entries}
    daily = {day.isoformat(): 0.0 for day in days}
    project_totals = {project["id"]: 0.0 for project in projects}
    for entry in entries:
        daily[entry["work_date"]] = daily.get(entry["work_date"], 0) + entry["hours"]
        project_totals[entry["project_id"]] = project_totals.get(entry["project_id"], 0) + entry["hours"]
    locked = bool(week_row and week_row["status"] in ("submitted", "approved"))
    editable = not locked
    is_approver = actor["id"] != target["id"] and (
        actor["role"] == "admin" or target["reports_to_id"] == actor["id"]
    )
    can_decide = bool(
        week_row
        and week_row["status"] == "submitted"
        and actor["id"] != target["id"]
        and (actor["role"] == "admin" or week_row["reviewer_id"] == actor["id"])
    )
    return render(
        request,
        "timesheet.html",
        actor,
        title=f"Timesheet · {week_label(week_start)}",
        eyebrow="Timesheet" if actor["id"] == target["id"] else "Entering for someone else",
        active="timesheet",
        target=target,
        people=people,
        projects=projects,
        week_start=week_start,
        week_iso=week_start.isoformat(),
        week_text=week_label(week_start),
        prev_week=(week_start - timedelta(days=7)).isoformat(),
        next_week=(week_start + timedelta(days=7)).isoformat(),
        is_current=week_start == monday(date.today()),
        days=day_meta(days),
        grid=grid,
        daily=daily,
        project_totals=project_totals,
        week_total=sum(daily.values()),
        week_row=week_row,
        manager=manager,
        reviewer=reviewer,
        reviewed_by=reviewed_by,
        editable=editable,
        is_approver=is_approver,
        can_decide=can_decide,
        on_behalf=actor["id"] != target["id"],
    )


@app.post("/timesheet")
async def save_timesheet(request: Request):
    actor = current_user(request)
    if not actor:
        return login_redirect(request)
    form = await request.form()
    if not csrf_ok(request, form_value(form, "csrf")):
        flash(request, "The form expired. Save again.", "error")
        return redirect("/")
    action = form_value(form, "action", "save")
    week_start = parse_week(form_value(form, "week"))
    try:
        target_id = int(form_value(form, "user_id"))
    except ValueError:
        flash(request, "Choose a person first.", "error")
        return redirect("/")
    back = timesheet_url(target_id, week_start)
    try:
        with connect() as conn:
            target = get_user(conn, target_id)
            if not target or not can_manage_time(conn, actor, target):
                raise AppError("You cannot edit that timesheet.")
            projects = list_projects(conn)
            week_row = get_week(conn, target["id"], week_start)
            allowed_projects = [
                project
                for project in projects
                if project["active"] or (week_row and any(
                    entry["project_id"] == project["id"] for entry in list_entries(conn, week_row["id"])
                ))
            ]
            entries = collect_entries(form, allowed_projects, week_days(week_start))
            week_row = get_or_create_week(conn, target["id"], week_start)
            save_week(conn, week_row, entries, form_value(form, "note"))
            week_row = get_week_by_id(conn, week_row["id"])
            if action == "save":
                flash(request, "Timesheet saved.")
            elif action == "submit":
                if target["role"] == "admin":
                    raise AppError("Admin hours are kept as a draft and are not sent for approval.")
                if not target["reports_to_id"]:
                    raise AppError("Assign a reporting person before submitting.")
                submit_week(conn, week_row, target["reports_to_id"])
                manager = get_user(conn, target["reports_to_id"])
                flash(request, f"Sent to {manager['name']} for approval.")
            elif action == "approve_now":
                if actor["id"] == target["id"]:
                    raise AppError("You cannot approve your own timesheet.")
                if actor["role"] != "admin" and target["reports_to_id"] != actor["id"]:
                    raise AppError("Only the reporting person can approve this timesheet.")
                if not list_entries(conn, week_row["id"]):
                    raise AppError("Add some hours before approving.")
                decide_week(conn, week_row, "approved", actor["id"], form_value(form, "review_note"))
                flash(request, f"Approved {target['name']}'s timesheet.")
            else:
                raise AppError("Unknown action.")
    except AppError as exc:
        flash(request, str(exc), "error")
    return redirect(back)


@app.post("/timesheet/decision")
async def timesheet_decision(request: Request):
    actor = current_user(request)
    if not actor:
        return login_redirect(request)
    form = await request.form()
    if not csrf_ok(request, form_value(form, "csrf")):
        flash(request, "The form expired. Try again.", "error")
        return redirect("/")
    try:
        week_id = int(form_value(form, "week_id"))
    except ValueError:
        return redirect("/")
    decision = form_value(form, "decision")
    note = form_value(form, "review_note")
    back = "/"
    try:
        with connect() as conn:
            week_row = get_week_by_id(conn, week_id)
            if not week_row or week_row["status"] != "submitted":
                raise AppError("That timesheet is not waiting for approval.")
            target = get_user(conn, week_row["user_id"])
            back = timesheet_url(target["id"], date.fromisoformat(week_row["week_start"]))
            if actor["id"] == target["id"]:
                raise AppError("You cannot approve your own timesheet.")
            if actor["role"] != "admin" and week_row["reviewer_id"] != actor["id"]:
                raise AppError("This timesheet is waiting on someone else.")
            if decision == "approved":
                decide_week(conn, week_row, "approved", actor["id"], note)
                flash(request, f"Approved {target['name']}'s week.")
            elif decision == "rejected":
                if not note:
                    raise AppError("Add a note explaining what needs to change.")
                decide_week(conn, week_row, "rejected", actor["id"], note)
                flash(request, f"Sent {target['name']}'s week back.")
            else:
                raise AppError("Choose approve or send back.")
    except AppError as exc:
        flash(request, str(exc), "error")
    return redirect(back)


@app.get("/approvals")
def approvals_page(request: Request):
    user = current_user(request)
    if not user:
        return login_redirect(request)
    if user["role"] not in ("admin", "project_manager", "project_lead"):
        flash(request, "Approvals are handled by the reporting person.", "error")
        return redirect("/")
    with connect() as conn:
        rows = pending_weeks(conn, None if user["role"] == "admin" else user["id"])
    return render(
        request,
        "approvals.html",
        user,
        title="Approvals",
        eyebrow="Waiting on you" if user["role"] != "admin" else "All pending weeks",
        active="approvals",
        rows=rows,
    )


@app.get("/people")
def people_page(request: Request, edit: int | None = None):
    user = current_user(request)
    if not user:
        return login_redirect(request)
    if user["role"] != "admin":
        flash(request, "Only an admin can add or remove people.", "error")
        return redirect("/")
    with connect() as conn:
        people = list_users(conn)
        editing = get_user(conn, edit) if edit else None
        managers = list_users(conn)
    return render(
        request,
        "people.html",
        user,
        title="People",
        eyebrow="Admin",
        active="people",
        people=people,
        editing=editing,
        managers=managers,
        reporting_targets=REPORTING_TARGETS,
    )


@app.post("/people")
async def people_create(request: Request):
    user = current_user(request)
    if not user:
        return login_redirect(request)
    if user["role"] != "admin":
        return redirect("/")
    form = await request.form()
    if not csrf_ok(request, form_value(form, "csrf")):
        flash(request, "The form expired. Try again.", "error")
        return redirect("/people")
    role = form_value(form, "role")
    manager_raw = form_value(form, "reports_to_id")
    manager_id = int(manager_raw) if manager_raw and role != "admin" else None
    try:
        with connect() as conn:
            create_user(
                conn,
                form_value(form, "name"),
                form_value(form, "email"),
                str(form.get("password") or ""),
                role,
                manager_id,
            )
        flash(request, "Person added. They can sign in with the email and password you set.")
    except (AppError, ValueError) as exc:
        flash(request, str(exc), "error")
    return redirect("/people")


@app.post("/people/{user_id}")
async def people_update(request: Request, user_id: int):
    actor = current_user(request)
    if not actor:
        return login_redirect(request)
    if actor["role"] != "admin":
        return redirect("/")
    form = await request.form()
    if not csrf_ok(request, form_value(form, "csrf")):
        flash(request, "The form expired. Try again.", "error")
        return redirect(f"/people?edit={user_id}")
    role = form_value(form, "role")
    manager_raw = form_value(form, "reports_to_id")
    manager_id = int(manager_raw) if manager_raw and role != "admin" else None
    try:
        with connect() as conn:
            update_user(
                conn,
                user_id,
                form_value(form, "name"),
                form_value(form, "email"),
                str(form.get("password") or ""),
                role,
                manager_id,
                actor["id"],
            )
        flash(request, "Person updated.")
        return redirect("/people")
    except (AppError, ValueError) as exc:
        flash(request, str(exc), "error")
        return redirect(f"/people?edit={user_id}")


@app.post("/people/{user_id}/delete")
async def people_delete(request: Request, user_id: int):
    actor = current_user(request)
    if not actor:
        return login_redirect(request)
    if actor["role"] != "admin":
        return redirect("/")
    form = await request.form()
    if not csrf_ok(request, form_value(form, "csrf")):
        flash(request, "The form expired. Try again.", "error")
        return redirect("/people")
    try:
        with connect() as conn:
            delete_user(conn, user_id, actor["id"])
        flash(request, "Person deleted, including their timesheets.")
    except AppError as exc:
        flash(request, str(exc), "error")
    return redirect("/people")


@app.get("/projects")
def projects_page(request: Request, edit: int | None = None):
    user = current_user(request)
    if not user:
        return login_redirect(request)
    if user["role"] != "admin":
        flash(request, "Only an admin can manage project IDs.", "error")
        return redirect("/")
    with connect() as conn:
        projects = list_projects(conn)
        editing = get_project(conn, edit) if edit else None
    return render(
        request,
        "projects.html",
        user,
        title="Projects",
        eyebrow="Admin",
        active="projects",
        projects=projects,
        editing=editing,
    )


@app.post("/projects")
async def projects_create(request: Request):
    user = current_user(request)
    if not user:
        return login_redirect(request)
    if user["role"] != "admin":
        return redirect("/")
    form = await request.form()
    if not csrf_ok(request, form_value(form, "csrf")):
        flash(request, "The form expired. Try again.", "error")
        return redirect("/projects")
    try:
        with connect() as conn:
            create_project(conn, form_value(form, "code"), form_value(form, "name"), form_value(form, "description"))
        flash(request, "Project ID added. People can log time against it.")
    except AppError as exc:
        flash(request, str(exc), "error")
    return redirect("/projects")


@app.post("/projects/{project_id}")
async def projects_update(request: Request, project_id: int):
    user = current_user(request)
    if not user:
        return login_redirect(request)
    if user["role"] != "admin":
        return redirect("/")
    form = await request.form()
    if not csrf_ok(request, form_value(form, "csrf")):
        flash(request, "The form expired. Try again.", "error")
        return redirect(f"/projects?edit={project_id}")
    try:
        with connect() as conn:
            update_project(
                conn,
                project_id,
                form_value(form, "code"),
                form_value(form, "name"),
                form_value(form, "description"),
                form.get("active") == "1",
            )
        flash(request, "Project updated.")
    except AppError as exc:
        flash(request, str(exc), "error")
        return redirect(f"/projects?edit={project_id}")
    return redirect("/projects")


@app.post("/projects/{project_id}/delete")
async def projects_delete(request: Request, project_id: int):
    user = current_user(request)
    if not user:
        return login_redirect(request)
    if user["role"] != "admin":
        return redirect("/")
    form = await request.form()
    if not csrf_ok(request, form_value(form, "csrf")):
        flash(request, "The form expired. Try again.", "error")
        return redirect("/projects")
    try:
        with connect() as conn:
            result = delete_project(conn, project_id)
        if result == "archived":
            flash(request, "This project has timesheets, so it was archived instead of deleted.")
        else:
            flash(request, "Project deleted.")
    except AppError as exc:
        flash(request, str(exc), "error")
    return redirect("/projects")
