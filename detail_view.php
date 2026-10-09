if (isset($_GET['id'])) {
    $rfq_id = $_GET['id'];
    $rfq = $pdo->prepare("SELECT * FROM rfqs WHERE id = ?");
    $rfq->execute([$rfq_id]);
    $rfq_data = $rfq->fetch();

    if (!$rfq_data) {
        echo "<div class='p-8 text-center mt-10'><h2 class='text-2xl font-bold'>Tender tidak ditemukan.</h2><a href='rfq_dashboard.php' class='text-indigo-600 underline mt-2 block'>Kembali</a></div>";
        require_once '../includes/footer.php'; exit;
    }

    $family_group = $rfq_data['parent_id'] ?: $rfq_data['id'];
    $stmt_family = $pdo->prepare("SELECT id, round_number, status, package_name FROM rfqs WHERE id = ? OR parent_id = ? ORDER BY round_number ASC");
    $stmt_family->execute([$family_group, $family_group]);
    $rfq_family = $stmt_family->fetchAll(PDO::FETCH_ASSOC);

    // Identify if this is the absolute latest round in the family
    $max_round = max(array_column($rfq_family, 'round_number'));
    $is_latest_round = ($rfq_data['round_number'] == $max_round);

    $prev_prices = [];
    if ($rfq_data['round_number'] > 1) {
        $prev_round = $rfq_data['round_number'] - 1;
        $stmt_prev = $pdo->prepare("SELECT v.vendor_name, v.total_price FROM rfq_vendors v JOIN rfqs r ON v.rfq_id = r.id WHERE (r.id = ? OR r.parent_id = ?) AND r.round_number = ? AND v.total_price IS NOT NULL");
        $stmt_prev->execute([$family_group, $family_group, $prev_round]);
        foreach($stmt_prev->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $prev_prices[$p['vendor_name']] = $p['total_price'];
        }
    }

    $vendors = $pdo->prepare("SELECT * FROM rfq_vendors WHERE rfq_id = ? ORDER BY total_price ASC");
    $vendors->execute([$rfq_id]);
    $vendor_data = $vendors->fetchAll(PDO::FETCH_ASSOC);

    $total_submissions = 0;
    $vendor_map = [];
    foreach ($vendor_data as $v) {
        if (!empty($v['submitted_at'])) $total_submissions++;
        $vendor_map[$v['id']] = $v['vendor_name'];
    }

    $vendor_items = [];
    if (($rfq_data['rfq_type'] ?? 'Construction') === 'Standard') {
        $stmt_vi = $pdo->prepare("SELECT vi.*, ri.ticket_line_item, ri.item_description, ri.qty, ri.uom 
                                  FROM rfq_vendor_items vi 
                                  JOIN rfq_items ri ON vi.rfq_item_id = ri.id 
                                  WHERE vi.rfq_vendor_id = ?");
        foreach($vendor_data as $v) {
            $stmt_vi->execute([$v['id']]);
            $vendor_items[$v['id']] = $stmt_vi->fetchAll(PDO::FETCH_ASSOC);
        }
    }

    $committee = $pdo->prepare("SELECT c.username, c.has_opened, c.opened_at, u.name FROM rfq_committee c JOIN users u ON c.username = u.username WHERE c.rfq_id = ?");
    $committee->execute([$rfq_id]);
    $committee_data = $committee->fetchAll(PDO::FETCH_ASSOC);

    $is_committee = false;
    $my_unseal_status = 0;
    foreach($committee_data as $c) {
        if ($c['username'] === $_SESSION['username']) {
            $is_committee = true;
            $my_unseal_status = $c['has_opened'];
        }
    }
    
    $logs = $pdo->prepare("SELECT * FROM rfq_audit_logs WHERE rfq_id = ? ORDER BY created_at DESC");
    $logs->execute([$rfq_id]);
    $audit_logs = $logs->fetchAll(PDO::FETCH_ASSOC);

    function getCheck($val) { return !empty($val) ? '<span class="text-green-500 font-bold text-lg">✅</span>' : '<span class="text-red-400 font-bold text-lg">❌</span>'; }
    ?>
    <div class="max-w-7xl mx-auto py-8">
        <div class="mb-4">
            <a href="rfq_dashboard.php" class="inline-flex items-center text-sm font-bold text-gray-500 hover:text-gray-700 transition">&larr; Kembali ke Daftar RFQ</a>
        </div>

        <?php if (count($rfq_family) > 1): ?>
        <div class="flex flex-wrap gap-2 mb-6 border-b border-gray-300 pb-2">
            <?php foreach($rfq_family as $fam): 
                $isActive = ($fam['id'] == $rfq_id);
                $tabClass = $isActive ? 'bg-indigo-600 text-white shadow-md' : 'bg-gray-200 text-gray-600 hover:bg-gray-300 hover:text-gray-800';
            ?>
                <a href="rfq_dashboard.php?id=<?= $fam['id'] ?>" class="px-5 py-2 rounded-t-lg font-bold text-sm transition-all <?= $tabClass ?>">
                    Ronde <?= $fam['round_number'] ?> 
                    <?php if($fam['status'] === 'Closed') echo '🔒'; elseif($fam['status'] === 'Locked') echo '⛔'; elseif($fam['status'] === 'Unsealed') echo '🔓'; else echo '⏳'; ?>
                </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        
        <?php if(isset($_GET['msg'])): ?>
            <?php if($_GET['msg'] === 'round_added'): ?><div class="bg-blue-100 text-blue-800 p-4 mb-4 rounded font-bold">Ronde Baru Berhasil Dibuat! Undangan dikirim ke vendor terpilih.</div>
            <?php elseif($_GET['msg'] === 'deadline_updated'): ?><div class="bg-green-100 text-green-800 p-4 mb-4 rounded font-bold">Batas Waktu berhasil diubah.</div>
            <?php elseif($_GET['msg'] === 'locked'): ?><div class="bg-purple-100 text-purple-800 p-4 mb-4 rounded font-bold">Tender dikunci untuk Tender Approval. Notifikasi telah dikirim ke PIC.</div>
            <?php endif; ?>
        <?php endif; ?>
        <?php if(isset($_GET['error']) && $_GET['error'] === 'no_vendors'): ?>
            <div class="bg-red-100 text-red-800 p-4 mb-4 rounded font-bold">Gagal: Anda harus memilih minimal 1 vendor untuk ronde selanjutnya.</div>
        <?php endif; ?>

        <div class="flex justify-between items-start mb-6 border-b pb-4">
            <div>
                <h2 class="text-3xl font-bold text-gray-800">Tender: <?= htmlspecialchars($rfq_data['package_name']) ?></h2>
                <p class="text-gray-500 mt-1 flex items-center gap-2">
                    Batas Waktu: <?= date('d F Y H:i', strtotime($rfq_data['deadline'])) ?> WIB
                    <?php if ($user_role === 'Project Director' && $rfq_data['status'] === 'Active'): ?>
                        <button onclick="document.getElementById('deadlineModal').classList.remove('hidden')" class="text-xs bg-gray-200 hover:bg-gray-300 px-2 py-1 rounded text-gray-700 font-bold">✏️ Edit</button>
                    <?php endif; ?>
                </p>
            </div>
            <div class="flex items-center gap-3">
                <?php if ($rfq_data['status'] === 'Active'): ?>
                    <span class="bg-red-100 text-red-800 font-bold px-4 py-2 rounded-full border border-red-200 flex items-center gap-2">TENDER DISEGEL</span>
                <?php elseif ($rfq_data['status'] === 'Unsealed'): ?>
                    <span class="bg-green-100 text-green-800 font-bold px-4 py-2 rounded-full border border-green-200 flex items-center gap-2">TENDER DIBUKA</span>
                <?php elseif ($rfq_data['status'] === 'Locked'): ?>
                    <span class="bg-purple-100 text-purple-800 font-bold px-4 py-2 rounded-full border border-purple-200 flex items-center gap-2">LOCKED FOR TENDER APPROVAL</span>
                <?php elseif ($rfq_data['status'] === 'Closed'): ?>
                    <span class="bg-gray-200 text-gray-800 font-bold px-4 py-2 rounded-full border border-gray-300 flex items-center gap-2">TENDER DITUTUP</span>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($rfq_data['status'] === 'Active'): ?>
        <div class="bg-white shadow-sm rounded-lg p-6 border border-gray-200 mb-8 border-t-4 border-t-blue-500">
            <div class="flex justify-between items-center mb-1">
                <h3 class="text-lg font-bold text-gray-800">Status Submit Vendor</h3>
                <?php if ($is_committee || in_array($user_role, ['Category Manager', 'PIC', 'QS'])): ?>
                    <button type="button" onclick="document.getElementById('inviteVendorModal').classList.remove('hidden')" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-1 px-3 text-sm rounded shadow transition">+ Undang Vendor Tambahan</button>
                <?php endif; ?>
            </div>
            <div class="overflow-x-auto mt-4">
                <table class="min-w-full text-left text-sm border-collapse">
                    <thead class="bg-gray-100 border-b-2 border-gray-300 text-gray-600">
                        <tr>
                            <th class="p-3 font-bold uppercase">Vendor</th>
                            <th class="p-3 font-bold uppercase text-center">Harga Lumpsum</th>
                            <th class="p-3 font-bold uppercase text-center">Price Validity</th>
                            <th class="p-3 font-bold uppercase text-center">Cover Letter</th>
                            <th class="p-3 font-bold uppercase text-center">BoQ (Excel)</th>
                            <th class="p-3 font-bold uppercase text-center">Dok Pendukung</th>
                            <th class="p-3 font-bold uppercase text-right">Waktu Submit</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($vendor_data as $v): ?>
                        <tr class="border-b hover:bg-gray-50">
                            <td class="p-3"><div class="font-bold text-indigo-700"><?= htmlspecialchars($v['vendor_name'] ?? '') ?></div><div class="text-xs text-gray-500">PIC: <?= htmlspecialchars($v['vendor_pic'] ?? '') ?></div></td>
                            <td class="p-3 text-center bg-gray-50 border-x border-white"><?= getCheck($v['total_price']) ?></td>
                            <td class="p-3 text-center bg-gray-50 border-x border-white"><?= getCheck($v['price_validity']) ?></td>
                            <td class="p-3 text-center bg-gray-50 border-x border-white"><?= getCheck($v['file_cover_letter']) ?></td>
                            <td class="p-3 text-center bg-gray-50 border-x border-white"><?= getCheck($v['file_boq']) ?></td>
                            <td class="p-3 text-center bg-gray-50 border-x border-white"><?= getCheck($v['file_supporting']) ?></td>
                            <td class="p-3 text-right font-mono text-xs">
                                <?php if (!empty($v['submitted_at'])): ?>
                                    <?php $dt = new DateTime($v['submitted_at'], new DateTimeZone('UTC')); $dt->setTimezone(new DateTimeZone('Asia/Jakarta')); echo $dt->format('d F Y H:i'); ?>
                                <?php else: ?>
                                    <span class="text-red-500 font-bold">Pending</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <?php if (count($committee_data) > 0): ?>
        <div class="bg-white shadow-sm rounded-lg p-6 border border-gray-200 mb-8">
            <h3 class="text-lg font-bold text-gray-800 border-b pb-2 mb-4">Status Unseal Komite</h3>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
                <?php foreach($committee_data as $c): ?>
                    <div class="p-3 rounded border <?= $c['has_opened'] ? 'bg-green-50 border-green-200' : 'bg-gray-50 border-gray-200' ?>">
                        <div class="font-bold text-gray-800 text-sm"><?= htmlspecialchars($c['name'] ?? $c['username']) ?></div>
                        <?php if($c['has_opened']): ?>
                            <?php $cd = new DateTime($c['opened_at'], new DateTimeZone('UTC')); $cd->setTimezone(new DateTimeZone('Asia/Jakarta')); ?>
                            <div class="text-xs text-green-600 font-bold mt-1">Unlocked at <?= $cd->format('H:i, d M Y') ?></div>
                        <?php else: ?>
                            <div class="text-xs text-red-500 font-bold mt-1">Menunggu Unlock...</div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php if ($is_committee && !$my_unseal_status && $rfq_data['status'] === 'Active'): ?>
                <?php if ($total_submissions > 0): ?>
                    <?php 
                        $unsub_count = count($vendor_data) - $total_submissions;
                        $confirm_msg = "Tindakan ini akan menyimpan tanda tangan digital Anda untuk membuka segel tender.";
                        if ($unsub_count > 0) $confirm_msg = "PERINGATAN: Ada $unsub_count vendor yang BELUM submit. Jika segel terbuka, mereka otomatis DIELIMINASI oleh sistem. Lanjutkan?";
                    ?>
                    <form method="POST" onsubmit="return confirm('<?= $confirm_msg ?>');">
                        <input type="hidden" name="unseal" value="1">
                        <input type="hidden" name="rfq_id" value="<?= $rfq_id ?>">
                        <button type="submit" class="w-full bg-red-600 hover:bg-red-700 text-white font-bold py-3 rounded shadow transition flex items-center justify-center text-lg">Buka Segel Tender</button>
                    </form>
                <?php else: ?>
                    <button disabled class="w-full bg-gray-400 text-white font-bold py-3 rounded shadow cursor-not-allowed text-lg">Belum Ada Penawaran Masuk</button>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($is_latest_round && in_array($rfq_data['status'], ['Unsealed', 'Locked'])): ?>
            <div class="flex justify-end mb-4 gap-4">
                <?php if (in_array($user_role, ['Project Director', 'QS']) && $rfq_data['status'] !== 'Locked'): ?>
                    <form method="POST" onsubmit="return confirm('Kunci tender ini untuk proses persetujuan (Tender Approval)? Sistem akan mengirim notifikasi ke PIC.');">
                        <input type="hidden" name="action" value="lock_negotiation">
                        <input type="hidden" name="rfq_id" value="<?= $rfq_id ?>">
                        <button type="submit" class="bg-gray-800 hover:bg-gray-900 text-white font-bold py-2 px-6 rounded shadow transition">🔒 Lock for Tender Approval</button>
                    </form>
                <?php endif; ?>

                <?php if ($is_committee || $user_role === 'QS'): ?>
                    <button type="button" onclick="document.getElementById('addRoundModal').classList.remove('hidden')" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2 px-6 rounded shadow transition">+ Tambah Ronde</button>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if (in_array($rfq_data['status'], ['Unsealed', 'Locked', 'Closed'])): ?>
            <h3 class="text-2xl font-bold text-gray-800 mb-4 flex items-center gap-2">📊 Matriks Perbandingan Vendor</h3>
            <div class="overflow-x-auto pb-4 mb-8">
                <div class="flex gap-4 min-w-max">
                    <?php 
                    $rank = 1;
                    foreach($vendor_data as $v): 
                        $has_submitted = !empty($v['submitted_at']);
                        $current_val = $v['total_price'];
                        $lumpsum = $current_val ? number_format($current_val, 0, ',', '.') : 'N/A';
                        $is_winner = ($rfq_data['status'] === 'Closed' && $rfq_data['winner_vendor_id'] == $v['id']);
                        $card_style = $is_winner ? 'border-yellow-400 ring-4 ring-yellow-400 bg-yellow-50' : 'border-gray-200 bg-white';
                        
                        $prev_val = $prev_prices[$v['vendor_name']] ?? null;
                        $discount_badge = '';
                        if ($prev_val && $current_val && $current_val < $prev_val) {
                            $diff = $prev_val - $current_val;
                            $pct = round(($diff / $prev_val) * 100, 2);
                            $discount_badge = "<div class='text-xs font-bold text-emerald-600 bg-emerald-100 px-2 py-1 rounded inline-block mt-1'>📉 Turun Rp " . number_format($diff, 0, ',', '.') . " ($pct%)</div>";
                        } elseif ($prev_val && $current_val && $current_val > $prev_val) {
                            $discount_badge = "<div class='text-xs font-bold text-red-600 bg-red-100 px-2 py-1 rounded inline-block mt-1'>📈 Naik Rp " . number_format($current_val - $prev_val, 0, ',', '.') . "</div>";
                        }
                    ?>
                    <div class="border <?= $card_style ?> rounded-lg shadow-md w-80 flex-shrink-0 flex flex-col relative overflow-hidden">
                        <?php if($is_winner): ?><div class="bg-yellow-400 text-yellow-900 text-center text-sm font-black py-2 uppercase tracking-widest">🏆 WINNER 🏆</div>
                        <?php elseif($rank === 1 && $has_submitted && $rfq_data['status'] !== 'Closed'): ?><div class="bg-green-500 text-white text-center text-xs font-bold py-1 uppercase tracking-widest">Penawaran Termurah</div><?php endif; ?>

                        <div class="p-4 border-b <?= $is_winner ? 'bg-yellow-100' : 'bg-gray-50' ?> flex-grow">
                            <div class="text-xs text-gray-500 font-bold uppercase mb-1">Peringkat #<?= $rank ?></div>
                            <h4 class="font-bold text-lg text-indigo-700 mb-1"><?= htmlspecialchars($v['vendor_name'] ?? '') ?></h4>
                            <p class="text-xs text-gray-600">PIC: <?= htmlspecialchars($v['vendor_pic'] ?? '') ?> (<?= htmlspecialchars($v['vendor_wa'] ?? '') ?>)</p>
                        </div>

                        <div class="p-4 flex-grow space-y-4">
                            <?php if($has_submitted): ?>
                                <div>
                                    <?php if($prev_val): ?><div class="text-[10px] text-gray-500 font-bold uppercase line-through mb-1">Harga Ronde Sebelumnya: Rp <?= number_format($prev_val, 0, ',', '.') ?></div><?php endif; ?>
                                    <div class="text-[10px] text-gray-800 font-bold uppercase">Harga Lumpsum (Ronde Ini)</div>
                                    <div class="text-2xl font-bold font-mono text-gray-800">Rp <?= $lumpsum ?></div>
                                    <?= $discount_badge ?>
                                    <?php if($is_winner && $rfq_data['final_price'] != $v['total_price']): ?>
                                        <div class="mt-2 p-2 bg-yellow-200 text-yellow-800 rounded text-sm font-bold border border-yellow-300">Final Negotiated: Rp <?= number_format($rfq_data['final_price'], 0, ',', '.') ?></div>
                                    <?php endif; ?>
                                </div>
                                <div class="grid grid-cols-1 gap-2 border-t pt-2 mt-2">
                                    <div><div class="text-[10px] text-gray-500 font-bold uppercase">Price Validity</div><div class="font-bold text-sm text-gray-800"><?= htmlspecialchars($v['price_validity'] ?? '') ?></div></div>
                                </div>
                                <?php if(($rfq_data['rfq_type'] ?? 'Construction') === 'Standard' && !empty($vendor_items[$v['id']])): ?>
                                    <div class="mt-4 border-t pt-2">
                                        <div class="text-[10px] text-gray-500 font-bold uppercase mb-2">Detail Item</div>
                                        <div class="space-y-2 max-h-48 overflow-y-auto pr-1 custom-scrollbar">
                                            <?php foreach($vendor_items[$v['id']] as $vi): ?>
                                            <div class="bg-white p-2 rounded border border-gray-200 shadow-sm text-xs">
                                                <div class="font-bold text-gray-800"><?= htmlspecialchars($vi['ticket_line_item'] ?? '') ?></div>
                                                <div class="flex justify-between mt-1">
                                                    <span class="text-gray-500">Qty:</span>
                                                    <span class="font-bold"><?= (float)$vi['qty'] ?><?= !empty($vi['uom']) ? ' ' . htmlspecialchars($vi['uom']) : '' ?></span>
                                                </div>
                                                <div class="flex justify-between">
                                                    <span class="text-gray-500">Harga/Unit:</span>
                                                    <span class="font-bold text-indigo-600">Rp <?= number_format($vi['price'], 0, ',', '.') ?></span>
                                                </div>
                                                <div class="flex justify-between">
                                                    <span class="text-gray-500">Lead Time:</span>
                                                    <span class="font-bold"><?= htmlspecialchars($vi['lead_time'] ?? '') ?></span>
                                                </div>
                                                <?php if(!empty($vi['remarks'])): ?>
                                                    <div class="text-gray-500 italic mt-1 bg-gray-50 p-1 rounded border border-gray-100">"<?= htmlspecialchars($vi['remarks'] ?? '') ?>"</div>
                                                <?php endif; ?>
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            <?php else: ?>
                                <div class="h-full flex items-center justify-center py-8"><span class="text-red-500 font-bold bg-red-50 px-3 py-1 rounded">Belum Submit di Ronde Ini</span></div>
                            <?php endif; ?>
                        </div>

                        <div class="p-4 <?= $is_winner ? 'bg-yellow-100' : 'bg-gray-50' ?> border-t space-y-2 mt-auto">
                            <?php if($has_submitted): ?>
                                <?php if(!empty($v['final_cover_letter'])): ?>
                                    <button type="button" onclick="logAndDownload(<?= $rfq_id ?>, <?= $v['id'] ?>, 'COVER', '<?= get_download_path($v['final_cover_letter']) ?>')" class="w-full text-center text-xs font-bold text-indigo-800 border border-indigo-400 bg-indigo-100 hover:bg-indigo-200 py-2 rounded shadow-sm">📄 View Final Cover Letter</button>
                                <?php else: ?>
                                    <button type="button" onclick="logAndDownload(<?= $rfq_id ?>, <?= $v['id'] ?>, 'COVER', '<?= get_download_path($v['file_cover_letter']) ?>')" class="w-full text-center text-xs font-bold text-indigo-600 border border-indigo-200 bg-white hover:bg-indigo-50 py-2 rounded">📄 View Cover Letter</button>
                                <?php endif; ?>
                                
                                <?php if(!empty($v['final_boq'])): ?>
                                    <button type="button" onclick="logAndDownload(<?= $rfq_id ?>, <?= $v['id'] ?>, 'BOQ', '<?= get_download_path($v['final_boq']) ?>')" class="w-full mt-2 text-center text-xs font-bold text-emerald-800 border border-emerald-400 bg-emerald-100 hover:bg-emerald-200 py-2 rounded shadow-sm">📊 Download Final BoQ</button>
                                <?php else: ?>
                                    <button type="button" onclick="logAndDownload(<?= $rfq_id ?>, <?= $v['id'] ?>, 'BOQ', '<?= get_download_path($v['file_boq']) ?>')" class="w-full mt-2 text-center text-xs font-bold text-emerald-600 border border-emerald-200 bg-white hover:bg-emerald-50 py-2 rounded">📊 Download BoQ (Excel)</button>
                                <?php endif; ?>

                                <?php if(!empty($v['final_supporting'])): ?>
                                    <button type="button" onclick="logAndDownload(<?= $rfq_id ?>, <?= $v['id'] ?>, 'SUPPORT', '<?= get_download_path($v['final_supporting']) ?>')" class="w-full mt-2 text-center text-xs font-bold text-gray-800 border border-gray-400 bg-gray-200 hover:bg-gray-300 py-2 rounded shadow-sm">🗂️ Final Dok Pendukung (ZIP)</button>
                                <?php elseif(!empty($v['file_supporting'])): ?>
                                    <button type="button" onclick="logAndDownload(<?= $rfq_id ?>, <?= $v['id'] ?>, 'SUPPORT', '<?= get_download_path($v['file_supporting']) ?>')" class="w-full mt-2 text-center text-xs font-bold text-gray-600 border border-gray-300 bg-gray-50 hover:bg-gray-100 py-2 rounded">🗂️ Dok Pendukung (ZIP)</button>
                                <?php endif; ?>
                                
                                <?php if($is_latest_round && in_array($rfq_data['status'], ['Unsealed', 'Locked']) && ($_SESSION['username'] === $rfq_data['created_by'] || in_array($user_role, ['Manager','Category Manager', 'PIC']))): ?>
                                    <button type="button" onclick="openWinnerModal(<?= $v['id'] ?>, '<?= htmlspecialchars($v['vendor_name']) ?>', '<?= $v['total_price'] ?>')" class="w-full mt-2 bg-yellow-400 hover:bg-yellow-500 text-yellow-900 font-bold py-2 rounded shadow">🏆 Tetapkan Pemenang & Tutup</button>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php $rank++; endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <div class="bg-white shadow-sm rounded-lg p-6 border border-gray-200 mb-10">
            <h3 class="text-lg font-bold text-gray-800 border-b pb-2 mb-4">Log Aktivitas (Audit Trail)</h3>
            <div class="max-h-64 overflow-y-auto">
                <table class="w-full text-sm text-left">
                    <tbody>
                        <?php foreach($audit_logs as $log): 
                            $vendor_name = $log['target_vendor_id'] ? ($vendor_map[$log['target_vendor_id']] ?? 'Vendor') : '';
                            $action_text = $log['action_type'];
                            
                            if (strpos($action_text, 'EDIT_DEADLINE') === 0) {
                                $parts = explode('|', $action_text);
                                $action_text = '<span class="text-blue-600 font-bold">Mengubah Batas Waktu</span>' . (isset($parts[1]) ? " dari {$parts[1]} menjadi {$parts[2]}" : '');
                            }
                            
                            if ($action_text === 'UNSEAL') $action_text = '<span class="text-red-600 font-bold">Membuka Segel Tender</span>';
                            if ($action_text === 'LOCKED') $action_text = '<span class="text-purple-600 font-bold">Mengunci Tender (Lock for Tender Approval)</span>';
                            if ($action_text === 'DOWNLOAD_COVER') $action_text = 'Melihat Cover Letter dari <span class="font-bold">'.$vendor_name.'</span>';
                            if ($action_text === 'DOWNLOAD_BOQ') $action_text = 'Mengunduh BoQ dari <span class="font-bold">'.$vendor_name.'</span>';
                            if ($action_text === 'DOWNLOAD_SUPPORT') $action_text = 'Mengunduh Dok Pendukung (ZIP) dari <span class="font-bold">'.$vendor_name.'</span>';
                            if ($action_text === 'CLOSED_TENDER_AND_SET_PRICE') $action_text = '<span class="text-yellow-600 font-bold">Menetapkan Pemenang ('.$vendor_name.') & Menutup</span>';
                            if ($action_text === 'ELIMINATED') $action_text = '<span class="font-bold">'.$vendor_name.'</span> <span class="text-red-500 font-bold">Tereliminasi (Tidak submit saat segel dibuka)</span>';
                                    
                            $ld = new DateTime($log['created_at'], new DateTimeZone('UTC'));
                            $ld->setTimezone(new DateTimeZone('Asia/Jakarta'));
                        ?>
                        <tr class="border-b last:border-b-0 hover:bg-gray-50">
                            <td class="py-2 text-gray-500 font-mono text-xs w-48"><?= $ld->format('d M Y H:i:s') ?> WIB</td>
                            <td class="py-2 font-bold text-gray-800 w-48"><?= htmlspecialchars($log['username']) ?></td>
                            <td class="py-2 text-gray-700"><?= $action_text ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if(empty($audit_logs)): ?><tr><td class="py-4 text-gray-500 italic">Belum ada aktivitas.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div id="addRoundModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
        <div class="relative top-40 mx-auto p-6 border w-full max-w-lg shadow-xl rounded-md bg-white">
            <h3 class="text-xl font-bold text-gray-900 mb-2">+ Tambah Ronde Negosiasi</h3>
            <p class="text-sm text-gray-600 mb-4">Pilih vendor dari ronde saat ini yang akan Anda undang ke ronde selanjutnya.</p>
            <form method="POST" onsubmit="return confirm('Ini akan menggandakan RFQ dan otomatis mengirim password baru ke vendor terpilih. Lanjutkan?');">
                <input type="hidden" name="action" value="add_round">
                <input type="hidden" name="rfq_id" value="<?= $rfq_id ?>">
                <div class="bg-gray-50 border border-gray-200 rounded p-4 mb-4 max-h-48 overflow-y-auto">
                    <?php foreach($vendor_data as $v): 
                        $eliminated = empty($v['submitted_at']);
                    ?>
                        <label class="flex items-center space-x-3 mb-2 <?= $eliminated ? 'opacity-50 cursor-not-allowed' : 'cursor-pointer hover:bg-gray-100' ?> p-2 rounded">
                            <input type="checkbox" name="invited_vendors[]" value="<?= $v['id'] ?>" <?= $eliminated ? 'disabled' : 'checked' ?> class="w-4 h-4 text-indigo-600 rounded">
                            <span class="text-sm font-bold text-gray-700"><?= htmlspecialchars($v['vendor_name']) ?></span>
                            <?= $eliminated ? '<span class="text-xs text-red-500 font-bold ml-2">(Tereliminasi - Tidak Dapat Diundang)</span>' : '' ?>
                        </label>
                    <?php endforeach; ?>
                </div>
                <div class="flex justify-end gap-2">
                    <button type="button" onclick="document.getElementById('addRoundModal').classList.add('hidden')" class="bg-gray-300 px-4 py-2 rounded font-bold text-sm">Batal</button>
                    <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded font-bold text-sm shadow">Buat Ronde Baru & Undang</button>
                </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal Invite Vendor -->
    <div id="inviteVendorModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
        <div class="relative top-20 mx-auto p-6 border w-full max-w-lg shadow-xl rounded-md bg-white">
            <h3 class="text-xl font-bold text-gray-900 mb-2">Undang Vendor Tambahan</h3>
            <p class="text-sm text-gray-600 mb-4">Vendor akan menerima WhatsApp dan Email berisi link serta password rahasia untuk submit dokumen pada ronde ini.</p>
            <form method="POST" class="space-y-4">
                <input type="hidden" name="action" value="invite_vendor">
                <input type="hidden" name="rfq_id" value="<?= $rfq_id ?>">
                
                <div>
                    <label class="block text-sm font-bold text-gray-700">Nama Perusahaan Vendor</label>
                    <input type="text" name="vendor_name" required class="w-full p-2 border border-gray-300 rounded focus:ring focus:ring-indigo-200">
                </div>
                <div>
                    <label class="block text-sm font-bold text-gray-700">Nama PIC Vendor</label>
                    <input type="text" name="vendor_pic" class="w-full p-2 border border-gray-300 rounded focus:ring focus:ring-indigo-200">
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-bold text-gray-700">Email Vendor</label>
                        <input type="email" name="vendor_email" required class="w-full p-2 border border-gray-300 rounded focus:ring focus:ring-indigo-200">
                    </div>
                    <div>
                        <label class="block text-sm font-bold text-gray-700">No. WhatsApp</label>
                        <input type="text" name="vendor_wa" placeholder="Contoh: 0812345678" required class="w-full p-2 border border-gray-300 rounded focus:ring focus:ring-indigo-200">
                    </div>
                </div>

                <div class="flex justify-end gap-2 mt-4">
                    <button type="button" onclick="document.getElementById('inviteVendorModal').classList.add('hidden')" class="bg-gray-300 px-4 py-2 rounded font-bold text-sm">Batal</button>
                    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded font-bold text-sm shadow">Undang Sekarang</button>
                </div>
            </form>
        </div>
    </div>

    <div id="winnerModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
        <div class="relative top-40 mx-auto p-6 border w-96 shadow-xl rounded-md bg-white">
            <h3 class="text-xl font-bold text-gray-900 mb-2">Tutup Tender & Tetapkan Pemenang</h3>
            <form method="POST">
                <input type="hidden" name="action" value="close_tender">
                <input type="hidden" name="rfq_id" value="<?= $rfq_id ?>">
                <input type="hidden" name="vendor_id" id="w_id">
                <input type="hidden" name="vendor_name" id="w_name">
                <label class="block text-sm font-bold text-gray-700 mb-1">Harga Final Negosiasi (IDR)</label>
                <input type="text" name="final_price" id="w_price" required class="w-full p-2 border rounded font-mono font-bold text-lg text-gray-800 mb-4" oninput="formatRupiah(this)">
                <div class="flex justify-end gap-2">
                    <button type="button" onclick="document.getElementById('winnerModal').classList.add('hidden')" class="bg-gray-300 px-4 py-2 rounded font-bold text-sm">Batal</button>
                    <button type="submit" class="bg-yellow-400 hover:bg-yellow-500 text-yellow-900 px-4 py-2 rounded font-bold text-sm shadow">Tetapkan Pemenang</button>
                </div>
            </form>
        </div>
    </div>

    <div id="deadlineModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
        <div class="relative top-40 mx-auto p-6 border w-96 shadow-xl rounded-md bg-white">
            <h3 class="text-xl font-bold text-gray-900 mb-2">Edit Batas Waktu (Deadline)</h3>
            <form method="POST">
                <input type="hidden" name="action" value="edit_deadline">
                <input type="hidden" name="rfq_id" value="<?= $rfq_id ?>">
                <input type="datetime-local" name="new_deadline" required class="w-full p-2 border rounded mb-4" value="<?= date('Y-m-d\TH:i', strtotime($rfq_data['deadline'])) ?>">
                <div class="flex justify-end gap-2">
                    <button type="button" onclick="document.getElementById('deadlineModal').classList.add('hidden')" class="bg-gray-300 px-4 py-2 rounded font-bold text-sm">Batal</button>
                    <button type="submit" class="bg-indigo-600 text-white px-4 py-2 rounded font-bold text-sm shadow">Simpan Deadline</button>
                </div>
            </form>
        </div>
    </div>

<?php 
} else { 
    // ==========================================
    // VIEW 2: MASTER LIST VIEW
    // ==========================================
    $query = "
        SELECT r.id, r.package_name, r.created_by, r.created_at, r.deadline, r.status, r.round_number,
            (SELECT COUNT(*) FROM rfq_vendors v WHERE v.rfq_id = r.id) as total_vendors,
            (SELECT COUNT(*) FROM rfq_vendors v WHERE v.rfq_id = r.id AND v.submitted_at IS NOT NULL) as total_submissions
        FROM rfqs r 
        WHERE r.id IN (SELECT MAX(id) FROM rfqs GROUP BY IFNULL(parent_id, id))
        ORDER BY r.created_at DESC
    ";
    $rfqs = $pdo->query($query)->fetchAll(PDO::FETCH_ASSOC);
    ?>
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
    
    <div class="max-w-7xl mx-auto py-8">
        <div class="mb-6 flex flex-col md:flex-row justify-between items-start md:items-end border-b pb-4 gap-4">
            <div>
                <h2 class="text-3xl font-bold text-gray-800">Daftar Tender & RFQ</h2>
                <p class="text-sm text-gray-500 mt-1">Hanya menampilkan putaran terakhir dari setiap tender.</p>
            </div>
            <div class="flex gap-2">
                <?php if ($user_role === 'QS' || $user_role === 'Project Director'): ?>
                    <a href="dashboard_project.php" class="bg-gray-500 hover:bg-gray-600 text-white font-bold py-2 px-4 rounded-lg text-sm shadow">&larr; Dashboard</a>
                <?php elseif ($user_role === 'Category Manager'): ?>
                    <a href="dashboard_mgr.php" class="bg-gray-500 hover:bg-gray-600 text-white font-bold py-2 px-4 rounded-lg text-sm shadow">&larr; Dashboard</a>
                <?php else: ?>
                    <a href="dashboard_pic.php" class="bg-gray-500 hover:bg-gray-600 text-white font-bold py-2 px-4 rounded-lg text-sm shadow">&larr; Dashboard</a>
                <?php endif; ?>

                <?php if ($user_role !== 'QS'): ?>
                    <a href="rfq_create.php" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2 px-4 rounded-lg text-sm shadow">+ Buat RFQ Baru</a>
                <?php endif; ?>
            </div>
        </div>

        <div class="bg-white shadow-md rounded-lg p-6 border border-gray-200">
            <table id="rfqListTable" class="min-w-full leading-normal w-full text-left">
                <thead>
                    <tr class="bg-gray-100 border-b-2 border-gray-200">
                        <th class="p-3 text-xs font-bold text-gray-600 uppercase">Tender Name</th>
                        <th class="p-3 text-xs font-bold text-gray-600 uppercase">Tgl Dibuat</th>
                        <th class="p-3 text-xs font-bold text-gray-600 uppercase text-center">Ronde</th>
                        <th class="p-3 text-xs font-bold text-gray-600 uppercase">Creator</th>
                        <th class="p-3 text-xs font-bold text-gray-600 uppercase">Timeline</th>
                        <th class="p-3 text-xs font-bold text-gray-600 uppercase text-center">Submissions</th>
                        <th class="p-3 text-xs font-bold text-gray-600 uppercase text-center">Status</th>
                        <th class="p-3 text-xs font-bold text-gray-600 uppercase text-center">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rfqs as $r): 
                        $raw_status = $r['status'];
                        $deadline_passed = time() > strtotime($r['deadline']);
                        if ($raw_status === 'Unsealed') { $ui_status = 'Opened'; $badge = 'bg-blue-100 text-blue-800 border-blue-200'; }
                        elseif ($raw_status === 'Locked') { $ui_status = 'Locked'; $badge = 'bg-purple-100 text-purple-800 border-purple-200'; }
                        elseif ($raw_status === 'Closed') { $ui_status = 'Closed'; $badge = 'bg-gray-100 text-gray-800 border-gray-200'; } 
                        else {
                            if ($deadline_passed || ($r['total_submissions'] > 0 && $r['total_submissions'] == $r['total_vendors'])) {
                                $ui_status = 'Waiting Opening'; $badge = 'bg-orange-100 text-orange-800 border-orange-200';
                            } else {
                                $ui_status = 'Waiting Submission'; $badge = 'bg-yellow-100 text-yellow-800 border-yellow-200';
                            }
                        }
                    ?>
                    <tr class="border-b hover:bg-indigo-50 transition">
                        <td class="p-3">
                            <div class="text-[10px] text-gray-500 font-mono font-bold mb-0.5">ID: #<?= $r['id'] ?></div>
                            <div class="font-bold text-indigo-700"><?= htmlspecialchars($r['package_name']) ?></div>
                        </td>
                        <td class="p-3 text-xs text-gray-600 font-mono"><?= date('d M Y', strtotime($r['created_at'])) ?></td>
                        <td class="p-3 text-center text-sm font-bold text-gray-600"><?= $r['round_number'] ?></td>
                        <td class="p-3 text-sm text-gray-800"><?= htmlspecialchars($r['created_by']) ?></td>
                        <td class="p-3 text-xs text-gray-600"><strong>Deadline:</strong> <br><?= date('d M Y H:i', strtotime($r['deadline'])) ?> WIB</td>
                        <td class="p-3 text-center font-bold text-gray-700"><span class="text-lg <?= $r['total_submissions'] === $r['total_vendors'] ? 'text-green-600' : '' ?>"><?= $r['total_submissions'] ?></span> / <?= $r['total_vendors'] ?></td>
                        <td class="p-3 text-center"><span class="<?= $badge ?> py-1 px-3 rounded-full text-xs font-bold border inline-block whitespace-nowrap"><?= $ui_status ?></span></td>
                        <td class="p-3 text-center">
                            <div class="flex justify-center items-center gap-2">
                                <a href="rfq_dashboard.php?id=<?= $r['id'] ?>" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-1.5 px-3 rounded text-xs shadow transition whitespace-nowrap">Lihat Tender</a>
                                <?php if ($user_role === 'Category Manager'): ?>
                                    <form method="POST" class="inline" onsubmit="return confirm('Hapus Tender ini? Semua putaran, data vendor, dan histori akan terhapus permanen.');">
                                        <input type="hidden" name="action" value="delete_rfq">
                                        <input type="hidden" name="rfq_id" value="<?= $r['id'] ?>">
                                        <button type="submit" class="bg-red-50 hover:bg-red-100 text-red-600 border border-red-200 font-bold py-1.5 px-3 rounded text-xs transition">Del</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php } ?>
