<?php
declare(strict_types=1);

// STDERR is only auto-defined under the CLI SAPI; fwrite(STDERR, ...) here
// under a web SAPI (found 2026-07-27 via production smoke testing) throws an
// uncaught "undefined constant" fatal error, which leaks the internal server
// file path in PHP's default stack trace instead of the intended CLI-only
// message. A web request must be rejected before this line does anything
// else (no bootstrap, no queue claim, no DB access), with no STDERR/output
// that depends on being in a CLI process.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(0);
}

require_once __DIR__ . '/../includes/automation/api_config.php';
require_once __DIR__ . '/../includes/automation/import_orchestrator.php';
require_once __DIR__ . '/../includes/automation/file_storage.php';
require_once __DIR__ . '/../includes/automation/diagnostics_logger.php';
require_once __DIR__ . '/../includes/automation/parser_adapters.php';
// NOTE: workspace/includes/ai_ticket_assigner.php is deliberately NOT required
// here. It is loaded inside k2_worker_run_ai_assignment(), so that importing PR
// and AON data -- this worker's actual job -- cannot be affected by anything
// wrong with the assignment library. See the comment at that require.

const K2_WORKER_EXIT_OK = 0;
const K2_WORKER_EXIT_ENV_ERROR = 1;
const K2_WORKER_EXIT_INTERNAL_ERROR = 2;

/** Advisory lock keeping two overlapping worker ticks from both running AI assignment. */
const K2_WORKER_AI_ASSIGN_LOCK_NAME = 'ai_auto_assign_global';

final class K2ImportWorkerConfig
{
    public function __construct(
        public readonly int $leaseSeconds = 1800,
        public readonly int $workerLockSeconds = 1800,
        public readonly int $failedRetentionSeconds = 259200
    ) {
        if ($leaseSeconds < 1) {
            throw new InvalidArgumentException('K2_AUTOMATION_WORKER_LEASE_SECONDS must be at least one second.');
        }
        if ($workerLockSeconds < 1) {
            throw new InvalidArgumentException('K2_AUTOMATION_WORKER_LOCK_SECONDS must be at least one second.');
        }
        if ($failedRetentionSeconds < 0) {
            throw new InvalidArgumentException('K2_AUTOMATION_FAILED_RETENTION_SECONDS must not be negative.');
        }
    }

    public static function fromEnvironment(): self
    {
        $lease = getenv('K2_AUTOMATION_WORKER_LEASE_SECONDS');
        $lock = getenv('K2_AUTOMATION_WORKER_LOCK_SECONDS');
        $retention = getenv('K2_AUTOMATION_FAILED_RETENTION_SECONDS');

        return new self(
            $lease === false || trim($lease) === '' ? 1800 : (int) $lease,
            $lock === false || trim($lock) === '' ? 1800 : (int) $lock,
            $retention === false || trim($retention) === '' ? 259200 : (int) $retention
        );
    }
}

/** operation_type => [adapter class, required upload fields => dataset type]. Archive has no queued phase. */
function k2_worker_operation_specs(): array
{
    return [
        K2ImportOperationType::AON => [
            'adapterClass' => K2AutomationAonXlsxAdapter::class,
            'fields' => [
                'catalog_file' => K2ImportDatasetType::CATALOG,
                'price_file' => K2ImportDatasetType::PRICE,
            ],
        ],
        K2ImportOperationType::PR => [
            'adapterClass' => K2AutomationPrXlsxAdapter::class,
            'fields' => [
                'pr_file' => K2ImportDatasetType::PR,
            ],
        ],
    ];
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    exit(k2_worker_main($argv));
}

