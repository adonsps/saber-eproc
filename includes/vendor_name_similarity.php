<?php
// includes/vendor_name_similarity.php
//
// Fuzzy company-name matching for the VSS vendor register.
//
// WHY THIS EXISTS
//   Registration was only ever de-duplicated on `users.email`, so the same real
//   company could sign up as many times as it had mailboxes — and it did. The
//   register now asks the fuzzy question instead: "does a company by this name
//   already exist?", and makes the vendor answer for it before an account is
//   created. The same matcher backs the cleanup tooling for the duplicates that
//   already landed, so the register and the cleanup can never disagree about
//   what counts as "the same vendor".
//
// WHY NOT AN NLP MODEL / AN API CALL
//   Indonesian company names are a closed, highly-templated vocabulary
//   ("PT <two or three words>"), and the duplicates in the data are typographic,
//   not semantic: "PT. ARSINSI PRIMA CIPTA" vs "PT ARSINSI PRIMA CIPTA". A
//   normalise-then-string-similarity pass catches those exactly, runs in the
//   same request with no network call, and — unlike an embedding model — gives
//   the same answer every time, which matters when the answer blocks a
//   registration. Semantic similarity would in fact be WRONG here: "PT Maju
//   Jaya" and "PT Maju Sejahtera" are near-identical semantically and are two
//   different legal entities.
//
// THE MATCHER, IN ORDER
//   1. Normalise: uppercase, drop dots/apostrophes with no space ("P.T." -> "PT"),
//      turn any other punctuation into a space, collapse whitespace.
//   2. Score: similar_text() percentage, taken as the better of the raw strings
//      and their token-sorted forms so word order doesn't matter, and of both
//      argument orders since similar_text() is not symmetric.
//   3. Compare against a threshold (95% by default).
//
// The legal form is deliberately NOT stripped: "PT MAJU" and "CV MAJU" are
// different legal entities and must score apart (they land near 71%), while
// "PT. MAJU" and "PT MAJU" normalise to the same string and score 100%.

/** Default similarity percentage at or above which two names are "the same". */
if (!defined('VENDOR_NAME_MATCH_THRESHOLD')) {
    define('VENDOR_NAME_MATCH_THRESHOLD', 95.0);
}

