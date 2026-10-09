import os
import re
import json
from collections import defaultdict

ROOT_DIR = "/Users/adonispallassutanto/Documents/vms"
OUTPUT_MD = os.path.join(ROOT_DIR, "VSS_Workspace_Documentation.md")
OUTPUT_JSON = os.path.join(ROOT_DIR, "VSS_Workspace_Documentation.json")

IGNORE_DIRS = {'.git', 'vendor', 'libs', 'assets', 'uploads', 'hyperframes_videos', 'hyperframes_rfq_launch', 'tools', 'db_backups'}

# Patterns
FUNC_PATTERN = re.compile(r'function\s+([a-zA-Z0-9_]+)\s*\((.*?)\)\s*\{', re.DOTALL)
CLASS_PATTERN = re.compile(r'class\s+([a-zA-Z0-9_]+)')
TABLE_PATTERN = re.compile(r'CREATE\s+TABLE\s+`?([a-zA-Z0-9_]+)`?\s*\((.*?)\);', re.DOTALL | re.IGNORECASE)
FK_PATTERN = re.compile(r'FOREIGN\s+KEY\s*\(`?([a-zA-Z0-9_]+)`?\)\s*REFERENCES\s*`?([a-zA-Z0-9_]+)`?', re.IGNORECASE)
SQL_ACTION_PATTERN = re.compile(r'(SELECT|INSERT|UPDATE|DELETE)\s+(?:INTO\s+|FROM\s+)?`?([a-zA-Z0-9_]+)`?', re.IGNORECASE)
CALL_PATTERN = re.compile(r'\b([a-zA-Z0-9_]+)\s*\(')

def extract_db_schema():
    tables = {}
    fks = []
    db_backups_dir = os.path.join(ROOT_DIR, "db_backups")
    if os.path.exists(db_backups_dir):
        for file in os.listdir(db_backups_dir):
            if file.endswith('.sql'):
                with open(os.path.join(db_backups_dir, file), 'r', encoding='utf-8', errors='ignore') as f:
                    content = f.read()
                    for match in TABLE_PATTERN.finditer(content):
                        table_name = match.group(1)
                        schema = match.group(2)
                        
                        columns = []
                        for line in schema.split('\n'):
                            line = line.strip()
                            if line and not line.startswith('--') and not line.startswith('/*'):
                                columns.append(line.rstrip(','))
                        tables[table_name] = columns
                        
                    for fk in FK_PATTERN.finditer(content):
                        col = fk.group(1)
                        ref_table = fk.group(2)
                        # We don't necessarily know which table the FK belongs to without context,
                        # but we can try to guess or just map references globally.
                        pass
    # Basic implicit relationship guessing
    relations = []
    table_names = list(tables.keys())
    for t, cols in tables.items():
        for c in cols:
            col_parts = c.split()
            if col_parts:
                cname = col_parts[0].replace('`', '')
                if cname.endswith('_id'):
                    ref = cname[:-3]
                    if ref in table_names:
                        relations.append((t, cname, ref, 'id'))
                    elif ref + 's' in table_names:
                        relations.append((t, cname, ref + 's', 'id'))
    return tables, relations

def get_function_body(content, start_index):
    # Extracts block enclosed in {}
    brace_count = 0
    in_block = False
    body = []
    for char in content[start_index:]:
        body.append(char)
        if char == '{':
            in_block = True
            brace_count += 1
        elif char == '}':
            brace_count -= 1
        
        if in_block and brace_count == 0:
            break
    return "".join(body)

def parse_php_file(filepath, global_funcs):
    funcs = []
    classes = []
    try:
        with open(filepath, 'r', encoding='utf-8', errors='ignore') as f:
            content = f.read()
            for match in CLASS_PATTERN.finditer(content):
                classes.append(match.group(1))
                
            for match in FUNC_PATTERN.finditer(content):
                name = match.group(1)
                params = match.group(2).strip()
                body = get_function_body(content, match.end() - 1)
                
                # Analyze body for SQL operations
                sql_actions = []
                for sql in SQL_ACTION_PATTERN.finditer(body):
                    action = sql.group(1).upper()
                    target = sql.group(2)
                    sql_actions.append(f"{action} {target}")
                
                # Analyze body for function calls
                calls = []
                for c in CALL_PATTERN.finditer(body):
                    called = c.group(1)
                    if called != name and called not in ['if', 'while', 'for', 'foreach', 'switch', 'isset', 'empty', 'array']:
                        calls.append(called)
                        
                funcs.append({
                    "name": name,
                    "params": params,
                    "sql_actions": list(set(sql_actions)),
                    "calls": list(set(calls))
                })
                global_funcs.add(name)
    except Exception as e:
        pass
    return {"functions": funcs, "classes": classes}

