import re

file_path = "workspace/includes/excel_parser.php"
with open(file_path, "r") as f:
    content = f.read()

# 1. Add canvassing_status extraction
content = content.replace(
    "$canvassing_no    = isset($cells[13]) && trim((string)$cells[13]) !== '' ? trim((string)$cells[13]) : null;",
    "$canvassing_no    = isset($cells[13]) && trim((string)$cells[13]) !== '' ? trim((string)$cells[13]) : null;\n            $canvassing_status = isset($cells[14]) && trim((string)$cells[14]) !== '' ? trim((string)$cells[14]) : null;"
)

# 2. Add canvassing_status to INSERT
content = content.replace(
    "status_ticket, canvassing_no, canvassing_date,",
    "status_ticket, canvassing_no, canvassing_status, canvassing_date,"
)

# 3. Add to VALUES (?, ?, ?)
content = content.replace(
    "VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
    "VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
)

# 4. Add to ON DUPLICATE KEY UPDATE
content = content.replace(
    "canvassing_no = VALUES(canvassing_no),",
    "canvassing_no = VALUES(canvassing_no),\n                canvassing_status = VALUES(canvassing_status),"
)

# 5. Add to execute array
content = content.replace(
    "$canvassing_no, $canvassing_date, $po_number, $ticket_type,",
    "$canvassing_no, $canvassing_status, $canvassing_date, $po_number, $ticket_type,"
)

with open(file_path, "w") as f:
    f.write(content)

print("Done")