function k2_worker_main(array $argv): int
{
    $options = k2_worker_parse_args($argv);
    $logger = K2AutomationDiagnosticsLogger::fromEnvironment();
    K2AutomationDiagnosticsLogger::installShutdownHandler($logger, ['stage' => 'worker_main']);

    try {
        $pdo = k2_worker_pdo();
    } catch (Throwable $e) {
        $logger->exception('WORKER_DB_CONNECT_FAILED', $e);

        return K2_WORKER_EXIT_ENV_ERROR;
    }

    try {
        $repo = new PdoK2ImportRunRepository($pdo);
        $apiConfig = K2AutomationApiConfig::fromEnvironment();
        $orchestrator = new K2ImportOrchestrator($repo, $apiConfig->lockSeconds, null, $apiConfig->staleOperationSeconds);
        $fileStore = new K2AutomationStagedFileStore(K2AutomationFileStorageConfig::fromEnvironment());
        $parserConfig = K2AutomationParserAdapterConfig::fromEnvironment();
        $workerConfig = K2ImportWorkerConfig::fromEnvironment();
    } catch (Throwable $e) {
        $logger->exception('WORKER_CONFIG_INVALID', $e);

        return K2_WORKER_EXIT_ENV_ERROR;
    }

    $hostname = gethostname();
    $hostname = $hostname === false ? 'unknown-host' : $hostname;
    $pid = getmypid();
    $pid = $pid === false ? 0 : $pid;

    $claimed = 0;
    $internalErrors = 0;
    $prImported = false;
    for ($i = 0; $i < $options['limit']; $i++) {
        $claimedOperationType = null;
        $outcome = k2_worker_process_next(
            $pdo,
            $repo,
            $orchestrator,
            $fileStore,
            $parserConfig,
            $workerConfig,
            $logger,
            k2_worker_generate_token(),
            $hostname,
            $pid,
            null,
            $claimedOperationType
        );

        if ($outcome === K2WorkerOutcome::NOTHING_CLAIMABLE) {
            $logger->info('QUEUE_EMPTY', ['claimed_this_run' => $claimed]);
            break;
        }

        $claimed++;
        if ($outcome === K2WorkerOutcome::INTERNAL_ERROR) {
            $internalErrors++;
        }
        if (k2_worker_should_run_ai_assignment($outcome, $claimedOperationType)) {
            $prImported = true;
        }
    }

    try {
        $expired = $fileStore->sweepExpired(null, $workerConfig->failedRetentionSeconds);
        if ($expired > 0) {
            $logger->info('STAGED_FILE_CLEANUP_EXPIRED', ['removed_count' => $expired]);
        }
    } catch (Throwable $e) {
        $logger->exception('STAGED_FILE_CLEANUP_FAILED', $e);
    }

    // --- AI ticket assignment ------------------------------------------------
    // Chained here on purpose: k2_worker_process_next() has already returned AND
    // its finally block has released both the batch lock and the worker claim,
    // so the batch is durably at ARCHIVE_PENDING by now. A slow or hanging Gemini
    // call therefore cannot strand a batch in PR_PROCESSING or blow the worker
    // lease -- it can only delay this process. Runs only after a PR import (an
    // AON-only run cannot have added anything to the assignment queue).
    //
    // Best-effort throughout: k2_worker_run_ai_assignment() swallows its own
    // failures and deliberately does NOT feed into the exit code below, which
    // stays governed by the import outcome alone so cron monitoring does not
    // start alarming on Gemini flakiness.
    $aiAssign = ['ran' => false];
    if ($prImported) {
        $aiAssign = k2_worker_run_ai_assignment($pdo, $logger);
    }

    $logger->info('WORKER_RUN_COMPLETED', [
        'claimed' => $claimed,
        'internal_errors' => $internalErrors,
        'limit' => $options['limit'],
        'ai_assign' => $aiAssign,
    ]);

    return $internalErrors > 0 ? K2_WORKER_EXIT_INTERNAL_ERROR : K2_WORKER_EXIT_OK;
}

/**
 * Claims and fully processes exactly one queued attempt, if any is available.
 * Returns NOTHING_CLAIMABLE when the queue is empty, SUCCESS/RETRYABLE_FAILURE/
 * FINAL_FAILURE when a claimed attempt was resolved and durably recorded, or
 * INTERNAL_ERROR when the claim itself could not be safely resolved (the batch
 * lock or the terminal record could not be written) — this is the one case that
 * needs an operator to look at queue state directly.
 *
 * $claimedOperationType is an optional out-param: it receives the claimed
 * attempt's operation type (K2ImportOperationType::AON|PR), or stays null when
 * nothing was claimable. k2_worker_main() uses it to decide whether the run
 * imported PR data and therefore may have added work to the AI assignment queue.
 */
