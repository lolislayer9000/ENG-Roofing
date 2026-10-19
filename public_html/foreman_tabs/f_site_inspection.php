<?php
// This file is included inside foreman.php.
// Foreman view of site inspections assigned to them.


$foreman_id = (int)($_SESSION['user_id'] ?? 0);

// =====================================================================
// AJAX: material template lookup
// =====================================================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_material_template') {
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');

    $service_type    = trim($_GET['service_type'] ?? '');
    $roof_type       = trim($_GET['roof_type'] ?? '');
    $roofing_product = trim($_GET['roofing_product'] ?? '');
    $measurements = [
        'roof_area'         => (float)($_GET['roof_area'] ?? 0),
        'replacement_area'  => (float)($_GET['replacement_area'] ?? 0),
        'repair_area'       => (float)($_GET['repair_area'] ?? 0),
        'ridge_length'      => (float)($_GET['ridge_length'] ?? 0),
        'eave_length'       => (float)($_GET['eave_length'] ?? 0),
    ];

    $normService  = normalizeServiceTypeName($service_type);
    $template     = getMaterialTemplate($pdo, $service_type, $roof_type, $roofing_product);
    $matchingMain = getMatchingMainMaterial($pdo, $roofing_product);
    $itemsWithQty = [];

    if ($template && !empty($template['items'])) {
        foreach ($template['items'] as $item) {
            $isMain = (($item['material_type'] ?? '') === 'main');
            if ($normService === 'roof repair' && $isMain) continue;

            $qty   = calculateSuggestedQuantity($item['qty_rule'], $measurements, $service_type);
            $price = (float)($item['estimated_price'] ?? 0);
            $itemsWithQty[] = array_merge($item, [
                'material_id'          => (int)$item['material_id'],
                'complete_name'        => getCompleteMaterialName($item),
                'suggested_qty'        => $qty,
                'unit_price'           => $price,
                'price_not_configured' => ($price <= 0),
            ]);
        }
    }

    if ($normService !== 'roof repair' && $matchingMain) {
        $hasMain = false;
        foreach ($itemsWithQty as $it) { if (($it['material_type'] ?? '') === 'main') { $hasMain = true; break; } }
        if (!$hasMain) {
            $qty   = calculateSuggestedQuantity('roof_area', $measurements, $service_type);
            $price = (float)($matchingMain['estimated_price'] ?? 0);
            array_unshift($itemsWithQty, array_merge($matchingMain, [
                'material_id'          => (int)$matchingMain['id'],
                'complete_name'        => getCompleteMaterialName($matchingMain),
                'suggested_qty'        => $qty,
                'unit_price'           => $price,
                'price_not_configured' => ($price <= 0),
                'qty_rule'             => 'fixed_1',
            ]));
        }
    }

    echo json_encode([
        'success'         => true,
        'has_template'    => (bool)$template,
        'template_name'   => $template['template_name'] ?? null,
        'roofing_product' => $roofing_product,
        'items'           => $itemsWithQty,
    ]);
    exit;
}
// =====================================================================
// Roof shapes — driven by the admin's Service & Property Configuration.
// =====================================================================
$roof_shapes = getActiveConfigurationNames($pdo, 'roof');
if (empty($roof_shapes)) {
    $roof_shapes = ['Gable Roof', 'Hip Roof', 'Flat Roof', 'Shed Roof'];
}

// Service types — same source.
$roof_types = getActiveConfigurationNames($pdo, 'service');
if (empty($roof_types)) {
    $roof_types = ['roof installation', 'roof repair', 'roof replacement'];
}

// -----------------------------------------------------------------------------
// Data for the collapsible "Prepare Quotation" section
// -----------------------------------------------------------------------------
$stmt = $pdo->query("SELECT * FROM system_settings ORDER BY id ASC LIMIT 1");
$system_settings = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$system_settings) $system_settings = ['down_payment_rate' => 30.00];
$down_payment_rate = (float)$system_settings['down_payment_rate'];

