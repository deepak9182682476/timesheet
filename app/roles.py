ROLE_ORDER = ("admin", "project_manager", "project_lead", "employee")

ROLE_LABELS = {
    "admin": "Admin",
    "project_manager": "Project Manager",
    "project_lead": "Project Lead",
    "employee": "Employee",
}

# Which roles may be chosen as the reporting person for a given role.
MANAGER_ROLES = {
    "admin": set(),
    "project_manager": {"admin"},
    "project_lead": {"admin", "project_manager"},
    "employee": {"admin", "project_manager", "project_lead"},
}

# Assignee roles that may report to someone with this role.
REPORTING_TARGETS = {
    "admin": "project_manager project_lead employee",
    "project_manager": "project_lead employee",
    "project_lead": "employee",
    "employee": "",
}

STATUS_LABELS = {
    "draft": "Draft",
    "submitted": "Pending approval",
    "approved": "Approved",
    "rejected": "Sent back",
}