function k2_worker_process_next(
    PDO $pdo,
    K2ImportRunRepositoryInterface $repo,
    K2ImportOrchestrator $orchestrator,
    K2AutomationStagedFileStore $fileStore,
    K2AutomationParserAdapterConfig $parserConfig,
    K2ImportWorkerConfig $workerConfig,
    K2AutomationDiagnosticsLogger $logger,
    string $workerToken,
    string $hostname,
    int $pid,
    ?K2AutomationWorkbookNormalizerInterface $normalizerOverride = null,
    ?string &$claimedOperationType = null
): string {
    $claimedOperationType = null;

    try {
        $claimed = $orchestrator->claimNextForProcessing($workerToken, $hostname, $pid, $workerConfig->leaseSeconds);
    } catch (Throwable $e) {
        $logger->exception('WORKER_CLAIM_FAILED', $e, ['worker_claim_token' => K2AutomationDiagnosticsLogger::redactToken($workerToken)]);

        return K2WorkerOutcome::INTERNAL_ERROR;
    }

    if ($claimed === null) {
        return K2WorkerOutcome::NOTHING_CLAIMABLE;
    }

    $batchUid = (string) $claimed['batch_uid'];
    $operationType = (string) $claimed['operation_type'];
    $claimedOperationType = $operationType;
    $attemptId = (int) $claimed['attempt_id'];
    $attemptNumber = (int) $claimed['attempt_number'];
    $requestKey = (string) $claimed['request_idempotency_key'];
    $logicalRequestId = (string) ($claimed['logical_request_id'] ?? $requestKey);
    $scopedLogger = $logger->withContext($batchUid, $operationType, $attemptNumber, $logicalRequestId);
    $startedAt = microtime(true);

    $scopedLogger->info('WORKER_CLAIM_SUCCEEDED', ['worker_claim_token' => K2AutomationDiagnosticsLogger::redactToken($workerToken)]);

    $lockToken = null;
    $prepared = null;
    $outcome = K2WorkerOutcome::INTERNAL_ERROR;

    try {
        // The batch lock is claimed before staged-file verification (not after) so
        // that ANY failure from this point on — including a checksum mismatch —
        // always has a lock token available to record it through recordOperationFailure().
        $lock = $orchestrator->claimBatchLock($batchUid, null, $logicalRequestId);
        if (!$lock['success']) {
            throw new K2ImportRepositoryStateConflictException("Worker could not claim batch lock for {$batchUid}: {$lock['code']}");
        }
        $lockToken = (string) $lock['result']['lock_token'];
        $scopedLogger->info('WORKER_BATCH_LOCK_ACQUIRED', []);

        $manifest = json_decode((string) ($claimed['staged_file_manifest'] ?? ''), true);
        if (!is_array($manifest) || $manifest === []) {
            throw new K2AutomationFileStorageException('STAGED_MANIFEST_MISSING', 'Claimed attempt has no staged file manifest.', false);
        }

        $scopedLogger->info('STAGED_FILE_VERIFICATION_STARTED', []);
        foreach ($manifest as $entry) {
            $fileStore->verifyStagedFile($entry);
        }
        $scopedLogger->info('STAGED_FILE_VERIFICATION_COMPLETED', []);

        $orchestrator->extendWorkerLease($attemptId, $workerToken);

        $spec = k2_worker_operation_specs()[$operationType] ?? null;
        if ($spec === null) {
            throw new InvalidArgumentException("Worker does not know how to process operation type {$operationType}.");
        }

        $context = k2_worker_build_context($repo, (int) $claimed['batch_id'], $manifest, $spec['fields']);
        $adapterClass = $spec['adapterClass'];
        /** @var K2PreparedAonImportExecutorInterface|K2PreparedPrImportExecutorInterface $adapter */
        $adapter = new $adapterClass($pdo, $normalizerOverride ?? new K2AutomationOpenSpoutWorkbookNormalizer(), $parserConfig);

        $scopedLogger->info('NORMALIZATION_STARTED', []);
        $normalizeStart = microtime(true);
        $prepared = $operationType === K2ImportOperationType::AON
            ? $adapter->prepareAon($context)
            : $adapter->preparePr($context);
        $scopedLogger->info('NORMALIZATION_COMPLETED', ['elapsed_ms' => (int) round((microtime(true) - $normalizeStart) * 1000)]);

        $orchestrator->extendWorkerLease($attemptId, $workerToken);

        $scopedLogger->info('BUSINESS_TRANSACTION_STARTED', []);
        $writeStart = microtime(true);
        $completed = $orchestrator->recordOperationSuccessWithExecution(
            $batchUid,
            $operationType,
            $requestKey,
            $attemptId,
            fn(): K2ImportAdapterResult => $operationType === K2ImportOperationType::AON
                ? $adapter->executePreparedAon($prepared)
                : $adapter->executePreparedPr($prepared),
            $lockToken,
            $logicalRequestId
        );
        $scopedLogger->info('BUSINESS_TRANSACTION_COMPLETED', ['elapsed_ms' => (int) round((microtime(true) - $writeStart) * 1000)]);

        if ($operationType === K2ImportOperationType::PR && $completed['state'] === K2ImportBatchState::IMPORT_SUCCESS) {
            $completed = $orchestrator->markArchivePending($batchUid, $lockToken, $logicalRequestId);
        }

        // AON contract reminders, checked against the data this batch just wrote.
        //
        // Here rather than inside executePreparedAon() because that runs inside
        // the caller-owned business transaction: sending from in there would
        // announce SKUs a rollback then erases, and the log rows recording the
        // send would roll back with it — so the same reminder would go out again.
        // recordOperationSuccessWithExecution() has committed by the time this
        // line runs, so the data really is on disk.
        //
        // aon_reminder_after_import() never throws and dedups per SKU per day, so
        // a queue that processes several AON batches in one day adds nothing to
        // the group chat unless the data itself changed. Wrapped anyway: a
        // reminder must never turn a successful import into a failed one.
        if ($operationType === K2ImportOperationType::AON && $completed['state'] === K2ImportBatchState::AON_SUCCESS) {
            try {
                require_once __DIR__ . '/../includes/aon_reminder.php';
                $reminderOutcome = aon_reminder_after_import($pdo, 'k2_queue');
                $scopedLogger->info('AON_REMINDER_DISPATCHED', [
                    'due'      => $reminderOutcome['due'],
                    'sent'     => $reminderOutcome['sent'],
                    'dry_run'  => $reminderOutcome['dry_run'],
                    'recorded' => $reminderOutcome['recorded'],
                ]);
            } catch (Throwable $reminderError) {
                $scopedLogger->error('AON_REMINDER_FAILED', ['reason' => $reminderError->getMessage()]);
            }
        }

        $scopedLogger->info('SUCCESS', ['elapsed_ms' => (int) round((microtime(true) - $startedAt) * 1000), 'result_state' => $completed['state']]);
        $fileStore->cleanupAttempt($batchUid, $operationType, $attemptNumber);
        $scopedLogger->info('STAGED_FILE_CLEANUP_COMPLETED', []);

        $outcome = K2WorkerOutcome::SUCCESS;
    } catch (K2ImportPreparedExecutionFailedException $e) {
        $adapterResult = $e->adapterResult();
        if ($adapterResult->result !== []) {
            $scopedLogger->error("FAILURE_CONTEXT", $adapterResult->result);
        }
        $outcome = k2_worker_record_failure(
            $orchestrator,
            $scopedLogger,
            $batchUid,
            $operationType,
            $requestKey,
            $attemptId,
            $lockToken,
            $logicalRequestId,
            $adapterResult->errorCode ?? 'AUTOMATION_PARSER_FAILED',
            $adapterResult->safeMessage ?? 'Automation parser execution failed.',
            $adapterResult->retryable
        );
    } catch (K2AutomationFileStorageException $e) {
        // A checksum/missing-file mismatch defaults to retryable (a fresh attempt
        // with a new upload reliably produces a good staged copy); a missing or
        // incomplete manifest is constructed non-retryable above — that indicates
        // a genuine bug rather than a transient condition a retry would fix.
        $outcome = k2_worker_record_failure(
            $orchestrator,
            $scopedLogger,
            $batchUid,
            $operationType,
            $requestKey,
            $attemptId,
            $lockToken,
            $logicalRequestId,
            $e->stableCode(),
            'Staged upload could not be verified before processing.',
            $e->retryable()
        );
    } catch (K2AonGlobalLockBusyException $e) {
        $outcome = k2_worker_record_failure(
            $orchestrator,
            $scopedLogger,
            $batchUid,
            $operationType,
            $requestKey,
            $attemptId,
            $lockToken,
            $logicalRequestId,
            'AON_IMPORT_BUSY',
            'Another AON import is already running.',
            true
        );
    } catch (Throwable $e) {
        $adapterResult = K2AutomationParserAdapterSupport::resultFromThrowable($e);
        if ($adapterResult->result !== []) {
            $scopedLogger->error("FAILURE_CONTEXT", $adapterResult->result);
        }
        $outcome = k2_worker_record_failure(
            $orchestrator,
            $scopedLogger,
            $batchUid,
            $operationType,
            $requestKey,
            $attemptId,
            $lockToken,
            $logicalRequestId,
            $adapterResult->errorCode ?? 'AUTOMATION_PARSER_FAILED',
            $adapterResult->safeMessage ?? 'Automation parser execution failed.',
            $adapterResult->retryable
        );
    } finally {
        $prepared?->cleanup();
        if ($lockToken !== null) {
            try {
                $orchestrator->releaseBatchLock($batchUid, $lockToken, $logicalRequestId);
            } catch (Throwable $e) {
                $scopedLogger->exception('WORKER_LOCK_RELEASE_FAILED', $e);
            }
        }
        try {
            $orchestrator->releaseWorkerClaim($attemptId, $workerToken);
        } catch (Throwable $e) {
            $scopedLogger->exception('WORKER_CLAIM_RELEASE_FAILED', $e);
        }
    }

    return $outcome;
}

