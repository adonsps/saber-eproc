import os
import re
import json
from collections import defaultdict

ROOT_DIR = "/Users/adonispallassutanto/Documents/vms"
OUTPUT_MD = os.path.join(ROOT_DIR, "VSS_Workspace_Documentation.md")
OUTPUT_JSON = os.path.join(ROOT_DIR, "VSS_Workspace_Documentation.json")

IGNORE_DIRS = {'.git', 'vendor', 'libs', 'assets', 'uploads', 'hyperframes_videos', 'hyperframes_rfq_launch', 'tools'}

# Patterns
FUNC_PATTERN = re.compile(r'function\s+([a-zA-Z0-9_]+)\s*\((.*?)\)')
CLASS_PATTERN = re.compile(r'class\s+([a-zA-Z0-9_]+)')
TABLE_PATTERN = re.compile(r'CREATE\s+TABLE\s+`?([a-zA-Z0-9_]+)`?\s*\((.*?)\);', re.DOTALL | re.IGNORECASE)

def parse_php_file(filepath):
    funcs = []
    classes = []
    try:
        with open(filepath, 'r', encoding='utf-8', errors='ignore') as f:
            content = f.read()
            # Extract functions
            for match in FUNC_PATTERN.finditer(content):
                funcs.append({
                    "name": match.group(1),
                    "params": match.group(2).strip()
                })
            # Extract classes
            for match in CLASS_PATTERN.finditer(content):
                classes.append(match.group(1))
    except Exception as e:
        pass
    return {"functions": funcs, "classes": classes}

def extract_db_schema():
    tables = {}
    db_backups_dir = os.path.join(ROOT_DIR, "db_backups")
    if os.path.exists(db_backups_dir):
        for file in os.listdir(db_backups_dir):
            if file.endswith('.sql'):
                with open(os.path.join(db_backups_dir, file), 'r', encoding='utf-8', errors='ignore') as f:
                    content = f.read()
                    for match in TABLE_PATTERN.finditer(content):
                        table_name = match.group(1)
                        schema = match.group(2).strip()
                        columns = []
                        for line in schema.split('\n'):
                            line = line.strip()
                            if line and not line.startswith('--') and not line.startswith('/*'):
                                columns.append(line.rstrip(','))
                        tables[table_name] = columns
    return tables