def main():
    architecture = defaultdict(dict)
    global_funcs = set()
    
    for root, dirs, files in os.walk(ROOT_DIR):
        dirs[:] = [d for d in dirs if d not in IGNORE_DIRS]
        for file in files:
            if file.endswith('.php'):
                filepath = os.path.join(root, file)
                rel_path = os.path.relpath(filepath, ROOT_DIR)
                parsed = parse_php_file(filepath, global_funcs)
                if parsed['functions'] or parsed['classes']:
                    architecture[rel_path] = parsed

    # Filter calls to only include our known internal functions
    for rel_path in architecture:
        for f in architecture[rel_path]['functions']:
            f['calls'] = [c for c in f['calls'] if c in global_funcs]

    schemas, relations = extract_db_schema()

    with open(OUTPUT_JSON, 'w', encoding='utf-8') as f:
        json.dump({"architecture": architecture, "database_schemas": schemas, "relations": relations}, f, indent=4)
        
    with open(OUTPUT_MD, 'w', encoding='utf-8') as md:
        md.write("# 📘 Master System Guide: VSS & Workspace\n\n")
        
        md.write("## 📑 Table of Contents\n")
        md.write("1. [🌟 System Overview](#1--system-overview)\n")
        md.write("2. [🧭 Navigation Guide (User POV)](#2--navigation-guide-user-pov)\n")
        md.write("3. [👥 Who Uses What? (Roles & Flows)](#3--who-uses-what-roles--flows)\n")
        md.write("4. [🗄️ Database Architecture & ER Diagram](#4--database-architecture--er-diagram)\n")
        md.write("5. [⚙️ Function Hierarchy & Call Graph](#5--function-hierarchy--call-graph)\n")
        md.write("6. [🖥️ Detailed Codebase API](#6--detailed-codebase-api)\n\n")
        
        md.write("<div style=\"page-break-after: always;\"></div>\n\n")
        md.write("## 1. 🌟 System Overview\n")
        md.write("Our platform is split into two main areas:\n")
        md.write("### 🏢 **VSS (Vendor Sourcing System)**\n")
        md.write("- **What it does:** Allows vendors to register, log in, and manage their profiles.\n\n")
        md.write("### 💻 **Workspace**\n")
        md.write("- **What it does:** The internal portal for Category Managers, Engineers, and Procurement teams to evaluate vendors and manage projects.\n\n")
        
        md.write("<div style=\"page-break-after: always;\"></div>\n\n")
        md.write("## 2. 🧭 Navigation Guide (User POV)\n")
        md.write("This section breaks down the application from the user's perspective. It explains every page available on the sidebar menu, what it does, and which roles can access it.\n\n")
        
        # Dashboard
        md.write("### 📊 Dashboard & Overview\n")
        md.write("- **Dashboard**\n")
        md.write("  - *Accessible By:* All Internal Users (Dynamic layout based on role)\n")
        md.write("  - *What it does:* The central hub of the Workspace. Category Managers and PICs see their ticket workload and SLA warnings. Project Directors and QS see their project budgets and active PRs. STC sees supply chain metrics.\n")
        md.write("- **Announcements**\n")
        md.write("  - *Accessible By:* All Internal Users\n")
        md.write("  - *What it does:* Displays global systemic announcements or team-wide messages from management.\n\n")

        # Operations
        md.write("### ⚙️ Operations\n")
        md.write("- **Upload PRs & AI**\n")
        md.write("  - *Accessible By:* Category Manager\n")
        md.write("  - *What it does:* Allows bulk upload of Purchase Requests. Triggers the internal AI to automatically extract items, match them with Master Data, and auto-assign them to procurement teams.\n")
        md.write("- **Vendor Evaluations**\n")
        md.write("  - *Accessible By:* Category Manager, Manager\n")
        md.write("  - *What it does:* A review portal to check incoming Vendor Evaluation scores, see compliance statuses, and manually assign specific Question Sets to Purchase Orders.\n\n")

        # Sourcing
        md.write("### 🤝 Sourcing\n")
        md.write("- **Create RFQ**\n")
        md.write("  - *Accessible By:* Category Manager, Manager, PIC\n")
        md.write("  - *What it does:* A form to manually generate a new Request for Quotation (RFQ) to send out to vendors for bidding.\n")
        md.write("- **RFQ Dashboard**\n")
        md.write("  - *Accessible By:* Category Manager, Manager, PIC, Project Director, QS\n")
        md.write("  - *What it does:* The primary workspace for managing active, drafted, and closed RFQs. Users can compare vendor bids here.\n")
        md.write("- **Team RFQ Monitoring**\n")
        md.write("  - *Accessible By:* Category Manager, Manager, Project Director, QS\n")
        md.write("  - *What it does:* A read-only, high-level overview for team leaders to monitor the progress and status of all RFQs across their respective teams.\n\n")

        # AON
        md.write("### 📦 AON (Agreement Order Network)\n")
        md.write("- **Master AON**\n")
        md.write("  - *Accessible By:* STC, Category Manager, Manager\n")
        md.write("  - *What it does:* The centralized table for managing long-term pricing agreements and supplier allocations.\n")
        md.write("- **Upload AON**\n")
        md.write("  - *Accessible By:* STC, Category Manager, Manager\n")
        md.write("  - *What it does:* Bulk upload tool for ingesting new AON references via Excel/CSV.\n")
        md.write("- **Repeat Purchase Alert**\n")
        md.write("  - *Accessible By:* STC, Category Manager, Manager\n")
        md.write("  - *What it does:* Automatically flags and warns procurement teams if they are repeatedly buying identical items without a long-term contract.\n\n")

        # Analytics
        md.write("### 📈 Analytics\n")
        md.write("- **Cost Avoidance**\n")
        md.write("  - *Accessible By:* Category Manager, Manager\n")
        md.write("  - *What it does:* Financial dashboards that track soft savings generated through negotiation *before* a Purchase Order is issued.\n")
        md.write("- **Cost Savings**\n")
        md.write("  - *Accessible By:* Category Manager, Manager, STC\n")
        md.write("  - *What it does:* Hard financial dashboards tracking actualized cost savings against predefined baseline budgets.\n")
        md.write("- **SLA Tracking**\n")
        md.write("  - *Accessible By:* Category Manager, Manager\n")
        md.write("  - *What it does:* Operational metrics that monitor turnaround times and bottleneck delays for the procurement team to ensure PRs are handled promptly.\n\n")

        # Configuration
        md.write("### 🛠️ Configuration\n")
        md.write("- **Master Data**\n")
        md.write("  - *Accessible By:* Category Manager, Manager, PIC\n")
        md.write("  - *What it does:* The core repository for SKUs, product dictionaries, items, and mapping records. Central source of truth for the system.\n")
        md.write("- **SKU Dictionary**\n")
        md.write("  - *Accessible By:* All Internal Users\n")
        md.write("  - *What it does:* A searchable taxonomy dictionary used to find correct naming structures for items.\n")
        md.write("- **PGroup Setup**\n")
        md.write("  - *Accessible By:* Category Manager, Manager\n")
        md.write("  - *What it does:* Interface to define Purchasing Groups and map them to their appropriate procurement teams.\n")
        md.write("- **Master Vendor Evaluation**\n")
        md.write("  - *Accessible By:* Category Manager\n")
        md.write("  - *What it does:* Configuration portal to build and edit Question Sets, individual Questions, and criteria for the Vendor Evaluation system.\n")
        md.write("- **Maintenance Settings**\n")
        md.write("  - *Accessible By:* Category Manager\n")
        md.write("  - *What it does:* Administrative controls, including putting the system into Maintenance Mode.\n\n")

        md.write("<div style=\"page-break-after: always;\"></div>\n\n")
        md.write("## 3. 👥 Who Uses What? (Roles & Flows)\n")
        md.write("## 4. 🗄️ Database Architecture & ER Diagram\n")
        md.write("### Database Relationships (ERD)\n")
        md.write("```mermaid\n")
        md.write("erDiagram\n")
        # Ensure we don't crash mermaid with too many relationships
        for (t1, c1, t2, c2) in relations:
            md.write(f"    {t1} }}|--|| {t2} : \"{c1} -> {c2}\"\n")
        md.write("```\n\n")
        
        md.write("### All Database Tables\n")
        md.write("| 📁 Table Name | Key Information Stored |\n")
        md.write("| :--- | :--- |\n")
        for table, cols in schemas.items():
            friendly_cols = []
            for c in cols:
                friendly_c = c.split()[0].replace('`', '')
                if friendly_c.lower() not in ['primary', 'key', 'unique', 'constraint', 'index']:
                    friendly_cols.append(friendly_c)
            cols_str = ", ".join(friendly_cols)
            md.write(f"| `{table}` | {cols_str} |\n")
        
        md.write("\n<div style=\"page-break-after: always;\"></div>\n\n")
        md.write("## 5. ⚙️ Function Hierarchy & Call Graph\n")
        md.write("### Core System Function Flows\n")
        md.write("This diagram maps out how key functions interact and call one another internally.\n")
        md.write("```mermaid\n")
        md.write("graph TD\n")
        edges_rendered = 0
        for rel_path, data in architecture.items():
            for func in data['functions']:
                for c in func['calls']:
                    md.write(f"    {func['name']} --> {c}\n")
                    edges_rendered += 1
                    if edges_rendered > 150: break
            if edges_rendered > 150: break
        md.write("```\n\n")
        
        md.write("<div style=\"page-break-after: always;\"></div>\n\n")
        md.write("## 6. 🖥️ Detailed Codebase API\n")
        for rel_path, data in architecture.items():
            md.write(f"### 📄 `{rel_path}`\n")
            if data['classes']:
                md.write(f"- **Classes Used:** {', '.join(data['classes'])}\n")
            if data['functions']:
                md.write("- **Functions Inside:**\n")
                for func in data['functions']:
                    desc_parts = []
                    if func['sql_actions']:
                        desc_parts.append("Interacts with database (" + ", ".join(func['sql_actions']) + ")")
                    if func['calls']:
                        desc_parts.append("Calls internal functions (" + ", ".join(func['calls']) + ")")
                    
                    if not desc_parts:
                        desc_parts.append("Performs internal processing or utility operations")
                        
                    friendly_desc = ". ".join(desc_parts) + "."
                    md.write(f"  - `{func['name']}({func['params']})` - *{friendly_desc}*\n")
            md.write("\n")

if __name__ == "__main__":
    main()
