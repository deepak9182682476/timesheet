import os
import sqlite3
from contextlib import contextmanager
from datetime import date, datetime, timedelta
from pathlib import Path

from app.roles import MANAGER_ROLES
from app.security import hash_password

ROOT = Path(__file__).resolve().parent.parent
DB_PATH = Path(os.environ.get("TIMESHEET_DB", ROOT / "data" / "timesheet.db"))
DATA_DIR = DB_PATH.parent

DEMO_ACCOUNTS = [
    {
        "key": "admin",
        "name": "Asha Menon",
        "email": "admin@timesheet.local",
        "password": "admin123",
        "role": "admin",
        "manager": None,
    },
    {
        "key": "pm",
        "name": "Priya Shah",
        "email": "priya@timesheet.local",
        "password": "manager123",
        "role": "project_manager",
        "manager": "admin",
    },
    {
        "key": "lead",
        "name": "Arjun Mehta",
        "email": "arjun@timesheet.local",
        "password": "lead123",
        "role": "project_lead",
        "manager": "pm",
    },
    {
        "key": "sara",
        "name": "Sara Iqbal",
        "email": "sara@timesheet.local",
        "password": "employee123",
        "role": "employee",
        "manager": "lead",
    },
    {
        "key": "dev",
        "name": "Dev Patel",
        "email": "dev@timesheet.local",
        "password": "employee123",
        "role": "employee",
        "manager": "lead",
    },
    {
        "key": "nina",
        "name": "Nina Rao",
        "email": "nina@timesheet.local",
        "password": "employee123",
        "role": "employee",
        "manager": "pm",
    },
]

DEMO_PROJECTS = [
    ("PRJ-2401", "Atlas Redesign", "Marketing site and design system"),
    ("PRJ-2402", "Harbor API", "Customer API and integrations"),
    ("PRJ-2403", "Northwind Support", "Retainer support hours"),
]

SCHEMA = """
CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY,
    name TEXT NOT NULL,
    email TEXT NOT NULL UNIQUE COLLATE NOCASE,
    password_hash TEXT NOT NULL,
    role TEXT NOT NULL CHECK (role IN ('admin', 'project_manager', 'project_lead', 'employee')),
    reports_to_id INTEGER REFERENCES users(id) ON DELETE RESTRICT,
    created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS projects (
    id INTEGER PRIMARY KEY,
    code TEXT NOT NULL UNIQUE COLLATE NOCASE,
    name TEXT NOT NULL,
    description TEXT NOT NULL DEFAULT '',
    active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS timesheet_weeks (
    id INTEGER PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    week_start TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'submitted', 'approved', 'rejected')),
    note TEXT NOT NULL DEFAULT '',
    reviewer_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
    submitted_at TEXT,
    reviewed_by_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
    reviewed_at TEXT,
    review_note TEXT NOT NULL DEFAULT '',
    updated_at TEXT NOT NULL,
    UNIQUE (user_id, week_start)
);

CREATE TABLE IF NOT EXISTS time_entries (
    id INTEGER PRIMARY KEY,
    week_id INTEGER NOT NULL REFERENCES timesheet_weeks(id) ON DELETE CASCADE,
    project_id INTEGER NOT NULL REFERENCES projects(id) ON DELETE RESTRICT,
    work_date TEXT NOT NULL,
    hours REAL NOT NULL CHECK (hours > 0 AND hours <= 24),
    UNIQUE (week_id, project_id, work_date)
);

CREATE INDEX IF NOT EXISTS idx_users_manager ON users(reports_to_id);
CREATE INDEX IF NOT EXISTS idx_weeks_user ON timesheet_weeks(user_id, week_start);
CREATE INDEX IF NOT EXISTS idx_weeks_review ON timesheet_weeks(status, reviewer_id);
CREATE INDEX IF NOT EXISTS idx_entries_week ON time_entries(week_id);
"""


class AppError(Exception):
    pass


def now_stamp() -> str:
    return datetime.now().replace(microsecond=0).isoformat(sep=" ")


def monday(day: date) -> date:
    return day - timedelta(days=day.weekday())


def week_days(week_start: date) -> list[date]:
    return [week_start + timedelta(days=offset) for offset in range(7)]


@contextmanager
def connect():
    DATA_DIR.mkdir(parents=True, exist_ok=True)
    conn = sqlite3.connect(DB_PATH)
    conn.row_factory = sqlite3.Row
    conn.execute("PRAGMA foreign_keys = ON")
    try:
        yield conn
        conn.commit()
    except Exception:
        conn.rollback()
        raise
    finally:
        conn.close()