/**
 * Whether a resolved claim should trigger the AI assignment phase.
 *
 * Only a PR import that actually SUCCEEDED can have added rows to the assignment
 * queue, so that is the sole trigger. In particular:
 *   - an AON-only run cannot have created unassigned PR lines;
 *   - a FAILED PR attempt rolled its business write back, so there is nothing new
 *     to assign and a retry is still pending -- assigning here would spend Gemini
 *     credits on a queue that has not changed.
 *
 * Extracted from k2_worker_main() so this gate is directly testable without
 * standing up a full worker run (which would reach the real Gemini API).
 */
function k2_worker_should_run_ai_assignment(string $outcome, ?string $operationType): bool
{
    return $outcome === K2WorkerOutcome::SUCCESS
        && $operationType === K2ImportOperationType::PR;
}

/**
 * Per-run limits for the AI assignment phase. Defaults are chosen so this needs
 * NO deployment configuration to be safe -- the production cron is registered
 * through a control panel, not through compose env, so anything that only works
 * once someone sets a variable is not a real guardrail.
 *
 * maxBatches x ticketsPerBatch is the hard ceiling on how much one run can spend:
 * 20 x 15 = 300 tickets, i.e. at most 20 Gemini calls per worker tick. In
 * practice the loop stops earlier, usually because the queue empties.
 *
 * Measured call latency varies a lot and does NOT scale with batch size: 98s for
 * a 2-ticket call, 39s for a 15-ticket one (34 line items). The prompt is nearly
 * all fixed master_data taxonomy either way, so the variance is the model's, not
 * ours. Capacity below is therefore sized on the SLOWER observation.
 *
 * Sized against measured production inflow: a mean of 38 AI-eligible tickets per
 * working day and a peak over 80, spread across 3 import slots. These defaults
 * clear a peak day inside a single run, so the queue drains rather than grows.
 */