$material_catalog = $pdo->query("
    SELECT id, item_product, specification, unit, estimated_price,
           material_type, product_category, roofing_product, general_category
    FROM materials
    ORDER BY material_type ASC, general_category ASC, roofing_product ASC, item_product ASC
")->fetchAll(PDO::FETCH_ASSOC);

$allMainMaterials    = array_values(array_filter($material_catalog, fn($m) => $m['material_type'] === 'main'));
$allGeneralMaterials = array_values(array_filter($material_catalog, fn($m) => $m['material_type'] === 'general'));
$productCategoryMap = getProductCategoriesAndProfiles($pdo);

if ($foreman_id <= 0) {
    die('Invalid foreman account.');
}


// -----------------------------------------------------------------------------
// Shared: calculate the derived measurements for a roof shape.
//
// Inputs:  $category ('Gable Roof' | 'Hip Roof' | 'Flat Roof' | 'Shed Roof')
//          $length, $width, $rise  (all in metres; $rise ignored for Flat)
//
// Returns: ['sloped_length'=>…, 'ridge'=>…, 'eave'=>…, 'hip_rafter'=>…, 'area'=>…]
// -----------------------------------------------------------------------------
function calcRoofMeasurements(string $category, float $length, float $width, float $rise): array
{
    $sloped_length = 0.0;
    $ridge         = 0.0;
    $eave          = 0.0;
    $hip_rafter    = 0.0;
    $area          = 0.0;

    switch ($category) {
        case 'Gable Roof':
            $sloped_length = sqrt(pow($width / 2, 2) + pow($rise, 2));
            $area          = 2 * $length * $sloped_length;
            $ridge         = $length;
            $eave          = 2 * $length;
            break;

        case 'Hip Roof':
            $sloped_length = sqrt(pow($width / 2, 2) + pow($rise, 2));
            $hip_rafter    = sqrt(pow($rise, 2) + (pow($width, 2) / 2));
            // Two trapezoids (long sides) + two hip triangles (ends).
            $area          = max(0, (2 * $length - $width)) * $sloped_length
                           + $width * $hip_rafter;
            $ridge         = max(0, $length - $width);
            $eave          = 2 * ($length + $width);
            break;

        case 'Flat Roof':
            $area          = $length * $width;
            $eave          = 2 * ($length + $width);
            break;

        case 'Shed Roof':
            $sloped_length = sqrt(pow($width, 2) + pow($rise, 2));
            $area          = $length * $sloped_length;
            $eave          = $length;
            break;
    }

    return [
        'sloped_length' => round($sloped_length, 4),
        'ridge'         => round($ridge, 4),
        'eave'          => round($eave, 4),
        'hip_rafter'    => round($hip_rafter, 4),
        'area'          => round($area, 2),
    ];
}


// -----------------------------------------------------------------------------
// Handle actions
// -----------------------------------------------------------------------------

// Update inspection (edit)
// Update inspection (edit)
if (isset($_POST['edit_inspection'])) {

    if (!isset($_POST['csrf_token']) || !csrf_validate($_POST['csrf_token'])) {
        die('CSRF token validation failed.');
    }

    $id = (int)($_POST['edit_id'] ?? 0);

    // Which form was submitted?
    $saveFields    = !empty($_POST['save_inspection_fields']);
    $saveQuotation = !empty($_POST['save_quotation_only']);

    // Verify ownership
    $stmt = $pdo->prepare("
        SELECT * FROM site_inspections
        WHERE id = ? AND inspector_id = ?
        LIMIT 1
    ");
    $stmt->execute([$id, $foreman_id]);
    $current = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$current) {
        header('Location: foreman.php?tab=site_inspection&msg=not_allowed');
        exit;
    }

    /* ================================================================
       PATH A — SAVE INSPECTION FIELDS
       ================================================================ */
    if ($saveFields) {
        
        // REVISION: Backend protection for completed inspection details.
        if (strtolower(trim((string)$current['status'])) === 'completed') {
        header('Location: foreman.php?tab=site_inspection&msg=inspection_locked');
            exit;
        }

        // REVISION: Reject invalid numeric input instead of silently removing characters.
        foreach (['rm_length', 'rm_width', 'rm_rise'] as $field) {
            $raw = trim((string)($_POST[$field] ?? ''));
            if ($raw !== '' && (!is_numeric($raw) || !is_finite((float)$raw) || (float)$raw < 0)) {
                header('Location: foreman.php?tab=site_inspection&msg=invalid_measurements');
                exit;
            }
        }
        if (filter_var($_POST['labor_requirement'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            header('Location: foreman.php?tab=site_inspection&msg=invalid_labor');
            exit;
        }

        // Sanitize numeric inputs
        $_POST['rm_length']         = (float)preg_replace('/[^0-9.]/', '', (string)($_POST['rm_length'] ?? ''));
        $_POST['rm_width']          = (float)preg_replace('/[^0-9.]/', '', (string)($_POST['rm_width'] ?? ''));
        $_POST['rm_rise']           = (float)preg_replace('/[^0-9.]/', '', (string)($_POST['rm_rise'] ?? ''));
        $_POST['labor_requirement'] = (int)preg_replace('/[^0-9]/', '', (string)($_POST['labor_requirement'] ?? '0'));

        if (!isset($_POST['labor_requirement']) || (int)$_POST['labor_requirement'] < 1) {
            header('Location: foreman.php?tab=site_inspection&msg=invalid_labor');
            exit;
        }

        $roofing_category = $_POST['roofing_category'] ?? '';
        $allowedRoofShapes = $roof_shapes;
        if (!empty($current['roofing_category']) && !in_array($current['roofing_category'], $allowedRoofShapes, true)) {
            $allowedRoofShapes[] = $current['roofing_category'];
        }
        if (!in_array($roofing_category, $allowedRoofShapes, true)) {
            header('Location: foreman.php?tab=site_inspection&msg=invalid_measurements');
            exit;
        }

        $rm_length = (float)($_POST['rm_length'] ?? 0);
        $rm_width  = (float)($_POST['rm_width']  ?? 0);
        $rm_rise   = (float)($_POST['rm_rise']   ?? 0);
        $is_flat   = ($roofing_category === 'Flat Roof');

        if ($rm_length <= 0 || $rm_width <= 0) {
            header('Location: foreman.php?tab=site_inspection&msg=invalid_measurements');
            exit;
        }
        if (!$is_flat && $rm_rise <= 0) {
            header('Location: foreman.php?tab=site_inspection&msg=invalid_measurements');
            exit;
        }
        if ($is_flat) { $rm_rise = 0; }

        $calc = calcRoofMeasurements($roofing_category, $rm_length, $rm_width, $rm_rise);
        $roof_area = $calc['area'];
        // REVISION: Unsupported roof shapes cannot produce a completed zero-area inspection.
        if (!is_finite($roof_area) || $roof_area <= 0) {
            header('Location: foreman.php?tab=site_inspection&msg=invalid_measurements');
            exit;
        }

        $existing_roof_condition = $_POST['existing_roof_condition'] ?? '';
        $roof_type               = $_POST['roof_type'] ?? '';
        $product_category        = $_POST['product_category'] ?? '';
        $roofing_product         = $_POST['roofing_product'] ?? '';
        $thickness               = $_POST['thickness'] ?? '';
        $labor_requirement       = (int)$_POST['labor_requirement'];
        $site_accessibility      = $_POST['site_accessibility'] ?? null;
        $site_preparation        = $_POST['site_preparation'] ?? null;
        $status                  = $_POST['status'] ?? $current['status'];
        // REVISION: Required inspection details; Thickness is stored only for compatibility.
        $requiredFields = [
        $existing_roof_condition,
        $roof_type,
        $product_category,
        $roofing_product,
        $site_accessibility,
        $site_preparation,
        ];

        foreach ($requiredFields as $value) {
            if (trim((string)$value) === '') {
                header('Location: foreman.php?tab=site_inspection&msg=incomplete_inspection');
                exit;
            }
        }

        $allowed_statuses = ['Scheduled', 'Completed', 'Requires Follow-up'];
        if (!in_array($status, $allowed_statuses, true)) {
            $status = $current['status'];
        }

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                UPDATE site_inspections
                SET roof_area = :roof_area,
                    existing_roof_condition = :existing_roof_condition,
                    roof_type = :roof_type,
                    product_category = :product_category,
                    roofing_category = :roofing_category,
                    roofing_product = :roofing_product,
                    thickness = :thickness,
                    labor_requirement = :labor_requirement,
                    site_accessibility = :site_accessibility,
                    site_preparation = :site_preparation,
                    status = :status
                WHERE id = :id AND inspector_id = :foreman_id
            ");
            $stmt->execute([
                ':roof_area'               => $roof_area,
                ':existing_roof_condition' => $existing_roof_condition,
                ':roof_type'               => $roof_type,
                ':product_category'        => $product_category,
                ':roofing_category'        => $roofing_category,
                ':roofing_product'         => $roofing_product,
                ':thickness'               => $thickness,
                ':labor_requirement'       => $labor_requirement,
                ':site_accessibility'      => $site_accessibility,
                ':site_preparation'        => $site_preparation,
                ':status'                  => $status,
                ':id'                      => $id,
                ':foreman_id'              => $foreman_id,
            ]);

            $pdo->prepare("DELETE FROM site_inspection_measurements WHERE inspection_id = ?")
                ->execute([$id]);

            $insertStmt = $pdo->prepare("
                INSERT INTO site_inspection_measurements
                    (inspection_id, component, length, unit, notes)
                VALUES (?, ?, ?, ?, ?)
            ");
            if ($rm_length > 0)        $insertStmt->execute([$id, 'Roof Length', $rm_length, 'm', null]);
            if ($rm_width > 0)         $insertStmt->execute([$id, 'Roof Width',  $rm_width,  'm', null]);
            if (!$is_flat && $rm_rise > 0) $insertStmt->execute([$id, 'Rise',    $rm_rise,   'm', null]);

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            header('Location: foreman.php?tab=site_inspection&msg=error');
            exit;
        }

        header('Location: foreman.php?tab=site_inspection&msg=updated');
        exit;
    }

    /* ================================================================
       PATH B — SAVE ONE QUOTATION
       ================================================================ */
    if ($saveQuotation) {

        $editing_quotation_id = (int)($_POST['editing_quotation_id'] ?? 0);
        if ($editing_quotation_id <= 0) {
            header('Location: foreman.php?tab=site_inspection&msg=error');
            exit;
        }

        // Fetch the specific quotation this form submits to
        $stmt = $pdo->prepare("
            SELECT * FROM quotations
            WHERE id = ? AND inspection_id = ?
            LIMIT 1
        ");
        $stmt->execute([$editing_quotation_id, $id]);
        $existingQuotation = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$existingQuotation) {
            header('Location: foreman.php?tab=site_inspection&msg=not_allowed');
            exit;
        }

        // Only editable statuses
        $editable = in_array($existingQuotation['status'],
            ['Waiting for Action', 'Waiting for Admin Confirmation'], true);

        if (!$editable) {
            header('Location: foreman.php?tab=site_inspection&msg=not_allowed');
            exit;
        }

        // Build the material rows
        $quotationMaterialsArr = [];
        $q_ids    = $_POST['q_material_id']         ?? [];
        $q_types  = $_POST['q_material_type']       ?? [];
        $q_qtys   = $_POST['q_material_qty']        ?? [];
        $q_prices = $_POST['q_material_unit_price'] ?? [];

        if (is_array($q_ids) && is_array($q_qtys) && is_array($q_prices)) {
            $count = count($q_ids);
            for ($i = 0; $i < $count; $i++) {
                $mid   = (int)($q_ids[$i] ?? 0);
                // REVISION: Reject invalid quantity; never silently remove a selected material.
            $qtyInput = $q_qtys[$i] ?? '';
            
            if (
                !is_scalar($qtyInput) ||
                !is_numeric($qtyInput) ||
                !is_finite((float)$qtyInput) ||
                (float)$qtyInput < 0.01
            ) {
                header('Location: foreman.php?tab=site_inspection&msg=invalid_quantity');
                exit;
            }
            
            $qty = (float)$qtyInput;
                $price = (float)str_replace(',', '', (string)($q_prices[$i] ?? '0'));
                $type  = in_array(($q_types[$i] ?? 'main'), ['main','general'], true) ? $q_types[$i] : 'main';

                if ($mid <= 0 || $qty <= 0) continue;

                $stmt = $pdo->prepare("SELECT id, item_product, specification, unit FROM materials WHERE id = ? LIMIT 1");
                $stmt->execute([$mid]);
                $material = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$material) continue;

                $price = max(0, $price);
                $quotationMaterialsArr[] = [
                    'material_id'   => $material['id'],
                    'material_type' => $type,
                    'item_product'  => $material['item_product'],
                    'specification' => $material['specification'],
                    'unit'          => $material['unit'],
                    'quantity'      => $qty,
                    'unit_price'    => $price,
                    'amount'        => round($qty * $price, 2),
                ];
            }
        }

        $material_subtotal = 0;
        foreach ($quotationMaterialsArr as $r) $material_subtotal += $r['amount'];
        $material_subtotal = round($material_subtotal, 2);

   // REVISION: Installation and delivery charges must both be greater than zero.
        $instInput = $_POST['q_installation_charge'] ?? '';
        $delivInput = $_POST['q_delivery_charges'] ?? '';
        
        $instInput = is_scalar($instInput)
            ? trim(str_replace(',', '', (string)$instInput)) : '';
        $delivInput = is_scalar($delivInput)
            ? trim(str_replace(',', '', (string)$delivInput)) : '';
        
        if (
            !is_numeric($instInput) ||
            !is_numeric($delivInput) ||
            !is_finite((float)$instInput) ||
            !is_finite((float)$delivInput) ||
            round((float)$instInput, 2) <= 0 ||
            round((float)$delivInput, 2) <= 0
        ) {
            header('Location: foreman.php?tab=site_inspection&msg=invalid_charges');
            exit;
        }
        
        $inst = round((float)$instInput, 2);
        $deliv = round((float)$delivInput, 2);
        $other = 0;
        // REVISION: Reject invalid discounts instead of silently changing them.
                $discountInput = $_POST['q_discount_percent'] ?? null;
                
                if (
                    !is_scalar($discountInput) ||
                    !is_numeric($discountInput) ||
                    !is_finite((float)$discountInput) ||
                    (float)$discountInput < 0 ||
                    (float)$discountInput > 100
                ) {
                    header('Location: foreman.php?tab=site_inspection&msg=invalid_discount');
                    exit;
                }
                
                $disc_percent = (float)$discountInput;

        $charges_before_discount = $inst + $deliv;
        $pre_discount_total      = $material_subtotal + $charges_before_discount;
        $discount_amount         = round($pre_discount_total * ($disc_percent / 100), 2);
        $grand_total             = round($pre_discount_total - $discount_amount, 2);
        $additional              = round($grand_total - $material_subtotal, 2);

        $down_payment = round($grand_total * ($down_payment_rate / 100), 2);
        $balance      = max(0, round($grand_total - $down_payment, 2));

        $newStatus = $existingQuotation['status'];
        if (!empty($_POST['q_request_admin'])) {
            $newStatus = 'Waiting for Admin Confirmation';
        }

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                UPDATE quotations SET
                    material_subtotal           = :ms,
                    hardware_attachments        = 0,
                    installation_charge         = :inst,
                    delivery_charges            = :del,
                    insulation_charge           = 0,
                    other_charges               = :oth,
                    discount                    = :disc,
                    additional_charges_subtotal = :add,
                    grand_total                 = :gt,
                    down_payment                = :dp,
                    balance                     = :bal,
                    status                      = :status
                WHERE id = :id
            ");
            $stmt->execute([
                ':ms'     => $material_subtotal,
                ':inst'   => $inst,
                ':del'    => $deliv,
                ':oth'    => $other,
                ':disc'   => $disc_percent,
                ':add'    => $additional,
                ':gt'     => $grand_total,
                ':dp'     => $down_payment,
                ':bal'    => $balance,
                ':status' => $newStatus,
                ':id'     => $editing_quotation_id,
            ]);

            $pdo->prepare("DELETE FROM quotation_materials WHERE quotation_id = ?")
                ->execute([$editing_quotation_id]);

            if (!empty($quotationMaterialsArr)) {
                $ins = $pdo->prepare("
                    INSERT INTO quotation_materials
                        (quotation_id, material_id, material_type, item_product,
                         specification, unit, quantity, unit_price, amount)
                    VALUES (:qid, :mid, :mt, :ip, :sp, :u, :qty, :up, :amt)
                ");
                foreach ($quotationMaterialsArr as $r) {
                    $ins->execute([
                        ':qid' => $editing_quotation_id,
                        ':mid' => $r['material_id'],
                        ':mt'  => $r['material_type'],
                        ':ip'  => $r['item_product'],
                        ':sp'  => $r['specification'],
                        ':u'   => $r['unit'],
                        ':qty' => $r['quantity'],
                        ':up'  => $r['unit_price'],
                        ':amt' => $r['amount'],
                    ]);
                }
            }

            $pdo->commit();

            if ($newStatus === 'Waiting for Admin Confirmation' &&
                $existingQuotation['status'] !== 'Waiting for Admin Confirmation') {
                $admins = $pdo->query("SELECT id FROM users WHERE role = 'admin' AND status = 'active'")
                              ->fetchAll(PDO::FETCH_COLUMN);
                if ($admins) {
                    $msg = "Foreman {$_SESSION['full_name']} submitted quotation {$existingQuotation['quotation_id']} for review.";
                    $notif = $pdo->prepare("INSERT INTO notifications (user_id, project_id, message) VALUES (?, NULL, ?)");
                    foreach ($admins as $aid) $notif->execute([$aid, $msg]);
                }
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log("Foreman quotation save failed: " . $e->getMessage());
            header('Location: foreman.php?tab=site_inspection&msg=error');
            exit;
        }

        header('Location: foreman.php?tab=site_inspection&msg=updated');
        exit;
    }

    // Neither flag was set — shouldn't happen, but fail safe.
    header('Location: foreman.php?tab=site_inspection&msg=error');
    exit;
}

// Mark as completed
if (isset($_POST['complete'])) {
    if (!isset($_POST['csrf_token']) || !csrf_validate($_POST['csrf_token'])) {
        die('CSRF token validation failed.');
    }
    $id = (int)$_POST['complete'];

        $stmt = $pdo->prepare("SELECT * FROM site_inspections WHERE id = ? AND inspector_id = ? 
            LIMIT 1");
    $stmt->execute([$id, $foreman_id]);
    $ins = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$ins) {
        header('Location: foreman.php?tab=site_inspection&msg=not_allowed');
        exit;
    }

    // REVISION: Validate saved inspection data before the separate Complete action.
        $requiredFields = [
        'existing_roof_condition',
        'roof_type',
        'product_category',
        'roofing_category',
        'roofing_product',
        'site_accessibility',
        'site_preparation',
    ];

    foreach ($requiredFields as $field) {
        if (trim((string)($ins[$field] ?? '')) === '') {
            header('Location: foreman.php?tab=site_inspection&msg=incomplete_inspection');
            exit;
        }
    }

    $stmt = $pdo->prepare("
        SELECT component, length
        FROM site_inspection_measurements
        WHERE inspection_id = ?
    ");
    $stmt->execute([$id]);
    $savedMeasurements = [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $savedMeasurements[strtolower(trim($row['component']))]
            = (float)$row['length'];
    }

    $length = $savedMeasurements['roof length'] ?? 0;
    $width  = $savedMeasurements['roof width'] ?? 0;
    $rise   = $savedMeasurements['rise'] ?? 0;
    $isFlat = $ins['roofing_category'] === 'Flat Roof';

    if (
        $length <= 0 ||
        $width <= 0 ||
        (!$isFlat && $rise <= 0) ||
        (float)($ins['roof_area'] ?? 0) <= 0 ||
        (int)($ins['labor_requirement'] ?? 0) < 1
    ) {
        header('Location: foreman.php?tab=site_inspection&msg=incomplete_inspection');
        exit;
    }


    $stmt = $pdo->prepare("UPDATE site_inspections SET status = 'Completed' WHERE id = ? AND inspector_id = ?");
    $stmt->execute([$id, $foreman_id]);

    if ($ins['status'] !== 'Completed') {
        createQuotationFromInspection($pdo, $id);
    }

    header('Location: foreman.php?tab=site_inspection&msg=status_updated');
    exit;
}

// Add an additional quotation to an inspection
if (isset($_POST['add_quotation'])) {
    if (!isset($_POST['csrf_token']) || !csrf_validate($_POST['csrf_token'])) {
        die('CSRF token validation failed.');
    }

    $inspection_id = (int)($_POST['inspection_id'] ?? 0);

    // Ownership check
    $stmt = $pdo->prepare("
        SELECT id FROM site_inspections
        WHERE id = ? AND inspector_id = ?
        LIMIT 1
    ");
    $stmt->execute([$inspection_id, $foreman_id]);
    if (!$stmt->fetch()) {
        header('Location: foreman.php?tab=site_inspection&msg=not_allowed');
        exit;
    }

    try {
        createAdditionalQuotationForInspection($pdo, $inspection_id);
        header('Location: foreman.php?tab=site_inspection&msg=quotation_added');
        exit;
    } catch (Throwable $e) {
        error_log("Add quotation failed for inspection {$inspection_id}: " . $e->getMessage());
        header('Location: foreman.php?tab=site_inspection&msg=error');
        exit;
    }
}



// -----------------------------------------------------------------------------
// Fetch inspections assigned to this foreman
// -----------------------------------------------------------------------------
$status_filter = $_GET['status_filter'] ?? 'all';
$allowed_status = ['Scheduled', 'Completed', 'all'];
if (!in_array($status_filter, $allowed_status, true)) $status_filter = 'all';

$sql = "SELECT si.*, u.full_name AS client_display_name
        FROM site_inspections si
        LEFT JOIN users u ON si.client_id = u.id
        WHERE si.inspector_id = :foreman_id";
$params = [':foreman_id' => $foreman_id];
if ($status_filter !== 'all') {
    $sql .= " AND si.status = :status";
    $params[':status'] = $status_filter;
}
$sql .= " ORDER BY si.created_at DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$inspections = $stmt->fetchAll(PDO::FETCH_ASSOC);

$counts = ['Scheduled' => 0, 'Completed' => 0];
$stmt = $pdo->prepare("SELECT status, COUNT(*) AS cnt FROM site_inspections WHERE inspector_id = ? GROUP BY status");
$stmt->execute([$foreman_id]);
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    if (isset($counts[$row['status']])) $counts[$row['status']] = (int)$row['cnt'];
}

// Load measurements for every displayed inspection.
$measurementsByInspection = [];
if (!empty($inspections)) {
    $ids = array_column($inspections, 'id');
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT * FROM site_inspection_measurements WHERE inspection_id IN ($ph) ORDER BY id ASC");
    $stmt->execute($ids);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $measurementsByInspection[(int)$row['inspection_id']][] = $row;
    }
}

// Quotations + their materials, keyed for the Edit modal.
$quotationByInspection     = [];
$quotationMaterialsByQuote = [];
if (!empty($inspections)) {
    $ids = array_column($inspections, 'id');
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT q.* FROM quotations q WHERE q.inspection_id IN ($ph)");
    $stmt->execute($ids);
    while ($q = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $quotationByInspection[(int)$q['inspection_id']] = $q;
    }
    if (!empty($quotationByInspection)) {
        $qids = array_column($quotationByInspection, 'id');
        $qph  = implode(',', array_fill(0, count($qids), '?'));
        $stmt = $pdo->prepare("SELECT * FROM quotation_materials WHERE quotation_id IN ($qph) ORDER BY id ASC");
        $stmt->execute($qids);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $quotationMaterialsByQuote[(int)$row['quotation_id']][] = $row;
        }
    }
}

$attachmentCounts = getInspectionAttachmentCounts($pdo, array_column($inspections, 'id'));

// Messages
$msg = '';
$msg_error = false;
if (isset($_GET['msg'])) {
    $msgs = [
        // REVISION: Explain why an invalid quantity was rejected.
        'invalid_quantity' => [
            'text' => 'Quotation not saved. Every selected material requires a quantity of at least 0.01.',
            'type' => 'error'
        ],
        'invalid_charges' => [
        'text' => 'Quotation not saved. Installation Fee and Delivery Charges are required and must both be greater than zero.',
        'type' => 'error'
    ],
        // REVISION: Explain why the quotation was not saved.
        'invalid_discount' => [
            'text' => 'Quotation not saved. Discount must be between 0 and 100%.',
            'type' => 'error'
        ],
        // REVISION: Explain validation failures and completed-record protection.
        'incomplete_inspection' => ['text' => 'Complete and save all required inspection details and valid measurements before marking Completed.', 'type' => 'error'],
        'inspection_locked' => ['text' => 'This inspection is completed. Inspection details are locked.', 'type' => 'error'],
        'updated'              => ['text' => 'Inspection updated successfully.', 'type' => ''],
        'status_updated'       => ['text' => 'Status updated successfully.', 'type' => ''],
        'error'                => ['text' => 'An error occurred.', 'type' => 'error'],
        'not_allowed'          => ['text' => 'You are not allowed to modify that inspection.', 'type' => 'error'],
        'invalid_labor'        => ['text' => 'Labor Requirement must be a positive integer of at least 1.', 'type' => 'error'],
        'invalid_measurements' => ['text' => 'Please provide valid Roof Length, Roof Width, and Rise values for the selected roof type.', 'type' => 'error'],
    ];
    if (isset($msgs[$_GET['msg']])) {
        $msg = $msgs[$_GET['msg']]['text'];
        $msg_error = ($msgs[$_GET['msg']]['type'] === 'error');
    }
}

// Config option lists.
$conditions     = ['new construction','good condition','fair condition','partially damage','severely damage'];
$thicknesses    = ['0.4mm','0.45mm','0.5mm','0.55mm','0.6mm','0.7mm','0.8mm'];
$access_options = ['Good','Fair','Difficult','Requires Scaffolding'];
?>

<?php if ($msg): ?>
    <div class="alert <?= $msg_error ? 'alert-danger' : 'alert-success' ?> d-flex align-items-center">
        <i class="bi <?= $msg_error ? 'bi-exclamation-triangle' : 'bi-check-circle' ?> me-2"></i>
        <?= htmlspecialchars($msg) ?>
    </div>
<?php endif; ?>

<!-- Header + Filter -->
<div class="d-flex justify-content-between align-items-end flex-wrap gap-3 mb-4">
    <div>
        <h2 class="fw-bold mb-1" style="font-size: 20px;">Site Inspections</h2>
        <p class="mb-0" style="color: var(--fr-muted); font-size: 13.5px;">
            Inspections assigned to you. Update field data as the inspection progresses.
        </p>
    </div>
    <form method="GET" action="foreman.php" class="d-flex align-items-center gap-2">
        <input type="hidden" name="tab" value="site_inspection">
        <label for="status_filter" class="form-label mb-0" style="font-size: 12px;">Status</label>
        <select name="status_filter" id="status_filter" class="form-select form-select-sm" style="width: auto;" onchange="this.form.submit()">
            <option value="all"                <?= $status_filter === 'all'                ? 'selected' : '' ?>>All</option>
            <option value="Scheduled"          <?= $status_filter === 'Scheduled'          ? 'selected' : '' ?>>Scheduled</option>
            <option value="Completed"          <?= $status_filter === 'Completed'          ? 'selected' : '' ?>>Completed</option>
        </select>
    </form>
</div>

<!-- Stats -->
<div class="row g-3 mb-4">
    <div class="col-md-6 col-6"><div class="fr-stat"><div class="fr-stat-label">Scheduled</div><div class="fr-stat-value" style="color:#b45309;"><?= $counts['Scheduled'] ?></div></div></div>
    <div class="col-md-6 col-6"><div class="fr-stat"><div class="fr-stat-label">Completed</div><div class="fr-stat-value" style="color:#047857;"><?= $counts['Completed'] ?></div></div></div>
</div>

<!-- Table -->
<div class="fr-table-wrap">
    <table class="fr-table">
        <thead>
            <tr>
                <th>Inspection ID</th><th>Client</th><th>Date</th><th>Location</th>
                <th>Roof Area</th><th>Status</th><th style="text-align: center;">Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($inspections)): ?>
            <tr>
                <td colspan="7" class="text-center py-5" style="color: var(--fr-muted);">
                    <i class="bi bi-clipboard-x" style="font-size: 28px; opacity: .4; display: block; margin-bottom: 8px;"></i>
                    No site inspections are currently assigned to you.
                </td>
            </tr>
        <?php else: ?>
            <?php foreach ($inspections as $ins):
                $status_key = $ins['status'] === 'Completed' ? 'completed'
                            : ($ins['status'] === 'Requires Follow-up' ? 'followup' : 'scheduled');
            ?>
                <tr>
                    <td class="fw-semibold"><?= htmlspecialchars($ins['inspection_id']) ?></td>
                    <td><?= htmlspecialchars($ins['client_name']) ?></td>
                    <td class="fw-num"><?= htmlspecialchars(date('M d, Y', strtotime($ins['inspection_date']))) ?></td>
                    <td><?= htmlspecialchars($ins['location']) ?></td>
                    <td class="fw-num">
                        <?= $ins['roof_area'] !== null ? number_format((float)$ins['roof_area'], 2) . ' sqm' : '<span style="color: var(--fr-muted);">—</span>' ?>
                    </td>
                    <td><span class="fr-badge <?= $status_key ?>"><?= htmlspecialchars($ins['status']) ?></span></td>
                    <td class="text-end">
                        <div class="d-flex gap-1 justify-content-end flex-wrap">
                            <button type="button"
                                    class="btn-action btn-action-info inspection-details-btn"
                                    data-client-name="<?= htmlspecialchars($ins['client_name']) ?>"
                                    data-inspection-date="<?= htmlspecialchars(date('F d, Y', strtotime($ins['inspection_date']))) ?>"
                                    data-location="<?= htmlspecialchars($ins['location']) ?>"
                                    data-remarks="<?= htmlspecialchars($ins['remarks'] ?? '') ?>"
                                    title="View inspection details">
                                <i class="bi bi-eye"></i><span></span>
                            </button>

                            <button type="button"
                                    class="btn-action btn-action-photo inspection-photos-btn"
                                    data-inspection-id="<?= (int)$ins['id'] ?>"
                                    data-inspection-code="<?= htmlspecialchars($ins['inspection_id']) ?>"
                                    title="Manage photos">
                                <i class="bi bi-image"></i>
                                <span><?= (int)($attachmentCounts[(int)$ins['id']] ?? 0) ?></span>
                            </button>

                            <button type="button"
                                    class="btn-action btn-action-edit"
                                    onclick="document.getElementById('fiModal<?= (int)$ins['id'] ?>').style.display='block';">
                                <i class="bi bi-pencil"></i><span>Manage</span>
                            </button>

                            <?php if ($ins['status'] !== 'Completed'): ?>
                                <form method="POST" action="foreman.php?tab=site_inspection" class="d-inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="complete" value="<?= (int)$ins['id'] ?>">
                                    <button type="submit" class="btn-action btn-action-complete"
                                            onclick="return confirm('Mark this inspection as completed? This will generate a quotation for the client.');">
                                        <i class="bi bi-check2"></i><span>Complete</span>
                                    </button>
                                </form>
                            <?php else: ?>
                                <span class="btn-action btn-action-complete is-done" aria-disabled="true"
                                      title="This inspection is already completed.">
                                    <i class="bi bi-check2-all"></i><span>Completed</span>
                                </span>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Edit Modals -->
<?php foreach ($inspections as $ins):
    // Extract the currently saved Roof Length / Width / Rise (if any).
    $existingMeasurements = $measurementsByInspection[(int)$ins['id']] ?? [];
    $savedLength = ''; $savedWidth = ''; $savedRise = '';
    foreach ($existingMeasurements as $m) {
        $comp = strtolower(trim($m['component'] ?? ''));
        if ($comp === 'roof length')      $savedLength = number_format((float)$m['length'], 2, '.', '');
        elseif ($comp === 'roof width')   $savedWidth  = number_format((float)$m['length'], 2, '.', '');
        elseif ($comp === 'rise')         $savedRise   = number_format((float)$m['length'], 2, '.', '');
    }
?>
<div id="fiModal<?= (int)$ins['id'] ?>" class="fi-modal" style="display:none; position:fixed; inset:0; z-index:1055; background: rgba(17,24,39,.5); padding: 20px; overflow-y: auto;">
    <div class="mx-auto my-3" style="max-width: 820px; background: #fff; border-radius: 14px; box-shadow: 0 20px 60px rgba(0,0,0,.2); padding: 26px; position: relative;">

        <button type="button" class="btn-close position-absolute" style="top: 16px; right: 18px;"
                onclick="document.getElementById('fiModal<?= (int)$ins['id'] ?>').style.display='none';"></button>

        <h2 class="fw-bold mb-1" style="font-size: 18px;">
            Edit Inspection — <?= htmlspecialchars($ins['inspection_id']) ?>
        </h2>
        <p style="color: var(--fr-muted); font-size: 13px; margin-bottom: 20px;">
            <?= htmlspecialchars($ins['client_name']) ?>
            &nbsp;·&nbsp; <?= htmlspecialchars(date('M d, Y', strtotime($ins['inspection_date']))) ?>
            &nbsp;·&nbsp; <?= htmlspecialchars($ins['location']) ?>
        </p>
        
        <?php
        // Fetch every quotation for this inspection.
        $insQuotations = getQuotationsForInspection($pdo, (int)$ins['id']);
        $hasAnyQuotation = !empty($insQuotations);
        ?>

        <form method="POST" action="foreman.php?tab=site_inspection" class="mb-4">
            <?= csrf_field() ?>
            <input type="hidden" name="edit_id" value="<?= (int)$ins['id'] ?>">
            <input type="hidden" name="save_inspection_fields" value="1">
            <!-- REVISION: Lock inspection fields only; quotation section remains outside. -->
            <fieldset <?= strtolower(trim((string)$ins['status'])) === 'completed' ? 'disabled' : '' ?>
            style="border:0; padding:0; margin:0; min-width:0;">

            <h3 class="fr-section-title mt-4">Inspection Details</h3>
            <div class="row g-3 mb-2">
                <div class="col-md-6">
                    <label class="form-label">Existing Roof Condition</label>
                    <select name="existing_roof_condition" class="form-select" required>
                        <?php foreach ($conditions as $c): ?>
                            <option value="<?= htmlspecialchars($c) ?>" <?= $ins['existing_roof_condition'] === $c ? 'selected' : '' ?>>
                                <?= htmlspecialchars(ucfirst($c)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Service Type</label>
                    <select name="roof_type" class="form-select" required>
                        <?php
                        $serviceOptionsForModal = $roof_types;
                        if (!empty($ins['roof_type']) && !in_array($ins['roof_type'], $serviceOptionsForModal, true)) {
                            $serviceOptionsForModal[] = $ins['roof_type'];
                        }
                        foreach ($serviceOptionsForModal as $rt): ?>
                            <option value="<?= htmlspecialchars($rt) ?>" <?= $ins['roof_type'] === $rt ? 'selected' : '' ?>>
                                <?= htmlspecialchars(ucfirst($rt)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <h3 class="fr-section-title mt-4">Roofing Specification</h3>
            <div class="row g-3 mb-2">
                <div class="col-md-4">
                    <label class="form-label">Product Category</label>
                    <select name="product_category" class="form-select" required>
                        <?php
                        $cats = ['ribbed roofing','tile-profile roofing','standing seam roofing','specialty roofing','ceilings'];
                        if (!empty($ins['product_category']) && !in_array($ins['product_category'], $cats, true)) $cats[] = $ins['product_category'];
                        foreach ($cats as $cat): ?>
                            <option value="<?= htmlspecialchars($cat) ?>" <?= $cat === $ins['product_category'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars(ucfirst($cat)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Roof Type</label>
                    <select name="roofing_category" class="form-select" required
                            onchange="onRoofTypeChange(this)">
                        <option value="">— Select —</option>
                        <?php
                        $shapeOptionsForModal = $roof_shapes;
                        if (!empty($ins['roofing_category']) && !in_array($ins['roofing_category'], $shapeOptionsForModal, true)) {
                            $shapeOptionsForModal[] = $ins['roofing_category'];
                        }
                        foreach ($shapeOptionsForModal as $shape): ?>
                            <option value="<?= htmlspecialchars($shape) ?>" <?= $ins['roofing_category'] === $shape ? 'selected' : '' ?>>
                                <?= htmlspecialchars($shape) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Roofing Product / Profile</label>
                    <select name="roofing_product" class="form-select" required
                            data-current-product="<?= htmlspecialchars($ins['roofing_product'] ?? '') ?>"></select>
                </div>
            </div>

            <!-- =====================================================
                 ESTIMATED MEASUREMENTS
                 Inputs: Length, Width, Rise (Rise hidden for Flat).
                 Outputs: Ridge, Eave, Hip Rafter, Area.
                 ===================================================== -->
            <h3 class="fr-section-title mt-4">Estimated Measurements</h3>
            <p style="color: var(--fr-muted); font-size: 12.5px;">
                Enter the Roof Length, Roof Width, and Rise (Rise not required for Flat). Other values are calculated automatically.
            </p>

            <div class="rounded-3 p-3 mb-2" style="background: var(--fr-bg); border: 1px solid var(--fr-border);">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Roof Length (m)</label>
                        <input type="text" inputmode="decimal" data-numeric="1" name="rm_length" class="form-control rm-input" required
                               value="<?= htmlspecialchars($savedLength) ?>"
                               oninput="updateRequiredMeasurements(this)">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Roof Width (m)</label>
                        <input type="text" inputmode="decimal" data-numeric="1" name="rm_width" class="form-control rm-input" required
                               value="<?= htmlspecialchars($savedWidth) ?>"
                               oninput="updateRequiredMeasurements(this)">
                    </div>
                    <div class="col-md-4 rm-field-rise">
                        <label class="form-label">Rise (m)</label>
                        <input type="text" inputmode="decimal" data-numeric="1" name="rm_rise" class="form-control rm-input"
                               value="<?= htmlspecialchars($savedRise) ?>"
                               oninput="updateRequiredMeasurements(this)">
                    </div>
                </div>

                <div class="row g-3 mt-2 pt-3" style="border-top: 1px dashed var(--fr-border);">
                    <div class="col-md-4 rm-field-ridge">
                        <label class="form-label">Ridge (m)</label>
                        <input type="text" name="rm_ridge" class="form-control rm-output" readonly
                               style="background: #fff; color: var(--fr-muted);">
                    </div>
                    <div class="col-md-4 rm-field-eave">
                        <label class="form-label rm-eave-label">Eave (m)</label>
                        <input type="text" name="rm_eave" class="form-control rm-output" readonly
                               style="background: #fff; color: var(--fr-muted);">
                    </div>
                    <div class="col-md-4 rm-field-hip-rafter">
                        <label class="form-label">Hip Rafter Length (m)</label>
                        <input type="text" name="rm_hip_rafter" class="form-control rm-output" readonly
                               style="background: #fff; color: var(--fr-muted);">
                    </div>
                    <div class="col-md-4 rm-field-area">
                        <label class="form-label">Roof Area (m²)</label>
                        <input type="text" name="rm_area" class="form-control rm-output" readonly
                               style="background: #fff; font-weight: 700; color: var(--fr-red);">
                    </div>
                </div>
            </div>

            <div class="row g-3 mb-2">
                <div class="col-md-6">
                     <!-- REVISION: Hide redundant Thickness input; preserve existing stored value. -->
                     <input type="hidden" name="thickness"
                       value="<?= htmlspecialchars($ins['thickness'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                <div class="col-md-6">
                    <label class="form-label">Labor Requirement</label>
                    <input type="text" inputmode="numeric" data-numeric="1" name="labor_requirement" class="form-control"
                           value="<?= htmlspecialchars($ins['labor_requirement'] ?? 1) ?>" required>
                    <div class="form-text">Minimum 1 worker.</div>
                </div>
            </div>

            <h3 class="fr-section-title mt-4">Site Assessment</h3>
            <div class="row g-3 mb-2">
                <div class="col-md-6">
                    <label class="form-label">Site Accessibility</label>
                    <select name="site_accessibility" class="form-select" required>
                        <option value="">— Select —</option>
                        <?php foreach ($access_options as $a): ?>
                            <option value="<?= htmlspecialchars($a) ?>" <?= $ins['site_accessibility'] === $a ? 'selected' : '' ?>><?= htmlspecialchars($a) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Site Preparation</label>
                    <select name="site_preparation" class="form-select" required>
                        <option value="">— Select —</option>
                        <option value="Ready" <?= $ins['site_preparation'] === 'Ready' ? 'selected' : '' ?>>Ready</option>
                        <option value="Needs Preparation" <?= $ins['site_preparation'] === 'Needs Preparation' ? 'selected' : '' ?>>Needs Preparation</option>
                    </select>
                </div>
            </div>
            
            <h3 class="fr-section-title mt-4">Status</h3>
            <div class="row g-3 mb-2">
                <div class="col-12">
                    <select name="status" class="form-select" required>
                        <option value="Scheduled"          <?= $ins['status'] === 'Scheduled'          ? 'selected' : '' ?>>Scheduled</option>
                        <option value="Completed"          <?= $ins['status'] === 'Completed'          ? 'selected' : '' ?>>Completed</option>
                        <option value="Requires Follow-up" <?= $ins['status'] === 'Requires Follow-up' ? 'selected' : '' ?>>Requires Follow-up</option>
                    </select>
                    <div class="form-text">Marking as Completed will generate a quotation.</div>
                </div>
            </div>

            <div class="d-flex justify-content-end gap-2 mt-4 pt-3" style="border-top: 1px solid var(--fr-border);">
                <button type="submit" name="edit_inspection" class="btn btn-primary">
                    Save Inspection Details
                </button>
            </div>
            </fieldset>
        </form>

        <!-- ============================================================
             QUOTATION SECTION (collapsible, AJAX-refreshable)
             ============================================================ -->
        <?= renderForemanQuotationSection($pdo, $ins) ?>
    </div>
</div>
<?php endforeach; ?>

<!-- Inspection Details Modal (shared, read-only) -->
<div class="modal fade" id="inspectionDetailsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title mb-0">Inspection Details</h5>
                    <div class="text-muted small">As provided by the admin</div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <div class="text-uppercase" style="font-size: 10px; font-weight: 700; letter-spacing: .5px; color: var(--fr-muted);">Client Name</div>
                    <div class="inspection-details-client" style="font-weight: 600;"></div>
                </div>
                <div class="mb-3">
                    <div class="text-uppercase" style="font-size: 10px; font-weight: 700; letter-spacing: .5px; color: var(--fr-muted);">Inspection Date</div>
                    <div class="inspection-details-date" style="font-weight: 600;"></div>
                </div>
                <div class="mb-3">
                    <div class="text-uppercase" style="font-size: 10px; font-weight: 700; letter-spacing: .5px; color: var(--fr-muted);">Location</div>
                    <div class="inspection-details-location" style="font-weight: 600; white-space: pre-line;"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Inspection photos modal -->
<div class="modal fade" id="inspectionPhotoModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title mb-0">Inspection Photos</h5>
                    <div class="inspection-photo-desc text-muted small"></div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="inspection-photo-empty text-center text-muted py-5 d-none">
                    <i class="bi bi-images" style="font-size: 36px; opacity: .4; display: block; margin-bottom: 8px;"></i>
                    No photos attached yet.
                </div>

                <div class="inspection-photo-stage position-relative rounded-3 mb-3 d-none" style="background:#111; overflow:hidden;">
                    <img class="inspection-photo-main" src="" alt="" style="width:100%; display:block; max-height:65vh; object-fit:contain; background:#111;">
                    <button type="button" class="inspection-photo-prev btn btn-dark position-absolute top-50 start-0 translate-middle-y ms-2" aria-label="Previous">
                        <i class="bi bi-chevron-left"></i>
                    </button>
                    <button type="button" class="inspection-photo-next btn btn-dark position-absolute top-50 end-0 translate-middle-y me-2" aria-label="Next">
                        <i class="bi bi-chevron-right"></i>
                    </button>
                    <div class="inspection-photo-meta position-absolute bottom-0 start-0 end-0 p-2 small text-white-50" style="background: linear-gradient(to top, rgba(0,0,0,.7), transparent);"></div>
                </div>

                <div class="inspection-photo-strip d-flex gap-2 overflow-auto d-none" style="padding-bottom: 4px;"></div>
            </div>
            <div class="modal-footer justify-content-between flex-wrap gap-2">
                <div class="d-flex gap-2 flex-wrap">
                    <input type="file" class="d-none inspection-photo-file-input" accept="image/jpeg,image/png,image/gif,image/webp,image/bmp">
                    <button type="button" class="btn btn-primary inspection-photo-upload-btn">
                        <i class="bi bi-upload me-1"></i>Add Photo
                    </button>
                    <button type="button" class="btn btn-outline-danger inspection-photo-delete-btn" disabled>
                        <i class="bi bi-trash me-1"></i>Remove Current
                    </button>
                </div>
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    if (window.__fiProductMap) return;
    window.__fiProductMap = true;

    const productByCategory = {
        'tile-profile roofing': ['Banawe','Hacienda (Royal Mediterranean Series)','Valencia (Royal Mediterranean Series)','Tilespan Elite','Tilespan Maxi','Satori Tile'],
        'ribbed roofing':       ['Twin Rib','EPR','R-Span','Multi R-Span','Corspan','Mega 2A Rib','Big V'],
        'specialty roofing':    ['Retrofitter','Curve Roof','Steel Decking'],
        'ceilings':             ['Spandrel'],
        'standing seam roofing':['Super R-Span','Standing S-Span','S-Rib Standing Seam']
    };
    const legacyCategoryMap = { 'curved roofing': ['Curve Roof'], 'steel decking': ['Steel Decking'] };

    function populateProductDropdown(selectElement, categoryValue, selectedProduct) {
        selectElement.innerHTML = '';
        const products = productByCategory[categoryValue] || legacyCategoryMap[categoryValue] || [];
        let matched = false;
        products.forEach(function (product) {
            const option = document.createElement('option');
            option.value = product;
            option.textContent = product;
            if (selectedProduct && product.toLowerCase() === String(selectedProduct).toLowerCase()) {
                option.selected = true; matched = true;
            }
            selectElement.appendChild(option);
        });
        if (selectedProduct && !matched) {
            const option = document.createElement('option');
            option.value = selectedProduct;
            option.textContent = selectedProduct + ' (legacy)';
            option.selected = true;
            selectElement.insertBefore(option, selectElement.firstChild);
        }
        if (products.length === 0 && !selectedProduct) {
            const option = document.createElement('option');
            option.value = '';
            option.textContent = 'No products available for this category';
            option.disabled = true; option.selected = true;
            selectElement.appendChild(option);
        }
    }

    function setupCategoryProductPair(categorySelect, productSelect, initialProduct) {
        populateProductDropdown(productSelect, categorySelect.value, initialProduct);
        categorySelect.addEventListener('change', function () {
            populateProductDropdown(productSelect, this.value, null);
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.fi-modal').forEach(function (modal) {
            const category = modal.querySelector('select[name="product_category"]');
            const product  = modal.querySelector('select[name="roofing_product"]');
            if (!category || !product) return;
            const currentProduct = product.dataset.currentProduct || '';
            setupCategoryProductPair(category, product, currentProduct);
        });
    });
})();


/* =========================================================
   REQUIRED MEASUREMENTS — roof-type aware calculator
   ========================================================= */

function onRoofTypeChange(selectEl) {
    const modal = selectEl.closest('.fi-modal');
    if (!modal) return;
    updateMeasurementFieldsVisibility(modal);
    updateRequiredMeasurements(selectEl);
}

function updateMeasurementFieldsVisibility(modal) {
    const typeEl = modal.querySelector('select[name="roofing_category"]');
    if (!typeEl) return;
    const type = typeEl.value;

    const riseGroup       = modal.querySelector('.rm-field-rise');
    const ridgeGroup      = modal.querySelector('.rm-field-ridge');
    const hipRafterGroup  = modal.querySelector('.rm-field-hip-rafter');
    const eaveLabel       = modal.querySelector('.rm-eave-label');

    if (riseGroup)      riseGroup.style.display      = (type === 'Flat Roof') ? 'none' : '';
    if (ridgeGroup)     ridgeGroup.style.display     = (type === 'Gable Roof' || type === 'Hip Roof') ? '' : 'none';
    if (hipRafterGroup) hipRafterGroup.style.display = (type === 'Hip Roof') ? '' : 'none';
    if (eaveLabel)      eaveLabel.textContent        = (type === 'Flat Roof') ? 'Eave/Perimeter (m)' : 'Eave (m)';
}

function updateRequiredMeasurements(sourceEl) {
    const modal = sourceEl ? sourceEl.closest('.fi-modal') : null;
    if (!modal) return;

    const typeEl = modal.querySelector('select[name="roofing_category"]');
    if (!typeEl) return;
    const type = typeEl.value;

    const lenEl  = modal.querySelector('input[name="rm_length"]');
    const widEl  = modal.querySelector('input[name="rm_width"]');
    const riseEl = modal.querySelector('input[name="rm_rise"]');

    const L = parseFloat(lenEl  ? lenEl.value  : 0) || 0;
    const W = parseFloat(widEl  ? widEl.value  : 0) || 0;
    const H = parseFloat(riseEl ? riseEl.value : 0) || 0;

    let slopedLength = 0, ridge = 0, eave = 0, hipRafter = 0, area = 0;

    switch (type) {
        case 'Gable Roof':
            slopedLength = Math.sqrt(Math.pow(W / 2, 2) + Math.pow(H, 2));
            area         = 2 * L * slopedLength;
            ridge        = L;
            eave         = 2 * L;
            break;
        case 'Hip Roof':
            slopedLength = Math.sqrt(Math.pow(W / 2, 2) + Math.pow(H, 2));
            hipRafter    = Math.sqrt(Math.pow(H, 2) + (Math.pow(W, 2) / 2));
            area         = Math.max(0, (2 * L - W)) * slopedLength + W * hipRafter;
            ridge        = Math.max(0, L - W);
            eave         = 2 * (L + W);
            break;
        case 'Flat Roof':
            area         = L * W;
            eave         = 2 * (L + W);
            break;
        case 'Shed Roof':
            slopedLength = Math.sqrt(Math.pow(W, 2) + Math.pow(H, 2));
            area         = L * slopedLength;
            eave         = L;
            break;
    }

    const setOut = function (name, val) {
        const el = modal.querySelector('input[name="' + name + '"]');
        if (el) el.value = (val > 0) ? Number(val).toFixed(2) : '';
    };

    setOut('rm_ridge',      ridge);
    setOut('rm_eave',       eave);
    setOut('rm_hip_rafter', hipRafter);
    setOut('rm_area',       area);
}

document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.fi-modal').forEach(function (modal) {
        updateMeasurementFieldsVisibility(modal);
        updateRequiredMeasurements(modal.querySelector('input[name="rm_length"]') || modal);
    });
});