def main():
    architecture = defaultdict(dict)
    
    # 1. Parse all PHP files
    for root, dirs, files in os.walk(ROOT_DIR):
        dirs[:] = [d for d in dirs if d not in IGNORE_DIRS]
        for file in files:
            if file.endswith('.php'):
                filepath = os.path.join(root, file)
                rel_path = os.path.relpath(filepath, ROOT_DIR)
                parsed = parse_php_file(filepath)
                if parsed['functions'] or parsed['classes']:
                    architecture[rel_path] = parsed
    
    # 2. Extract schemas
    schemas = extract_db_schema()

    # 3. Create JSON layout
    payload = {
        "architecture": architecture,
        "database_schemas": schemas
    }
    
    with open(OUTPUT_JSON, 'w', encoding='utf-8') as f:
        json.dump(payload, f, indent=4)
        
    # 4. Generate Markdown for Document Export (User-Friendly Version)
    with open(OUTPUT_MD, 'w', encoding='utf-8') as md:
        md.write("# 📘 Master System Guide: VSS & Workspace\n\n")
        md.write("> *This document is designed to provide a clear, easy-to-understand overview of our system for all team members, regardless of technical background.*\n\n")
        
        md.write("<div style=\"page-break-after: always;\"></div>\n\n")
        md.write("## 📑 Table of Contents\n")
        md.write("1. [🌟 System Overview](#1--system-overview)\n")
        md.write("2. [👥 Who Uses What? (Roles & Flows)](#2--who-uses-what-roles--flows)\n")
        md.write("3. [🖥️ What Does It Look Like? (UI Layouts)](#3--what-does-it-look-like-ui-layouts)\n")
        md.write("4. [🗄️ Where Is Data Stored? (Database)](#4--where-is-data-stored-database)\n")
        md.write("5. [⚙️ Under the Hood (For the Tech Team)](#5--under-the-hood-for-the-tech-team)\n\n")
        
        md.write("<div style=\"page-break-after: always;\"></div>\n\n")
        md.write("## 1. 🌟 System Overview\n")
        md.write("Our platform is split into two main areas that work together to handle everything from vendor registration to project approvals:\n\n")
        md.write("### 🏢 **VSS (Vendor Sourcing System)**\n")
        md.write("- **What it is:** The front door of our application.\n")
        md.write("- **What it does:** Allows outside vendors to register, log in, and manage their profiles safely.\n\n")
        
        md.write("### 💻 **Workspace**\n")
        md.write("- **What it is:** The internal portal for our team.\n")
        md.write("- **What it does:** Used by Category Managers, Engineers, and Procurement teams to evaluate vendors, track projects, and manage data efficiently.\n\n")
        
        md.write("<div style=\"page-break-after: always;\"></div>\n\n")
        md.write("## 2. 👥 Who Uses What? (Roles & Flows)\n")
        md.write("Different people use our system in different ways. Here is how they interact:\n\n")
        
        md.write("- **Vendors:** Stay on the VSS side. They submit their profiles and respond to requests.\n")
        md.write("- **Category Managers:** Use the Workspace to review vendors and approve new products (SKUs).\n")
        md.write("- **Engineers:** Log into Workspace to request new projects and materials.\n")
        md.write("- **Procurement Team:** Track Service Level Agreements (SLAs) and process orders.\n\n")
        
        md.write("> **Visual Flow of a Typical Request:**\n\n")
        md.write("```mermaid\n")
        md.write("graph TD\n")
        md.write("    A[Vendor Registers] --> B[System Saves Data]\n")
        md.write("    B --> C[Category Manager Reviews Data]\n")
        md.write("    C --> D[Category Manager Approves/Rejects]\n")
        md.write("    D --> E[Internal Team Can Now Use Vendor]\n")
        md.write("```\n\n")
        
        md.write("<div style=\"page-break-after: always;\"></div>\n\n")
        md.write("## 3. 🖥️ What Does It Look Like? (UI Layouts)\n")
        md.write("Here is a simple breakdown of the main Dashboard that our internal team sees every day.\n\n")
        md.write("### Dashboard Manager\n")
        md.write("- **Top Bar (Quick Filters):** Easy buttons to filter tasks by status (e.g., 'Show only User Suggested', 'Show only AI Suggested').\n")
        md.write("- **Middle Section (Metrics):** A quick snapshot of how many tasks are pending and if any are overdue (SLA warnings).\n")
        md.write("- **Main Area (Data Table):** The core list of pending requests, projects, and evaluations waiting for action.\n\n")
        
        md.write("<div style=\"page-break-after: always;\"></div>\n\n")
        md.write("## 4. 🗄️ Where Is Data Stored? (Database)\n")
        md.write("We organize our data into different 'tables' (like spreadsheets) so it's easy to find. Here are some of the most important ones:\n\n")
        
        # Friendly DB Output as a Table
        md.write("| 📁 Table Name | Description | Key Information Stored |\n")
        md.write("| :--- | :--- | :--- |\n")
        
        for table, cols in schemas.items():
            description = f"Stores information related to {table.replace('_', ' ')}"
            friendly_cols = []
            for c in cols:
                friendly_c = c.split()[0].replace('`', '').replace('_', ' ').title()
                friendly_cols.append(friendly_c)
            
            cols_str = ", ".join(friendly_cols)
                
            md.write(f"| `{table}` | {description} | {cols_str} |\n")
        
        md.write("\n<div style=\"page-break-after: always;\"></div>\n\n")
        md.write("## 5. ⚙️ Under the Hood (For the Tech Team)\n")
        md.write("*(This section is a quick reference for developers maintaining the code.)*\n\n")
        
        for rel_path, data in architecture.items():
            md.write(f"### 📄 `{rel_path}`\n")
            if data['classes']:
                md.write(f"- **Classes Used:** {', '.join(data['classes'])}\n")
            if data['functions']:
                md.write("- **Functions Inside:**\n")
                for func in data['functions']:
                    friendly_desc = f"Responsible for handling the {func['name'].replace('_', ' ')} logic and workflow."
                    md.write(f"  - `{func['name']}()` - *{friendly_desc}*\n")
            md.write("\n")
            
if __name__ == "__main__":
    main()