if (!function_exists('vendor_name_normalize')) {

    /**
     * Fold a company name to its comparable form.
     *
     * Dots and apostrophes are removed WITHOUT leaving a space so that "PT.",
     * "P.T." and "PT" all collapse to "PT"; every other separator becomes a
     * space so "MAJU-BERSAMA" and "MAJU BERSAMA" agree.
     */
    function vendor_name_normalize(?string $raw): string
    {
        $s = mb_strtoupper(trim((string) $raw), 'UTF-8');
        $s = str_replace(['.', "'", '`', '’'], '', $s);
        $s = preg_replace('/[^A-Z0-9]+/u', ' ', $s);
        return trim(preg_replace('/\s+/', ' ', $s));
    }

    /**
     * similar_text() percentage, made symmetric.
     *
     * PHP's similar_text() walks the strings from the longest common substring
     * outwards, so swapping the arguments can return a different figure. Taking
     * the better of the two keeps the score independent of which name happens to
     * be the "candidate".
     */
    function vendor_name_similar_pct(string $a, string $b): float
    {
        similar_text($a, $b, $forward);
        similar_text($b, $a, $backward);
        return (float) max($forward, $backward);
    }

    /**
     * Similarity of two ALREADY-NORMALISED names, 0.0–100.0.
     *
     * Scored twice: on the strings as written, and on their alphabetically
     * sorted tokens so that "PT SUMBER JAYA" and "PT JAYA SUMBER" — the same
     * company entered by two different people — still match.
     */
    function vendor_name_similarity(string $normA, string $normB): float
    {
        if ($normA === '' || $normB === '') {
            return 0.0;
        }
        if ($normA === $normB) {
            return 100.0;
        }

        $direct = vendor_name_similar_pct($normA, $normB);

        $ta = explode(' ', $normA);
        $tb = explode(' ', $normB);
        sort($ta);
        sort($tb);
        $sorted = vendor_name_similar_pct(implode(' ', $ta), implode(' ', $tb));

        return max($direct, $sorted);
    }

    /**
     * Can these two lengths possibly reach $threshold? Exact, not a heuristic.
     *
     * similar_text() counts at most min(len) matching characters, and its
     * percentage is 2*matched/(lenA+lenB)*100 — so 2*min/(lenA+lenB)*100 is a
     * hard ceiling on the score. Names that cannot clear the bar on length alone
     * are dropped before the O(n^3) similar_text() ever runs, which is what keeps
     * a full-register scan cheap as the vendor count grows.
     */
    function vendor_name_length_can_match(int $lenA, int $lenB, float $threshold): bool
    {
        if ($lenA === 0 || $lenB === 0) {
            return false;
        }
        return (2.0 * min($lenA, $lenB) / ($lenA + $lenB)) * 100.0 >= $threshold;
    }

    /**
     * The name's distinctive words: its tokens minus the legal form.
     *
     * "PT MITRA PRINTING" -> [MITRA, PRINTING]. The legal form is dropped here
     * and ONLY here — vendor_name_similarity() keeps it, because a character
     * score is what separates "PT MAJU" from "CV MAJU". Word-level comparison
     * needs the opposite: the same business incorporated as a CV and re-entered
     * as a PT shares every distinctive word, and that is the signal.
     */
    function vendor_name_core_tokens(string $norm): array
    {
        static $legal = [
            'PT', 'CV', 'UD', 'PD', 'FA', 'NV', 'TBK', 'PERSERO',
            'LTD', 'INC', 'LLC', 'PTE', 'SDN', 'BHD',
        ];
        if ($norm === '') {
            return [];
        }
        return array_values(array_unique(array_diff(explode(' ', $norm), $legal)));
    }

    /**
     * Word-level overlap between two normalised names.
     *
     * Character similarity cannot see a whole inserted word: "PT MITRA PRINTING"
     * against "CV MITRA ABADI PRINTING" tops out at 85% because the strings
     * differ in length by six characters, so no threshold at 95% will ever pair
     * them. Word-level, though, every distinctive word of the shorter name
     * appears in the longer one — the pattern of a company re-registering with a
     * word added or dropped.
     *
     * `pct` is containment: what share of the SHORTER name's words the longer one
     * has. `extra` is how many words the longer name adds on top; it is what
     * keeps containment honest, since "PT SINAR JAYA" is contained in "PT SINAR
     * JAYA ABADI SENTOSA MAKMUR" too, and those are not the same company.
     *
     * @return array{pct:float,shared:int,extra:int,min_tokens:int}
     */
    function vendor_name_token_overlap(string $normA, string $normB): array
    {
        $a = vendor_name_core_tokens($normA);
        $b = vendor_name_core_tokens($normB);
        if ($a === [] || $b === []) {
            return ['pct' => 0.0, 'shared' => 0, 'extra' => 0, 'min_tokens' => 0];
        }

        $shared = count(array_intersect($a, $b));
        $small  = min(count($a), count($b));
        $large  = max(count($a), count($b));

        return [
            'pct'        => $shared / $small * 100.0,
            'shared'     => $shared,
            'extra'      => $large - $shared,
            'min_tokens' => $small,
        ];
    }

    /**
     * Mask an email for display to someone who must not learn the whole address.
     *
     * The vendor being asked "is this your company?" needs just enough to
     * recognise their own mailbox: first character, then the domain.
     * "budi@kawanlama.com" -> "b*****@kawanlama.com".
     */
    function vendor_name_mask_email(?string $email): string
    {
        $email = trim((string) $email);
        $at = strrpos($email, '@');
        if ($email === '' || $at === false || $at === 0) {
            return '(email tidak tercatat)';
        }
        $local  = substr($email, 0, $at);
        $domain = substr($email, $at + 1);

        return mb_substr($local, 0, 1, 'UTF-8')
            . str_repeat('*', max(3, mb_strlen($local, 'UTF-8') - 1))
            . '@' . $domain;
    }

    /**
     * Every vendor whose name matches $candidate at or above $threshold.
     *
     * Returns the full register row plus `match_pct`, best match first. The whole
     * `vendors` table is pulled in one query and filtered in PHP — the register is
     * in the low thousands of rows and the length ceiling above rejects most
     * pairs without scoring them, so this stays a single-digit-millisecond call
     * on the registration path.
     *
     * @param int|null $excludeVendorId Vendor to leave out (self-comparison).
     * @return array<int,array{id:int,company_name:string,email:?string,registration_status:string,created_at:?string,match_pct:float}>
     */
    function vendor_name_find_matches(
        mysqli $conn,
        string $candidate,
        float $threshold = VENDOR_NAME_MATCH_THRESHOLD,
        ?int $excludeVendorId = null
    ): array {
        $norm = vendor_name_normalize($candidate);
        if ($norm === '') {
            return [];
        }
        $len = strlen($norm);

        $sql = "SELECT id, company_name, email, registration_status, created_at
                  FROM vendors
                 WHERE company_name IS NOT NULL AND company_name <> ''";
        try {
            $res = $conn->query($sql);
        } catch (mysqli_sql_exception $e) {
            // A duplicate check that cannot run must not take the register down
            // with it: no matches means registration proceeds as it did before.
            error_log('[vendor_name_similarity] match query failed: ' . $e->getMessage());
            return [];
        }

        $matches = [];
        while ($row = $res->fetch_assoc()) {
            if ($excludeVendorId !== null && (int) $row['id'] === $excludeVendorId) {
                continue;
            }
            $otherNorm = vendor_name_normalize($row['company_name']);
            if (!vendor_name_length_can_match($len, strlen($otherNorm), $threshold)) {
                continue;
            }
            $pct = vendor_name_similarity($norm, $otherNorm);
            if ($pct < $threshold) {
                continue;
            }
            $row['id']        = (int) $row['id'];
            $row['match_pct'] = round($pct, 2);
            $matches[] = $row;
        }

        usort($matches, static fn($a, $b) => $b['match_pct'] <=> $a['match_pct']);
        return $matches;
    }

    // -----------------------------------------------------------------------
    // The registration gate
    // -----------------------------------------------------------------------
    // Both the AJAX endpoint the form calls and the server-side enforcement in
    // register_action.php go through vendor_duplicate_check(), so the popup a
    // vendor sees and the rule that actually stops the INSERT can never drift
    // apart.

    /** Word rule: shared words must be the whole of the shorter name, that name
     *  must carry more than one word, and the longer may add at most one. */
    define('VENDOR_DUP_WORD_MIN_SHARED', 2);
    define('VENDOR_DUP_WORD_MAX_EXTRA', 1);

    /**
     * Classify a proposed company name against the register.
     *
     * Returns `level`:
     *   'block' — character similarity >= 95%. Precise; worth stopping on.
     *   'warn'  — same distinctive words (+ at most one). Catches what character
     *             similarity structurally cannot, but only about half of these
     *             are genuinely the same company ("MAKMUR JAYA" links to "PT Yoga
     *             Jaya Makmur"), so it must inform rather than block.
     *   'none'  — nothing close enough to mention.
     *
     * Emails come back MASKED. Callers hand this straight to an anonymous
     * visitor, so nothing here may carry an address, a vendor id, or a pipeline
     * status off the server.
     *
     * @return array{level:string,matches:array<int,array{name:string,email:string,signal:string}>}
     */
    function vendor_duplicate_check(mysqli $conn, string $candidate, ?int $excludeVendorId = null): array
    {
        $norm = vendor_name_normalize($candidate);
        if ($norm === '') {
            return ['level' => 'none', 'matches' => []];
        }
        $len = strlen($norm);

        try {
            // Deactivated rows are skipped. A vendor switched off as a
            // duplicate (modules/vendormgt/duplicate_vendors.php) must not be
            // what a new registrant is matched against, or the popup would
            // offer them a dead account and a masked email nobody reads. The
            // surviving record in that cluster is still there and still matches.
            // COALESCE keeps this working on a database where the column has
            // not been added yet.
            $res = $conn->query(
                "SELECT id, company_name, email
                   FROM vendors
                  WHERE company_name IS NOT NULL AND company_name <> ''
                    AND COALESCE(is_active, 1) = 1"
            );
        } catch (mysqli_sql_exception $e) {
            // Fail OPEN. A lookup that cannot run must never be what stops a
            // vendor registering.
            error_log('[vendor_duplicate_check] query failed: ' . $e->getMessage());
            return ['level' => 'none', 'matches' => []];
        }

        $blocks = [];
        $warns  = [];

        while ($row = $res->fetch_assoc()) {
            if ($excludeVendorId !== null && (int) $row['id'] === $excludeVendorId) {
                continue;
            }
            $otherNorm = vendor_name_normalize($row['company_name']);
            if ($otherNorm === '') {
                continue;
            }

            $entry = [
                'name'  => (string) $row['company_name'],
                'email' => vendor_name_mask_email($row['email']),
            ];

            // CHAR — only worth scoring when the two lengths leave the threshold
            // reachable at all.
            if (vendor_name_length_can_match($len, strlen($otherNorm), VENDOR_NAME_MATCH_THRESHOLD)
                && vendor_name_similarity($norm, $otherNorm) >= VENDOR_NAME_MATCH_THRESHOLD) {
                $blocks[] = $entry + ['signal' => 'char'];
                continue;
            }

            // WORD — the inserted/dropped-word case CHAR cannot reach.
            $word = vendor_name_token_overlap($norm, $otherNorm);
            if ($word['pct'] >= 100.0
                && $word['min_tokens'] >= VENDOR_DUP_WORD_MIN_SHARED
                && $word['extra'] <= VENDOR_DUP_WORD_MAX_EXTRA) {
                $warns[] = $entry + ['signal' => 'word'];
            }
        }

        if ($blocks) {
            return ['level' => 'block', 'matches' => array_slice($blocks, 0, 3)];
        }
        if ($warns) {
            return ['level' => 'warn', 'matches' => array_slice($warns, 0, 3)];
        }
        return ['level' => 'none', 'matches' => []];
    }

    /**
     * Token proving the vendor answered the popup for THIS name.
     *
     * Bound to the normalised name so an acknowledgement cannot carry over to a
     * name edited after the popup was dismissed. Deliberately not a secret: the
     * vendor may always answer "this is a different company", so this checks
     * correctness, not authority.
     */
    function vendor_duplicate_ack_token(string $candidate): string
    {
        return hash('sha256', 'vendor-dup-ack|' . vendor_name_normalize($candidate));
    }
}