def all_rows(conn, sql, params=()):
    return [dict(row) for row in conn.execute(sql, params).fetchall()]


def one(conn, sql, params=()):
    row = conn.execute(sql, params).fetchone()
    return dict(row) if row else None


def init_db() -> None:
    with connect() as conn:
        conn.executescript(SCHEMA)
        count = conn.execute("SELECT COUNT(*) AS c FROM users").fetchone()["c"]
        if count == 0:
            seed(conn)


def seed(conn) -> None:
    stamp = now_stamp()
    ids = {}
    for account in DEMO_ACCOUNTS:
        manager_id = ids.get(account["manager"]) if account["manager"] else None
        cur = conn.execute(
            """
            INSERT INTO users (name, email, password_hash, role, reports_to_id, created_at)
            VALUES (?, ?, ?, ?, ?, ?)
            """,
            (
                account["name"],
                account["email"],
                hash_password(account["password"]),
                account["role"],
                manager_id,
                stamp,
            ),
        )
        ids[account["key"]] = cur.lastrowid

    project_ids = []
    for code, name, description in DEMO_PROJECTS:
        cur = conn.execute(
            """
            INSERT INTO projects (code, name, description, active, created_at)
            VALUES (?, ?, ?, 1, ?)
            """,
            (code, name, description, stamp),
        )
        project_ids.append(cur.lastrowid)

    this_week = monday(date.today())
    last_week = this_week - timedelta(days=7)

    def add_week(user_key, week_start, status, entries, reviewer_key=None, review_note=""):
        reviewer_id = ids.get(reviewer_key) if reviewer_key else None
        reviewed_by = reviewer_id if status == "approved" else None
        reviewed_at = stamp if status == "approved" else None
        submitted_at = stamp if status in ("submitted", "approved") else None
        cur = conn.execute(
            """
            INSERT INTO timesheet_weeks (
                user_id, week_start, status, note, reviewer_id, submitted_at,
                reviewed_by_id, reviewed_at, review_note, updated_at
            ) VALUES (?, ?, ?, '', ?, ?, ?, ?, ?, ?)
            """,
            (
                ids[user_key],
                week_start.isoformat(),
                status,
                reviewer_id,
                submitted_at,
                reviewed_by,
                reviewed_at,
                review_note,
                stamp,
            ),
        )
        week_id = cur.lastrowid
        for project_index, weekday, hours in entries:
            work_date = week_start + timedelta(days=weekday)
            conn.execute(
                "INSERT INTO time_entries (week_id, project_id, work_date, hours) VALUES (?, ?, ?, ?)",
                (week_id, project_ids[project_index], work_date.isoformat(), hours),
            )

    add_week(
        "sara",
        this_week,
        "submitted",
        [(0, 0, 6), (0, 1, 6), (0, 2, 4), (1, 2, 4), (1, 3, 8)],
        "lead",
    )
    add_week("dev", this_week, "draft", [(0, 0, 8), (0, 1, 8), (0, 2, 5)])
    add_week(
        "nina",
        this_week,
        "submitted",
        [(2, 0, 8), (2, 1, 8), (2, 2, 7.5)],
        "pm",
    )
    add_week("lead", this_week, "draft", [(1, 0, 3), (1, 1, 4), (1, 2, 3)])
    add_week("pm", this_week, "draft", [(0, 0, 2), (0, 1, 2)])

    add_week(
        "sara",
        last_week,
        "approved",
        [(0, day, 8) for day in range(5)],
        "lead",
        "Approved. Hours match the Atlas plan.",
    )
    add_week(
        "dev",
        last_week,
        "approved",
        [(1, 0, 8), (1, 1, 8), (1, 2, 8), (1, 3, 8), (2, 4, 6)],
        "lead",
        "Approved.",
    )
    add_week(
        "nina",
        last_week,
        "approved",
        [(2, day, 7.5) for day in range(5)],
        "pm",
        "Approved.",
    )
    add_week(
        "lead",
        last_week,
        "approved",
        [(1, day, 4) for day in range(5)],
        "pm",
        "Approved.",
    )
    add_week(
        "pm",
        last_week,
        "approved",
        [(0, 0, 2), (0, 1, 2), (0, 2, 2)],
        "admin",
        "Approved.",
    )


def get_user(conn, user_id: int):
    return one(conn, "SELECT * FROM users WHERE id = ?", (user_id,))


def get_user_by_email(conn, email: str):
    return one(conn, "SELECT * FROM users WHERE email = ?", (email.strip(),))


