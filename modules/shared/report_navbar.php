<?php
// modules/shared/report_navbar.php
// Horizontal navigation bar for the Report section: switch between the
// Vendor Distribution and Spending per Category views of reports.php
// (?view=vendor-distribution | ?view=spending-per-category). Set
$report_navbar_active = $report_navbar_active ?? 'vendor-distribution';

// PQ Risk Analysis is Vendor Management-only, and only modules/vendormgt/reports.php
// serves that view — the including page opts in so the tab never links to a
// reports.php that cannot render it.
$report_navbar_show_pq_risk = $report_navbar_show_pq_risk ?? false;
?>
<style>
    /* Layout: nav bar on top, content below (single column). */
    .report-layout  { display: flex; flex-direction: column; gap: 1rem; }
    .report-content { min-width: 0; flex: 1 1 auto; }

    /* Dashboard nav bar */
    .report-nav { width: 100%; }
    .report-nav .report-nav-links {
        display: flex;
        align-items: stretch;
        gap: .5rem;
        flex-wrap: wrap;
    }
    .report-nav .report-nav-item {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        flex: 1 1 0;                          
        gap: .5rem;
        border: 1px solid transparent;
        border-radius: 50px;            
        padding: .5rem 1.15rem;
        color: #7c8ba1;
        font-weight: 500;
        letter-spacing: .3px;
        line-height: 1.3;
        white-space: nowrap;
        text-decoration: none;
        transition: color .2s ease, background-color .2s ease, border-color .2s ease, box-shadow .2s ease;
    }
    .report-nav .report-nav-item i {
        font-size: 1rem;
        color: #aeb9cc;
        transition: color .2s ease;
    }
    .report-nav .report-nav-item:hover {
        color: #4a7fb0;
        background: #eef5fb;               
    }
    .report-nav .report-nav-item:hover i { color: #4a7fb0; }
    .report-nav .report-nav-item.active {
        background: linear-gradient(135deg, #dbebf7 0%, #cfe6f0 100%); /* soft pastel blue */
        border-color: #bcdcf0;
        color: #2f6690;
        box-shadow: 0 3px 8px rgba(79, 127, 176, .18);
    }
    .report-nav .report-nav-item.active i { color: #5b93bf; }
</style>

<nav class="report-nav" id="reportNav">
    <div class="container">
        <div class="card shadow-sm">
            <div class="card-body p-2 report-nav-links">
                <a href="reports.php?view=vendor-distribution" title="Vendor Distribution" class="report-nav-item <?= $report_navbar_active === 'vendor-distribution' ? 'active' : '' ?>">
                    <i class="fas fa-chart-pie"></i><span>Vendor Distribution</span>
                </a>
                <a href="reports.php?view=spending-per-category" title="Spending per Category" class="report-nav-item <?= $report_navbar_active === 'spending-per-category' ? 'active' : '' ?>">
                    <i class="fas fa-coins"></i><span>Spending per Category</span>
                </a>
                <?php if ($report_navbar_show_pq_risk): ?>
                <a href="reports.php?view=pq-risk-analysis" title="PQ Risk Analysis" class="report-nav-item <?= $report_navbar_active === 'pq-risk-analysis' ? 'active' : '' ?>">
                    <i class="fas fa-triangle-exclamation"></i><span>PQ Risk Analysis</span>
                </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</nav>