/* =========================================================
   Site inspection photo gallery
   ========================================================= */
document.addEventListener('DOMContentLoaded', function () {
    const modalEl = document.getElementById('inspectionPhotoModal');
    if (!modalEl) return;

    const modal = new bootstrap.Modal(modalEl);
    const csrfToken = <?= json_encode(csrf_token()) ?>;
    const endpoint  = 'foreman.php?ajax=inspection_attachment';

    const descEl    = modalEl.querySelector('.inspection-photo-desc');
    const emptyEl   = modalEl.querySelector('.inspection-photo-empty');
    const stageEl   = modalEl.querySelector('.inspection-photo-stage');
    const stripEl   = modalEl.querySelector('.inspection-photo-strip');
    const mainImg   = modalEl.querySelector('.inspection-photo-main');
    const metaEl    = modalEl.querySelector('.inspection-photo-meta');
    const prevBtn   = modalEl.querySelector('.inspection-photo-prev');
    const nextBtn   = modalEl.querySelector('.inspection-photo-next');
    const uploadBtn = modalEl.querySelector('.inspection-photo-upload-btn');
    const delBtn    = modalEl.querySelector('.inspection-photo-delete-btn');
    const fileInput = modalEl.querySelector('.inspection-photo-file-input');

    let currentId = 0;
    let index     = 0;
    let items     = [];

    function postAction(action, data) {
        const body = new FormData();
        body.append('csrf_token', csrfToken);
        body.append('action', action);
        Object.keys(data || {}).forEach(k => body.append(k, data[k]));
        return fetch(endpoint, {
            method: 'POST', body, credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(r => r.json());
    }

    function formatBytes(n) {
        if (!n) return '0 B';
        const u = ['B','KB','MB','GB']; let i = 0, v = n;
        while (v >= 1024 && i < u.length - 1) { v /= 1024; i++; }
        return v.toFixed(v >= 10 || i === 0 ? 0 : 1) + ' ' + u[i];
    }

    function updateCountBadge(id, count) {
        document.querySelectorAll('.inspection-photos-btn[data-inspection-id="' + id + '"] span')
            .forEach(el => { el.textContent = count; });
    }

    function render() {
        if (!items.length) {
            stageEl.classList.add('d-none'); stripEl.classList.add('d-none');
            emptyEl.classList.remove('d-none'); delBtn.disabled = true; return;
        }
        emptyEl.classList.add('d-none');
        stageEl.classList.remove('d-none'); stripEl.classList.remove('d-none');
        delBtn.disabled = false;
        if (index < 0) index = 0;
        if (index >= items.length) index = items.length - 1;
        const cur = items[index];
        mainImg.src = cur.url; mainImg.alt = cur.original_name;
        metaEl.textContent = (index + 1) + ' / ' + items.length + ' · ' + cur.original_name
            + (cur.uploaded_by_name ? ' · uploaded by ' + cur.uploaded_by_name : '')
            + ' · ' + formatBytes(cur.file_size);
        prevBtn.style.display = items.length > 1 ? '' : 'none';
        nextBtn.style.display = items.length > 1 ? '' : 'none';
        stripEl.innerHTML = '';
        items.forEach((a, i) => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'btn p-0 border rounded-2 flex-shrink-0';
            btn.style.width = '72px'; btn.style.height = '72px'; btn.style.overflow = 'hidden';
            if (i === index) { btn.classList.add('border-primary'); btn.style.borderWidth = '2px'; }
            btn.innerHTML = '<img src="' + a.url + '" alt="" style="width:100%;height:100%;object-fit:cover;display:block;">';
            btn.addEventListener('click', () => { index = i; render(); });
            stripEl.appendChild(btn);
        });
    }

    function openFor(inspectionId, inspectionCode) {
        currentId = inspectionId; index = 0; items = [];
        descEl.textContent = inspectionCode || '';
        render();
        modal.show();
        postAction('list', { inspection_id: inspectionId })
            .then(res => { if (!res.success) return;
                items = res.attachments || []; render(); updateCountBadge(currentId, items.length); })
            .catch(err => { console.error(err); alert('Unable to load photos.'); });
    }

    document.addEventListener('click', function (e) {
        const btn = e.target.closest('.inspection-photos-btn');
        if (!btn) return;
        openFor(parseInt(btn.dataset.inspectionId, 10), btn.dataset.inspectionCode || '');
    });

    prevBtn.addEventListener('click', () => { index--; render(); });
    nextBtn.addEventListener('click', () => { index++; render(); });

    document.addEventListener('keydown', function (e) {
        if (!modalEl.classList.contains('show')) return;
        if (e.key === 'ArrowLeft')  { index--; render(); }
        if (e.key === 'ArrowRight') { index++; render(); }
    });

    uploadBtn.addEventListener('click', () => fileInput.click());

    fileInput.addEventListener('change', function () {
        const file = this.files && this.files[0]; if (!file) return;
        const fd = new FormData();
        fd.append('csrf_token', csrfToken);
        fd.append('action', 'upload');
        fd.append('inspection_id', currentId);
        fd.append('image', file);
        uploadBtn.disabled = true;
        const prevLabel = uploadBtn.innerHTML;
        uploadBtn.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>Uploading…';
        fetch(endpoint, { method: 'POST', body: fd, credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(r => r.json())
            .then(res => {
                if (!res.success) { alert(res.message || 'Upload failed.'); return; }
                items.push(res.attachment); index = items.length - 1;
                render(); updateCountBadge(currentId, items.length);
            })
            .catch(err => { console.error(err); alert('Upload failed. Please try again.'); })
            .finally(() => { uploadBtn.disabled = false; uploadBtn.innerHTML = prevLabel; fileInput.value = ''; });
    });

    delBtn.addEventListener('click', function () {
        const cur = items[index]; if (!cur) return;
        if (!confirm('Remove this photo?')) return;
        delBtn.disabled = true;
        postAction('delete', { attachment_id: cur.id })
            .then(res => {
                if (!res.success) { alert(res.message || 'Unable to remove photo.'); return; }
                items.splice(index, 1);
                if (index >= items.length) index = Math.max(0, items.length - 1);
                render(); updateCountBadge(currentId, items.length);
            })
            .catch(err => { console.error(err); alert('Unable to remove photo.'); })
            .finally(() => { if (items.length) delBtn.disabled = false; });
    });
});

/* =============================================================
   Inspection Details Modal (read-only)
   ============================================================= */
document.addEventListener('DOMContentLoaded', function () {
    const modalEl = document.getElementById('inspectionDetailsModal');
    if (!modalEl) return;
    const modal = new bootstrap.Modal(modalEl);

    document.addEventListener('click', function (e) {
        const btn = e.target.closest('.inspection-details-btn');
        if (!btn) return;
        modalEl.querySelector('.inspection-details-client').textContent   = btn.dataset.clientName || '—';
        modalEl.querySelector('.inspection-details-date').textContent     = btn.dataset.inspectionDate || '—';
        modalEl.querySelector('.inspection-details-location').textContent = btn.dataset.location || '—';
        modal.show();
    });
});

/* =========================================================
   QUOTATION MATERIAL PICKER — Stage 1 (revised)
   ========================================================= */
(function () {
    'use strict';

    const PAGE_SIZE = 15;

    function fmt(n) {
        return '₱' + Number(n || 0).toLocaleString('en-PH', {
            minimumFractionDigits: 2, maximumFractionDigits: 2
        });
    }
    function escapeHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function makeSectionState(cfg, section) {
        const roofingProduct = cfg.roofingProduct || '';
        const st = {
            section:         section,
            catalog:         cfg.catalog || [],
            selected:        Object.assign({}, cfg.preselected || {}),
            filter:          roofingProduct || 'all',   // ← was 'all'
            search:          '',
            shown:           PAGE_SIZE,
            roofingProduct:  roofingProduct,
            categories:      cfg.categories || [],
            showAllProducts: false
        };
        return st;
    }

    function initPicker(form) {
        if (form.dataset.qpInit === '1') return;
        form.dataset.qpInit = '1';
        
        const cfgEl = form.querySelector('script.qp-config');
        if (!cfgEl) return;

        let cfg;
        try { cfg = JSON.parse(cfgEl.textContent); }
        catch (e) { console.error('Bad qp-config', e); return; }

        const state = {
            main:    makeSectionState(cfg.main || {},    'main'),
            general: makeSectionState(cfg.general || {}, 'general')
        };
        form.__qpState = state;

        ['main', 'general'].forEach(function (section) {
            const root = form.querySelector('.qp-split[data-section="' + section + '"]');
            if (!root) return;
            renderChips(root, state[section]);
            renderContextBar(root, state[section]);
            renderSelected(root, state[section]);
            renderAvailable(root, state[section]);
            bindSectionEvents(root, state[section], form);
        });

        const rpSelect = (form.closest('.fi-modal') || form)
        .querySelector('select[name="roofing_product"]');
        if (rpSelect) {
            rpSelect.addEventListener('change', function () {
                const newProduct = this.value || '';
                state.main.roofingProduct = newProduct;
                state.main.filter = newProduct || 'all';

            const chips = form.querySelector(
                '.qp-split[data-section="main"] .qp-chips'
            );
            
            if (chips) {
                chips.querySelectorAll('.qp-chip').forEach(function (chip) {
                    chip.classList.toggle(
                        'is-active',
                        chip.dataset.filter === state.main.filter
                    );
                });
            }
                state.main.shown = PAGE_SIZE;
                const root = form.querySelector('.qp-split[data-section="main"]');
                if (root) {
                    renderContextBar(root, state.main);
                    renderAvailable(root, state.main);
                }
            });
        }
        
        // ------------------------------------------------------------------
        // Template auto-loader
        //
        // Whenever Service Type, Product Category, Roof Type, or Roofing
        // Product changes, re-fetch the matching template from the server
        // and REPLACE the current quotation selections with it.
        //
        // Any manual qty / price tweaks the foreman made belong to the old
        // template, so they are intentionally discarded when one of these
        // four fields changes.
        // ------------------------------------------------------------------
        const modalRoot       = form.closest('.fi-modal') || form;
        const serviceSelect   = modalRoot.querySelector('select[name="roof_type"]');
        const categorySelect  = modalRoot.querySelector('select[name="product_category"]');
        const roofShapeSelect = modalRoot.querySelector('select[name="roofing_category"]');
        // rpSelect is already declared above.
        
        const reloadSuggested = function () {
            const serv = serviceSelect   ? serviceSelect.value.trim() : '';
            const roof = roofShapeSelect ? roofShapeSelect.value      : '';
            const prod = rpSelect        ? rpSelect.value             : '';
        
            // All three are required for a template lookup.
            if (!serv || !roof || !prod) return;
        
            const areaEl  = form.querySelector('input[name="rm_area"]');
            const ridgeEl = form.querySelector('input[name="rm_ridge"]');
            const eaveEl  = form.querySelector('input[name="rm_eave"]');
        
            const params = new URLSearchParams({
                ajax:             'get_material_template',
                service_type:     serv,
                roof_type:        roof,          // Gable Roof / Hip Roof / …
                roofing_product:  prod,          // Twin Rib / Banawe / …
                roof_area:        areaEl  ? areaEl.value  : '',
                ridge_length:     ridgeEl ? ridgeEl.value : '',
                eave_length:      eaveEl  ? eaveEl.value  : ''
            });
        
            fetch('foreman.php?tab=site_inspection&' + params.toString())
                .then(r => r.json())
                .then(res => {
                    if (!res.success || !Array.isArray(res.items)) return;
        
                    // Reset the picker's current selections and rebuild from
                    // the freshly fetched template.
                    state.main.selected    = {};
                    state.general.selected = {};
        
                    res.items.forEach(function (it) {
                        const mid = parseInt(it.material_id, 10);
                        const sec = (it.material_type === 'general') ? 'general' : 'main';
                        state[sec].selected[mid] = {
                            qty:   parseFloat(it.suggested_qty) || 1,
                            price: parseFloat(it.unit_price)    || 0
                        };
                    });
        
                    ['main', 'general'].forEach(function (s) {
                        const root = form.querySelector('.qp-split[data-section="' + s + '"]');
                        if (root) {
                            renderSelected(root, state[s]);
                            renderAvailable(root, state[s]);
                        }
                    });
        
                    recalcTotals(form);
                });
        };
        
        if (serviceSelect)   serviceSelect.addEventListener('change', reloadSuggested);
        if (categorySelect)  categorySelect.addEventListener('change', reloadSuggested);
        if (roofShapeSelect) roofShapeSelect.addEventListener('change', reloadSuggested);
        if (rpSelect)        rpSelect.addEventListener('change', reloadSuggested);
        recalcTotals(form);
    }

    function renderChips(root, state) {
        const chips = root.querySelector('.qp-chips');
        if (!chips) return;
        const isAll = state.filter === 'all';
        let html = '<button type="button" class="qp-chip'
                 + (isAll ? ' is-active' : '')
                 + '" data-filter="all">All</button>';
        state.categories.forEach(function (c) {
            html += '<button type="button" class="qp-chip'
                  + (state.filter === c ? ' is-active' : '')
                  + '" data-filter="' + escapeHtml(c) + '">'
                  + escapeHtml(c) + '</button>';
        });
        chips.innerHTML = html;
    }

    function renderContextBar(root, state) {
        const bar = root.querySelector('.qp-context-bar');
        if (!bar) return;
        const productEl = bar.querySelector('.qp-context-product');
        const toggle    = bar.querySelector('.qp-context-toggle');

        if (!state.roofingProduct) {
            productEl.textContent = 'all products';
            bar.classList.add('is-showing-all');
            toggle.style.display = 'none';
            return;
        }
        if (state.showAllProducts) {
            productEl.textContent = 'all products';
            bar.classList.add('is-showing-all');
            toggle.style.display = '';
            toggle.textContent = 'Filter to ' + state.roofingProduct;
        } else {
            productEl.textContent = state.roofingProduct;
            bar.classList.remove('is-showing-all');
            toggle.style.display = '';
            toggle.textContent = 'Show all products';
        }
    }

    function renderSelected(root, state) {
        const list  = root.querySelector('.qp-selected-list');
        const count = root.querySelector('.qp-count');
        const ids   = Object.keys(state.selected);
        if (count) count.textContent = ids.length;
        if (!ids.length) {
            list.innerHTML = '<div class="qp-empty">No materials selected yet.</div>';
            return;
        }
        list.innerHTML = ids.map(function (idStr) {
            const id = parseInt(idStr, 10);
            const mat = state.catalog.find(function (m) { return m.id === id; });
            if (!mat) return '';
            const sel    = state.selected[idStr];
            const amount = (sel.qty || 0) * (sel.price || 0);
            const meta   = [mat.specification, mat.unit].filter(Boolean).join(' · ');
            // Price is display-only; the value rides along in a hidden input.
            const priceText = (sel.price > 0) ? fmt(sel.price) : '—';
            return ''
                + '<div class="qp-selected-row" data-material-id="' + mat.id + '">'
                +   '<input type="hidden" name="q_material_type[]" value="' + state.section + '">'
                +   '<input type="hidden" name="q_material_id[]" value="' + mat.id + '">'
                +   '<input type="hidden" name="q_material_unit_price[]" value="' + (sel.price || 0) + '">'
                +   '<div class="qp-row-header">'
                +     '<strong>' + escapeHtml(mat.item_product) + '</strong>'
                +     '<button type="button" class="qp-row-remove" data-remove="' + mat.id + '" title="Remove">&times;</button>'
                +   '</div>'
                + '<div class="qp-row-inputs">'
                + '<label>Qty <input type="number" name="q_material_qty[]" data-numeric="1" min="0.01" step="0.01" required value="' + (sel.qty || 1) + '" data-field="qty"></label>'
                + '<label>Price <span class="qp-row-price">' + priceText + '</span></label>'
                + '<span class="qp-row-amount">' + fmt(amount) + '</span>'
                + '</div>'
                + '</div>';
        }).join('');
    }

    function renderAvailable(root, state) {
        const list     = root.querySelector('.qp-available-list');
        const showMore = root.querySelector('.qp-show-more');
        const needle = state.search.trim().toLowerCase();
        const filtered = state.catalog.filter(function (m) {
            if (state.filter !== 'all' && m.category !== state.filter) return false;
            if (!needle) return true;
            return (m.item_product || '').toLowerCase().indexOf(needle) !== -1
                || (m.specification || '').toLowerCase().indexOf(needle) !== -1;
        });
        if (!filtered.length) {
            let emptyMsg = 'No materials match your search.';
            if (!needle && state.filter !== 'all') {
                emptyMsg = 'No main component materials are configured for "' + state.filter + '".';
            }
            list.innerHTML = '<div class="qp-empty">' + escapeHtml(emptyMsg) + '</div>';
            if (showMore) showMore.style.display = 'none';
            return;
        }
        const visible = filtered.slice(0, state.shown);
        list.innerHTML = visible.map(function (m) {
            const isChecked = state.selected[m.id] != null;
            const meta      = [m.specification, m.unit].filter(Boolean).join(' · ');
            const priceStr  = m.price ? ' · ₱' + Number(m.price).toFixed(2) : '';
            return ''
                + '<label class="qp-available-row' + (isChecked ? ' is-checked' : '') + '" data-material-id="' + m.id + '">'
                +   '<input type="checkbox" class="qp-available-check"' + (isChecked ? ' checked' : '') + '>'
                +   '<span>'
                +     '<strong>' + escapeHtml(m.item_product) + '</strong>'
                +     '<small>' + escapeHtml(meta) + priceStr + '</small>'
                +   '</span>'
                + '</label>';
        }).join('');
        if (showMore) {
            if (filtered.length > state.shown) {
                showMore.style.display = 'block';
                showMore.textContent = 'Show ' + Math.min(PAGE_SIZE, filtered.length - state.shown)
                    + ' more… (' + (filtered.length - state.shown) + ' remaining)';
            } else {
                showMore.style.display = 'none';
            }
        }
    }

    function bindSectionEvents(root, state, form) {
        const search   = root.querySelector('.qp-search');
        const chips    = root.querySelector('.qp-chips');
        const list     = root.querySelector('.qp-available-list');
        const showMore = root.querySelector('.qp-show-more');
        const selList  = root.querySelector('.qp-selected-list');
        const ctxBar   = root.querySelector('.qp-context-bar');

        if (search) {
            search.addEventListener('input', function () {
                state.search = this.value;
                state.shown = PAGE_SIZE;
                renderAvailable(root, state);
            });
        }
        if (chips) {
            chips.addEventListener('click', function (e) {
                const chip = e.target.closest('.qp-chip');
                if (!chip) return;
                chips.querySelectorAll('.qp-chip').forEach(c => c.classList.remove('is-active'));
                chip.classList.add('is-active');
                state.filter = chip.dataset.filter || 'all';
                state.shown = PAGE_SIZE;
                renderAvailable(root, state);
            });
        }
        if (ctxBar) {
            const toggle = ctxBar.querySelector('.qp-context-toggle');
            if (toggle) {
                toggle.addEventListener('click', function () {
                    state.showAllProducts = !state.showAllProducts;
                    state.filter = state.showAllProducts ? 'all' : (state.roofingProduct || 'all');
                    state.shown = PAGE_SIZE;
                    renderContextBar(root, state);
                    renderAvailable(root, state);
                });
            }
        }
        if (showMore) {
            showMore.addEventListener('click', function () {
                state.shown += PAGE_SIZE;
                renderAvailable(root, state);
            });
        }
        if (list) {
            list.addEventListener('change', function (e) {
                const cb = e.target.closest('.qp-available-check');
                if (!cb) return;
                const row = cb.closest('.qp-available-row');
                const id  = parseInt(row.dataset.materialId, 10);
                const mat = state.catalog.find(m => m.id === id);
                if (!mat) return;
                if (cb.checked) {
                    if (state.selected[id] == null) {
                        state.selected[id] = { qty: 1, price: mat.price || 0 };
                    }
                } else {
                    delete state.selected[id];
                }
                renderSelected(root, state);
                renderAvailable(root, state);
                recalcTotals(form);
            });
        }
        if (selList) {
            selList.addEventListener('click', function (e) {
                const removeBtn = e.target.closest('.qp-row-remove');
                if (!removeBtn) return;
                const id = parseInt(removeBtn.dataset.remove, 10);
                delete state.selected[id];
                renderSelected(root, state);
                renderAvailable(root, state);
                recalcTotals(form);
            });
            selList.addEventListener('input', function (e) {
                const inp = e.target;
                if (!inp.matches('input[data-field]')) return;
                const row = inp.closest('.qp-selected-row');
                const id  = parseInt(row.dataset.materialId, 10);
                const sel = state.selected[id];
                if (!sel) return;
                if (inp.dataset.field === 'qty') sel.qty = parseFloat(inp.value) || 0;
                recalcTotals(form);
            });
        }
    }

    function recalcTotals(form) {
        let mainSub = 0, genSub = 0;
        form.querySelectorAll('.qp-selected-row').forEach(function (row) {
            const qtyEl   = row.querySelector('input[data-field="qty"]');
            const priceEl = row.querySelector('input[name="q_material_unit_price[]"]');
            const qty     = parseFloat(qtyEl ? qtyEl.value : 0) || 0;
            const price   = window.frParseMoney(priceEl ? priceEl.value : '');
            const amount  = qty * price;
            const amtEl   = row.querySelector('.qp-row-amount');
            if (amtEl) amtEl.textContent = fmt(amount);
            const split   = row.closest('.qp-split');
            const section = split ? split.dataset.section : 'main';
            if (section === 'main') mainSub += amount;
            else                    genSub  += amount;
        });
        const mainSubEl = form.querySelector('.qp-main-subtotal');
        const genSubEl  = form.querySelector('.qp-general-subtotal');
        if (mainSubEl) mainSubEl.textContent = fmt(mainSub);
        if (genSubEl)  genSubEl.textContent  = fmt(genSub);

        const inst     = window.frParseMoney(form.querySelector('[name="q_installation_charge"]')?.value);
        const deliv    = window.frParseMoney(form.querySelector('[name="q_delivery_charges"]')?.value);
        // REVISION: Block invalid discounts and avoid a misleading negative total.
        const discountEl = form.querySelector('[name="q_discount_percent"]');
        const discountText = discountEl ? discountEl.value.trim() : '';
        const discPct = Number(discountText);
        
        const invalidDiscount =
            discountText === '' ||
            !Number.isFinite(discPct) ||
            discPct < 0 ||
            discPct > 100;
        
        if (discountEl) {
            discountEl.setCustomValidity(
                invalidDiscount ? 'Enter a discount between 0 and 100%.' : ''
            );
        }
        
        if (invalidDiscount) {
            const totalEl = form.querySelector('.q-grand-total');
            if (totalEl) totalEl.textContent = 'Invalid discount';
            return;
        }
        
        const chargesBeforeDiscount = inst + deliv;
        const preDiscountTotal = mainSub + genSub + chargesBeforeDiscount;
        const discountAmount = preDiscountTotal * (discPct / 100);
        const grand = preDiscountTotal - discountAmount;

        const gtEl = form.querySelector('.q-grand-total');
        if (gtEl) gtEl.textContent = fmt(grand);
    }

    window.qpRecalcAll = function () {
        document.querySelectorAll('.quotation-panel-form').forEach(function (form) {
            if (form.__qpState) recalcTotals(form);
        });
    };

    window.__initQuotationPickersIn = function (root) {
        if (!root) return;
        root.querySelectorAll('.quotation-panel-form').forEach(initPicker);
    };

    document.addEventListener('DOMContentLoaded', function () {
        window.__initQuotationPickersIn(document);
    });
})();

// =========================================================================
// Quotation tabs — one form per quotation; switching just toggles display.
// =========================================================================
document.addEventListener('click', function (e) {
    const tab = e.target.closest('.quotation-tab');
    if (!tab) return;

    const bar = tab.closest('.quotation-tabs');
    if (!bar) return;

    const targetQid = tab.dataset.qid;

    bar.querySelectorAll('.quotation-tab').forEach(t => {
        t.classList.toggle('is-active', t === tab);
    });

    const section = bar.parentElement;
    section.querySelectorAll('.quotation-panel').forEach(p => {
        p.style.display = (p.dataset.qid === targetQid) ? 'block' : 'none';
    });
});

// =========================================================================
// Collapse / expand toggle
// =========================================================================
window.toggleQuotationSection = function (btn) {
    const wrapper = btn.closest('.quotation-section-wrapper');
    if (!wrapper) return;
    wrapper.classList.toggle('is-collapsed');
};

// =========================================================================
// Add Quotation — AJAX, no page reload
// =========================================================================
document.addEventListener('submit', function (e) {
    const form = e.target.closest('.add-quotation-form');
    if (!form) return;
    e.preventDefault();
    e.stopPropagation();

    const inspectionInput = form.querySelector('input[name="inspection_id"]');
    const csrfInput       = form.querySelector('input[name="csrf_token"]');
    if (!inspectionInput || !csrfInput) return;

    const button = form.querySelector('button[type="submit"]');
    const originalLabel = button ? button.innerHTML : '';

    if (button) {
        button.disabled = true;
        button.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>Adding…';
    }

    const fd = new FormData();
    fd.append('csrf_token', csrfInput.value);
    fd.append('inspection_id', inspectionInput.value);

    fetch('foreman.php?ajax=add_quotation', {
        method: 'POST',
        body: fd,
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(r => r.json())
    .then(res => {
        if (!res.success) {
            alert(res.message || 'Could not add the quotation.');
            return;
        }

        const wrapper = form.closest('.quotation-section-wrapper');
        if (!wrapper) return;

        // Swap in the freshly-rendered section.
        const temp = document.createElement('div');
        temp.innerHTML = res.html.trim();
        const newWrapper = temp.firstElementChild;
        if (!newWrapper) return;

        // Preserve the collapsed state.
        if (wrapper.classList.contains('is-collapsed')) {
            newWrapper.classList.add('is-collapsed');
        }

        wrapper.replaceWith(newWrapper);

        // Re-init the material pickers on the new panels.
        if (window.__initQuotationPickersIn) {
            window.__initQuotationPickersIn(newWrapper);
        }

        // Switch to the newly-added tab (last one).
        const tabs = newWrapper.querySelectorAll('.quotation-tab');
        if (tabs.length > 0) {
            tabs[tabs.length - 1].click();
        }
    })
    .catch(err => {
        console.error(err);
        alert('Network error. Please try again.');
    })
    .finally(() => {
        if (button) {
            button.disabled = false;
            button.innerHTML = originalLabel;
        }
    });
});
// REVISION: Show specific field warnings before submitting the quotation.
(function () {
    const rules = {
        'q_material_qty[]': {
            min: 0.01,
            message: 'Enter a quantity of at least 0.01.'
        },
        'q_installation_charge': {
            min: 0.01,
            message: 'Enter an Installation Fee greater than ₱0.'
        },
        'q_delivery_charges': {
            min: 0.01,
            message: 'Enter Delivery Charges greater than ₱0.'
        },
        'q_discount_percent': {
            min: 0,
            max: 100,
            message: 'Enter a discount from 0 to 100%. Use 0 for no discount.'
        }
    };

    function validateField(input) {
        const rule = rules[input.name];
        if (!rule || input.matches(':disabled')) return true;

        const raw = input.value.trim().replace(/,/g, '');
        const value = Number(raw);
        const invalid = raw === '' ||
            !Number.isFinite(value) ||
            value < rule.min ||
            (rule.max !== undefined && value > rule.max);

        input.setCustomValidity(invalid ? rule.message : '');

        let warning = input.parentElement.querySelector(
            '[data-quotation-warning]'
        );

        if (!warning && invalid) {
            warning = document.createElement('small');
            warning.dataset.quotationWarning = '1';
            warning.style.cssText =
                'display:block;color:#b42318;margin-top:4px;';
            input.insertAdjacentElement('afterend', warning);
        }

        if (warning) {
            warning.textContent = invalid ? rule.message : '';
            warning.hidden = !invalid;
        }

        return !invalid;
    }

    // Validate after leaving a field; clear warnings when corrected.
    document.addEventListener('focusout', function (event) {
        const input = event.target;
        if (input.closest('.quotation-panel-form') && rules[input.name]) {
            validateField(input);
        }
    });

    document.addEventListener('input', function (event) {
        const input = event.target;
        if (input.closest('.quotation-panel-form') && rules[input.name]) {
            validateField(input);
        }
    });

    function validateForm(form) {
        let valid = true;

        form.querySelectorAll('input').forEach(function (input) {
            if (rules[input.name] && !validateField(input)) valid = false;
        });

        if (!valid) form.reportValidity();
        return valid;
    }

    // Run before the browser submits or closes the form.
    document.addEventListener('click', function (event) {
        const button = event.target.closest(
            'button[type="submit"], input[type="submit"]'
        );
        const form = button ? button.form : null;

        if (!form || !form.matches('.quotation-panel-form')) return;

        if (!validateForm(form)) {
            event.preventDefault();
            event.stopImmediatePropagation();
        }
    }, true);

    document.addEventListener('submit', function (event) {
        const form = event.target;
        if (!form.matches('.quotation-panel-form')) return;

        if (!validateForm(form)) {
            event.preventDefault();
            event.stopImmediatePropagation();
        }
    }, true);
})();
</script>