def list_users(conn):
    return all_rows(
        conn,
        """
        SELECT u.*, m.name AS manager_name,
               (SELECT COUNT(*) FROM users r WHERE r.reports_to_id = u.id) AS report_count
        FROM users u
        LEFT JOIN users m ON m.id = u.reports_to_id
        ORDER BY CASE u.role
            WHEN 'admin' THEN 0
            WHEN 'project_manager' THEN 1
            WHEN 'project_lead' THEN 2
            ELSE 3
        END, u.name
        """,
    )


def direct_reports(conn, user_id: int):
    return all_rows(
        conn,
        "SELECT * FROM users WHERE reports_to_id = ? ORDER BY name",
        (user_id,),
    )


def descendant_ids(conn, user_id: int) -> list[int]:
    ids = []
    seen = set()
    queue = [user_id]
    while queue:
        current = queue.pop(0)
        for child in direct_reports(conn, current):
            if child["id"] in seen:
                continue
            seen.add(child["id"])
            ids.append(child["id"])
            queue.append(child["id"])
    return ids


def team_tree(conn, user_id: int):
    def walk(uid, depth):
        nodes = []
        for child in direct_reports(conn, uid):
            nodes.append({"user": child, "depth": depth})
            nodes.extend(walk(child["id"], depth + 1))
        return nodes

    return walk(user_id, 0)


def creates_cycle(conn, user_id: int | None, manager_id: int | None) -> bool:
    if not manager_id:
        return False
    if user_id and manager_id == user_id:
        return True
    seen = set()
    current = manager_id
    while current:
        if user_id and current == user_id:
            return True
        if current in seen:
            return True
        seen.add(current)
        manager = get_user(conn, current)
        if not manager:
            return True
        current = manager["reports_to_id"]
    return False


def validate_manager(conn, role: str, manager_id: int | None, user_id: int | None = None) -> None:
    if role not in MANAGER_ROLES:
        raise AppError("Choose a valid role.")
    allowed = MANAGER_ROLES[role]
    if role == "admin":
        if manager_id:
            raise AppError("An admin does not report to anyone.")
        return
    if not manager_id:
        raise AppError("Choose the person this user reports to.")
    manager = get_user(conn, manager_id)
    if not manager or manager["role"] not in allowed:
        raise AppError("That reporting person is not valid for this role.")
    if creates_cycle(conn, user_id, manager_id):
        raise AppError("That reporting line would create a loop.")


def admin_count(conn) -> int:
    return conn.execute("SELECT COUNT(*) AS c FROM users WHERE role = 'admin'").fetchone()["c"]


def create_user(conn, name, email, password, role, manager_id):
    name = name.strip()
    email = email.strip().lower()
    if not name:
        raise AppError("Name is required.")
    if "@" not in email or email.startswith("@") or email.endswith("@") or " " in email:
        raise AppError("Enter a valid email.")
    if len(password) < 6:
        raise AppError("Password must be at least 6 characters.")
    if get_user_by_email(conn, email):
        raise AppError("That email is already in use.")
    validate_manager(conn, role, manager_id, None)
    conn.execute(
        """
        INSERT INTO users (name, email, password_hash, role, reports_to_id, created_at)
        VALUES (?, ?, ?, ?, ?, ?)
        """,
        (name, email, hash_password(password), role, manager_id, now_stamp()),
    )


def update_user(conn, user_id, name, email, password, role, manager_id, actor_id):
    user = get_user(conn, user_id)
    if not user:
        raise AppError("That person no longer exists.")
    name = name.strip()
    email = email.strip().lower()
    if not name:
        raise AppError("Name is required.")
    if "@" not in email or " " in email:
        raise AppError("Enter a valid email.")
    existing = get_user_by_email(conn, email)
    if existing and existing["id"] != user_id:
        raise AppError("That email is already in use.")
    if password and len(password) < 6:
        raise AppError("Password must be at least 6 characters.")
    validate_manager(conn, role, manager_id, user_id)
    if user["role"] == "admin" and role != "admin" and admin_count(conn) <= 1:
        raise AppError("Keep at least one admin.")
    if user_id == actor_id and role != user["role"]:
        raise AppError("You cannot change your own role.")
    password_hash = hash_password(password) if password else user["password_hash"]
    conn.execute(
        """
        UPDATE users
        SET name = ?, email = ?, password_hash = ?, role = ?, reports_to_id = ?
        WHERE id = ?
        """,
        (name, email, password_hash, role, manager_id, user_id),
    )