function k2_worker_ai_assign_config(): array
{
    $intFromEnv = static function (string $name, int $default, int $min): int {
        $raw = getenv($name);
        if ($raw === false || trim($raw) === '') {
            return $default;
        }
        $value = (int) $raw;

        return $value < $min ? $default : $value;
    };

    return [
        'maxBatches' => $intFromEnv('AI_ASSIGN_MAX_BATCHES_PER_RUN', 20, 1),
        // 15, not the browser dispatcher's 5. That 5 (reduced from 10) exists to
        // dodge web-gateway timeouts, which do not apply to a CLI worker. Batch
        // size is also the cheap throughput lever: a measured call spends ~14.7k
        // prompt tokens almost entirely on the master_data category list, which is
        // sent whole no matter how many tickets ride along, so tripling the batch
        // roughly thirds the per-ticket cost. Kept well below the point where the
        // response JSON could approach the model's output limit and truncate.
        'ticketsPerBatch' => $intFromEnv('AI_ASSIGN_TICKETS_PER_BATCH', 15, 1),
        // 600s, not 120s. Calls have been measured as slow as 98s
        // (gemini-2.5-flash reasons before answering), so a 120s cap could admit
        // just ONE batch per run -- ~15 tickets/day against a measured mean
        // inflow of 38 and peaks over 80, i.e. a queue that grows forever. This
        // is a ceiling, not a target: the loop stops the moment the queue empties,
        // and the worker's own MySQL session already runs with wait_timeout=1800
        // for the same class of reason.
        'maxSeconds' => $intFromEnv('AI_ASSIGN_MAX_SECONDS_PER_RUN', 600, 1),
        'sleepMs' => $intFromEnv('AI_ASSIGN_SLEEP_MS_BETWEEN_BATCHES', 1000, 0),
        // Matches the browser dispatcher's own halt-after-repeated-errors guard
        // (auto_assign.php stops at 5); tighter here because nobody is watching.
        'maxConsecutiveFailures' => 3,
        // A batch that assigns nothing has made no progress, and the next batch
        // would re-select the same unassignable tickets and pay for them again.
        // Two such rounds ends the run. The browser path lacks this guard only
        // because a human is there to close the tab.
        'maxNoProgressBatches' => 2,
    ];
}

