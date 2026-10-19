<?php
// foreman_tabs/_quotation_section.php
//
// Shared renderer for the foreman's collapsible quotation section.
// Used by:
//   - f_site_inspection.php                (initial page render)
//   - foreman.php?ajax=add_quotation       (AJAX refresh)
//
// The function does its own DB lookups so callers don't have to pass
// in a bunch of pre-computed arrays.

if (!function_exists('renderForemanQuotationSection')) {

function renderForemanQuotationSection(PDO $pdo, array $ins): string
{
    $inspectionId = (int)$ins['id'];

    // ---- Material catalog ----
    $materialCatalog = $pdo->query("
        SELECT id, item_product, specification, unit, estimated_price,
               material_type, product_category, roofing_product, general_category
        FROM materials
        ORDER BY material_type ASC, general_category ASC, roofing_product ASC, item_product ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $allMainMaterials    = array_values(array_filter($materialCatalog, fn($m) => $m['material_type'] === 'main'));
    $allGeneralMaterials = array_values(array_filter($materialCatalog, fn($m) => $m['material_type'] === 'general'));

    // ---- Measurements ----
    $stmt = $pdo->prepare("
        SELECT * FROM site_inspection_measurements
        WHERE inspection_id = ?
        ORDER BY id ASC
    ");
    $stmt->execute([$inspectionId]);
    $insMeas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ---- Down payment rate ----
    $stmt = $pdo->query("SELECT * FROM system_settings ORDER BY id ASC LIMIT 1");
    $systemSettings = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $down_payment_rate = (float)($systemSettings['down_payment_rate'] ?? 30.00);

    // ---- Quotations ----
    $insQuotations = getQuotationsForInspection($pdo, $inspectionId);
    $hasAnyQuotation = !empty($insQuotations);

    ob_start();
    ?>
    <div class="quotation-section-wrapper" data-inspection-id="<?= $inspectionId ?>">
        <div class="quotation-section-header">
            <h3 class="fr-section-title">Quotations</h3>
            <div class="quotation-section-header-actions">
                <button type="button" class="quotation-collapse-btn"
                        aria-label="Collapse or expand quotations"
                        onclick="toggleQuotationSection(this)">
                    <i class="bi bi-chevron-down"></i>
                </button>
                <form method="POST" action="foreman.php?tab=site_inspection"
                      class="add-quotation-form m-0">
                    <?= csrf_field() ?>
                    <input type="hidden" name="inspection_id" value="<?= $inspectionId ?>">
                    <button type="submit" name="add_quotation"
                            class="btn btn-sm btn-outline-secondary">
                        <i class="bi bi-plus-lg me-1"></i>Add Quotation
                    </button>
                </form>
            </div>
        </div>

        <div class="quotation-section-body">
            <?php if (!$hasAnyQuotation): ?>
                <div class="text-center py-4 rounded-3"
                     style="background: var(--fr-bg); border: 1px dashed var(--fr-border); font-size: 13px; color: var(--fr-muted);">
                    No quotation has been prepared yet. One is created automatically when this inspection is marked Completed.
                </div>
            <?php else: ?>
                <div class="quotation-tabs" data-fi="<?= $inspectionId ?>">
                    <?php foreach ($insQuotations as $idx => $q): ?>
                        <button type="button"
                                class="quotation-tab <?= $idx === 0 ? 'is-active' : '' ?>"
                                data-qid="<?= (int)$q['id'] ?>">
                            Quotation <?= $idx + 1 ?>
                            <small class="tab-status"><?= htmlspecialchars($q['status']) ?></small>
                        </button>
                    <?php endforeach; ?>
                </div>

                <?php foreach ($insQuotations as $idx => $q):
                    $qMats = getQuotationMaterials($pdo, (int)$q['id']);
                    $qMain = []; $qGen = [];
                    foreach ($qMats as $m) {
                        if (($m['material_type'] ?? 'main') === 'general') $qGen[(int)$m['material_id']] = $m;
                        else $qMain[(int)$m['material_id']] = $m;
                    }
                    $preselectedMain = [];
                    foreach ($qMain as $mid => $m) {
                        $preselectedMain[$mid] = ['qty' => (float)$m['quantity'], 'price' => (float)$m['unit_price']];
                    }
                    $preselectedGeneral = [];
                    foreach ($qGen as $mid => $m) {
                        $preselectedGeneral[$mid] = ['qty' => (float)$m['quantity'], 'price' => (float)$m['unit_price']];
                    }

                    // Auto-fill from template if the quotation is brand new.
                    if (empty($qMats) && !empty($ins['roofing_product'])) {
                        $savedLen = 0; $savedWid = 0;
                        foreach ($insMeas as $im) {
                            $c = strtolower(trim($im['component'] ?? ''));
                            if ($c === 'roof length')      $savedLen = (float)$im['length'];
                            elseif ($c === 'roof width')   $savedWid = (float)$im['length'];
                        }
                        $measMap = [
                            'roof_area'    => (float)($ins['roof_area'] ?? 0),
                            'ridge_length' => $savedLen,
                            'eave_length'  => 2 * ($savedLen + $savedWid),
                        ];
                        $effService = strtolower(trim($ins['roof_type'] ?: 'roof installation'));
                        $template   = getMaterialTemplate($pdo, $effService, $ins['roofing_category'] ?? '', $ins['roofing_product']);
                        if ($template && !empty($template['items'])) {
                            foreach ($template['items'] as $tItem) {
                                $isMain = (($tItem['material_type'] ?? '') === 'main');
                                if ($effService === 'roof repair' && $isMain) continue;
                                $mid = (int)$tItem['material_id'];
                                $qty = calculateSuggestedQuantity($tItem['qty_rule'], $measMap, $effService);
                                $price = (float)($tItem['estimated_price'] ?? 0);
                                if ($isMain) $preselectedMain[$mid] = ['qty' => $qty, 'price' => $price];
                                else         $preselectedGeneral[$mid] = ['qty' => $qty, 'price' => $price];
                            }
                        }
                    }

                    $catalogMain = array_map(fn($m) => [
                        'id'              => (int)$m['id'],
                        'item_product'    => $m['item_product'],
                        'specification'   => $m['specification'] ?? '',
                        'unit'            => $m['unit'] ?? '',
                        'price'           => (float)($m['estimated_price'] ?? 0),
                        'category'        => $m['roofing_product'] ?: 'Uncategorized',
                        'roofing_product' => $m['roofing_product'] ?? '',
                    ], $allMainMaterials);

                    $catalogGeneral = array_map(fn($m) => [
                        'id'           => (int)$m['id'],
                        'item_product' => $m['item_product'],
                        'specification'=> $m['specification'] ?? '',
                        'unit'         => $m['unit'] ?? '',
                        'price'        => (float)($m['estimated_price'] ?? 0),
                        'category'     => $m['general_category'] ?: 'Uncategorized',
                    ], $allGeneralMaterials);

                    $mainCats = array_values(array_unique(array_filter(array_map(
                        fn($m) => $m['roofing_product'] ?: 'Uncategorized', $allMainMaterials
                    ))));
                    sort($mainCats);

                    $generalCats = array_values(array_unique(array_filter(array_map(
                        fn($m) => $m['general_category'] ?: 'Uncategorized', $allGeneralMaterials
                    ))));
                    sort($generalCats);

                    $qpConfig = [
                        'main' => [
                            'catalog'        => $catalogMain,
                            'preselected'    => $preselectedMain,
                            'roofingProduct' => $ins['roofing_product'] ?? '',
                            'categories'     => $mainCats,
                        ],
                        'general' => [
                            'catalog'     => $catalogGeneral,
                            'preselected' => $preselectedGeneral,
                            'categories'  => $generalCats,
                        ],
                    ];
                    $qEditable = in_array(
                        $q['status'],
                        ['Waiting for Action', 'Waiting for Admin Confirmation'],
                        true
                    );
                ?>
                    <div class="quotation-panel"
                         data-qid="<?= (int)$q['id'] ?>"
                         style="display: <?= $idx === 0 ? 'block' : 'none' ?>;">
                        <form method="POST" action="foreman.php?tab=site_inspection" class="quotation-panel-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="edit_id" value="<?= $inspectionId ?>">
                            <input type="hidden" name="editing_quotation_id" value="<?= (int)$q['id'] ?>">
                            <input type="hidden" name="save_quotation_only" value="1">

                            <script type="application/json" class="qp-config"><?= json_encode($qpConfig, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?></script>

                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <div>
                                    <strong><?= htmlspecialchars($q['quotation_id']) ?></strong>
                                    <span style="font-size: 11.5px; color: var(--fr-muted); margin-left: 6px;">
                                        <?= date('M d, Y', strtotime($q['created_at'])) ?>
                                    </span>
                                </div>
                                <span class="fr-badge <?= strtolower(str_replace(' ', '-', $q['status'])) ?>">
                                    <?= htmlspecialchars($q['status']) ?>
                                </span>
                            </div>

                            <!-- REVISION: Match the existing server edit rule. A disabled fieldset
                                 also locks material controls inserted later by JavaScript.
                                 Initial quotations remain editable; Sent/Approved stay read-only. -->
                            <fieldset <?= $qEditable ? '' : 'disabled' ?>
                                      style="border:0; padding:0; margin:0; min-width:0;">
                            <h4 class="fr-section-title">Main Component Materials</h4>
                            <?php if (!empty($ins['roofing_product'])): ?>
                                <p style="color: var(--fr-muted); font-size: 12.5px; margin-bottom: 8px;">
                                    Filtered to the Roofing Product / Profile selected in the inspection:
                                    <strong style="color: var(--fr-text);"><?= htmlspecialchars($ins['roofing_product']) ?></strong>
                                </p>
                            <?php else: ?>
                                <p style="color: var(--fr-muted); font-size: 12.5px; margin-bottom: 8px;">
                                    No Roofing Product / Profile is set — showing all main component materials.
                                </p>
                            <?php endif; ?>

                            <div class="qp-split" data-section="main">
                                <div class="qp-panel">
                                    <div class="qp-panel-header">
                                        <span>Selected <span class="qp-count">0</span></span>
                                    </div>
                                    <div class="qp-list qp-selected-list"></div>
                                </div>
                                <div class="qp-panel">
                                    <div class="qp-panel-header" style="padding: 8px 10px;">
                                        <input type="text" class="qp-search" placeholder="Search materials…">
                                    </div>
                                    <div class="qp-list qp-available-list"></div>
                                    <button type="button" class="qp-show-more" style="display:none;">Show more…</button>
                                </div>
                            </div>
                            <div class="qp-subtotal">
                                <span>Main Subtotal</span>
                                <strong class="qp-main-subtotal">₱0.00</strong>
                            </div>

                            <h4 class="fr-section-title">General Roof Construction Materials</h4>
                            <div class="qp-split" data-section="general">
                                <div class="qp-panel">
                                    <div class="qp-panel-header">
                                        <span>Selected <span class="qp-count">0</span></span>
                                    </div>
                                    <div class="qp-list qp-selected-list"></div>
                                </div>
                                <div class="qp-panel">
                                    <div class="qp-panel-header" style="padding: 8px 10px;">
                                        <input type="text" class="qp-search" placeholder="Search materials…">
                                    </div>
                                    <div class="qp-chips"></div>
                                    <div class="qp-list qp-available-list"></div>
                                    <button type="button" class="qp-show-more" style="display:none;">Show more…</button>
                                </div>
                            </div>
                            <div class="qp-subtotal">
                                <span>General Subtotal</span>
                                <strong class="qp-general-subtotal">₱0.00</strong>
                            </div>

                            <!-- REVISION: Refresh Grand Total when delivery or installation charges change. -->
                            <h4 class="fr-section-title">Additional Charges</h4>
                            <div class="row g-3">
                                <div class="col-md-4"><div class="form-group"><label>Delivery Charges (₱)</label>
                                    <input type="text" inputmode="decimal" class="form-control money-input"
                                           name="q_delivery_charges" placeholder="0.00" required
                                           oninput="qpRecalcAll()" onchange="qpRecalcAll()"
                                           value="<?= ((float)($q['delivery_charges'] ?? 0) > 0)
                                                     ? htmlspecialchars(number_format((float)$q['delivery_charges'], 2, '.', ','))
                                                     : '' ?>"></div></div>
                                <div class="col-md-4"><div class="form-group"><label>Installation Fee (₱)</label>
                                    <input type="text" inputmode="decimal" class="form-control money-input"
                                           name="q_installation_charge" placeholder="0.00" required
                                           oninput="qpRecalcAll()" onchange="qpRecalcAll()"
                                           value="<?= ((float)($q['installation_charge'] ?? 0) > 0)
                                                     ? htmlspecialchars(number_format((float)$q['installation_charge'], 2, '.', ','))
                                                     : '' ?>"></div></div>
                                <div class="col-md-4"><div class="form-group"><label>Discount (%)</label>
                                    <input type="number" name="q_discount_percent" data-numeric="1" required min="0" max="100" step="0.01"
                                           value="<?= htmlspecialchars($q['discount'] ?? 0) ?>"
                                           oninput="qpRecalcAll()"></div></div>
                            </div>

                            <div class="d-flex justify-content-end align-items-center gap-4 py-3"
                                 style="border-top: 1px solid var(--fr-border);">
                                <span class="fw-bold">Quotation Grand Total</span>
                                <strong class="q-grand-total" style="color: var(--fr-red); font-size: 17px;">₱0.00</strong>
                            </div>

                            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px;">
                                <input type="checkbox" name="q_request_admin" value="1"
                                       <?= $q['status'] === 'Waiting for Admin Confirmation' ? 'checked' : '' ?>>
                                <span>Request admin confirmation when saving</span>
                            </label>

                            <div class="d-flex justify-content-between align-items-center gap-2 mt-4 pt-3"
                                 style="border-top: 1px solid var(--fr-border);">
                                <?php if ($qEditable): ?>
                                    <span></span>
                                    <button type="submit" name="edit_inspection" class="btn btn-primary">
                                        Save This Quotation
                                    </button>
                                <?php else: ?>
                                    <div class="d-flex align-items-center gap-2" style="color: var(--fr-muted); font-size: 13px;">
                                        <i class="bi bi-lock-fill"></i>
                                        <span>
                                            Locked — this quotation is no longer editable.
                                            <?php if ($q['status'] === 'Approved'): ?>
                                                The client has already approved it.
                                            <?php elseif ($q['status'] === 'Sent'): ?>
                                                It is awaiting the client's decision.
                                            <?php elseif ($q['status'] === 'Not Selected'): ?>
                                                Another quotation on this inspection was chosen.
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                <?php endif; ?>
                            </div>
                            </fieldset>
                        </form>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

}