def delete_user(conn, user_id, actor_id):
    user = get_user(conn, user_id)
    if not user:
        raise AppError("That person no longer exists.")
    if user_id == actor_id:
        raise AppError("You cannot delete your own account.")
    if user["role"] == "admin" and admin_count(conn) <= 1:
        raise AppError("Keep at least one admin.")
    reports = conn.execute(
        "SELECT COUNT(*) AS c FROM users WHERE reports_to_id = ?",
        (user_id,),
    ).fetchone()["c"]
    if reports:
        raise AppError("Reassign people who report to this person before deleting them.")
    conn.execute("DELETE FROM timesheet_weeks WHERE user_id = ?", (user_id,))
    conn.execute("DELETE FROM users WHERE id = ?", (user_id,))


def list_projects(conn, active_only=False):
    sql = "SELECT * FROM projects"
    if active_only:
        sql += " WHERE active = 1"
    sql += " ORDER BY code"
    return all_rows(conn, sql)


def get_project(conn, project_id: int):
    return one(conn, "SELECT * FROM projects WHERE id = ?", (project_id,))


def create_project(conn, code, name, description):
    code = code.strip().upper()
    name = name.strip()
    description = description.strip()
    if not code:
        raise AppError("Project ID is required.")
    if len(code) > 20:
        raise AppError("Project ID must be 20 characters or fewer.")
    if not name:
        raise AppError("Project name is required.")
    if one(conn, "SELECT id FROM projects WHERE code = ?", (code,)):
        raise AppError("That project ID already exists.")
    conn.execute(
        """
        INSERT INTO projects (code, name, description, active, created_at)
        VALUES (?, ?, ?, 1, ?)
        """,
        (code, name, description, now_stamp()),
    )


def update_project(conn, project_id, code, name, description, active):
    project = get_project(conn, project_id)
    if not project:
        raise AppError("That project no longer exists.")
    code = code.strip().upper()
    name = name.strip()
    description = description.strip()
    if not code or not name:
        raise AppError("Project ID and name are required.")
    existing = one(conn, "SELECT id FROM projects WHERE code = ?", (code,))
    if existing and existing["id"] != project_id:
        raise AppError("That project ID already exists.")
    conn.execute(
        """
        UPDATE projects
        SET code = ?, name = ?, description = ?, active = ?
        WHERE id = ?
        """,
        (code, name, description, 1 if active else 0, project_id),
    )


def delete_project(conn, project_id) -> str:
    project = get_project(conn, project_id)
    if not project:
        raise AppError("That project no longer exists.")
    used = conn.execute(
        "SELECT 1 FROM time_entries WHERE project_id = ? LIMIT 1",
        (project_id,),
    ).fetchone()
    if used:
        conn.execute("UPDATE projects SET active = 0 WHERE id = ?", (project_id,))
        return "archived"
    conn.execute("DELETE FROM projects WHERE id = ?", (project_id,))
    return "deleted"


def get_week(conn, user_id: int, week_start: date):
    return one(
        conn,
        "SELECT * FROM timesheet_weeks WHERE user_id = ? AND week_start = ?",
        (user_id, week_start.isoformat()),
    )


def get_week_by_id(conn, week_id: int):
    return one(conn, "SELECT * FROM timesheet_weeks WHERE id = ?", (week_id,))


def list_entries(conn, week_id: int):
    return all_rows(
        conn,
        "SELECT * FROM time_entries WHERE week_id = ? ORDER BY work_date, project_id",
        (week_id,),
    )


def get_or_create_week(conn, user_id: int, week_start: date):
    existing = get_week(conn, user_id, week_start)
    if existing:
        return existing
    conn.execute(
        """
        INSERT INTO timesheet_weeks (user_id, week_start, status, updated_at)
        VALUES (?, ?, 'draft', ?)
        """,
        (user_id, week_start.isoformat(), now_stamp()),
    )
    return get_week(conn, user_id, week_start)


def save_week(conn, week, entries, note: str):
    if week["status"] in ("submitted", "approved"):
        raise AppError("This week is locked. It can be edited again only if it is sent back.")
    status = "draft" if week["status"] == "rejected" else week["status"]
    conn.execute(
        """
        UPDATE timesheet_weeks
        SET note = ?, status = ?, updated_at = ?
        WHERE id = ?
        """,
        (note.strip(), status, now_stamp(), week["id"]),
    )
    conn.execute("DELETE FROM time_entries WHERE week_id = ?", (week["id"],))
    conn.executemany(
        "INSERT INTO time_entries (week_id, project_id, work_date, hours) VALUES (?, ?, ?, ?)",
        [(week["id"], project_id, work_date, hours) for project_id, work_date, hours in entries],
    )
    return get_week_by_id(conn, week["id"])


