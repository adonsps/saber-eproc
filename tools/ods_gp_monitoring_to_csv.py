#!/usr/bin/env python3
"""
ods_gp_monitoring_to_csv.py

One-off converter for the "Monitoring GP Project" workbook. Streams the three
line-item detail sheets (Retail / Maintenance / Property), keeps only Year=2026
rows, maps each sheet to its Cost Avoidance team, and emits a normalized CSV
consumed by tools/import_gp_monitoring_cost_avoidance.php.

This script does NOT touch the database. All dedup-against-prs and DB writes
happen in the PHP importer. Columns are located by HEADER NAME (the three sheets
have slightly different column positions), and dates/numbers are read from the
ODS office:date-value / office:value attributes for clean, locale-free values.

Usage:
    python tools/ods_gp_monitoring_to_csv.py \
        "C:/path/to/Monitoring GP Project.ods" \
        db_backups/local_runtime_imports/gp_monitoring_2026.csv
"""
import sys, csv, zipfile
import xml.etree.ElementTree as ET

TABLE  = "urn:oasis:names:tc:opendocument:xmlns:table:1.0"
OFFICE = "urn:oasis:names:tc:opendocument:xmlns:office:1.0"
TEXT   = "urn:oasis:names:tc:opendocument:xmlns:text:1.0"
def q(ns, tag): return f"{{{ns}}}{tag}"

# sheet name -> full team name (matches ca_manual_team_options() in
# workspace/includes/cost_avoidance_helper.php)
SHEET_TEAM = {
    "Retail":      "Project Konstruksi Retail",       # PKR
    "Maintenance": "Maintenance & Facility Management", # MFM
    "Property":    "Project Konstruksi Properti",      # PKP
}

# CSV output columns
OUT_COLS = [
    "sheet", "team_full", "canvassing_no", "po_number", "entry_date",
    "vendor_name", "item_description", "taxonomy_category",
    "unit_price_before", "avoided_price", "total_cost_avoidance",
    "year", "month", "remarks", "bu", "pic",
]

def cell_value(cell):
    """Return (display_text, value_type, date_value, num_value)."""
    vt   = cell.get(q(OFFICE, "value-type"))
    dv   = cell.get(q(OFFICE, "date-value"))
    nv   = cell.get(q(OFFICE, "value"))
    parts = ["".join(p.itertext()) for p in cell.iter(q(TEXT, "p"))]
    txt = " ".join(parts).strip()
    return txt, vt, dv, nv

def best_number(disp, nv):
    if nv is not None and nv != "":
        return nv
    return (disp or "").replace(",", "").strip()

def money2(s):
    """Round a monetary string to 2 decimals; blank stays blank."""
    s = (s or "").strip()
    if s == "":
        return ""
    try:
        return f"{round(float(s), 2):.2f}"
    except ValueError:
        return s

def best_date(disp, dv):
    # ODS date-value is ISO "YYYY-MM-DD..." — take the date part.
    if dv:
        return dv[:10]
    return (disp or "").strip()   # leave textual dates for PHP to parse

def norm_header(h):
    return " ".join((h or "").split()).strip().lower()

def build_col_map(cells_disp):
    """Map normalized header name -> first column index."""
    m = {}
    for i, h in enumerate(cells_disp):
        key = norm_header(h)
        if key and key not in m:
            m[key] = i
    return m

def get(cells, idx, default=""):
    return cells[idx] if idx is not None and idx < len(cells) else default

def main():
    if len(sys.argv) < 3:
        print("usage: ods_gp_monitoring_to_csv.py <in.ods> <out.csv>", file=sys.stderr)
        sys.exit(2)
    ods_path, out_path = sys.argv[1], sys.argv[2]

    z = zipfile.ZipFile(ods_path)
    f = z.open("content.xml")

    out = open(out_path, "w", encoding="utf-8", newline="")
    w = csv.writer(out)
    w.writerow(OUT_COLS)

    cur = None
    active = False
    header_map = None
    per_sheet = {}
    total = 0

    for event, elem in ET.iterparse(f, events=("start", "end")):
        if event == "start" and elem.tag == q(TABLE, "table"):
            cur = elem.get(q(TABLE, "name"))
            active = cur in SHEET_TEAM
            header_map = None
            if active:
                per_sheet.setdefault(cur, 0)
        elif event == "end" and elem.tag == q(TABLE, "table-row"):
            if active:
                disp, vt, dv, nv = [], [], [], []
                for cell in list(elem):
                    if cell.tag not in (q(TABLE, "table-cell"), q(TABLE, "covered-table-cell")):
                        continue
                    rep = int(cell.get(q(TABLE, "number-columns-repeated"), "1"))
                    # cap absurd trailing repeats (blank filler columns)
                    rep = min(rep, 200)
                    d, t, dval, n = cell_value(cell)
                    for _ in range(rep):
                        disp.append(d); vt.append(t); dv.append(dval); nv.append(n)

                if header_map is None:
                    hm = build_col_map(disp)
                    if "canvassing no." in hm and "year" in hm:
                        header_map = hm
                else:
                    ci_year = header_map.get("year")
                    yr_disp = get(disp, ci_year)
                    yr = best_number(yr_disp, get(nv, ci_year)).replace(".0", "")
                    try:
                        yr_int = int(float(yr)) if yr else 0
                    except ValueError:
                        yr_int = 0
                    if yr_int == 2026:
                        def col(name):
                            return header_map.get(name)
                        ci_before = col("price before nego")
                        ci_after  = col("price after nego")
                        ci_saving = col("saving")
                        before = best_number(get(disp, ci_before), get(nv, ci_before))
                        after  = best_number(get(disp, ci_after),  get(nv, ci_after))
                        saving = best_number(get(disp, ci_saving), get(nv, ci_saving))
                        # fall back to before-after if saving blank
                        if (saving == "" or saving is None):
                            try:
                                saving = str(float(before or 0) - float(after or 0))
                            except ValueError:
                                saving = "0"
                        before, after, saving = money2(before), money2(after), money2(saving)
                        ci_cd = col("canvassing date")
                        entry_date = best_date(get(disp, ci_cd), get(dv, ci_cd))
                        if not entry_date:
                            ci_ad = col("assign date")
                            entry_date = best_date(get(disp, ci_ad), get(dv, ci_ad))
                        ci_month = col("month")
                        month = best_number(get(disp, ci_month), get(nv, ci_month)).replace(".0", "")

                        row = [
                            cur,
                            SHEET_TEAM[cur],
                            get(disp, col("canvassing no.")).strip(),
                            get(disp, col("no. po")).strip().replace(",", ""),
                            entry_date,
                            get(disp, col("winner vendor")).strip(),
                            get(disp, col("project description")).strip(),
                            get(disp, col("category management")).strip(),
                            before, after, saving,
                            "2026", month,
                            get(disp, col("remarks")).strip(),
                            get(disp, col("bu")).strip(),
                            get(disp, col("pic")).strip(),
                        ]
                        w.writerow(row)
                        per_sheet[cur] += 1
                        total += 1
                elem.clear()
        elif event == "end" and elem.tag == q(TABLE, "table"):
            elem.clear()

    out.close()
    for s, n in per_sheet.items():
        print(f"  {s:12s} ({SHEET_TEAM[s]}): {n} rows (Year=2026)", file=sys.stderr)
    print(f"TOTAL 2026 rows written: {total} -> {out_path}", file=sys.stderr)

if __name__ == "__main__":
    main()
