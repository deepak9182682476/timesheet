import os
import tempfile
from pathlib import Path

_dir = Path(tempfile.mkdtemp(prefix="timesheet-test-"))
os.environ["TIMESHEET_DB"] = str(_dir / "test.db")