def submit_week(conn, week, reviewer_id: int):
    if not list_entries(conn, week["id"]):
        raise AppError("Add some hours before submitting.")
    conn.execute(
        """
        UPDATE timesheet_weeks
        SET status = 'submitted', reviewer_id = ?, submitted_at = ?,
            reviewed_by_id = NULL, reviewed_at = NULL, review_note = '', updated_at = ?
        WHERE id = ?
        """,
        (reviewer_id, now_stamp(), now_stamp(), week["id"]),
    )


def decide_week(conn, week, status: str, reviewer_id: int, note: str):
    conn.execute(
        """
        UPDATE timesheet_weeks
        SET status = ?, reviewer_id = COALESCE(reviewer_id, ?), reviewed_by_id = ?,
            reviewed_at = ?, review_note = ?, submitted_at = COALESCE(submitted_at, ?),
            updated_at = ?
        WHERE id = ?
        """,
        (status, reviewer_id, reviewer_id, now_stamp(), note.strip(), now_stamp(), now_stamp(), week["id"]),
    )


def week_summaries(conn, user_ids, week_start: date):
    if not user_ids:
        return {}
    marks = ",".join("?" for _ in user_ids)
    rows = all_rows(
        conn,
        f"""
        SELECT w.user_id, w.status, w.id AS week_id, COALESCE(SUM(e.hours), 0) AS hours
        FROM timesheet_weeks w
        LEFT JOIN time_entries e ON e.week_id = w.id
        WHERE w.week_start = ? AND w.user_id IN ({marks})
        GROUP BY w.id
        """,
        (week_start.isoformat(), *user_ids),
    )
    return {row["user_id"]: row for row in rows}


def recent_weeks(conn, user_id: int, limit: int = 6):
    return all_rows(
        conn,
        """
        SELECT w.*, COALESCE(SUM(e.hours), 0) AS hours
        FROM timesheet_weeks w
        LEFT JOIN time_entries e ON e.week_id = w.id
        WHERE w.user_id = ?
        GROUP BY w.id
        ORDER BY w.week_start DESC
        LIMIT ?
        """,
        (user_id, limit),
    )


def pending_weeks(conn, reviewer_id: int | None):
    if reviewer_id is None:
        where = "w.status = 'submitted'"
        params = ()
    else:
        where = "w.status = 'submitted' AND w.reviewer_id = ?"
        params = (reviewer_id,)
    return all_rows(
        conn,
        f"""
        SELECT w.*, u.name AS user_name, u.role AS user_role,
               m.name AS manager_name,
               COALESCE(SUM(e.hours), 0) AS hours
        FROM timesheet_weeks w
        JOIN users u ON u.id = w.user_id
        LEFT JOIN users m ON m.id = w.reviewer_id
        LEFT JOIN time_entries e ON e.week_id = w.id
        WHERE {where}
        GROUP BY w.id
        ORDER BY w.submitted_at
        """,
        params,
    )


def pending_count(conn, user) -> int:
    if user["role"] == "admin":
        row = one(conn, "SELECT COUNT(*) AS c FROM timesheet_weeks WHERE status = 'submitted'")
    else:
        row = one(
            conn,
            "SELECT COUNT(*) AS c FROM timesheet_weeks WHERE status = 'submitted' AND reviewer_id = ?",
            (user["id"],),
        )
    return row["c"] if row else 0


def total_hours(conn, start: date, end: date, user_ids=None) -> float:
    clauses = ["e.work_date >= ?", "e.work_date < ?"]
    params: list = [start.isoformat(), end.isoformat()]
    if user_ids is not None:
        if not user_ids:
            return 0
        marks = ",".join("?" for _ in user_ids)
        clauses.append(f"w.user_id IN ({marks})")
        params.extend(user_ids)
    row = one(
        conn,
        f"""
        SELECT COALESCE(SUM(e.hours), 0) AS hours
        FROM time_entries e
        JOIN timesheet_weeks w ON w.id = e.week_id
        WHERE {' AND '.join(clauses)}
        """,
        params,
    )
    return float(row["hours"])


def can_manage_time(conn, actor, target) -> bool:
    if not actor or not target:
        return False
    if actor["id"] == target["id"]:
        return True
    if actor["role"] == "admin":
        return True
    return target["id"] in descendant_ids(conn, actor["id"])


def people_for_entry(conn, actor):
    if actor["role"] == "admin":
        return list_users(conn)
    ordered = [get_user(conn, actor["id"])]
    tree_ids = []

    def walk(uid):
        for child in direct_reports(conn, uid):
            tree_ids.append(child["id"])
            walk(child["id"])

    walk(actor["id"])
    people = ordered
    for user_id in tree_ids:
        people.append(get_user(conn, user_id))
    return [person for person in people if person]