/**
 * Assigns a PIC to tickets the deterministic routing in excel_parser.php left
 * unassigned, using the same engine the browser dispatcher uses
 * (workspace/includes/ai_ticket_assigner.php).
 *
 * TWO independent gates must both be true before a single Gemini call happens:
 *   1. $worker_wants_ai_assign below (code-level intent), and
 *   2. the system_settings kill switch `ai_auto_assign_enabled` = '1',
 *      read fail-closed by ai_assign_live_enabled().
 * Flipping only one does nothing. While either is off this logs the queue depth
 * and returns without touching the API -- which is also how the OFF observation
 * period before go-live is monitored.
 *
 * Never throws: every failure path is logged and swallowed so the worker's exit
 * code keeps reflecting the import outcome only.
 *
 * $gemini exists only so tests can drive the batching/cap/abort logic with a
 * stub; production always passes null and gets the real call_gemini_api().
 */
function k2_worker_run_ai_assignment(PDO $pdo, K2AutomationDiagnosticsLogger $logger, ?callable $gemini = null): array
{
    // Code-level intent switch. This alone does NOT make the worker assign:
    // the kill switch below must ALSO independently be enabled.
    $worker_wants_ai_assign = true;

    $summary = [
        'ran' => false,
        'batches' => 0,
        'tickets_assigned' => 0,
        'estimated_tokens' => 0,
        'errors' => 0,
    ];

    try {
        // Loaded HERE rather than at file scope, and inside this try, so that a
        // missing, unreadable or syntactically broken assignment library can only
        // ever cost this optional phase -- never the PR/AON import that is this
        // worker's actual job. Verified on PHP 8.2: a require of a missing file
        // throws Error and of an unparseable file throws ParseError, both of
        // which this catch handles, so the process keeps running and exits on the
        // import outcome alone.
        require_once __DIR__ . '/../includes/ai_ticket_assigner.php';

        // Optional pr_date floor, so an operator can open the window gradually
        // instead of assigning months of stale backlog on the first live run.
        $minPrDate = ai_assign_min_pr_date($pdo);

        // Read unconditionally (not short-circuited behind the gates) so the
        // backlog is visible in the log even while assignment is switched off.
        // Both numbers are logged: the windowed one is what this run would
        // actually touch, the total is what is waiting behind the window.
        $queued = ai_assign_queue_count($pdo, $minPrDate);
        $queuedTotal = $minPrDate === null ? $queued : ai_assign_queue_count($pdo);
        $live = ai_assign_live_enabled($pdo);

        if (!($worker_wants_ai_assign && $live)) {
            $logger->info('AI_ASSIGN_SKIPPED_DISABLED', [
                'queue_tickets' => $queued,
                'queue_tickets_all_dates' => $queuedTotal,
                'min_pr_date' => $minPrDate,
                'code_gate_enabled' => $worker_wants_ai_assign,
                'kill_switch_enabled' => $live,
            ]);

            return $summary;
        }

        if ($queued < 1) {
            $logger->info('AI_ASSIGN_SKIPPED_QUEUE_EMPTY', []);

            return $summary;
        }

        // Timeout 0: an overlapping worker tick skips instantly rather than
        // queueing up behind this one and doubling the API spend.
        $lockStmt = $pdo->prepare('SELECT GET_LOCK(?, 0) AS lock_result');
        $lockStmt->execute([K2_WORKER_AI_ASSIGN_LOCK_NAME]);
        $lockRow = $lockStmt->fetch(PDO::FETCH_ASSOC);
        if (!isset($lockRow['lock_result']) || (int) $lockRow['lock_result'] !== 1) {
            $logger->info('AI_ASSIGN_SKIPPED_LOCKED', ['queue_tickets' => $queued]);

            return $summary;
        }

        $config = k2_worker_ai_assign_config();
        $startedAt = microtime(true);
        $consecutiveFailures = 0;
        $noProgressBatches = 0;
        $summary['ran'] = true;

        $logger->info('AI_ASSIGN_STARTED', [
            'queue_tickets' => $queued,
            'queue_tickets_all_dates' => $queuedTotal,
            'min_pr_date' => $minPrDate,
            'max_batches' => $config['maxBatches'],
            'tickets_per_batch' => $config['ticketsPerBatch'],
            'max_seconds' => $config['maxSeconds'],
        ]);

        try {
            for ($batch = 0; $batch < $config['maxBatches']; $batch++) {
                if ((microtime(true) - $startedAt) >= $config['maxSeconds']) {
                    $logger->info('AI_ASSIGN_STOPPED_TIME_CAP', ['batches' => $summary['batches']]);
                    break;
                }

                try {
                    $result = ai_assign_process_batch($pdo, $config['ticketsPerBatch'], $gemini, $minPrDate);
                    $consecutiveFailures = 0;
                } catch (Throwable $e) {
                    $consecutiveFailures++;
                    $summary['errors']++;
                    $logger->exception('AI_ASSIGN_BATCH_FAILED', $e, [
                        'consecutive_failures' => $consecutiveFailures,
                    ]);

                    if ($consecutiveFailures >= $config['maxConsecutiveFailures']) {
                        $logger->error('AI_ASSIGN_ABORTED_CONSECUTIVE_FAILURES', [
                            'consecutive_failures' => $consecutiveFailures,
                            'batches' => $summary['batches'],
                        ]);
                        break;
                    }

                    if ($config['sleepMs'] > 0) {
                        usleep($config['sleepMs'] * 1000);
                    }
                    continue;
                }

                if (($result['status'] ?? '') === 'complete') {
                    break;
                }

                $assigned = (int) ($result['successCount'] ?? 0);
                $remaining = (int) ($result['remaining'] ?? 0);

                $summary['batches']++;
                $summary['tickets_assigned'] += $assigned;
                $summary['estimated_tokens'] += (int) ($result['estimated_tokens'] ?? 0);

                // Counts only -- fallbackDetails/failedDetails carry ticket
                // numbers and item descriptions, which do not belong in a log.
                $logger->info('AI_ASSIGN_BATCH_COMPLETED', [
                    'assigned' => $assigned,
                    'remaining' => $remaining,
                    'enriched_count' => count($result['fallbackDetails'] ?? []),
                    'unresolved_count' => count($result['failedDetails'] ?? []),
                ]);

                if ($assigned < 1) {
                    $noProgressBatches++;
                    if ($noProgressBatches >= $config['maxNoProgressBatches']) {
                        $logger->warn('AI_ASSIGN_STOPPED_NO_PROGRESS', [
                            'batches' => $summary['batches'],
                            'remaining' => $remaining,
                        ]);
                        break;
                    }
                } else {
                    $noProgressBatches = 0;
                }

                if ($remaining < 1) {
                    break;
                }

                if ($config['sleepMs'] > 0) {
                    usleep($config['sleepMs'] * 1000);
                }
            }
        } finally {
            try {
                $releaseStmt = $pdo->prepare('SELECT RELEASE_LOCK(?) AS release_result');
                $releaseStmt->execute([K2_WORKER_AI_ASSIGN_LOCK_NAME]);
            } catch (Throwable $e) {
                $logger->exception('AI_ASSIGN_LOCK_RELEASE_FAILED', $e);
            }
        }

        ai_assign_touch_last_run($pdo);

        $logger->info('AI_ASSIGN_COMPLETED', $summary + [
            'elapsed_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);
    } catch (Throwable $e) {
        $summary['errors']++;
        $logger->exception('AI_ASSIGN_FAILED', $e);
    }

    return $summary;
}

function k2_worker_record_failure(
    K2ImportOrchestrator $orchestrator,
    K2AutomationDiagnosticsLogger $logger,
    string $batchUid,
    string $operationType,
    string $requestKey,
    int $attemptId,
    ?string $lockToken,
    string $logicalRequestId,
    string $errorCode,
    string $safeMessage,
    bool $retryable
): string {
    $logger->error($retryable ? 'RETRYABLE_FAILURE' : 'FINAL_FAILURE', [
        'error_code' => $errorCode,
        'safe_message' => $safeMessage,
    ]);

    if ($lockToken === null) {
        // The batch lock itself could not be claimed — recordOperationFailure
        // would fail its own lock check, so there is nothing safe left to do
        // except surface this as an internal error for an operator to inspect.
        return K2WorkerOutcome::INTERNAL_ERROR;
    }

    try {
        $orchestrator->recordOperationFailure(
            $batchUid,
            $operationType,
            $requestKey,
            $attemptId,
            $errorCode,
            $safeMessage,
            $retryable,
            $lockToken,
            $logicalRequestId
        );
    } catch (Throwable $e) {
        $logger->exception('WORKER_FAILURE_RECORD_FAILED', $e);

        return K2WorkerOutcome::INTERNAL_ERROR;
    }

    return $retryable ? K2WorkerOutcome::RETRYABLE_FAILURE : K2WorkerOutcome::FINAL_FAILURE;
}

/**
 * Rebuilds the ['payload' => ['source_files' => ...], 'files' => [...staged
 * paths shaped like $_FILES...]] context K2AutomationAonXlsxAdapter/
 * K2AutomationPrXlsxAdapter expect, using the batch's durably registered
 * source files (not the original — long gone — upload tmp paths) and the
 * staged private-storage paths from the claimed attempt's manifest.
 */
function k2_worker_build_context(K2ImportRunRepositoryInterface $repo, int $batchId, array $manifest, array $fieldsToDatasetTypes): array
{
    $sourceFiles = [];
    foreach ($repo->findSourceFilesForBatch($batchId) as $row) {
        $sourceFiles[] = new K2ImportSourceFileMetadata(
            (string) $row['dataset_type'],
            (string) $row['drive_file_id'],
            (string) $row['original_filename'],
            new DateTimeImmutable((string) $row['drive_modified_at'], new DateTimeZone('UTC')),
            (int) $row['file_size_bytes'],
            (string) $row['sha256_content_hash']
        );
    }

    $manifestByDatasetType = [];
    foreach ($manifest as $entry) {
        $manifestByDatasetType[(string) ($entry['dataset_type'] ?? '')] = $entry;
    }

    $files = [];
    foreach ($fieldsToDatasetTypes as $field => $datasetType) {
        $entry = $manifestByDatasetType[$datasetType] ?? null;
        if ($entry === null) {
            throw new K2AutomationFileStorageException(
                'STAGED_MANIFEST_INCOMPLETE',
                "Staged manifest is missing the {$datasetType} file.",
                false
            );
        }
        $files[$field] = [
            'tmp_name' => (string) $entry['storage_path'],
            'name' => (string) ($entry['original_filename'] ?? basename((string) $entry['storage_path'])),
        ];
    }

    return [
        'payload' => ['source_files' => array_map(
            static fn(K2ImportSourceFileMetadata $file): array => $file->canonicalArray(),
            K2ImportIdentity::normalizeSourceFiles($sourceFiles)
        )],
        'files' => $files,
    ];
}

function k2_worker_parse_args(array $argv): array
{
    $limit = 1;
    foreach ($argv as $arg) {
        if (str_starts_with($arg, '--limit=')) {
            $limit = (int) substr($arg, strlen('--limit='));
        }
    }

    if ($limit < 1) {
        throw new InvalidArgumentException('--limit must be a positive integer.');
    }

    return ['limit' => $limit];
}

function k2_worker_pdo(): PDO
{
    if (getenv('K2_AUTOMATION_TEST_DSN') !== false) {
        return new PDO(
            (string) getenv('K2_AUTOMATION_TEST_DSN'),
            (string) getenv('K2_AUTOMATION_TEST_USER'),
            (string) getenv('K2_AUTOMATION_TEST_PASSWORD'),
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
    }

    require __DIR__ . '/../config/database.php';
    if (!isset($pdo) || !$pdo instanceof PDO) {
        throw new RuntimeException('Database connection is unavailable.');
    }

    // This host's MySQL wait_timeout is 20s (found 2026-08-07 via production
    // debugging: large PR imports were dying with "MySQL server has gone away"
    // because XLSX normalization -- CPU-bound, no DB activity -- routinely runs
    // several minutes for big files, so the idle connection got closed server-side
    // before the transaction that follows normalization could start). Raised here,
    // scoped to this worker connection only, matching the existing 1800s worker
    // lease/lock defaults below.
    $pdo->exec('SET SESSION wait_timeout = 1800');

    return $pdo;
}

function k2_worker_generate_token(): string
{
    return 'k2wrk_' . bin2hex(random_bytes(16));
}

final class K2WorkerOutcome
{
    public const NOTHING_CLAIMABLE = 'nothing_claimable';
    public const SUCCESS = 'success';
    public const RETRYABLE_FAILURE = 'retryable_failure';
    public const FINAL_FAILURE = 'final_failure';
    public const INTERNAL_ERROR = 'internal_error';
}
