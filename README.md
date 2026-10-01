# Timesheet

A small timesheet app with four logins: admin, project manager, project lead, and employee.

- Admins get a company dashboard, add project IDs, and add or delete people.
- Project managers see their own week, everyone who reports to them (leads and the people under those leads), and can enter time for those people.
- Project leads see their own week and can enter time for employees mapped to them.
- Employees enter a week and submit it to the person they report to.
- A manager filling in a direct report's week can approve it immediately. Other submissions wait on that person's reporting manager.

## Run

```bash
python3 -m venv .venv
source .venv/bin/activate
pip install -r requirements.txt
uvicorn app.main:app --reload --port 8000
```

Open http://127.0.0.1:8000

## Demo logins

| Person | Role | Email | Password |
| --- | --- | --- | --- |
| Asha Menon | Admin | admin@timesheet.local | admin123 |
| Priya Shah | Project manager | priya@timesheet.local | manager123 |
| Arjun Mehta | Project lead | arjun@timesheet.local | lead123 |
| Sara Iqbal | Employee | sara@timesheet.local | employee123 |
| Dev Patel | Employee | dev@timesheet.local | employee123 |
| Nina Rao | Employee | nina@timesheet.local | employee123 |

Sara and Dev report to Arjun. Nina and Arjun report to Priya. Priya reports to Asha.

Delete `data/timesheet.db` to reset the demo data.
