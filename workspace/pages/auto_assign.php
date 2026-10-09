<?php
// pages/auto_assign.php
require_once '../config/database.php';
require_once '../includes/header.php';
// Same library ajax_process_batch.php runs on. This page used to hand-roll its
// own copy of the eligibility filter, which meant the progress bar and the
// endpoint could disagree about what was left -- the page would announce "all
// caught up" while the batch it launches still had work. Sharing the fragments
// is what keeps the headline honest.
require_once '../includes/ai_ticket_assigner.php';

// No pr_date floor here on purpose: ai_auto_assign_min_pr_date narrows the
// WORKER only, so the browser dispatcher stays the deliberate way to reach the
// older backlog.
$pending_sql   = ai_assign_pending_predicate();
$exclusion_sql = ai_assign_exclusion_sql($pdo, null);

// Fetch BOTH Ticket Count and Line Count
$stmt = $pdo->query("
    SELECT COUNT(DISTINCT ticket_no) as total_tickets, COUNT(*) as total_lines
    FROM prs
    WHERE $pending_sql
    $exclusion_sql
");
$counts = $stmt->fetch(PDO::FETCH_ASSOC);

$total_tickets = $counts['total_tickets'];
$total_lines = $counts['total_lines'];
?>

<div class="max-w-4xl mx-auto py-8">
    <div class="mb-6">
        <a href="upload_pr.php" class="bg-gray-500 hover:bg-gray-600 text-white font-bold py-2 px-4 rounded transition shadow inline-block">&larr; Back to Uploads</a>
    </div>

    <h2 class="text-2xl font-bold mb-6 text-gray-800">AI Dispatcher</h2>

    <?php if ($total_tickets == 0): ?>
        <div class='bg-blue-100 border-l-4 border-blue-500 text-blue-700 p-4 mb-6'>
            All qualified PRs are already assigned! You're all caught up.
        </div>
    <?php else: ?>

        <div id="progress-container" class="bg-white p-6 rounded-lg shadow-md border border-gray-200 mb-6">
            <div class="flex justify-between items-end mb-2">
                <h3 class="text-lg font-semibold" id="status-text">Processing 0 of <?= $total_tickets ?> Tickets...</h3>
                <div class="text-right">
                    <p class="text-xs font-bold text-gray-500 uppercase">Estimated API Tokens</p>
                    <p class="text-lg font-mono font-bold text-indigo-600" id="token-counter">0</p>
                </div>
            </div>
            
            <p class="text-sm text-gray-500 mb-4" id="status-subtext">The AI is analyzing <?= $total_lines ?> individual line items across these tickets to determine the best team mapping.</p>
            
            <div class="w-full bg-gray-200 rounded-full h-4 mb-4">
                <div id="progress-bar" class="bg-purple-600 h-4 rounded-full transition-all duration-500" style="width: 0%"></div>
            </div>
            
            <p class="text-sm font-bold text-purple-700 animate-pulse" id="loading-indicator">Connecting to Gemini API...</p>
        </div>

        <div class="bg-gray-900 rounded-lg shadow-md border border-gray-700 mb-6 overflow-hidden">
            <div class="bg-gray-800 px-4 py-2 border-b border-gray-700 flex justify-between items-center">
                <h4 class="text-xs font-bold text-gray-300 uppercase tracking-wider">System Log & Diagnostics</h4>
                <div class="flex space-x-2">
                    <div class="w-3 h-3 rounded-full bg-red-500"></div>
                    <div class="w-3 h-3 rounded-full bg-yellow-500"></div>
                    <div class="w-3 h-3 rounded-full bg-green-500"></div>
                </div>
            </div>
            <div id="diagnostic-console" class="p-4 text-xs font-mono text-green-400 h-48 overflow-y-auto space-y-1">
                <div>> Initializing AI batch processing...</div>
                <div>> Target queue: <?= $total_tickets ?> Tickets.</div>
            </div>
        </div>

        <div id="results-container" class="hidden">
            <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-4 shadow">
                <p class="font-bold text-lg">Auto-Assignment Complete!</p>
                <p>Gemini successfully mapped and routed <strong id="total-success">0</strong> Tickets.</p>
            </div>

            <div id="fallback-box" class="hidden bg-blue-100 border-l-4 border-blue-500 text-blue-800 p-4 mb-4 shadow">
                <p class="font-bold text-sm">Smart Fallbacks Used</p>
                <ul id="fallback-list" class="list-disc pl-5 text-xs mt-1"></ul>
            </div>

            <div id="failed-box" class="hidden bg-yellow-100 border-l-4 border-yellow-500 text-yellow-800 p-4 mb-6 shadow">
                <p class="font-bold">Failed to Assign</p>
                <p class="text-sm mb-2">The AI's guess was too far outside your taxonomy:</p>
                <ul id="failed-list" class="list-disc pl-5 text-sm"></ul>
            </div>

            <div class="flex space-x-4">
                <a href="dashboard_mgr.php" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2 px-4 rounded transition">Go to Dashboard</a>
            </div>
        </div>

        <script>
            const totalTickets = <?= $total_tickets ?>; 
            let totalSuccess = 0;
            let totalTokens = 0;
            let allFallbacks = [];
            let allFailures = [];
            let errorRetryCount = 0;

            function logToConsole(message, type = 'info') {
                const consoleDiv = document.getElementById('diagnostic-console');
                const time = new Date().toLocaleTimeString();
                
                let colorClass = 'text-green-400';
                if (type === 'error') colorClass = 'text-red-400';
                if (type === 'warning') colorClass = 'text-yellow-400';

                const logLine = document.createElement('div');
                logLine.className = colorClass;
                logLine.innerHTML = `<span class="text-gray-500">[${time}]</span> > ${message}`;
                
                consoleDiv.appendChild(logLine);
                consoleDiv.scrollTop = consoleDiv.scrollHeight; // Auto-scroll to bottom
            }

            function processNextBatch() {
                document.getElementById('loading-indicator').innerText = "Processing batch via Gemini API...";
                document.getElementById('loading-indicator').className = "text-sm font-bold text-purple-700 animate-pulse";

                fetch('ajax_process_batch.php')
                    .then(response => response.json())
                    .then(data => {
                        if (data.status === 'error') {
                            // API Hit an error (Rate limit, JSON crash, etc)
                            logToConsole(`API Error: ${data.message}`, 'error');
                            triggerAutoRetry();
                            return;
                        }

                        // Success! Reset retry count.
                        errorRetryCount = 0; 
                        
                        if (data.status === 'complete' || data.remaining === 0) {
                            logToConsole(`Batch processing complete!`, 'info');
                            finishProcessing();
                            return;
                        }

                        // Accumulate results
                        totalSuccess += data.successCount;
                        if(data.estimated_tokens) totalTokens += data.estimated_tokens;
                        
                        allFallbacks = allFallbacks.concat(data.fallbackDetails);
                        allFailures = allFailures.concat(data.failedDetails);

                        // Update Progress Bar & Tokens
                        let processedCount = totalTickets - data.remaining;
                        let percent = Math.round((processedCount / totalTickets) * 100);
                        
                        document.getElementById('progress-bar').style.width = percent + '%';
                        document.getElementById('status-text').innerText = `Processed ${processedCount} of ${totalTickets} Tickets...`;
                        document.getElementById('token-counter').innerText = totalTokens.toLocaleString();

                        logToConsole(`Successfully processed batch. Target queue remaining: ${data.remaining}`, 'info');

                        // Small 1 second delay between batches to respect rate limits naturally
                        setTimeout(processNextBatch, 1000);
                    })
                    .catch(error => {
                        logToConsole(`Network or Server Timeout: ${error.message}`, 'error');
                        triggerAutoRetry();
                    });
            }

            function triggerAutoRetry() {
                errorRetryCount++;
                if (errorRetryCount > 5) {
                    logToConsole(`CRITICAL: Failed 5 times in a row. Halting execution to prevent infinite loop.`, 'error');
                    document.getElementById('loading-indicator').innerText = "Execution Halted due to multiple errors.";
                    document.getElementById('loading-indicator').className = "text-sm font-bold text-red-600";
                    return;
                }

                let countdown = 10;
                document.getElementById('loading-indicator').className = "text-sm font-bold text-yellow-600";
                
                const timer = setInterval(() => {
                    document.getElementById('loading-indicator').innerText = `Cooling down API. Retrying in ${countdown} seconds...`;
                    countdown--;
                    if (countdown < 0) {
                        clearInterval(timer);
                        logToConsole(`Attempting auto-retry (${errorRetryCount}/5)...`, 'warning');
                        processNextBatch();
                    }
                }, 1000);
            }

            function finishProcessing() {
                document.getElementById('progress-bar').style.width = '100%';
                document.getElementById('progress-container').classList.add('hidden');
                document.getElementById('results-container').classList.remove('hidden');
                document.getElementById('total-success').innerText = totalSuccess;

                if (allFallbacks && allFallbacks.length > 0) {
                    document.getElementById('fallback-box').classList.remove('hidden');
                    const fbList = document.getElementById('fallback-list');
                    allFallbacks.forEach(item => {
                        let li = document.createElement('li');
                        li.innerHTML = item;
                        fbList.appendChild(li);
                    });
                }

                if (allFailures && allFailures.length > 0) {
                    document.getElementById('failed-box').classList.remove('hidden');
                    const failList = document.getElementById('failed-list');
                    allFailures.forEach(item => {
                        let li = document.createElement('li');
                        li.innerHTML = item;
                        failList.appendChild(li);
                    });
                }
            }

            // Start the loop as soon as the page loads
            processNextBatch();
        </script>

    <?php endif; ?>
</div>

<?php require_once '../includes/footer.php'; ?>
