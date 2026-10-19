<?php
// This file is included inside admin.php.

$stmt = $pdo->query("SELECT * FROM system_settings ORDER BY id ASC LIMIT 1");
$settings = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$settings) {
    $stmt = $pdo->prepare("
        INSERT INTO system_settings (company_name, company_subtitle, down_payment_rate, late_threshold, standard_start_time)
        VALUES ('Eng Roofing', 'Supply and Installation', 30.00, '00:15:00', '08:00:00')
    ");
    $stmt->execute();
    $settings_id = $pdo->lastInsertId();
    $stmt = $pdo->prepare("SELECT * FROM system_settings WHERE id = ? LIMIT 1");
    $stmt->execute([$settings_id]);
    $settings = $stmt->fetch(PDO::FETCH_ASSOC);
}

$product_categories = [
    'Ribbed Roofing'        => 'Ribbed / Corrugated Profiles',
    'Tile-Profile Roofing'  => 'Tile Profile / Elegant Series',
    'Standing Seam Roofing' => 'Long Span & Standing Seam Systems',
    'Specialty Roofing'     => 'Specialized & Structural Roofing',
    'Ceilings'              => 'Ceilings / Architectural Panels',
];

$roofing_products_by_category = [
    'Tile-Profile Roofing'  => ['Banawe', 'Hacienda (Royal Mediterranean Series)', 'Valencia (Royal Mediterranean Series)', 'Tilespan Elite', 'Tilespan Maxi', 'Satori Tile'],
    'Ribbed Roofing'        => ['Twin Rib', 'EPR', 'R-Span', 'Multi R-Span', 'Corspan', 'Mega 2A Rib', 'Big V'],
    'Specialty Roofing'     => ['Retrofitter', 'Curve Roof', 'Steel Decking'],
    'Ceilings'              => ['Spandrel'],
    'Standing Seam Roofing' => ['Super R-Span', 'Standing S-Span', 'S-Rib Standing Seam'],
];

$roofing_product_to_category = [];
foreach ($roofing_products_by_category as $__cat => $__prods) {
    foreach ($__prods as $__p) {
        $roofing_product_to_category[$__p] = $__cat;
    }
}

// =====================================================================
// AJAX endpoint
// =====================================================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'settings_material') {

    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        echo json_encode(['success' => false, 'message' => 'POST required.']);
        exit;
    }
    if (!isset($_POST['csrf_token']) || !csrf_validate($_POST['csrf_token'])) {
        echo json_encode(['success' => false, 'message' => 'CSRF token validation failed.']);
        exit;
    }

    $action        = $_POST['action'] ?? '';
    $allowed_units = ['PC', 'LM', 'SQM', 'SET', 'Tube', 'BOX', 'Roll', 'Other'];
    $unit_labels   = [
        'PC'    => 'PC / Pieces',
        'LM'    => 'LM / Linear Meter',
        'SQM'   => 'SQM / Sq Meter',
        'SET'   => 'SET',
        'Tube'  => 'Tube',
        'BOX'   => 'BOX',
        'Roll'  => 'Roll',
        'Other' => 'Other',
    ];

    try {

        // ---------- Add main material ----------
        if ($action === 'add_material') {
            $item_product     = trim($_POST['item_product'] ?? '');
            $product_category = trim($_POST['product_category'] ?? '');
            $roofing_product  = trim($_POST['roofing_product'] ?? '');
            $specification    = trim($_POST['specification'] ?? '');
            $unit             = trim($_POST['unit'] ?? '');
            $estimated_price  = max(0, (float)($_POST['estimated_price'] ?? 0));

            if ($item_product === '')                     { echo json_encode(['success'=>false,'message'=>'Item/Product is required.']); exit; }
            if (!in_array($unit, $allowed_units, true))   { echo json_encode(['success'=>false,'message'=>'Invalid unit.']); exit; }
            if (!array_key_exists($product_category, $product_categories)) { echo json_encode(['success'=>false,'message'=>'Invalid product category.']); exit; }
            if (!isset($roofing_product_to_category[$roofing_product]))    { echo json_encode(['success'=>false,'message'=>'Invalid roofing product.']); exit; }
            if ($roofing_product_to_category[$roofing_product] !== $product_category) {
                echo json_encode(['success'=>false,'message'=>'Roofing product does not belong to that product category.']); exit;
            }

            $material_code = generateMaterialCode($pdo);

            $stmt = $pdo->prepare("
                INSERT INTO materials (material_code, item_product, specification, unit, estimated_price, material_type,
                                       product_category, roofing_product, general_category)
                VALUES (:material_code, :item_product, :specification, :unit, :estimated_price, 'main',
                        :product_category, :roofing_product, NULL)
            ");
            $stmt->execute([
                ':material_code'    => $material_code,
                ':item_product'     => $item_product,
                ':specification'    => $specification,
                ':unit'             => $unit,
                ':estimated_price'  => $estimated_price,
                ':product_category' => $product_category,
                ':roofing_product'  => $roofing_product,
            ]);

            echo json_encode([
                'success'  => true,
                'message'  => 'Main component material added successfully.',
                'material' => [
                    'id'               => (int)$pdo->lastInsertId(),
                    'material_code'    => $material_code,
                    'item_product'     => $item_product,
                    'specification'    => $specification,
                    'unit'             => $unit,
                    'estimated_price'  => $estimated_price,
                    'unit_label'       => $unit_labels[$unit] ?? $unit,
                    'product_category' => $product_category,
                    'category_label'   => $product_categories[$product_category],
                    'roofing_product'  => $roofing_product,
                ],
            ]);
            exit;
        }

        // ---------- Edit main material ----------
        if ($action === 'edit_material') {
            $material_id      = (int)($_POST['material_id'] ?? 0);
            $item_product     = trim($_POST['item_product'] ?? '');
            $product_category = trim($_POST['product_category'] ?? '');
            $roofing_product  = trim($_POST['roofing_product'] ?? '');
            $specification    = trim($_POST['specification'] ?? '');
            $unit             = trim($_POST['unit'] ?? '');
            $estimated_price  = max(0, (float)($_POST['estimated_price'] ?? 0));

            if ($material_id <= 0 || $item_product === '') { echo json_encode(['success'=>false,'message'=>'Item/Product is required.']); exit; }
            if (!in_array($unit, $allowed_units, true))    { echo json_encode(['success'=>false,'message'=>'Invalid unit.']); exit; }
            if (!array_key_exists($product_category, $product_categories)) { echo json_encode(['success'=>false,'message'=>'Invalid product category.']); exit; }
            if (!isset($roofing_product_to_category[$roofing_product]))    { echo json_encode(['success'=>false,'message'=>'Invalid roofing product.']); exit; }
            if ($roofing_product_to_category[$roofing_product] !== $product_category) {
                echo json_encode(['success'=>false,'message'=>'Roofing product does not belong to that product category.']); exit;
            }

            $stmt = $pdo->prepare("
                UPDATE materials SET
                    item_product     = :item_product,
                    specification    = :specification,
                    unit             = :unit,
                    estimated_price  = :estimated_price,
                    product_category = :product_category,
                    roofing_product  = :roofing_product
                WHERE id = :id AND material_type = 'main'
            ");
            $stmt->execute([
                ':item_product'     => $item_product,
                ':specification'    => $specification,
                ':unit'             => $unit,
                ':estimated_price'  => $estimated_price,
                ':product_category' => $product_category,
                ':roofing_product'  => $roofing_product,
                ':id'               => $material_id,
            ]);

            echo json_encode([
                'success'  => true,
                'message'  => 'Main component material updated successfully.',
                'material' => [
                    'id'               => $material_id,
        // REVISION: Preserve Material ID when the edited row refreshes.
                    'material_code' => $pdo->query("SELECT material_code FROM materials WHERE id = " . (int)$material_id)->fetchColumn(),               
                    'item_product'     => $item_product,
                    'specification'    => $specification,
                    'unit'             => $unit,
                    'estimated_price'  => $estimated_price,
                    'unit_label'       => $unit_labels[$unit] ?? $unit,
                    'product_category' => $product_category,
                    'category_label'   => $product_categories[$product_category],
                    'roofing_product'  => $roofing_product,
                ],
            ]);
            exit;
        }
        
        // ---------- Edit general material ----------
        if ($action === 'edit_general_material') {
            $material_id      = (int)($_POST['material_id'] ?? 0);
            $item_product     = trim($_POST['item_product'] ?? '');
            $general_category = trim($_POST['general_category'] ?? '');
            $specification    = trim($_POST['specification'] ?? '');
            $unit             = trim($_POST['unit'] ?? '');
            $estimated_price  = max(0, (float)($_POST['estimated_price'] ?? 0));

            if ($material_id <= 0 || $item_product === '') { echo json_encode(['success'=>false,'message'=>'Item/Product is required.']); exit; }
            if (!in_array($unit, $allowed_units, true))    { echo json_encode(['success'=>false,'message'=>'Invalid unit.']); exit; }

            $stmt = $pdo->prepare("SELECT id FROM general_material_categories WHERE name = ? LIMIT 1");
            $stmt->execute([$general_category]);
            if (!$stmt->fetch()) { echo json_encode(['success'=>false,'message'=>'Invalid general category.']); exit; }

            $stmt = $pdo->prepare("
                UPDATE materials SET
                    item_product     = :item_product,
                    specification    = :specification,
                    unit             = :unit,
                    estimated_price  = :estimated_price,
                    general_category = :general_category
                WHERE id = :id AND material_type = 'general'
            ");
            $stmt->execute([
                ':item_product'     => $item_product,
                ':specification'    => $specification,
                ':unit'             => $unit,
                ':estimated_price'  => $estimated_price,
                ':general_category' => $general_category,
                ':id'               => $material_id,
            ]);

            echo json_encode([
                'success'  => true,
                'message'  => 'General material updated successfully.',
                'material' => [
                    'id'               => $material_id,
        // REVISION: Preserve Material ID when the edited row refreshes.
                    'material_code' => $pdo->query("SELECT material_code FROM materials WHERE id = " . (int)$material_id)->fetchColumn(),
                    'item_product'     => $item_product,
                    'specification'    => $specification,
                    'unit'             => $unit,
                    'unit_label'       => $unit_labels[$unit] ?? $unit,
                    'estimated_price'  => $estimated_price,
                    'general_category' => $general_category,
                ],
            ]);
            exit;
        }

        // ---------- Delete material ----------
        if ($action === 'delete_material') {
            $material_id = (int)($_POST['material_id'] ?? $_POST['delete_material'] ?? 0);
            $return_to   = $_POST['return_to'] ?? 'main';
            if ($material_id <= 0) { echo json_encode(['success'=>false,'message'=>'Invalid material.']); exit; }
            $stmt = $pdo->prepare("DELETE FROM materials WHERE id = ?");
            $stmt->execute([$material_id]);
            echo json_encode([
                'success'     => true,
                'message'     => $return_to === 'general' ? 'General material deleted successfully.' : 'Material deleted successfully.',
                'material_id' => $material_id,
                'return_to'   => $return_to,
            ]);
            exit;
        }

        // ---------- Add general category ----------
        if ($action === 'add_general_category') {
            $name = trim($_POST['category_name'] ?? '');
            if ($name === '' || mb_strlen($name) > 100) { echo json_encode(['success'=>false,'message'=>'Category name is required.']); exit; }
            $stmt = $pdo->prepare("SELECT id FROM general_material_categories WHERE LOWER(name) = LOWER(?) LIMIT 1");
            $stmt->execute([$name]);
            if ($stmt->fetch()) { echo json_encode(['success'=>false,'message'=>'That category already exists.']); exit; }
            $stmt = $pdo->prepare("INSERT INTO general_material_categories (name) VALUES (?)");
            $stmt->execute([$name]);
            echo json_encode([
                'success'  => true,
                'message'  => 'Category added successfully.',
                'category' => ['id'=>(int)$pdo->lastInsertId(),'name'=>$name,'material_count'=>0],
            ]);
            exit;
        }

        // ---------- Delete general category ----------
        if ($action === 'delete_general_category') {
            $category_id = (int)($_POST['category_id'] ?? 0);
            if ($category_id <= 0) { echo json_encode(['success'=>false,'message'=>'Invalid category.']); exit; }
            $stmt = $pdo->prepare("SELECT name FROM general_material_categories WHERE id = ? LIMIT 1");
            $stmt->execute([$category_id]);
            $cat_name = $stmt->fetchColumn();
            if ($cat_name === false) { echo json_encode(['success'=>false,'message'=>'Category not found.']); exit; }
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM materials WHERE material_type='general' AND general_category=?");
            $stmt->execute([$cat_name]);
            if ((int)$stmt->fetchColumn() > 0) { echo json_encode(['success'=>false,'message'=>'This category still has materials. Delete them first.']); exit; }
            $stmt = $pdo->prepare("DELETE FROM general_material_categories WHERE id = ?");
            $stmt->execute([$category_id]);
            echo json_encode(['success'=>true,'message'=>'Category deleted successfully.','category_id'=>$category_id,'category_name'=>$cat_name]);
            exit;
        }

        // ---------- Add general material ----------
        if ($action === 'add_general_material') {
            $item_product     = trim($_POST['item_product'] ?? '');
            $general_category = trim($_POST['general_category'] ?? '');
            $specification    = trim($_POST['specification'] ?? '');
            $unit             = trim($_POST['unit'] ?? '');
            $estimated_price  = max(0, (float)($_POST['estimated_price'] ?? 0));

            if ($item_product === '')                   { echo json_encode(['success'=>false,'message'=>'Item/Product is required.']); exit; }
            if (!in_array($unit, $allowed_units, true)) { echo json_encode(['success'=>false,'message'=>'Invalid unit.']); exit; }

            $stmt = $pdo->prepare("SELECT id FROM general_material_categories WHERE name = ? LIMIT 1");
            $stmt->execute([$general_category]);
            if (!$stmt->fetch()) { echo json_encode(['success'=>false,'message'=>'Invalid general category.']); exit; }

            $material_code = generateMaterialCode($pdo);

            $stmt = $pdo->prepare("
                INSERT INTO materials (material_code, item_product, specification, unit, estimated_price, material_type,
                                       product_category, roofing_product, general_category)
                VALUES (:material_code, :item_product, :specification, :unit, :estimated_price, 'general',
                        NULL, NULL, :general_category)
            ");
            $stmt->execute([
                ':material_code'    => $material_code,
                ':item_product'     => $item_product,
                ':specification'    => $specification,
                ':unit'             => $unit,
                ':estimated_price'  => $estimated_price,
                ':general_category' => $general_category,
            ]);

            echo json_encode([
                'success'  => true,
                'message'  => 'General material added successfully.',
                'material' => [
                    'id'               => (int)$pdo->lastInsertId(),
                    'material_code'    => $material_code,
                    'item_product'     => $item_product,
                    'specification'    => $specification,
                    'unit'             => $unit,
                    'estimated_price'  => $estimated_price,
                    'unit_label'       => $unit_labels[$unit] ?? $unit,
                    'general_category' => $general_category,
                ],
            ]);
            exit;
        }
        
        // ---------- Toggle Template Status ----------
        if ($action === 'toggle_template_status') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) { echo json_encode(['success'=>false,'message'=>'Invalid template.']); exit; }
            $pdo->prepare("UPDATE material_templates SET is_active = IF(is_active=1,0,1) WHERE id = ?")->execute([$id]);
            $new = (int)$pdo->query("SELECT is_active FROM material_templates WHERE id = {$id}")->fetchColumn();
            echo json_encode(['success'=>true,'message'=>'Template status updated.','is_active'=>$new]); exit;
        }
        
        // ---------- Get Template Details ----------
        if ($action === 'get_template_details') {
            $id = (int)($_POST['id'] ?? 0);
            $tmpl = $id > 0 ? getTemplateWithItems($pdo, $id) : null;
            if (!$tmpl) { echo json_encode(['success'=>false,'message'=>'Template not found.']); exit; }
            echo json_encode(['success'=>true,'template'=>$tmpl]); exit;
        }
        
        // ---------- Add Template ----------
        if ($action === 'add_template') {
            $name    = trim($_POST['template_name'] ?? '');
            $service = normalizeServiceTypeName($_POST['service_type'] ?? '');
            $roof    = normalizeRoofTypeName($_POST['roof_type'] ?? '');
            $product = trim($_POST['roofing_product'] ?? '');
            $desc    = trim($_POST['description'] ?? '');
            $ids     = $_POST['material_ids'] ?? [];
        
            if ($name === '' || $service === '' || $roof === '' || $product === '') {
                echo json_encode(['success'=>false,'message'=>'Template name, service, roof type, and roofing product are required.']); exit;
            }
        
            $stmt = $pdo->prepare("
                SELECT id FROM material_templates
                WHERE LOWER(service_type)=LOWER(?) AND LOWER(roof_type)=LOWER(?) AND LOWER(roofing_product)=LOWER(?)
                LIMIT 1
            ");
            $stmt->execute([$service, $roof, $product]);
            if ($stmt->fetch()) {
                echo json_encode(['success'=>false,'message'=>'A template already exists for that Service Type + Roof Type + Roofing Product.']); exit;
            }
        
            $pdo->prepare("
                INSERT INTO material_templates (template_name, service_type, roof_type, roofing_product, description, is_active)
                VALUES (?, ?, ?, ?, ?, 1)
            ")->execute([$name, $service, $roof, $product, $desc]);
            $tid = (int)$pdo->lastInsertId();
        
            if (is_array($ids)) {
                $sort = 1;
                $ins  = $pdo->prepare("
                    INSERT IGNORE INTO material_template_items (template_id, material_id, qty_rule, sort_order)
                    VALUES (?, ?, ?, ?)
                ");
                foreach ($ids as $mid) {
                    $mid = (int)$mid;
                    if ($mid <= 0) continue;
                    $ms = $pdo->prepare("SELECT material_type, unit FROM materials WHERE id = ? LIMIT 1");
                    $ms->execute([$mid]);
                    $m = $ms->fetch(PDO::FETCH_ASSOC);
                    $rule = 'fixed_1';
                    if ($m) {
                        if ($m['material_type'] === 'main') $rule = 'fixed_1';
                        elseif (strtoupper($m['unit']) === 'SQM') $rule = 'roof_area';
                        elseif (strtoupper($m['unit']) === 'LM')  $rule = 'ridge_length';
                    }
                    $ins->execute([$tid, $mid, $rule, $sort++]);
                }
            }
        
            echo json_encode(['success'=>true,'message'=>'Template created.','template_id'=>$tid]); exit;
        }
        
        // ---------- Edit Template Details ----------
        if ($action === 'edit_template_details') {
            $id   = (int)($_POST['id'] ?? 0);
            $name = trim($_POST['template_name'] ?? '');
            $desc = trim($_POST['description'] ?? '');
            if ($id <= 0 || $name === '') { echo json_encode(['success'=>false,'message'=>'Template name is required.']); exit; }
        
            $pdo->prepare("UPDATE material_templates SET template_name = ?, description = ? WHERE id = ?")
                ->execute([$name, $desc, $id]);
            echo json_encode(['success'=>true,'message'=>'Template details updated.']); exit;
        }
        
        // ---------- Delete Template ----------
        if ($action === 'delete_template') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) { echo json_encode(['success'=>false,'message'=>'Invalid template.']); exit; }
            $pdo->prepare("DELETE FROM material_templates WHERE id = ?")->execute([$id]);
            echo json_encode(['success'=>true,'message'=>'Template deleted.','template_id'=>$id]); exit;
        }
        
        // ---------- Add Item to Template ----------
        if ($action === 'add_item_to_template') {
            $tid  = (int)($_POST['template_id'] ?? 0);
            $mid  = (int)($_POST['material_id'] ?? 0);
            $rule = trim($_POST['qty_rule'] ?? 'fixed_1');
            $allowed_rules = ['roof_area','ridge_length','eave_length','fasteners_area','fixed_1','fixed_2','fixed_4','manual'];
            if (!in_array($rule, $allowed_rules, true)) $rule = 'fixed_1';
            if ($tid <= 0 || $mid <= 0) { echo json_encode(['success'=>false,'message'=>'Select a valid template and material.']); exit; }
        
            $pdo->prepare("
                INSERT INTO material_template_items (template_id, material_id, qty_rule, sort_order)
                VALUES (?, ?, ?, 99)
                ON DUPLICATE KEY UPDATE qty_rule = VALUES(qty_rule)
            ")->execute([$tid, $mid, $rule]);
        
            echo json_encode(['success'=>true,'message'=>'Material added.','template'=>getTemplateWithItems($pdo,$tid)]); exit;
        }
        
        // ---------- Remove Item ----------
        if ($action === 'remove_item_from_template') {
            $iid = (int)($_POST['template_item_id'] ?? 0);
            if ($iid <= 0) { echo json_encode(['success'=>false,'message'=>'Invalid item.']); exit; }
            $stmt = $pdo->prepare("SELECT template_id FROM material_template_items WHERE id = ? LIMIT 1");
            $stmt->execute([$iid]);
            $tid = (int)$stmt->fetchColumn();
            $pdo->prepare("DELETE FROM material_template_items WHERE id = ?")->execute([$iid]);
            echo json_encode([
                'success'  => true,
                'message'  => 'Item removed.',
                'template' => $tid > 0 ? getTemplateWithItems($pdo, $tid) : null
            ]); exit;
        }
        
        // ---------- Update Item Rule ----------
        if ($action === 'update_template_item_rule') {
            $iid  = (int)($_POST['template_item_id'] ?? 0);
            $rule = trim($_POST['qty_rule'] ?? 'fixed_1');
            $allowed_rules = ['roof_area','ridge_length','eave_length','fasteners_area','fixed_1','fixed_2','fixed_4','manual'];
            if (!in_array($rule, $allowed_rules, true)) $rule = 'fixed_1';
            $pdo->prepare("UPDATE material_template_items SET qty_rule = ? WHERE id = ?")->execute([$rule, $iid]);
            echo json_encode(['success'=>true,'message'=>'Rule updated.']); exit;
        }
        
                // ---------- Toggle Config Status ----------
        if ($action === 'toggle_config_status') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) { echo json_encode(['success'=>false,'message'=>'Invalid configuration.']); exit; }

            $stmt = $pdo->prepare("SELECT id FROM service_property_configurations WHERE id = ? LIMIT 1");
            $stmt->execute([$id]);
            if (!$stmt->fetch()) { echo json_encode(['success'=>false,'message'=>'Configuration not found.']); exit; }

            $pdo->prepare("UPDATE service_property_configurations SET is_active = IF(is_active=1,0,1) WHERE id = ?")
                ->execute([$id]);

            $stmt = $pdo->prepare("SELECT is_active FROM service_property_configurations WHERE id = ? LIMIT 1");
            $stmt->execute([$id]);
            $new = (int)$stmt->fetchColumn();

            echo json_encode([
                'success'   => true,
                'message'   => $new ? 'Option activated.' : 'Option deactivated.',
                'is_active' => $new,
            ]);
            exit;
        }

        // ---------- Get Config Details ----------
        if ($action === 'get_config_details') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) { echo json_encode(['success'=>false,'message'=>'Invalid configuration.']); exit; }

            $stmt = $pdo->prepare("SELECT * FROM service_property_configurations WHERE id = ? LIMIT 1");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) { echo json_encode(['success'=>false,'message'=>'Configuration not found.']); exit; }
            echo json_encode(['success'=>true, 'config'=>$row]);
            exit;
        }

        // ---------- Update Config Details ----------
        if ($action === 'update_config_details') {
            $id   = (int)($_POST['id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $desc = trim($_POST['description'] ?? '');

            if ($id <= 0 || $name === '') {
                echo json_encode(['success'=>false,'message'=>'Name is required.']); exit;
            }
            if (mb_strlen($name) > 100) {
                echo json_encode(['success'=>false,'message'=>'Name cannot exceed 100 characters.']); exit;
            }
            if (mb_strlen($desc) > 500) {
                echo json_encode(['success'=>false,'message'=>'Description cannot exceed 500 characters.']); exit;
            }

            $stmt = $pdo->prepare("SELECT config_type FROM service_property_configurations WHERE id = ? LIMIT 1");
            $stmt->execute([$id]);
            $configType = $stmt->fetchColumn();
            if ($configType === false) {
                echo json_encode(['success'=>false,'message'=>'Configuration not found.']); exit;
            }

            $stmt = $pdo->prepare("
                SELECT id FROM service_property_configurations
                WHERE config_type = ? AND LOWER(name) = LOWER(?) AND id <> ?
                LIMIT 1
            ");
            $stmt->execute([$configType, $name, $id]);
            if ($stmt->fetch()) {
                echo json_encode(['success'=>false,'message'=>'An option with that name already exists.']); exit;
            }

            $stmt = $pdo->prepare("
                UPDATE service_property_configurations
                SET name = ?, description = ?
                WHERE id = ?
            ");
            $stmt->execute([$name, $desc, $id]);

            echo json_encode([
                'success'     => true,
                'message'     => 'Option updated.',
                'id'          => $id,
                'name'        => $name,
                'description' => $desc,
                'config_type' => $configType,
            ]);
            exit;
        }
        
        echo json_encode(['success' => false, 'message' => 'Unknown action.']);

    } catch (Throwable $e) {
        error_log('settings AJAX error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
    }
    exit;
}

$msg = '';
$msg_type = '';

// =====================================================================
// Save company
// =====================================================================
if (isset($_POST['save_company'])) {
    if (!isset($_POST['csrf_token']) || !csrf_validate($_POST['csrf_token'])) { die('CSRF token validation failed.'); }

    $company_name     = trim($_POST['company_name'] ?? '');
    $company_subtitle = trim($_POST['company_subtitle'] ?? '');
    $company_phone    = trim($_POST['company_phone'] ?? '');
    $company_email    = trim($_POST['company_email'] ?? '');
    $company_tin      = trim($_POST['company_tin'] ?? '');
    $company_address  = trim($_POST['company_address'] ?? '');

    if ($company_name === '') { header('Location: admin.php?tab=settings&msg=company_required'); exit; }
    if ($company_email !== '' && !filter_var($company_email, FILTER_VALIDATE_EMAIL)) { header('Location: admin.php?tab=settings&msg=invalid_email'); exit; }
    if ($company_phone !== '' && !preg_match('/^09\d{9}$/', $company_phone)) { header('Location: admin.php?tab=settings&msg=invalid_company_phone'); exit; }

    $company_logo = $settings['company_logo'] ?? '';

    if (isset($_FILES['company_logo']) && $_FILES['company_logo']['error'] !== UPLOAD_ERR_NO_FILE) {
        if ($_FILES['company_logo']['error'] !== UPLOAD_ERR_OK) { header('Location: admin.php?tab=settings&msg=logo_error'); exit; }
        if ($_FILES['company_logo']['size'] > 2 * 1024 * 1024) { header('Location: admin.php?tab=settings&msg=logo_too_large'); exit; }

        $tmp_name = $_FILES['company_logo']['tmp_name'];
        $image_info = @getimagesize($tmp_name);
        if ($image_info === false) { header('Location: admin.php?tab=settings&msg=invalid_logo'); exit; }

        $allowed_types = [IMAGETYPE_JPEG=>'jpg', IMAGETYPE_PNG=>'png', IMAGETYPE_WEBP=>'webp'];
        if (!isset($allowed_types[$image_info[2]])) { header('Location: admin.php?tab=settings&msg=invalid_logo'); exit; }

        $extension = $allowed_types[$image_info[2]];
        $upload_dir = __DIR__ . '/../uploads/company/';
        if (!is_dir($upload_dir)) { if (!mkdir($upload_dir, 0755, true)) { header('Location: admin.php?tab=settings&msg=logo_error'); exit; } }

        $filename = 'company_logo_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
        $destination = $upload_dir . $filename;
        if (!move_uploaded_file($tmp_name, $destination)) { header('Location: admin.php?tab=settings&msg=logo_error'); exit; }

        if (!empty($company_logo)) {
            $old_logo = __DIR__ . '/../' . ltrim($company_logo, '/');
            if (is_file($old_logo)) @unlink($old_logo);
        }
        $company_logo = 'uploads/company/' . $filename;
    }

    $stmt = $pdo->prepare("
        UPDATE system_settings SET
            company_name = :company_name, company_subtitle = :company_subtitle,
            company_phone = :company_phone, company_email = :company_email,
            company_tin = :company_tin, company_address = :company_address,
            company_logo = :company_logo
        WHERE id = :id
    ");
    $stmt->execute([
        ':company_name'=>$company_name, ':company_subtitle'=>$company_subtitle,
        ':company_phone'=>$company_phone, ':company_email'=>$company_email,
        ':company_tin'=>$company_tin, ':company_address'=>$company_address,
        ':company_logo'=>$company_logo, ':id'=>$settings['id'],
    ]);
    header('Location: admin.php?tab=settings&msg=company_saved');
    exit;
}

// =====================================================================
// Save business rules
// =====================================================================
if (isset($_POST['save_business_rules'])) {
    if (!isset($_POST['csrf_token']) || !csrf_validate($_POST['csrf_token'])) { die('CSRF token validation failed.'); }

    $down_payment_rate = isset($_POST['down_payment_rate']) ? (float)$_POST['down_payment_rate'] : 0;
    $late_threshold_minutes = isset($_POST['late_threshold_minutes']) ? (int)$_POST['late_threshold_minutes'] : 0;
    $standard_start_time = trim($_POST['standard_start_time'] ?? '');

    if ($down_payment_rate < 0 || $down_payment_rate > 100) { header('Location: admin.php?tab=settings&msg=invalid_down_payment'); exit; }
    if ($late_threshold_minutes < 0 || $late_threshold_minutes > 120) { header('Location: admin.php?tab=settings&msg=invalid_threshold'); exit; }
    if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $standard_start_time)) { header('Location: admin.php?tab=settings&msg=invalid_start_time'); exit; }

    $late_threshold_db = sprintf('%02d:%02d:00', intdiv($late_threshold_minutes, 60), $late_threshold_minutes % 60);
    $standard_start_time_db = $standard_start_time . ':00';

    $stmt = $pdo->prepare("
        UPDATE system_settings SET
            down_payment_rate = :down_payment_rate,
            late_threshold = :late_threshold,
            standard_start_time = :standard_start_time
        WHERE id = :id
    ");
    $stmt->execute([
        ':down_payment_rate'=>$down_payment_rate,
        ':late_threshold'=>$late_threshold_db,
        ':standard_start_time'=>$standard_start_time_db,
        ':id'=>$settings['id'],
    ]);
    header('Location: admin.php?tab=settings&msg=rules_saved');
    exit;
}

// =====================================================================
// Non-AJAX fallbacks
// =====================================================================
if (isset($_POST['add_material'])) {
    if (!isset($_POST['csrf_token']) || !csrf_validate($_POST['csrf_token'])) { die('CSRF token validation failed.'); }
    $item_product     = trim($_POST['item_product'] ?? '');
    $product_category = trim($_POST['product_category'] ?? '');
    $roofing_product  = trim($_POST['roofing_product'] ?? '');
    $specification    = trim($_POST['specification'] ?? '');
    $unit             = trim($_POST['unit'] ?? '');
    $estimated_price  = max(0, (float)($_POST['estimated_price'] ?? 0));
    $allowed_units = ['PC', 'LM', 'SQM', 'SET', 'Tube', 'Box', 'Roll', 'Other'];

    if ($item_product === '') { header('Location: admin.php?tab=settings&msg=material_required'); exit; }
    if (!in_array($unit, $allowed_units, true)) { header('Location: admin.php?tab=settings&msg=invalid_unit'); exit; }
    if (!array_key_exists($product_category, $product_categories)) { header('Location: admin.php?tab=settings&msg=invalid_category'); exit; }
    if (!isset($roofing_product_to_category[$roofing_product])) { header('Location: admin.php?tab=settings&msg=invalid_roofing_product'); exit; }
    if ($roofing_product_to_category[$roofing_product] !== $product_category) { header('Location: admin.php?tab=settings&msg=product_category_mismatch'); exit; }

    $material_code = generateMaterialCode($pdo);

    $stmt = $pdo->prepare("
        INSERT INTO materials (material_code, item_product, specification, unit, estimated_price, material_type,
                               product_category, roofing_product, general_category)
        VALUES (:material_code, :item_product, :specification, :unit, :estimated_price, 'main',
                :product_category, :roofing_product, NULL)
    ");
    $stmt->execute([
        ':material_code'=>$material_code,
        ':item_product'=>$item_product, ':specification'=>$specification, ':unit'=>$unit,
        ':estimated_price'=>$estimated_price, ':product_category'=>$product_category,
        ':roofing_product'=>$roofing_product,
    ]);
    header('Location: admin.php?tab=settings&msg=material_added');
    exit;
}

if (isset($_POST['edit_material'])) {
    if (!isset($_POST['csrf_token']) || !csrf_validate($_POST['csrf_token'])) { die('CSRF token validation failed.'); }
    $material_id      = (int)($_POST['material_id'] ?? 0);
    $item_product     = trim($_POST['item_product'] ?? '');
    $product_category = trim($_POST['product_category'] ?? '');
    $roofing_product  = trim($_POST['roofing_product'] ?? '');
    $specification    = trim($_POST['specification'] ?? '');
    $unit             = trim($_POST['unit'] ?? '');
    $estimated_price  = max(0, (float)($_POST['estimated_price'] ?? 0));
    $allowed_units = ['PC', 'LM', 'SQM', 'SET', 'Tube', 'Box', 'Roll', 'Other'];

    if ($material_id <= 0 || $item_product === '') { header('Location: admin.php?tab=settings&msg=material_required'); exit; }
    if (!in_array($unit, $allowed_units, true)) { header('Location: admin.php?tab=settings&msg=invalid_unit'); exit; }
    if (!array_key_exists($product_category, $product_categories)) { header('Location: admin.php?tab=settings&msg=invalid_category'); exit; }
    if (!isset($roofing_product_to_category[$roofing_product])) { header('Location: admin.php?tab=settings&msg=invalid_roofing_product'); exit; }
    if ($roofing_product_to_category[$roofing_product] !== $product_category) { header('Location: admin.php?tab=settings&msg=product_category_mismatch'); exit; }

    $stmt = $pdo->prepare("
        UPDATE materials SET
            item_product = :item_product, specification = :specification, unit = :unit,
            estimated_price = :estimated_price, product_category = :product_category,
            roofing_product = :roofing_product
        WHERE id = :id AND material_type = 'main'
    ");
    $stmt->execute([
        ':item_product'=>$item_product, ':specification'=>$specification, ':unit'=>$unit,
        ':estimated_price'=>$estimated_price, ':product_category'=>$product_category,
        ':roofing_product'=>$roofing_product, ':id'=>$material_id,
    ]);
    header('Location: admin.php?tab=settings&msg=material_updated');
    exit;
}

if (isset($_POST['edit_general_material'])) {
    if (!isset($_POST['csrf_token']) || !csrf_validate($_POST['csrf_token'])) { die('CSRF token validation failed.'); }

    $material_id      = (int)($_POST['material_id'] ?? 0);
    $item_product     = trim($_POST['item_product'] ?? '');
    $general_category = trim($_POST['general_category'] ?? '');
    $specification    = trim($_POST['specification'] ?? '');
    $unit             = trim($_POST['unit'] ?? '');
    $estimated_price  = max(0, (float)($_POST['estimated_price'] ?? 0));
    $allowed_units    = ['PC', 'LM', 'SQM', 'SET', 'Tube', 'Box', 'Roll', 'Other'];

    if ($material_id <= 0 || $item_product === '') { header('Location: admin.php?tab=settings&msg=material_required'); exit; }
    if (!in_array($unit, $allowed_units, true))    { header('Location: admin.php?tab=settings&msg=invalid_unit'); exit; }

    $stmt = $pdo->prepare("SELECT id FROM general_material_categories WHERE name = ? LIMIT 1");
    $stmt->execute([$general_category]);
    if (!$stmt->fetch()) { header('Location: admin.php?tab=settings&msg=invalid_general_category'); exit; }

    $stmt = $pdo->prepare("
        UPDATE materials SET
            item_product     = :item_product,
            specification    = :specification,
            unit             = :unit,
            estimated_price  = :estimated_price,
            general_category = :general_category
        WHERE id = :id AND material_type = 'general'
    ");
    $stmt->execute([
        ':item_product'     => $item_product,
        ':specification'    => $specification,
        ':unit'             => $unit,
        ':estimated_price'  => $estimated_price,
        ':general_category' => $general_category,
        ':id'               => $material_id,
    ]);
    header('Location: admin.php?tab=settings&msg=general_material_updated');
    exit;
}

if (isset($_POST['delete_material'])) {
    if (!isset($_POST['csrf_token']) || !csrf_validate($_POST['csrf_token'])) { die('CSRF token validation failed.'); }
    $material_id = (int)$_POST['delete_material'];
    if ($material_id > 0) { $stmt = $pdo->prepare("DELETE FROM materials WHERE id = ?"); $stmt->execute([$material_id]); }
    $back = $_POST['return_to'] ?? 'main';
    $msg_key = $back === 'general' ? 'general_material_deleted' : 'material_deleted';
    header('Location: admin.php?tab=settings&msg=' . $msg_key);
    exit;
}

if (isset($_POST['add_general_category'])) {
    if (!isset($_POST['csrf_token']) || !csrf_validate($_POST['csrf_token'])) { die('CSRF token validation failed.'); }
    $name = trim($_POST['category_name'] ?? '');
    if ($name === '' || mb_strlen($name) > 100) { header('Location: admin.php?tab=settings&msg=category_name_required'); exit; }
    $stmt = $pdo->prepare("SELECT id FROM general_material_categories WHERE LOWER(name) = LOWER(?) LIMIT 1");
    $stmt->execute([$name]);
    if ($stmt->fetch()) { header('Location: admin.php?tab=settings&msg=category_exists'); exit; }
    $stmt = $pdo->prepare("INSERT INTO general_material_categories (name) VALUES (?)");
    $stmt->execute([$name]);
    header('Location: admin.php?tab=settings&msg=category_added');
    exit;
}

if (isset($_POST['delete_general_category'])) {
    if (!isset($_POST['csrf_token']) || !csrf_validate($_POST['csrf_token'])) { die('CSRF token validation failed.'); }
    $category_id = (int)($_POST['category_id'] ?? 0);
    if ($category_id <= 0) { header('Location: admin.php?tab=settings&msg=category_delete_error'); exit; }
    $stmt = $pdo->prepare("SELECT name FROM general_material_categories WHERE id = ? LIMIT 1");
    $stmt->execute([$category_id]);
    $cat_name = $stmt->fetchColumn();
    if ($cat_name === false) { header('Location: admin.php?tab=settings&msg=category_delete_error'); exit; }
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM materials WHERE material_type='general' AND general_category=?");
    $stmt->execute([$cat_name]);
    if ((int)$stmt->fetchColumn() > 0) { header('Location: admin.php?tab=settings&msg=category_not_empty'); exit; }
    $stmt = $pdo->prepare("DELETE FROM general_material_categories WHERE id = ?");
    $stmt->execute([$category_id]);
    header('Location: admin.php?tab=settings&msg=category_deleted');
    exit;
}

if (isset($_POST['add_general_material'])) {
    if (!isset($_POST['csrf_token']) || !csrf_validate($_POST['csrf_token'])) { die('CSRF token validation failed.'); }
    $item_product     = trim($_POST['item_product'] ?? '');
    $general_category = trim($_POST['general_category'] ?? '');
    $specification    = trim($_POST['specification'] ?? '');
    $unit             = trim($_POST['unit'] ?? '');
    $estimated_price  = max(0, (float)($_POST['estimated_price'] ?? 0));
    $allowed_units = ['PC', 'LM', 'SQM', 'SET', 'Tube', 'Box', 'Roll', 'Other'];

    if ($item_product === '') { header('Location: admin.php?tab=settings&msg=material_required'); exit; }
    if (!in_array($unit, $allowed_units, true)) { header('Location: admin.php?tab=settings&msg=invalid_unit'); exit; }

    $stmt = $pdo->prepare("SELECT id FROM general_material_categories WHERE name = ? LIMIT 1");
    $stmt->execute([$general_category]);
    if (!$stmt->fetch()) { header('Location: admin.php?tab=settings&msg=invalid_general_category'); exit; }

    $material_code = generateMaterialCode($pdo);

    $stmt = $pdo->prepare("
        INSERT INTO materials (material_code, item_product, specification, unit, estimated_price, material_type,
                               product_category, roofing_product, general_category)
        VALUES (:material_code, :item_product, :specification, :unit, :estimated_price, 'general',
                NULL, NULL, :general_category)
    ");
    $stmt->execute([
        ':material_code'=>$material_code,
        ':item_product'=>$item_product, ':specification'=>$specification, ':unit'=>$unit,
        ':estimated_price'=>$estimated_price, ':general_category'=>$general_category,
    ]);
    header('Location: admin.php?tab=settings&msg=general_material_added');
    exit;
}

// =====================================================================
// Reload data
// =====================================================================
$stmt = $pdo->query("SELECT * FROM system_settings ORDER BY id ASC LIMIT 1");
$settings = $stmt->fetch(PDO::FETCH_ASSOC);

$main_materials = $pdo->query("
    SELECT * FROM materials WHERE material_type = 'main'
    ORDER BY product_category ASC, roofing_product ASC, item_product ASC, id ASC
")->fetchAll(PDO::FETCH_ASSOC);

$general_materials = $pdo->query("
    SELECT * FROM materials WHERE material_type = 'general'
    ORDER BY general_category ASC, item_product ASC, id ASC
")->fetchAll(PDO::FETCH_ASSOC);

$general_categories = $pdo->query("
    SELECT gc.id, gc.name, COALESCE(COUNT(m.id), 0) AS material_count
    FROM general_material_categories gc
    LEFT JOIN materials m ON m.general_category = gc.name AND m.material_type = 'general'
    GROUP BY gc.id, gc.name ORDER BY gc.name ASC
")->fetchAll(PDO::FETCH_ASSOC);

// =====================================================================
// Auto-seed the standard material templates.
//   3 service types × 20 roofing products × 4 roof types = 240 templates
// On every page load we check the current count; if any are missing,
// we top them up. INSERT ... ON DUPLICATE KEY UPDATE means this is
// idempotent and safe to run concurrently.
// =====================================================================
try {
    $productCount = 0;
    foreach ($roofing_products_by_category as $__prods) {
        $productCount += count($__prods);
    }
    $expectedTemplateCount = $productCount * 4 * 3;   // 240

    $currentTemplateCount = (int)$pdo->query("SELECT COUNT(*) FROM material_templates")->fetchColumn();

    if ($currentTemplateCount < $expectedTemplateCount) {
        $seedStmt = $pdo->prepare("
            INSERT INTO material_templates
                (template_name, service_type, roof_type, roofing_product, description, is_active)
            VALUES (?, ?, ?, ?, '', 1)
            ON DUPLICATE KEY UPDATE template_name = VALUES(template_name)
        ");
        $seedServices  = ['Roof Installation', 'Roof Replacement', 'Roof Repair'];
        $seedRoofTypes = ['Gable Roof', 'Hip Roof', 'Flat Roof', 'Shed Roof'];

        $pdo->beginTransaction();
        foreach ($seedServices as $st) {
            $stLabel = ucwords($st);
            foreach ($roofing_products_by_category as $cat => $products) {
                foreach ($products as $prod) {
                    foreach ($seedRoofTypes as $rt) {
                        $tname = $stLabel . '-' . $cat . '-' . $prod . '-' . $rt;
                        $seedStmt->execute([$tname, $st, $rt, $prod]);
                    }
                }
            }
        }
        $pdo->commit();
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Template auto-seed failed: ' . $e->getMessage());
}

// Material Templates + catalogs used by the template editor
$material_templates = getAllMaterialTemplates($pdo);

$available_roofing_products = $pdo->query("
    SELECT DISTINCT roofing_product FROM materials
    WHERE material_type = 'main' AND roofing_product IS NOT NULL AND roofing_product <> ''
    ORDER BY roofing_product
")->fetchAll(PDO::FETCH_COLUMN);

$all_catalog_materials = $pdo->query("
    SELECT id, item_product, specification, unit, estimated_price,
           material_type, product_category, roofing_product, general_category
    FROM materials
    ORDER BY material_type, item_product
")->fetchAll(PDO::FETCH_ASSOC);

// =====================================================================
// Messages
// =====================================================================
if (isset($_GET['msg'])) {
    $messages = [
        'company_saved' => ['text'=>'Company information saved successfully.','type'=>''],
        'company_required' => ['text'=>'Company Name is required.','type'=>'error'],
        'invalid_email' => ['text'=>'Please enter a valid company email address.','type'=>'error'],
        'invalid_company_phone' => ['text'=>'Phone Number must start with 09 and have exactly 11 digits.','type'=>'error'],
        'logo_error' => ['text'=>'The company logo could not be uploaded.','type'=>'error'],
        'logo_too_large' => ['text'=>'The company logo must be 2 MB or smaller.','type'=>'error'],
        'invalid_logo' => ['text'=>'Please upload a valid JPG, PNG, or WebP image.','type'=>'error'],
        'rules_saved' => ['text'=>'Business rules saved successfully.','type'=>''],
        'invalid_down_payment' => ['text'=>'Down payment rate must be between 0% and 100%.','type'=>'error'],
        'invalid_threshold' => ['text'=>'Grace Period must be between 0 and 120 minutes.','type'=>'error'],
        'invalid_start_time' => ['text'=>'Standard Start Time must be a valid time in HH:MM format.','type'=>'error'],
        'material_added' => ['text'=>'Main component material added successfully.','type'=>''],
        'material_updated' => ['text'=>'Main component material updated successfully.','type'=>''],
        'material_deleted' => ['text'=>'Material deleted successfully.','type'=>''],
        'material_required' => ['text'=>'Item/Product is required.','type'=>'error'],
        'invalid_unit' => ['text'=>'Please select a valid material unit.','type'=>'error'],
        'invalid_category' => ['text'=>'Please select a valid product category.','type'=>'error'],
        'invalid_roofing_product' => ['text'=>'Please select a valid roofing product / profile.','type'=>'error'],
        'product_category_mismatch' => ['text'=>'The selected roofing product does not belong to that product category.','type'=>'error'],
        'general_material_added' => ['text'=>'General roof construction material added successfully.','type'=>''],
        'general_material_deleted' => ['text'=>'General material deleted successfully.','type'=>''],
        'invalid_general_category' => ['text'=>'Please select a valid general category.','type'=>'error'],
        'category_added' => ['text'=>'Category added successfully.','type'=>''],
        'category_deleted' => ['text'=>'Category deleted successfully.','type'=>''],
        'category_exists' => ['text'=>'That category already exists.','type'=>'error'],
        'category_name_required' => ['text'=>'Category name is required.','type'=>'error'],
        'category_not_empty' => ['text'=>'This category still has materials. Delete them first, or move them to another category.','type'=>'error'],
        'category_delete_error' => ['text'=>'The category could not be deleted.','type'=>'error'],
        'general_material_updated' => ['text'=>'General material updated successfully.','type'=>'']
    ];
    if (isset($messages[$_GET['msg']])) {
        $msg = $messages[$_GET['msg']]['text'];
        $msg_type = $messages[$_GET['msg']]['type'];
    }
}

$unit_labels_map = [
    'PC'    => 'PC / Pieces',
    'LM'    => 'LM / Linear Meter',
    'SQM'   => 'SQM / Sq Meter',
    'SET'   => 'SET',
    'Tube'  => 'Tube',
    'Box'   => 'Box',
    'Roll'  => 'Roll',
    'Other' => 'Other',
];
?>

<?php if (!empty($msg)): ?>
    <div class="msg <?= htmlspecialchars($msg_type) ?>"><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>

<div id="settingsFlashMsg" class="msg" style="display:none; margin-bottom:16px;"></div>

<div class="mb-4">
    <h2 class="fw-bold mb-1" style="font-size: 20px;">System &amp; Company Settings</h2>
    <p class="mb-0" style="color: var(--fr-muted); font-size: 13.5px;">Manage company info, business rules, and the material lists.</p>
</div>

<!-- =========================================================
     Company Information
     ========================================================= -->
<div class="fr-card mb-4">
    <div class="fr-card-pad">
        <h3 class="fr-section-title">Company Information</h3>
        <p style="color: var(--fr-muted); font-size: 13px;">This info is shared throughout the system and may appear on quotations and receipts.</p>

        <form method="POST" enctype="multipart/form-data" class="mt-3">
            <?= csrf_field() ?>

            <div class="d-flex align-items-center gap-3 p-3 rounded-3 mb-4 flex-wrap" style="background: var(--fr-bg); border: 1px solid var(--fr-border);">
                <div style="width: 90px; height: 90px; border-radius: 8px; border: 1px solid var(--fr-border); background: #fff; display: flex; align-items: center; justify-content: center; overflow: hidden; flex-shrink: 0;">
                    <?php if (!empty($settings['company_logo']) && is_file(__DIR__ . '/../' . ltrim($settings['company_logo'], '/'))): ?>
                        <img src="<?= htmlspecialchars($settings['company_logo']) ?>" alt="Logo" style="width: 100%; height: 100%; object-fit: contain;">
                    <?php else: ?>
                        <span style="color: var(--fr-muted); font-size: 12px; font-weight: 700;">LOGO</span>
                    <?php endif; ?>
                </div>
                <div>
                    <div style="font-size: 22px; font-weight: 700; color: var(--fr-red);"><?= htmlspecialchars($settings['company_name']) ?></div>
                    <div style="font-size: 13px; color: var(--fr-muted);"><?= htmlspecialchars($settings['company_subtitle']) ?></div>
                    <?php if (!empty($settings['company_phone'])): ?><div style="color: var(--fr-muted);"><?= htmlspecialchars($settings['company_phone']) ?></div><?php endif; ?>
                    <?php if (!empty($settings['company_email'])): ?><div style="color: var(--fr-muted);"><?= htmlspecialchars($settings['company_email']) ?></div><?php endif; ?>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group"><label for="company_name">Company Name</label><input type="text" id="company_name" name="company_name" value="<?= htmlspecialchars($settings['company_name']) ?>" required></div>
                <div class="form-group"><label for="company_subtitle">Company Subtitle</label><input type="text" id="company_subtitle" name="company_subtitle" value="<?= htmlspecialchars($settings['company_subtitle']) ?>" placeholder="e.g. Supply and Installation"></div>
            </div>

            <div class="form-row">
                <div class="form-group"><label for="company_phone">Phone Number</label><input type="text" id="company_phone" name="company_phone" value="<?= htmlspecialchars($settings['company_phone']) ?>" maxlength="11" pattern="09[0-9]{9}" placeholder="09XXXXXXXXX" oninput="this.value=this.value.replace(/[^0-9]/g,'').slice(0,11);"></div>
                <div class="form-group"><label for="company_email">Email Address</label><input type="email" id="company_email" name="company_email" value="<?= htmlspecialchars($settings['company_email']) ?>"></div>
            </div>

            <div class="form-row">
                <div class="form-group"><label for="company_tin">TIN</label><input type="text" id="company_tin" name="company_tin" value="<?= htmlspecialchars($settings['company_tin']) ?>"></div>
                <div class="form-group">
                    <label for="company_logo">Company Logo</label>
                    <input type="file" id="company_logo" name="company_logo" class="form-control" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp">
                    <small class="form-text">JPG, PNG, or WebP. Maximum 2 MB.</small>
                </div>
            </div>

            <div class="form-group"><label for="company_address">Company Address</label><textarea id="company_address" name="company_address" rows="3"><?= htmlspecialchars($settings['company_address']) ?></textarea></div>

            <div class="form-actions">
                <button type="submit" name="save_company" class="btn btn-primary">Save Company Information</button>
            </div>
        </form>
    </div>
</div>

<!-- =========================================================
     Business Rules
     ========================================================= -->
<div class="fr-card mb-4">
    <div class="fr-card-pad">
        <h3 class="fr-section-title">Business Rules</h3>
        <p style="color: var(--fr-muted); font-size: 13px;">Configure default business rules for quotations and attendance.</p>

        <form method="POST" class="mt-3">
            <?= csrf_field() ?>
            <div class="form-row">
                <div class="form-group">
                    <label for="down_payment_rate">Down Payment Rate</label>
                    <div class="input-group">
                        <input type="number" id="down_payment_rate" name="down_payment_rate" min="0" max="100" step="0.01" value="<?= htmlspecialchars($settings['down_payment_rate']) ?>" required>
                        <span class="input-group-text">%</span>
                    </div>
                    <small class="form-text">Between 0% and 100%.</small>
                </div>

                <?php
                $late_threshold_minutes = 0;
                if (!empty($settings['late_threshold'])) {
                    $parts = explode(':', $settings['late_threshold']);
                    $h = isset($parts[0]) ? (int)$parts[0] : 0;
                    $m = isset($parts[1]) ? (int)$parts[1] : 0;
                    $late_threshold_minutes = ($h * 60) + $m;
                }
                $standard_start_display = '08:00';
                if (!empty($settings['standard_start_time'])) {
                    $standard_start_display = substr($settings['standard_start_time'], 0, 5);
                }
                ?>

                <div class="form-group">
                    <label for="standard_start_time">Standard Start Time</label>
                    <input type="time" id="standard_start_time" name="standard_start_time" value="<?= htmlspecialchars($standard_start_display) ?>" required>
                    <small class="form-text">Official start of the work day (e.g. 08:00).</small>
                </div>
            </div>

            <div class="form-group" style="max-width: 260px;">
                <label for="late_threshold_minutes">Grace Period After Start</label>
                <div class="input-group">
                    <input type="number" id="late_threshold_minutes" name="late_threshold_minutes" min="0" max="120" step="1" value="<?= htmlspecialchars($late_threshold_minutes) ?>" required>
                    <span class="input-group-text">min</span>
                </div>
                <small class="form-text">How many minutes after start time an employee may check in before being marked Late.</small>
            </div>

            <div class="row g-3 mt-2">
                <div class="col-md-4"><div class="p-3 rounded-3" style="background: var(--fr-bg); border: 1px solid var(--fr-border);"><div style="font-size: 12px; color: var(--fr-muted); margin-bottom: 4px;">Standard Start</div><strong><?= htmlspecialchars($standard_start_display) ?></strong></div></div>
                <div class="col-md-4"><div class="p-3 rounded-3" style="background: var(--fr-bg); border: 1px solid var(--fr-border);"><div style="font-size: 12px; color: var(--fr-muted); margin-bottom: 4px;">Grace Period</div><strong><?= (int)$late_threshold_minutes ?> min</strong></div></div>
                <div class="col-md-4"><div class="p-3 rounded-3" style="background: var(--fr-bg); border: 1px solid var(--fr-border);"><div style="font-size: 12px; color: var(--fr-muted); margin-bottom: 4px;">Current Threshold</div><strong><?= htmlspecialchars(substr($settings['late_threshold'], 0, 5)) ?></strong></div></div>
            </div>

            <div class="form-actions">
                <button type="submit" name="save_business_rules" class="btn btn-primary">Save Business Rules</button>
            </div>
        </form>
    </div>
</div>

<!-- =========================================================
     Service & Property Configuration
     ========================================================= -->
<div class="fr-card mb-4">
    <div class="fr-card-pad">
        <h3 class="fr-section-title">Service &amp; Property Configuration</h3>
        <p style="color: var(--fr-muted); font-size: 13px; margin: 0 0 16px;">
            Predefined system options for services, property types, and roof geometry.
            Admin can edit descriptions and toggle active status.
        </p>

        <div class="spc-tabs" role="tablist">
            <button type="button" class="spc-tab is-active" data-tab="service" onclick="spcSwitchTab(this)">
                <i class="bi bi-tools"></i><span>Service Types</span>
            </button>
            <button type="button" class="spc-tab" data-tab="property" onclick="spcSwitchTab(this)">
                <i class="bi bi-building"></i><span>Property Types</span>
            </button>
            <button type="button" class="spc-tab" data-tab="roof" onclick="spcSwitchTab(this)">
                <i class="bi bi-house"></i><span>Roof Types</span>
            </button>
        </div>

        <?php
        $spcHeadings = [
            'service'  => 'Service Name',
            'property' => 'Property Type',
            'roof'     => 'Roof Type',
        ];
        $spcFirst = true;
        foreach (['service','property','roof'] as $spcType):
            $spcOptions = getConfigurationOptions($pdo, $spcType);
        ?>
            <div class="spc-panel<?= $spcFirst ? ' is-active' : '' ?>" data-panel="<?= $spcType ?>">
                <div class="spc-table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th style="width: 26%;"><?= htmlspecialchars($spcHeadings[$spcType]) ?></th>
                                <th>Description</th>
                                <th style="width: 110px; text-align: center;">Status</th>
                                <th style="width: 220px; text-align: right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($spcOptions)): ?>
                            <tr>
                                <td colspan="4" class="text-center py-5" style="color: var(--fr-muted);">
                                    No options configured.
                                </td>
                            </tr>
                        <?php else: foreach ($spcOptions as $opt): ?>
                            <tr data-spc-id="<?= (int)$opt['id'] ?>"
                                data-spc-type="<?= htmlspecialchars($spcType) ?>">
                                <td class="spc-name">
                                    <!-- REVISION: Capitalize labels visually; preserve stored configuration names. -->
                                    <strong style="text-transform: capitalize;">
                                        <?= htmlspecialchars($opt['name']) ?>
                                    </strong>
                                </td>
                                <td class="spc-description" style="color: var(--fr-muted);">
                                    <?= htmlspecialchars($opt['description'] ?: '—') ?>
                                </td>
                                <td style="text-align: center;">
                                    <span class="spc-status-badge <?= $opt['is_active'] ? 'is-active' : 'is-inactive' ?>">
                                        <i class="bi <?= $opt['is_active'] ? 'bi-check-circle' : 'bi-slash-circle' ?>"></i>
                                        <span><?= $opt['is_active'] ? 'Active' : 'Inactive' ?></span>
                                    </span>
                                </td>
                                <td style="text-align: right;">
                                    <div class="spc-actions">
                                        <button type="button" class="spc-btn"
                                                onclick="spcOpenEdit(<?= (int)$opt['id'] ?>)">
                                            <i class="bi bi-pencil"></i><span>Edit</span>
                                        </button>
                                        <button type="button"
                                                class="spc-btn spc-btn-toggle <?= $opt['is_active'] ? 'is-deactivate' : 'is-activate' ?>"
                                                onclick="spcToggleStatus(<?= (int)$opt['id'] ?>)">
                                            <i class="bi <?= $opt['is_active'] ? 'bi-power' : 'bi-check-circle' ?>"></i>
                                            <span><?= $opt['is_active'] ? 'Deactivate' : 'Activate' ?></span>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php $spcFirst = false; endforeach; ?>

        <div class="spc-footer-note">
            <i class="bi bi-info-circle"></i>
            <span>
                <strong>Predefined System Options:</strong> These predefined options are integrated with
                Client Booking, Foreman Site Inspection, and Quotation Generation. Deactivating an option
                hides it from new client bookings and inspections without invalidating existing records.
            </span>
        </div>
    </div>
</div>

<!-- Edit Configuration Modal -->
<div id="spcEditModal" class="ad-modal">
    <div class="ad-modal-content" style="max-width: 560px;">
        <button type="button" class="close" onclick="spcCloseEdit()">&times;</button>
        <h2 id="spcEditTitle">Edit Option</h2>
        <form id="spcEditForm" onsubmit="spcSaveEdit(event)">
            <input type="hidden" id="spcEditId">
            <div class="form-group">
                <label for="spcEditName">Name</label>
                <input type="text" id="spcEditName" class="form-control" maxlength="100" required>
            </div>
            <div class="form-group">
                <label for="spcEditDesc">Description</label>
                <textarea id="spcEditDesc" class="form-control" rows="3" maxlength="500"
                          placeholder="Short description shown on the settings table."></textarea>
            </div>
            <div class="form-actions">
                <button type="button" class="btn btn-outline-secondary" onclick="spcCloseEdit()">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- =========================================================
     Standard Material Templates
     ========================================================= -->
<div class="fr-card mb-4">
    <div class="fr-card-pad">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
            <div>
                <h3 class="fr-section-title mb-1">Standard Material Templates</h3>
                <p style="color: var(--fr-muted); font-size: 13px; margin: 0;">
                    Every possible combination of Service Type, Product Category, Roofing Product / Profile, and Roof Type.
                    Configure which materials the foreman sees as suggestions for each combination.
                </p>
            </div>
            <span id="templateCountInfo" style="font-size: 12px; color: var(--fr-muted); font-weight: 600;"></span>
        </div>

        <!-- Filters -->
        <div class="d-flex align-items-center gap-3 flex-wrap mb-3 p-2 rounded-3" style="background: var(--fr-bg); border: 1px solid var(--fr-border);">
            <div class="d-flex align-items-center gap-2">
                <label for="tmpl_filter_service" style="font-size: 12.5px; font-weight: 600; color: var(--fr-text-soft); margin: 0;">Service Type:</label>
                <select id="tmpl_filter_service" class="form-select form-select-sm" style="width: auto; min-width: 150px;" onchange="applyTemplateFilter()">
                    <option value="all">All</option>
                    <option value="Roof Installation">Roof Installation</option>
                    <option value="Roof Replacement">Roof Replacement</option>
                    <option value="Roof Repair">Roof Repair</option>
                </select>
            </div>
            <div class="d-flex align-items-center gap-2">
                <label for="tmpl_filter_category" style="font-size: 12.5px; font-weight: 600; color: var(--fr-text-soft); margin: 0;">Product Category:</label>
                <select id="tmpl_filter_category" class="form-select form-select-sm" style="width: auto; min-width: 180px;" onchange="onTemplateFilterCategoryChange()">
                    <option value="all">All</option>
                    <?php foreach ($product_categories as $key => $label): ?>
                        <option value="<?= htmlspecialchars($key) ?>"><?= htmlspecialchars($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="d-flex align-items-center gap-2">
                <label for="tmpl_filter_product" style="font-size: 12.5px; font-weight: 600; color: var(--fr-text-soft); margin: 0;">Roofing Product / Profile:</label>
                <select id="tmpl_filter_product" class="form-select form-select-sm" style="width: auto; min-width: 200px;" onchange="applyTemplateFilter()">
                    <option value="all">All Products</option>
                </select>
            </div>
            <div class="d-flex align-items-center gap-2">
                <label for="tmpl_filter_roof" style="font-size: 12.5px; font-weight: 600; color: var(--fr-text-soft); margin: 0;">Roof Type:</label>
                <select id="tmpl_filter_roof" class="form-select form-select-sm" style="width: auto; min-width: 130px;" onchange="applyTemplateFilter()">
                    <option value="all">All</option>
                    <option value="Gable Roof">Gable Roof</option>
                    <option value="Hip Roof">Hip Roof</option>
                    <option value="Flat Roof">Flat Roof</option>
                    <option value="Shed Roof">Shed Roof</option>
                </select>
            </div>
        </div>

        <!-- Scrollable template list -->
        <div class="table-wrap template-table-wrap" style="box-shadow: none;">
            <table>
                <thead>
                    <tr>
                        <th>Template Name</th>
                        <th style="text-align: center; width: 100px;">Items</th>
                        <th style="text-align: center; width: 90px;">Status</th>
                        <th style="text-align: center; width: 200px;">Actions</th>
                    </tr>
                </thead>
                <tbody id="templatesBody">
                    <?php if (empty($material_templates)): ?>
                        <tr><td colspan="4" class="text-center py-5" style="color: var(--fr-muted);">No material templates configured yet.</td></tr>
                    <?php else: foreach ($material_templates as $tmpl):
                        $tmplCategoryKey   = $roofing_product_to_category[$tmpl['roofing_product']] ?? '';
                        $tmplCategoryLabel = $product_categories[$tmplCategoryKey] ?? $tmplCategoryKey;
                        $tmplServiceLabel  = ucwords($tmpl['service_type']);
                        $tmplItemCount     = (int)$tmpl['item_count'];
                    ?>
                        <tr class="template-row"
                            id="tmpl-row-<?= (int)$tmpl['id'] ?>"
                            data-service-type="<?= htmlspecialchars($tmpl['service_type']) ?>"
                            data-product-category="<?= htmlspecialchars($tmplCategoryKey) ?>"
                            data-roofing-product="<?= htmlspecialchars($tmpl['roofing_product']) ?>"
                            data-roof-type="<?= htmlspecialchars($tmpl['roof_type']) ?>">
                
                            <!-- Template name — product on top, metadata line below -->
                            <td>
                                <div class="template-name-product">
                                    <?= htmlspecialchars($tmpl['roofing_product']) ?>
                                </div>
                                <div class="template-name-meta">
                                    <span><?= htmlspecialchars($tmplServiceLabel) ?></span>
                                    <?php if ($tmplCategoryLabel !== ''): ?>
                                        <span class="meta-dot">·</span>
                                        <span class="template-meta-pill"><?= htmlspecialchars($tmplCategoryLabel) ?></span>
                                    <?php endif; ?>
                                    <span class="meta-dot">·</span>
                                    <span class="template-meta-pill"><?= htmlspecialchars($tmpl['roof_type']) ?></span>
                                </div>
                            </td>
                
                            <!-- Item count chip -->
                            <td style="text-align: center;">
                                <button type="button"
                                        class="tmpl-count-chip<?= $tmplItemCount === 0 ? ' is-empty' : '' ?>"
                                        onclick="openManageTemplateMaterials(<?= (int)$tmpl['id'] ?>)"
                                        title="Manage materials for this template">
                                    <i class="bi bi-box-seam"></i>
                                    <span>
                                        <?php if ($tmplItemCount === 0): ?>
                                            No items
                                        <?php elseif ($tmplItemCount === 1): ?>
                                            1 item
                                        <?php else: ?>
                                            <?= $tmplItemCount ?> items
                                        <?php endif; ?>
                                    </span>
                                </button>
                            </td>
                
                            <!-- Status badge (unchanged) -->
                            <td style="text-align: center;">
                                <span class="fr-badge <?= $tmpl['is_active'] ? 'active' : 'cancelled' ?>" id="tmpl-status-<?= (int)$tmpl['id'] ?>">
                                    <?= $tmpl['is_active'] ? 'Active' : 'Inactive' ?>
                                </span>
                            </td>
                
                            <!-- Action buttons — original solid style -->
                            <td class="actions">
                                <button type="button" class="action-btn edit"
                                        onclick="openManageTemplateMaterials(<?= (int)$tmpl['id'] ?>)">Materials</button>
                                <button type="button"
                                        class="action-btn <?= $tmpl['is_active'] ? 'reject' : 'approve' ?>"
                                        id="tmpl-toggle-<?= (int)$tmpl['id'] ?>"
                                        onclick="toggleTemplateStatus(<?= (int)$tmpl['id'] ?>)">
                                    <?= $tmpl['is_active'] ? 'Disable' : 'Enable' ?>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    <tr id="templateNoResultsRow" style="display:none;"><td colspan="4" class="text-center py-5" style="color: var(--fr-muted);">No templates match the current filter.</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- =========================================================
     Main Component Materials
     ========================================================= -->
<div class="fr-card mb-4">
    <div class="fr-card-pad">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
            <div>
                <h3 class="fr-section-title mb-1">Main Component Materials</h3>
                <p style="color: var(--fr-muted); font-size: 13px; margin: 0;">Materials tied to a specific Roofing Product / Profile.</p>
            </div>
            <button type="button" class="btn btn-primary" onclick="document.getElementById('addMaterialModal').classList.add('show')">
                <i class="bi bi-plus-lg me-1"></i>Add Main Material
            </button>
        </div>

        <div class="d-flex align-items-center gap-3 flex-wrap mb-3">
            <div class="d-flex align-items-center gap-2">
                <label for="main_filter_category" style="font-size: 12.5px; font-weight: 600; color: var(--fr-text-soft); margin: 0;">Product Category:</label>
                <select id="main_filter_category" class="form-select form-select-sm" style="width: auto;" onchange="onMainFilterCategoryChange()">
                    <option value="all">All</option>
                    <?php foreach ($product_categories as $key => $label): ?>
                        <option value="<?= htmlspecialchars($key) ?>"><?= htmlspecialchars($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="d-flex align-items-center gap-2">
                <label for="main_filter_product" style="font-size: 12.5px; font-weight: 600; color: var(--fr-text-soft); margin: 0;">Roofing Product / Profile:</label>
                <select id="main_filter_product" class="form-select form-select-sm" style="width: auto; min-width: 200px;" onchange="applyMainFilter()">
                    <option value="all">All Products</option>
                </select>
            </div>
        </div>

        <div class="table-wrap" style="box-shadow: none;">
            <table>
                <thead>
                    <tr>
                        <th style="width: 100px;">Material ID</th>
                        <th>Item / Product</th>
                        <th>Product Category</th>
                        <th>Roofing Product / Profile</th>
                        <th>Specification</th>
                        <th>Unit</th>
                        <th>Estimated Price</th>
                        <th style="width: 140px;">Actions</th>
                    </tr>
                </thead>
                <tbody id="mainMaterialsBody">
                <?php if (empty($main_materials)): ?>
                    <tr id="mainEmptyRow"><td colspan="8" class="text-center py-5" style="color: var(--fr-muted);">No main component materials have been added yet.</td></tr>
                <?php else: foreach ($main_materials as $mat):
                    $cat_label  = $product_categories[$mat['product_category']] ?? null;
                    $prod_label = $mat['roofing_product'] ?: null;
                ?>
                    <tr class="main-material-row"
                        data-material-id="<?= (int)$mat['id'] ?>"
                        data-product-category="<?= htmlspecialchars($mat['product_category'] ?? '') ?>"
                        data-roofing-product="<?= htmlspecialchars($mat['roofing_product'] ?? '') ?>">
                        <td><strong><?= htmlspecialchars($mat['material_code'] ?? '—') ?></strong></td>
                        <td><strong><?= htmlspecialchars($mat['item_product']) ?></strong></td>
                        <td>
                            <?php if ($cat_label): ?>
                                <span class="fr-badge followup" style="font-weight: 600;"><?= htmlspecialchars($cat_label) ?></span>
                            <?php else: ?>
                                <span class="fr-badge cancelled" style="font-weight: 600;">Uncategorized</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($prod_label): ?>
                                <strong><?= htmlspecialchars($prod_label) ?></strong>
                            <?php else: ?>
                                <span style="color: var(--fr-muted); font-style: italic;">—</span>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($mat['specification'] ?: '—') ?></td>
                        <td><?= htmlspecialchars($unit_labels_map[$mat['unit']] ?? $mat['unit']) ?></td>
                        <td>₱<?= number_format((float)($mat['estimated_price'] ?? 0), 2) ?></td>
                        <td class="actions">
                            <button type="button" class="action-btn edit"
                                    onclick='openMaterialEdit(
                                        <?= (int)$mat['id'] ?>,
                                        <?= json_encode($mat['item_product'], JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_TAG|JSON_HEX_AMP) ?>,
                                        <?= json_encode($mat['specification'], JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_TAG|JSON_HEX_AMP) ?>,
                                        <?= json_encode($mat['unit'], JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_TAG|JSON_HEX_AMP) ?>,
                                        <?= json_encode((float)($mat['estimated_price'] ?? 0), JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_TAG|JSON_HEX_AMP) ?>,
                                        <?= json_encode($mat['product_category'], JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_TAG|JSON_HEX_AMP) ?>,
                                        <?= json_encode($mat['roofing_product'], JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_TAG|JSON_HEX_AMP) ?>
                                    )'>Edit</button>
                            <form method="POST" action="admin.php?tab=settings" class="d-inline settings-ajax-form"
                                  data-action="delete_material" data-confirm="Delete this material?">
                                <?= csrf_field() ?>
                                <input type="hidden" name="delete_material" value="<?= (int)$mat['id'] ?>">
                                <input type="hidden" name="return_to" value="main">
                                <button type="submit" class="action-btn cancel-btn">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                <tr id="mainNoResultsRow" style="display:none;"><td colspan="8" class="text-center py-5" style="color: var(--fr-muted);">No materials match the current filter.</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- =========================================================
     General Roof Construction Materials
     ========================================================= -->
<div class="fr-card">
    <div class="fr-card-pad">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
            <div>
                <h3 class="fr-section-title mb-1">General Roof Construction Materials</h3>
                <p style="color: var(--fr-muted); font-size: 13px; margin: 0;">Non-product-specific items such as fasteners, sealants, and consumables.</p>
            </div>
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <button type="button" class="btn btn-outline-secondary" onclick="document.getElementById('manageCategoriesModal').classList.add('show')">
                    <i class="bi bi-tags me-1"></i>Manage Categories
                </button>
                <button type="button" class="btn btn-primary" onclick="openAddGeneralMaterial()"
                        <?= empty($general_categories) ? 'disabled title="Add a category first"' : '' ?>>
                    <i class="bi bi-plus-lg me-1"></i>Add General Material
                </button>
            </div>
        </div>

        <?php if (empty($general_categories)): ?>
            <div class="text-center py-4 mb-3" style="color: var(--fr-muted); border: 1px dashed var(--fr-border); border-radius: 8px; font-size: 13px;">
                No general categories yet. Click <strong>Manage Categories</strong> to add one.
            </div>
        <?php else: ?>
            <div class="d-flex align-items-center gap-2 flex-wrap mb-3">
                <label for="general_filter_category" style="font-size: 12.5px; font-weight: 600; color: var(--fr-text-soft); margin: 0;">Category:</label>
                <select id="general_filter_category" class="form-select form-select-sm" style="width: auto;" onchange="applyGeneralFilter()">
                    <option value="all">All</option>
                    <?php foreach ($general_categories as $cat): ?>
                        <option value="<?= htmlspecialchars($cat['name']) ?>"><?= htmlspecialchars($cat['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>

        <div class="table-wrap" style="box-shadow: none;">
            <table>
                <thead>
                    <tr>
                        <th style="width: 100px;">Material ID</th>
                        <th>Item / Product</th>
                        <th>Category</th>
                        <th>Specification</th>
                        <th>Unit</th>
                        <th>Estimated Price</th>
                        <th style="width: 100px;">Actions</th>
                    </tr>
                </thead>
                <tbody id="generalMaterialsBody">
                <?php if (empty($general_materials)): ?>
                    <tr id="generalEmptyRow"><td colspan="7" class="text-center py-5" style="color: var(--fr-muted);">No general materials have been added yet.</td></tr>
                <?php else: foreach ($general_materials as $mat): ?>
                    <tr class="general-material-row"
                        data-material-id="<?= (int)$mat['id'] ?>"
                        data-general-category="<?= htmlspecialchars($mat['general_category'] ?? '') ?>">
                        <td><strong><?= htmlspecialchars($mat['material_code'] ?? '—') ?></strong></td>
                        <td><strong><?= htmlspecialchars($mat['item_product']) ?></strong></td>
                        <td><span class="fr-badge scheduled" style="font-weight: 600;"><?= htmlspecialchars($mat['general_category'] ?: '—') ?></span></td>
                        <td><?= htmlspecialchars($mat['specification'] ?: '—') ?></td>
                        <td><?= htmlspecialchars($unit_labels_map[$mat['unit']] ?? $mat['unit']) ?></td>
                        <td>₱<?= number_format((float)($mat['estimated_price'] ?? 0), 2) ?></td>
                        <td class="actions">
                            <button type="button" class="action-btn edit"
                                    onclick='openGeneralMaterialEdit(
                                        <?= (int)$mat['id'] ?>,
                                        <?= json_encode($mat['item_product'], JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_TAG|JSON_HEX_AMP) ?>,
                                        <?= json_encode($mat['specification'], JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_TAG|JSON_HEX_AMP) ?>,
                                        <?= json_encode($mat['unit'], JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_TAG|JSON_HEX_AMP) ?>,
                                        <?= json_encode((float)($mat['estimated_price'] ?? 0), JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_TAG|JSON_HEX_AMP) ?>,
                                        <?= json_encode($mat['general_category'], JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_TAG|JSON_HEX_AMP) ?>
                                    )'>Edit</button>
                            <form method="POST" action="admin.php?tab=settings" class="d-inline settings-ajax-form"
                                  data-action="delete_material" data-confirm="Delete this material?">
                                <?= csrf_field() ?>
                                <input type="hidden" name="delete_material" value="<?= (int)$mat['id'] ?>">
                                <input type="hidden" name="return_to" value="general">
                                <button type="submit" class="action-btn cancel-btn">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                <tr id="generalNoResultsRow" style="display:none;"><td colspan="7" class="text-center py-5" style="color: var(--fr-muted);">No materials in this category.</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- =========================================================
     Add Main Material Modal
     ========================================================= -->
<div id="addMaterialModal" class="ad-modal">
    <div class="ad-modal-content" style="max-width: 600px;">
        <button type="button" class="close" onclick="document.getElementById('addMaterialModal').classList.remove('show')">&times;</button>
        <h2>Add Main Component Material</h2>
        <form method="POST" class="settings-ajax-form"
              data-action="add_material" data-reset="1" data-close-modal="addMaterialModal">
            <?= csrf_field() ?>
            <div class="form-group"><label for="add_item_product">Item / Product</label><input type="text" id="add_item_product" name="item_product" required></div>
            <div class="form-group">
                <label for="add_product_category">Product Category</label>
                <select id="add_product_category" name="product_category" required onchange="onAddCategoryChange()">
                    <?php foreach ($product_categories as $key => $label): ?>
                        <option value="<?= htmlspecialchars($key) ?>"><?= htmlspecialchars($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group"><label for="add_roofing_product">Roofing Product / Profile</label><select id="add_roofing_product" name="roofing_product" required></select></div>
            <div class="form-group"><label for="add_specification">Specification</label><input type="text" id="add_specification" name="specification" placeholder="e.g. 0.5mm thick, 4m length"></div>
            <div class="form-group">
                <label for="add_unit">Unit</label>
                <select id="add_unit" name="unit" required>
                    <option value="PC">PC / Pieces</option>
                    <option value="LM">LM / Linear Meter</option>
                    <option value="SQM">SQM / Sq Meter</option>
                    <option value="SET">SET</option>
                    <option value="Tube">Tube</option>
                    <option value="Box">Box</option>
                    <option value="Roll">Roll</option>
                    <option value="Other">Other</option>
                </select>
            </div>
            <div class="form-group"><label for="add_estimated_price">Estimated Price (₱)</label><input type="number" id="add_estimated_price" name="estimated_price" min="0" step="0.01" value="0"></div>
            <div class="form-actions">
                <button type="button" class="btn btn-outline-secondary" onclick="document.getElementById('addMaterialModal').classList.remove('show')">Cancel</button>
                <button type="submit" name="add_material" class="btn btn-primary">Add Material</button>
            </div>
        </form>
    </div>
</div>

<!-- =========================================================
     Edit Main Material Modal
     ========================================================= -->
<div id="editMaterialModal" class="ad-modal">
    <div class="ad-modal-content" style="max-width: 600px;">
        <button type="button" class="close" onclick="document.getElementById('editMaterialModal').classList.remove('show')">&times;</button>
        <h2>Edit Main Component Material</h2>
        <form method="POST" class="settings-ajax-form"
              data-action="edit_material" data-close-modal="editMaterialModal">
            <?= csrf_field() ?>
            <input type="hidden" name="material_id" id="edit_material_id">
            <div class="form-group"><label for="edit_item_product">Item / Product</label><input type="text" id="edit_item_product" name="item_product" required></div>
            <div class="form-group">
                <label for="edit_product_category">Product Category</label>
                <select id="edit_product_category" name="product_category" required onchange="onEditCategoryChange()">
                    <?php foreach ($product_categories as $key => $label): ?>
                        <option value="<?= htmlspecialchars($key) ?>"><?= htmlspecialchars($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group"><label for="edit_roofing_product">Roofing Product / Profile</label><select id="edit_roofing_product" name="roofing_product" required></select></div>
            <div class="form-group"><label for="edit_specification">Specification</label><input type="text" id="edit_specification" name="specification"></div>
            <div class="form-group">
                <label for="edit_unit">Unit</label>
                <select id="edit_unit" name="unit" required>
                    <option value="PC">PC / Pieces</option>
                    <option value="LM">LM / Linear Meter</option>
                    <option value="SQM">SQM / Sq Meter</option>
                    <option value="SET">SET</option>
                    <option value="Tube">Tube</option>
                    <option value="Box">Box</option>
                    <option value="Roll">Roll</option>
                    <option value="Other">Other</option>
                </select>
            </div>
            <div class="form-group"><label for="edit_estimated_price">Estimated Price (₱)</label><input type="number" id="edit_estimated_price" name="estimated_price" min="0" step="0.01" value="0"></div>
            <div class="form-actions">
                <button type="button" class="btn btn-outline-secondary" onclick="document.getElementById('editMaterialModal').classList.remove('show')">Cancel</button>
                <button type="submit" name="edit_material" class="btn btn-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- =========================================================
     Edit General Material Modal
     ========================================================= -->
<div id="editGeneralMaterialModal" class="ad-modal">
    <div class="ad-modal-content" style="max-width: 600px;">
        <button type="button" class="close" onclick="document.getElementById('editGeneralMaterialModal').classList.remove('show')">&times;</button>
        <h2>Edit General Roof Construction Material</h2>
        <form method="POST" class="settings-ajax-form"
              data-action="edit_general_material" data-close-modal="editGeneralMaterialModal">
            <?= csrf_field() ?>
            <input type="hidden" name="material_id" id="edit_general_material_id">
            <div class="form-group"><label for="edit_general_item_product">Item / Product</label><input type="text" id="edit_general_item_product" name="item_product" required></div>
            <div class="form-group">
                <label for="edit_general_category_select">Category</label>
                <select id="edit_general_category_select" name="general_category" required>
                    <?php foreach ($general_categories as $cat): ?>
                        <option value="<?= htmlspecialchars($cat['name']) ?>"><?= htmlspecialchars($cat['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group"><label for="edit_general_specification">Specification</label><input type="text" id="edit_general_specification" name="specification"></div>
            <div class="form-group">
                <label for="edit_general_unit">Unit</label>
                <select id="edit_general_unit" name="unit" required>
                    <option value="PC">PC / Pieces</option>
                    <option value="LM">LM / Linear Meter</option>
                    <option value="SQM">SQM / Sq Meter</option>
                    <option value="SET">SET</option>
                    <option value="Tube">Tube</option>
                    <option value="Box">Box</option>
                    <option value="Roll">Roll</option>
                    <option value="Other">Other</option>
                </select>
            </div>
            <div class="form-group"><label for="edit_general_estimated_price">Estimated Price (₱)</label><input type="number" id="edit_general_estimated_price" name="estimated_price" min="0" step="0.01" value="0"></div>
            <div class="form-actions">
                <button type="button" class="btn btn-outline-secondary" onclick="document.getElementById('editGeneralMaterialModal').classList.remove('show')">Cancel</button>
                <button type="submit" name="edit_general_material" class="btn btn-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- =========================================================
     Add General Material Modal
     ========================================================= -->
<div id="addGeneralMaterialModal" class="ad-modal">
    <div class="ad-modal-content" style="max-width: 600px;">
        <button type="button" class="close" onclick="document.getElementById('addGeneralMaterialModal').classList.remove('show')">&times;</button>
        <h2>Add General Roof Construction Material</h2>
        <form method="POST" class="settings-ajax-form"
              data-action="add_general_material" data-reset="1" data-close-modal="addGeneralMaterialModal">
            <?= csrf_field() ?>
            <div class="form-group"><label for="add_general_item_product">Item / Product</label><input type="text" id="add_general_item_product" name="item_product" required></div>
            <div class="form-group">
                <label for="add_general_category_select">Category</label>
                <select id="add_general_category_select" name="general_category" required>
                    <?php foreach ($general_categories as $cat): ?>
                        <option value="<?= htmlspecialchars($cat['name']) ?>"><?= htmlspecialchars($cat['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group"><label for="add_general_specification">Specification</label><input type="text" id="add_general_specification" name="specification" placeholder="e.g. 100pcs per box"></div>
            <div class="form-group">
                <label for="add_general_unit">Unit</label>
                <select id="add_general_unit" name="unit" required>
                    <option value="PC">PC / Pieces</option>
                    <option value="LM">LM / Linear Meter</option>
                    <option value="SQM">SQM / Sq Meter</option>
                    <option value="SET">SET</option>
                    <option value="Tube">Tube</option>
                    <option value="Box">Box</option>
                    <option value="Roll">Roll</option>
                    <option value="Other">Other</option>
                </select>
            </div>
            <div class="form-group"><label for="add_general_estimated_price">Estimated Price (₱)</label><input type="number" id="add_general_estimated_price" name="estimated_price" min="0" step="0.01" value="0"></div>
            <div class="form-actions">
                <button type="button" class="btn btn-outline-secondary" onclick="document.getElementById('addGeneralMaterialModal').classList.remove('show')">Cancel</button>
                <button type="submit" name="add_general_material" class="btn btn-primary">Add Material</button>
            </div>
        </form>
    </div>
</div>

<!-- =========================================================
     Manage General Categories Modal
     ========================================================= -->
<div id="manageCategoriesModal" class="ad-modal">
    <div class="ad-modal-content" style="max-width: 640px;">
        <button type="button" class="close" onclick="document.getElementById('manageCategoriesModal').classList.remove('show')">&times;</button>
        <h2>Manage General Categories</h2>
        <p style="color: var(--fr-muted); font-size: 13px;">Add or remove categories for General Roof Construction Materials. A category must be empty before it can be deleted.</p>

        <form method="POST" class="mb-4 settings-ajax-form" data-action="add_general_category" data-reset="1">
            <?= csrf_field() ?>
            <div class="d-flex gap-2">
                <input type="text" name="category_name" class="form-control" placeholder="e.g. Fasteners, Sealants, Flashing" maxlength="100" required>
                <button type="submit" name="add_general_category" class="btn btn-primary text-nowrap"><i class="bi bi-plus-lg me-1"></i>Add</button>
            </div>
        </form>

        <div class="table-wrap" style="box-shadow: none;">
            <table>
                <thead><tr><th>Category</th><th style="width: 120px;">Materials</th><th style="width: 110px;">Action</th></tr></thead>
                <tbody>
                <?php if (empty($general_categories)): ?>
                    <tr><td colspan="3" class="text-center py-4" style="color: var(--fr-muted);">No categories yet.</td></tr>
                <?php else: foreach ($general_categories as $cat): $count = (int)$cat['material_count']; ?>
                    <tr data-category-id="<?= (int)$cat['id'] ?>">
                        <td><strong><?= htmlspecialchars($cat['name']) ?></strong></td>
                        <td><?= $count ?></td>
                        <td>
                            <form method="POST" action="admin.php?tab=settings" class="d-inline settings-ajax-form"
                                  data-action="delete_general_category" data-confirm="Delete this category?">
                                <?= csrf_field() ?>
                                <input type="hidden" name="category_id" value="<?= (int)$cat['id'] ?>">
                                <button type="submit" name="delete_general_category" class="action-btn cancel-btn"
                                        <?= $count > 0 ? 'disabled title="Category must be empty before it can be deleted"' : '' ?>>Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>

        <div class="form-actions">
            <button type="button" class="btn btn-outline-secondary" onclick="document.getElementById('manageCategoriesModal').classList.remove('show')">Close</button>
        </div>
    </div>
</div>

<!-- Manage Template Materials Modal -->
<div id="manageTemplateMaterialsModal" class="ad-modal">
    <div class="ad-modal-content" style="max-width: 720px; max-height: 90vh; display: flex; flex-direction: column;">
        <button type="button" class="close"
                onclick="document.getElementById('manageTemplateMaterialsModal').classList.remove('show')">&times;</button>

        <h2 id="mtm_title">Template Materials</h2>
        <div id="mtm_subtitle" style="font-size: 13px; color: var(--fr-muted); margin-bottom: 12px;"></div>

        <div class="d-flex justify-content-between align-items-center gap-2 mb-2 flex-wrap">
            <div id="mtm_count" style="font-size: 12.5px; color: var(--fr-muted); font-weight: 600;"></div>
            <input type="text" id="mtm_search" class="form-control form-control-sm"
                   style="max-width: 220px;" placeholder="Search materials…"
                   oninput="filterTemplateMaterials()">
        </div>

        <div class="tmpl-pick-list" id="mtm_list">
            <?php
            $mtmMain = array_values(array_filter($all_catalog_materials, fn($m) => $m['material_type'] === 'main'));
            $mtmGen  = array_values(array_filter($all_catalog_materials, fn($m) => $m['material_type'] === 'general'));
        
            $defaultRuleFor = function ($m) {
                if ($m['material_type'] === 'main') return 'fixed_1';
                $unit = strtoupper((string)$m['unit']);
                if ($unit === 'SQM') return 'roof_area';
                if ($unit === 'LM')  return 'ridge_length';
                return 'fixed_1';
            };
            $ruleOptions = [
                'roof_area'      => 'Roof Area (m²)',
                'ridge_length'   => 'Ridge Length (m)',
                'eave_length'    => 'Eave Length (m)',
                'fasteners_area' => 'Fasteners / 16 m²',
                'fixed_1'        => 'Fixed 1',
                'fixed_2'        => 'Fixed 2',
                'fixed_4'        => 'Fixed 4',
                'manual'         => 'Manual',
            ];
            ?>
        
            <?php if (empty($all_catalog_materials)): ?>
                <div class="text-center py-5" style="color: var(--fr-muted);">
                    No materials in the catalog yet. Add some under <em>Main Component Materials</em> below first.
                </div>
            <?php else: ?>
        
                <?php if (!empty($mtmMain)): ?>
                    <div class="tmpl-pick-section" data-section="main">
                        <div class="tmpl-pick-section-title" data-section="main">
                            <span>Main Component Materials</span>
                            <div class="tmpl-pick-filters">
                                <select id="mtm_filter_main_category" class="form-select tmpl-pick-filter"
                                        onchange="onMtmMainCategoryChange()">
                                    <option value="all">All Categories</option>
                                    <?php foreach ($product_categories as $key => $label): ?>
                                        <option value="<?= htmlspecialchars($key) ?>"><?= htmlspecialchars($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <select id="mtm_filter_main_product" class="form-select tmpl-pick-filter"
                                        onchange="filterTemplateMaterials()">
                                    <option value="all">All Products</option>
                                </select>
                            </div>
                        </div>
        
                        <?php foreach ($mtmMain as $cm):
                            $searchBlob = strtolower(trim(
                                $cm['item_product'] . ' ' . ($cm['specification'] ?? '') . ' ' . ($cm['roofing_product'] ?? '')
                            ));
                            $defaultRule = $defaultRuleFor($cm);
                        ?>
                            <div class="tmpl-pick-row"
                                 data-material-id="<?= (int)$cm['id'] ?>"
                                 data-material-type="main"
                                 data-material-category="<?= htmlspecialchars($cm['product_category'] ?? '') ?>"
                                 data-roofing-product="<?= htmlspecialchars($cm['roofing_product'] ?? '') ?>"
                                 data-material-search="<?= htmlspecialchars($searchBlob) ?>">
                                <label>
                                    <input type="checkbox" class="tmpl-pick-check" data-material-id="<?= (int)$cm['id'] ?>">
                                    <span class="tmpl-pick-text">
                                        <span class="tmpl-pick-name"><?= htmlspecialchars($cm['item_product']) ?></span>
                                        <span class="tmpl-pick-sub">
                                            <?php if (!empty($cm['roofing_product'])): ?><?= htmlspecialchars($cm['roofing_product']) ?> · <?php endif; ?>
                                            <?= htmlspecialchars($cm['specification'] ?: '—') ?> · <?= htmlspecialchars($cm['unit']) ?>
                                        </span>
                                    </span>
                                </label>
                                <select class="form-select form-select-sm tmpl-pick-rule"
                                        onchange="updateTemplateItemRuleFromModal(this)">
                                    <?php foreach ($ruleOptions as $val => $label): ?>
                                        <option value="<?= $val ?>" <?= $val === $defaultRule ? 'selected' : '' ?>><?= $label ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
        
                <?php if (!empty($mtmGen)): ?>
                    <div class="tmpl-pick-section" data-section="general">
                        <div class="tmpl-pick-section-title" data-section="general">
                            <span>General Roof Construction Materials</span>
                            <div class="tmpl-pick-filters">
                                <select id="mtm_filter_general_category" class="form-select tmpl-pick-filter"
                                        onchange="filterTemplateMaterials()">
                                    <option value="all">All Categories</option>
                                    <?php foreach ($general_categories as $cat): ?>
                                        <option value="<?= htmlspecialchars($cat['name']) ?>"><?= htmlspecialchars($cat['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
        
                        <?php foreach ($mtmGen as $cm):
                            $searchBlob = strtolower(trim(
                                $cm['item_product'] . ' ' . ($cm['specification'] ?? '') . ' ' . ($cm['general_category'] ?? '')
                            ));
                            $defaultRule = $defaultRuleFor($cm);
                        ?>
                            <div class="tmpl-pick-row"
                                 data-material-id="<?= (int)$cm['id'] ?>"
                                 data-material-type="general"
                                 data-material-category="<?= htmlspecialchars($cm['general_category'] ?? '') ?>"
                                 data-material-search="<?= htmlspecialchars($searchBlob) ?>">
                                <label>
                                    <input type="checkbox" class="tmpl-pick-check" data-material-id="<?= (int)$cm['id'] ?>">
                                    <span class="tmpl-pick-text">
                                        <span class="tmpl-pick-name"><?= htmlspecialchars($cm['item_product']) ?></span>
                                        <span class="tmpl-pick-sub">
                                            <?php if (!empty($cm['general_category'])): ?><?= htmlspecialchars($cm['general_category']) ?> · <?php endif; ?>
                                            <?= htmlspecialchars($cm['specification'] ?: '—') ?> · <?= htmlspecialchars($cm['unit']) ?>
                                        </span>
                                    </span>
                                </label>
                                <select class="form-select form-select-sm tmpl-pick-rule"
                                        onchange="updateTemplateItemRuleFromModal(this)">
                                    <?php foreach ($ruleOptions as $val => $label): ?>
                                        <option value="<?= $val ?>" <?= $val === $defaultRule ? 'selected' : '' ?>><?= $label ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
        
            <?php endif; ?>
        </div>

        <div class="form-actions mt-3">
            <button type="button" class="btn btn-outline-secondary"
                    onclick="document.getElementById('manageTemplateMaterialsModal').classList.remove('show')">Done</button>
        </div>
    </div>
</div>

<script>
window.__settingsRoofingProducts = <?= json_encode($roofing_products_by_category, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>;
window.__settingsCsrf = <?= json_encode(csrf_token()) ?>;
window.__templateProductsByCategory = <?= json_encode($roofing_products_by_category, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>;

function populateRoofingProducts(selectEl, categoryValue, selectedProduct) {
    if (!selectEl) return;
    selectEl.innerHTML = '';
    const products = window.__settingsRoofingProducts[categoryValue] || [];
    products.forEach(function (p) {
        const opt = document.createElement('option');
        opt.value = p;
        opt.textContent = p;
        if (selectedProduct && p === selectedProduct) opt.selected = true;
        selectEl.appendChild(opt);
    });
    if (selectedProduct && !products.includes(selectedProduct)) {
        const opt = document.createElement('option');
        opt.value = selectedProduct;
        opt.textContent = selectedProduct + ' (legacy)';
        opt.selected = true;
        selectEl.insertBefore(opt, selectEl.firstChild);
    }
}

function onMainFilterCategoryChange() {
    const catSel  = document.getElementById('main_filter_category');
    const prodSel = document.getElementById('main_filter_product');
    if (!catSel || !prodSel) return;
    const previousProd = prodSel.value;
    prodSel.innerHTML = '<option value="all">All Products</option>';
    let products = [];
    if (catSel.value === 'all') {
        Object.keys(window.__settingsRoofingProducts).forEach(function (c) {
            products = products.concat(window.__settingsRoofingProducts[c]);
        });
    } else {
        products = (window.__settingsRoofingProducts[catSel.value] || []).slice();
    }
    products.sort();
    products.forEach(function (p) {
        const opt = document.createElement('option');
        opt.value = p; opt.textContent = p;
        prodSel.appendChild(opt);
    });
    if (previousProd !== 'all' && products.indexOf(previousProd) !== -1) prodSel.value = previousProd;
    else prodSel.value = 'all';
    applyMainFilter();
}

function applyMainFilter() {
    const catSel  = document.getElementById('main_filter_category');
    const prodSel = document.getElementById('main_filter_product');
    const tbody   = document.getElementById('mainMaterialsBody');
    if (!catSel || !prodSel || !tbody) return;
    const cat  = catSel.value;
    const prod = prodSel.value;
    let visible = 0;
    tbody.querySelectorAll('.main-material-row').forEach(function (row) {
        const matchesCat  = (cat  === 'all') || (row.dataset.productCategory === cat);
        const matchesProd = (prod === 'all') || (row.dataset.roofingProduct  === prod);
        const show = matchesCat && matchesProd;
        row.style.display = show ? '' : 'none';
        if (show) visible++;
    });
    const noRes = document.getElementById('mainNoResultsRow');
    if (noRes) noRes.style.display = (visible === 0) ? '' : 'none';
}

function onAddCategoryChange() {
    const catSel  = document.getElementById('add_product_category');
    const prodSel = document.getElementById('add_roofing_product');
    if (!catSel || !prodSel) return;
    populateRoofingProducts(prodSel, catSel.value, null);
}

function onEditCategoryChange() {
    const catSel  = document.getElementById('edit_product_category');
    const prodSel = document.getElementById('edit_roofing_product');
    if (!catSel || !prodSel) return;
    populateRoofingProducts(prodSel, catSel.value, null);
}

function openMaterialEdit(id, itemProduct, specification, unit, estimatedPrice, productCategory, roofingProduct) {
    document.getElementById('edit_material_id').value         = id;
    document.getElementById('edit_item_product').value        = itemProduct;
    document.getElementById('edit_specification').value       = specification;
    document.getElementById('edit_unit').value                = unit;
    document.getElementById('edit_estimated_price').value     = estimatedPrice;
    const catSel = document.getElementById('edit_product_category');
    if (productCategory && productCategory !== '') catSel.value = productCategory;
    const prodSel = document.getElementById('edit_roofing_product');
    populateRoofingProducts(prodSel, catSel.value, roofingProduct || null);
    document.getElementById('editMaterialModal').classList.add('show');
}

function openGeneralMaterialEdit(id, itemProduct, specification, unit, estimatedPrice, generalCategory) {
    document.getElementById('edit_general_material_id').value         = id;
    document.getElementById('edit_general_item_product').value        = itemProduct;
    document.getElementById('edit_general_specification').value       = specification;
    document.getElementById('edit_general_unit').value                = unit;
    document.getElementById('edit_general_estimated_price').value     = estimatedPrice;

    const catSel = document.getElementById('edit_general_category_select');
    if (generalCategory && generalCategory !== '') {
        catSel.value = generalCategory;
    }

    document.getElementById('editGeneralMaterialModal').classList.add('show');
}

function applyGeneralFilter() {
    const sel   = document.getElementById('general_filter_category');
    const tbody = document.getElementById('generalMaterialsBody');
    if (!sel || !tbody) return;
    const category = sel.value;
    let visible = 0;
    tbody.querySelectorAll('.general-material-row').forEach(function (row) {
        const show = (category === 'all') || (row.dataset.generalCategory === category);
        row.style.display = show ? '' : 'none';
        if (show) visible++;
    });
    const noRes = document.getElementById('generalNoResultsRow');
    if (noRes) noRes.style.display = (visible === 0) ? '' : 'none';
}

function openAddGeneralMaterial() {
    const modal = document.getElementById('addGeneralMaterialModal');
    if (modal) modal.classList.add('show');
}

document.addEventListener('DOMContentLoaded', function () {
    onAddCategoryChange();
    onMainFilterCategoryChange();
});

// =====================================================================
// AJAX handlers
// =====================================================================
(function () {

    function showSettingsFlash(message, type) {
        const el = document.getElementById('settingsFlashMsg');
        if (!el) return;
        el.textContent = message;
        el.className = 'msg ' + (type === 'error' ? 'error' : '');
        el.style.display = '';
        clearTimeout(el._hideTimer);
        el._hideTimer = setTimeout(function () { el.style.display = 'none'; }, 4000);
    }
    
    window.showSettingsFlash = showSettingsFlash;

    function closeModalById(id) {
        const m = document.getElementById(id);
        if (m) m.classList.remove('show');
    }

    function fmtPrice(v) {
        return '₱' + Number(v || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function buildMainMaterialRow(m) {
        const tr = document.createElement('tr');
        tr.className = 'main-material-row';
        tr.dataset.materialId      = m.id;
        tr.dataset.productCategory = m.product_category || '';
        tr.dataset.roofingProduct  = m.roofing_product || '';

        const catClass = m.category_label ? 'followup' : 'cancelled';

        tr.innerHTML = `
            <td><strong></strong></td>
            <td><strong></strong></td>
            <td><span class="fr-badge ${catClass}" style="font-weight: 600;"></span></td>
            <td></td>
            <td></td>
            <td></td>
            <td></td>
            <td class="actions">
                <button type="button" class="action-btn edit">Edit</button>
                <form method="POST" action="admin.php?tab=settings" class="d-inline settings-ajax-form"
                      data-action="delete_material" data-confirm="Delete this material?">
                    <input type="hidden" name="csrf_token" value="${window.__settingsCsrf}">
                    <input type="hidden" name="delete_material" value="${m.id}">
                    <input type="hidden" name="return_to" value="main">
                    <button type="submit" class="action-btn cancel-btn">Delete</button>
                </form>
            </td>
        `;

        tr.cells[0].querySelector('strong').textContent = m.material_code || '—';
        tr.cells[1].querySelector('strong').textContent = m.item_product;
        tr.cells[2].querySelector('span').textContent   = m.category_label || 'Uncategorized';

        if (m.roofing_product) {
            const s = document.createElement('strong');
            s.textContent = m.roofing_product;
            tr.cells[3].appendChild(s);
        } else {
            const e = document.createElement('span');
            e.style.color = 'var(--fr-muted)';
            e.style.fontStyle = 'italic';
            e.textContent = '—';
            tr.cells[3].appendChild(e);
        }

        tr.cells[4].textContent = m.specification || '—';
        tr.cells[5].textContent = m.unit_label || m.unit;
        tr.cells[6].textContent = fmtPrice(m.estimated_price);

        tr.querySelector('.action-btn.edit').addEventListener('click', function () {
            window.openMaterialEdit(
                m.id, m.item_product, m.specification, m.unit, m.estimated_price,
                m.product_category, m.roofing_product
            );
        });

        return tr;
    }

    function buildGeneralMaterialRow(m) {
        const tr = document.createElement('tr');
        tr.className = 'general-material-row';
        tr.dataset.materialId      = m.id;
        tr.dataset.generalCategory = m.general_category || '';

        tr.innerHTML = `
            <td><strong></strong></td>
            <td><strong></strong></td>
            <td><span class="fr-badge scheduled" style="font-weight: 600;"></span></td>
            <td></td>
            <td></td>
            <td></td>
            <td class="actions">
                <button type="button" class="action-btn edit">Edit</button>
                <form method="POST" action="admin.php?tab=settings" class="d-inline settings-ajax-form"
                      data-action="delete_material" data-confirm="Delete this material?">
                    <input type="hidden" name="csrf_token" value="${window.__settingsCsrf}">
                    <input type="hidden" name="delete_material" value="${m.id}">
                    <input type="hidden" name="return_to" value="general">
                    <button type="submit" class="action-btn cancel-btn">Delete</button>
                </form>
            </td>
        `;

        tr.cells[0].querySelector('strong').textContent = m.material_code || '—';
        tr.cells[1].querySelector('strong').textContent = m.item_product;
        tr.cells[2].querySelector('span').textContent   = m.general_category || '—';
        tr.cells[3].textContent = m.specification || '—';
        tr.cells[4].textContent = m.unit_label || m.unit;
        tr.cells[5].textContent = fmtPrice(m.estimated_price);

        tr.querySelector('.action-btn.edit').addEventListener('click', function () {
            window.openGeneralMaterialEdit(
                m.id, m.item_product, m.specification, m.unit, m.estimated_price,
                m.general_category
            );
        });

        return tr;
    }

    function buildCategoryRow(c) {
        const tr = document.createElement('tr');
        tr.dataset.categoryId = c.id;
        const count = c.material_count || 0;
        tr.innerHTML = `
            <td><strong></strong></td>
            <td>${count}</td>
            <td>
                <form method="POST" action="admin.php?tab=settings" class="d-inline settings-ajax-form"
                      data-action="delete_general_category" data-confirm="Delete this category?">
                    <input type="hidden" name="csrf_token" value="${window.__settingsCsrf}">
                    <input type="hidden" name="category_id" value="${c.id}">
                    <button type="submit" class="action-btn cancel-btn" ${count > 0 ? 'disabled' : ''}>Delete</button>
                </form>
            </td>
        `;
        tr.cells[0].querySelector('strong').textContent = c.name;
        return tr;
    }

    function bumpCategoryCount(categoryName, delta) {
        const tbody = document.querySelector('#manageCategoriesModal tbody');
        if (!tbody) return;
        tbody.querySelectorAll('tr[data-category-id]').forEach(function (row) {
            const nameEl = row.cells[0].querySelector('strong');
            if (!nameEl || nameEl.textContent !== categoryName) return;
            const countCell = row.cells[1];
            const oldCount = parseInt(countCell.textContent, 10) || 0;
            const newCount = Math.max(0, oldCount + delta);
            countCell.textContent = newCount;
            const btn = row.querySelector('button[type="submit"]');
            if (btn) btn.disabled = (newCount > 0);
        });
    }

    function onSuccess(action, res, form) {
        if (action === 'add_material' && res.material) {
            const emptyRow = document.getElementById('mainEmptyRow');
            if (emptyRow) emptyRow.remove();
            const tbody     = document.getElementById('mainMaterialsBody');
            const noResults = document.getElementById('mainNoResultsRow');
            const row       = buildMainMaterialRow(res.material);
            if (noResults) tbody.insertBefore(row, noResults); else tbody.appendChild(row);
            bindForm(row.querySelector('.settings-ajax-form'));
            if (typeof applyMainFilter === 'function') applyMainFilter();
        } else if (action === 'edit_material' && res.material) {
            const tbody  = document.getElementById('mainMaterialsBody');
            const oldRow = tbody && tbody.querySelector('tr[data-material-id="' + res.material.id + '"]');
            if (oldRow) {
                const newRow = buildMainMaterialRow(res.material);
                oldRow.parentNode.replaceChild(newRow, oldRow);
                bindForm(newRow.querySelector('.settings-ajax-form'));
                if (typeof applyMainFilter === 'function') applyMainFilter();
            }
        } else if (action === 'edit_general_material' && res.material) {
            const tbody  = document.getElementById('generalMaterialsBody');
            const oldRow = tbody && tbody.querySelector('tr[data-material-id="' + res.material.id + '"]');
            if (oldRow) {
                const newRow = buildGeneralMaterialRow(res.material);
                oldRow.parentNode.replaceChild(newRow, oldRow);
                bindForm(newRow.querySelector('.settings-ajax-form'));
                if (typeof applyGeneralFilter === 'function') applyGeneralFilter();
            }
        } else if (action === 'delete_material') {
            ['mainMaterialsBody', 'generalMaterialsBody'].forEach(function (id) {
                const tbody = document.getElementById(id);
                if (!tbody) return;
                const row = tbody.querySelector('tr[data-material-id="' + res.material_id + '"]');
                if (row) row.remove();
            });
            if (typeof applyMainFilter === 'function')    applyMainFilter();
            if (typeof applyGeneralFilter === 'function') applyGeneralFilter();
        } else if (action === 'add_general_material' && res.material) {
            const emptyRow = document.getElementById('generalEmptyRow');
            if (emptyRow) emptyRow.remove();
            const tbody     = document.getElementById('generalMaterialsBody');
            const noResults = document.getElementById('generalNoResultsRow');
            const row       = buildGeneralMaterialRow(res.material);
            if (noResults) tbody.insertBefore(row, noResults); else tbody.appendChild(row);
            bindForm(row.querySelector('.settings-ajax-form'));
            if (typeof applyGeneralFilter === 'function') applyGeneralFilter();
            bumpCategoryCount(res.material.general_category, 1);
        } else if (action === 'add_general_category' && res.category) {
            const tbody = document.querySelector('#manageCategoriesModal tbody');
            if (tbody) {
                const placeholder = tbody.querySelector('tr td[colspan="3"]');
                if (placeholder) placeholder.closest('tr').remove();
                const row = buildCategoryRow(res.category);
                tbody.appendChild(row);
                bindForm(row.querySelector('.settings-ajax-form'));
            }
            ['general_filter_category', 'add_general_category_select'].forEach(function (id) {
                const sel = document.getElementById(id);
                if (!sel) return;
                const opt = document.createElement('option');
                opt.value = res.category.name; opt.textContent = res.category.name;
                sel.appendChild(opt);
            });
        } else if (action === 'delete_general_category') {
            const tbody = document.querySelector('#manageCategoriesModal tbody');
            if (tbody) {
                const row = tbody.querySelector('tr[data-category-id="' + res.category_id + '"]');
                if (row) row.remove();
            }
            ['general_filter_category', 'add_general_category_select'].forEach(function (id) {
                const sel = document.getElementById(id);
                if (!sel) return;
                Array.from(sel.options).forEach(function (opt) {
                    if (opt.value === res.category_name) opt.remove();
                });
                if (sel.value === res.category_name) sel.value = 'all';
            });
            if (typeof applyGeneralFilter === 'function') applyGeneralFilter();
        }
        if (form.dataset.closeModal) closeModalById(form.dataset.closeModal);
        if (form.dataset.reset === '1') form.reset();
    }

    function bindForm(form) {
        if (!form || form.dataset.ajaxBound === '1') return;
        form.dataset.ajaxBound = '1';
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            const action = form.dataset.action;
            if (!action) return;
            if (form.dataset.confirm && !confirm(form.dataset.confirm)) return;
            const submitBtn = form.querySelector('button[type="submit"]');
            const original  = submitBtn ? submitBtn.innerHTML : '';
            if (submitBtn) { submitBtn.disabled = true; submitBtn.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>Saving...'; }
            const fd = new FormData(form);
            fd.append('action', action);
            fetch('admin.php?tab=settings&ajax=settings_material', {
                method: 'POST', body: fd, credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function (r) {
                return r.text().then(function (txt) {
                    try { return JSON.parse(txt); }
                    catch (e) { throw new Error('Invalid JSON from server: ' + txt.slice(0, 200)); }
                });
            })
            .then(function (res) {
                if (!res.success) { showSettingsFlash(res.message || 'An error occurred.', 'error'); return; }
                showSettingsFlash(res.message || 'Success.', '');
                onSuccess(action, res, form);
            })
            .catch(function (err) {
                console.error(err);
                showSettingsFlash('Network error. Please try again.', 'error');
            })
            .finally(function () {
                if (submitBtn) { submitBtn.disabled = false; submitBtn.innerHTML = original; }
            });
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.settings-ajax-form').forEach(bindForm);
    });
})();
// =====================================================================
// Material Templates
// =====================================================================
function _escapeHtml(s) {
    return String(s == null ? '' : s)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
}

function toggleTemplateStatus(id) {
    const fd = new FormData();
    fd.append('action', 'toggle_template_status');
    fd.append('id', id);
    fd.append('csrf_token', window.__settingsCsrf);
    fetch('admin.php?tab=settings&ajax=settings_material', {
        method: 'POST', body: fd, credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    }).then(r => r.json()).then(res => {
        if (!res.success) { showSettingsFlash(res.message, 'error'); return; }
        const badge = document.getElementById('tmpl-status-' + id);
        const btn   = document.getElementById('tmpl-toggle-' + id);
        if (badge) {
            badge.className = 'fr-badge ' + (res.is_active ? 'active' : 'cancelled');
            badge.textContent = res.is_active ? 'Active' : 'Inactive';
        }
        if (btn) {
            btn.className = 'action-btn ' + (res.is_active ? 'reject' : 'approve');
            btn.textContent = res.is_active ? 'Disable' : 'Enable';
        }
        showSettingsFlash(res.message, '');
    });
}

// =====================================================================
// Manage Template Materials — checkbox picker
// =====================================================================
window.__mtmTemplateId  = null;
window.__mtmItemsMap    = {};   // material_id -> { template_item_id, qty_rule }

function openManageTemplateMaterials(id) {
    window.__mtmTemplateId = id;
    window.__mtmItemsMap   = {};

    // Reset all rows
    document.querySelectorAll('#mtm_list .tmpl-pick-row').forEach(function (row) {
        row.classList.remove('is-checked');
        const cb = row.querySelector('.tmpl-pick-check');
        if (cb) cb.checked = false;
    });

    document.getElementById('mtm_title').textContent = 'Template Materials';
    document.getElementById('mtm_subtitle').textContent = '';
    document.getElementById('mtm_count').textContent = 'Loading…';
    document.getElementById('mtm_search').value = '';
    // Reset all filters so the user sees the full catalog first
    document.getElementById('mtm_search').value = '';
    const _mc = document.getElementById('mtm_filter_main_category');
    if (_mc) _mc.value = 'all';
    const _mp = document.getElementById('mtm_filter_main_product');
    if (_mp) _mp.value = 'all';
    const _gc = document.getElementById('mtm_filter_general_category');
    if (_gc) _gc.value = 'all';
    onMtmMainCategoryChange();   // rebuilds the product dropdown + calls filter

    document.getElementById('manageTemplateMaterialsModal').classList.add('show');

    // Fetch current template contents
    const fd = new FormData();
    fd.append('action', 'get_template_details');
    fd.append('id', id);
    fd.append('csrf_token', window.__settingsCsrf);

    fetch('admin.php?tab=settings&ajax=settings_material', {
        method: 'POST', body: fd, credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(r => r.json())
    .then(res => {
        if (!res.success || !res.template) {
            showSettingsFlash(res.message || 'Unable to load template.', 'error');
            return;
        }
        const tmpl = res.template;
        document.getElementById('mtm_title').textContent = tmpl.template_name;
        document.getElementById('mtm_subtitle').textContent =
            tmpl.service_type.toUpperCase() + ' · ' + tmpl.roof_type + ' · ' + tmpl.roofing_product;

        applyTemplateItemsToModal(tmpl.items || []);
    })
    .catch(err => {
        console.error(err);
        showSettingsFlash('Network error while loading template.', 'error');
    });
}

function applyTemplateItemsToModal(items) {
    window.__mtmItemsMap = {};

    items.forEach(function (it) {
        const mid = String(it.material_id);
        window.__mtmItemsMap[mid] = {
            template_item_id: it.template_item_id,
            qty_rule: it.qty_rule || 'fixed_1'
        };

        const row = document.querySelector('#mtm_list .tmpl-pick-row[data-material-id="' + mid + '"]');
        if (!row) return;

        row.classList.add('is-checked');
        const cb = row.querySelector('.tmpl-pick-check');
        if (cb) cb.checked = true;

        const sel = row.querySelector('.tmpl-pick-rule');
        if (sel) sel.value = it.qty_rule || 'fixed_1';
    });

    updateMtmCount();
}

function updateMtmCount() {
    const total    = document.querySelectorAll('#mtm_list .tmpl-pick-row').length;
    const selected = document.querySelectorAll('#mtm_list .tmpl-pick-row.is-checked').length;
    const el = document.getElementById('mtm_count');
    if (el) {
        el.textContent = selected + ' of ' + total + ' materials selected';
    }
}

function onTemplateMaterialCheck(e) {
    const cb  = e.target;
    const row = cb.closest('.tmpl-pick-row');
    if (!row) return;

    const mid = row.dataset.materialId;

    if (cb.checked) {
        // Optimistic UI
        row.classList.add('is-checked');
        const rule = row.querySelector('.tmpl-pick-rule').value || 'fixed_1';

        const fd = new FormData();
        fd.append('action', 'add_item_to_template');
        fd.append('template_id', window.__mtmTemplateId);
        fd.append('material_id', mid);
        fd.append('qty_rule', rule);
        fd.append('csrf_token', window.__settingsCsrf);

        fetch('admin.php?tab=settings&ajax=settings_material', {
            method: 'POST', body: fd, credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(r => r.json())
        .then(res => {
            if (!res.success) {
                cb.checked = false;
                row.classList.remove('is-checked');
                showSettingsFlash(res.message || 'Could not add material.', 'error');
                return;
            }
            // Capture the freshly assigned template_item_id
            if (res.template && Array.isArray(res.template.items)) {
                const found = res.template.items.find(x => String(x.material_id) === String(mid));
                if (found) {
                    window.__mtmItemsMap[mid] = {
                        template_item_id: found.template_item_id,
                        qty_rule: found.qty_rule || rule
                    };
                }
            }
            updateMtmCount();
        })
        .catch(err => {
            console.error(err);
            cb.checked = false;
            row.classList.remove('is-checked');
            showSettingsFlash('Network error. Please try again.', 'error');
        });

    } else {
        const entry = window.__mtmItemsMap[mid];
        if (!entry) {
            row.classList.remove('is-checked');
            updateMtmCount();
            return;
        }

        // Optimistic UI
        row.classList.remove('is-checked');

        const fd = new FormData();
        fd.append('action', 'remove_item_from_template');
        fd.append('template_item_id', entry.template_item_id);
        fd.append('csrf_token', window.__settingsCsrf);

        fetch('admin.php?tab=settings&ajax=settings_material', {
            method: 'POST', body: fd, credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(r => r.json())
        .then(res => {
            if (!res.success) {
                cb.checked = true;
                row.classList.add('is-checked');
                showSettingsFlash(res.message || 'Could not remove material.', 'error');
                return;
            }
            delete window.__mtmItemsMap[mid];
            updateMtmCount();
        })
        .catch(err => {
            console.error(err);
            cb.checked = true;
            row.classList.add('is-checked');
            showSettingsFlash('Network error. Please try again.', 'error');
        });
    }
}

// =====================================================================
// Service & Property Configuration — tab switching + AJAX
// =====================================================================
function spcSwitchTab(btn) {
    const type = btn.dataset.tab;
    document.querySelectorAll('.spc-tab').forEach(function (t) {
        t.classList.toggle('is-active', t === btn);
    });
    document.querySelectorAll('.spc-panel').forEach(function (p) {
        p.classList.toggle('is-active', p.dataset.panel === type);
    });
}

function spcToggleStatus(id) {
    const fd = new FormData();
    fd.append('action', 'toggle_config_status');
    fd.append('id', id);
    fd.append('csrf_token', window.__settingsCsrf);

    fetch('admin.php?tab=settings&ajax=settings_material', {
        method: 'POST', body: fd, credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(r => r.json())
    .then(res => {
        if (!res.success) { showSettingsFlash(res.message || 'Could not update.', 'error'); return; }

        const row = document.querySelector('tr[data-spc-id="' + id + '"]');
        if (row) {
            const badge = row.querySelector('.spc-status-badge');
            if (badge) {
                badge.className = 'spc-status-badge ' + (res.is_active ? 'is-active' : 'is-inactive');
                badge.innerHTML =
                    '<i class="bi ' + (res.is_active ? 'bi-check-circle' : 'bi-slash-circle') + '"></i>' +
                    '<span>' + (res.is_active ? 'Active' : 'Inactive') + '</span>';
            }
            const toggleBtn = row.querySelector('.spc-btn-toggle');
            if (toggleBtn) {
                toggleBtn.className = 'spc-btn spc-btn-toggle ' +
                    (res.is_active ? 'is-deactivate' : 'is-activate');
                toggleBtn.innerHTML =
                    '<i class="bi ' + (res.is_active ? 'bi-power' : 'bi-check-circle') + '"></i>' +
                    '<span>' + (res.is_active ? 'Deactivate' : 'Activate') + '</span>';
            }
        }
        showSettingsFlash(res.message, '');
    })
    .catch(err => {
        console.error(err);
        showSettingsFlash('Network error. Please try again.', 'error');
    });
}

function spcOpenEdit(id) {
    const fd = new FormData();
    fd.append('action', 'get_config_details');
    fd.append('id', id);
    fd.append('csrf_token', window.__settingsCsrf);

    fetch('admin.php?tab=settings&ajax=settings_material', {
        method: 'POST', body: fd, credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(r => r.json())
    .then(res => {
        if (!res.success || !res.config) {
            showSettingsFlash(res.message || 'Unable to load option.', 'error');
            return;
        }
        const cfg = res.config;
        const titleMap = { service: 'Service Type', property: 'Property Type', roof: 'Roof Type' };

        document.getElementById('spcEditId').value    = cfg.id;
        document.getElementById('spcEditName').value  = cfg.name;
        document.getElementById('spcEditDesc').value  = cfg.description || '';
        document.getElementById('spcEditTitle').textContent =
            'Edit ' + (titleMap[cfg.config_type] || 'Option');

        document.getElementById('spcEditModal').classList.add('show');
    })
    .catch(err => {
        console.error(err);
        showSettingsFlash('Network error. Please try again.', 'error');
    });
}

function spcCloseEdit() {
    document.getElementById('spcEditModal').classList.remove('show');
}

function spcSaveEdit(event) {
    event.preventDefault();
    const id   = document.getElementById('spcEditId').value;
    const name = document.getElementById('spcEditName').value.trim();
    const desc = document.getElementById('spcEditDesc').value.trim();

    if (!name) return;

    const fd = new FormData();
    fd.append('action', 'update_config_details');
    fd.append('id', id);
    fd.append('name', name);
    fd.append('description', desc);
    fd.append('csrf_token', window.__settingsCsrf);

    fetch('admin.php?tab=settings&ajax=settings_material', {
        method: 'POST', body: fd, credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(r => r.json())
    .then(res => {
        if (!res.success) { showSettingsFlash(res.message || 'Could not save.', 'error'); return; }

        const row = document.querySelector('tr[data-spc-id="' + id + '"]');
        if (row) {
            const nameEl = row.querySelector('.spc-name strong');
            if (nameEl) nameEl.textContent = res.name;

            const descEl = row.querySelector('.spc-description');
            if (descEl) descEl.textContent = res.description || '—';
        }

        showSettingsFlash(res.message, '');
        spcCloseEdit();
    })
    .catch(err => {
        console.error(err);
        showSettingsFlash('Network error. Please try again.', 'error');
    });
}

function updateTemplateItemRuleFromModal(selectEl) {
    const row = selectEl.closest('.tmpl-pick-row');
    if (!row) return;
    const mid = row.dataset.materialId;
    const entry = window.__mtmItemsMap[mid];

    // Ignore rule changes for unchecked rows (shouldn't happen, but safe).
    if (!entry) return;

    const fd = new FormData();
    fd.append('action', 'update_template_item_rule');
    fd.append('template_item_id', entry.template_item_id);
    fd.append('qty_rule', selectEl.value);
    fd.append('csrf_token', window.__settingsCsrf);

    fetch('admin.php?tab=settings&ajax=settings_material', {
        method: 'POST', body: fd, credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(r => r.json())
    .then(res => {
        if (!res.success) {
            showSettingsFlash(res.message || 'Could not save rule.', 'error');
            return;
        }
        entry.qty_rule = selectEl.value;
        // Subtle confirmation: quick green flash on the select
        selectEl.style.transition = 'box-shadow .3s';
        selectEl.style.boxShadow = '0 0 0 3px rgba(16,185,129,.25)';
        setTimeout(() => { selectEl.style.boxShadow = ''; }, 600);
    })
    .catch(err => {
        console.error(err);
        showSettingsFlash('Network error. Please try again.', 'error');
    });
}

function onMtmMainCategoryChange() {
    const catSel  = document.getElementById('mtm_filter_main_category');
    const prodSel = document.getElementById('mtm_filter_main_product');
    if (!catSel || !prodSel) return;

    const prev = prodSel.value;
    prodSel.innerHTML = '<option value="all">All Products</option>';

    let products = [];
    if (catSel.value === 'all') {
        Object.keys(window.__settingsRoofingProducts).forEach(function (c) {
            products = products.concat(window.__settingsRoofingProducts[c]);
        });
    } else {
        products = (window.__settingsRoofingProducts[catSel.value] || []).slice();
    }
    products.sort();

    products.forEach(function (p) {
        const opt = document.createElement('option');
        opt.value = p;
        opt.textContent = p;
        prodSel.appendChild(opt);
    });

    if (prev !== 'all' && products.indexOf(prev) !== -1) {
        prodSel.value = prev;
    } else {
        prodSel.value = 'all';
    }

    filterTemplateMaterials();
}

function filterTemplateMaterials() {
    const needle  = (document.getElementById('mtm_search').value || '').trim().toLowerCase();
    const mainCat = document.getElementById('mtm_filter_main_category')?.value || 'all';
    const mainProd= document.getElementById('mtm_filter_main_product')?.value  || 'all';
    const genCat  = document.getElementById('mtm_filter_general_category')?.value || 'all';

    document.querySelectorAll('#mtm_list .tmpl-pick-row').forEach(function (row) {
        const blob = row.dataset.materialSearch || '';
        const type = row.dataset.materialType   || '';
        const cat  = row.dataset.materialCategory || '';
        const prod = row.dataset.roofingProduct || '';

        const matchesSearch = (!needle || blob.indexOf(needle) !== -1);

        let matchesFilter = true;
        if (type === 'main') {
            matchesFilter = (mainCat === 'all' || cat === mainCat)
                         && (mainProd === 'all' || prod === mainProd);
        } else if (type === 'general') {
            matchesFilter = (genCat === 'all' || cat === genCat);
        }

        row.style.display = (matchesSearch && matchesFilter) ? '' : 'none';
    });

    // Hide a section entirely if all its rows are filtered out.
    document.querySelectorAll('#mtm_list .tmpl-pick-section').forEach(function (sec) {
        const section = sec.dataset.section;
        const anyVisible = Array.from(
            sec.querySelectorAll('.tmpl-pick-row')
        ).some(r => r.style.display !== 'none');
        sec.style.display = anyVisible ? '' : 'none';
    });
}

// Bind checkbox listeners once on load
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('#mtm_list .tmpl-pick-check').forEach(function (cb) {
        cb.addEventListener('change', onTemplateMaterialCheck);
    });
});

// =====================================================================
// Standard Material Templates table — filtering
// =====================================================================
function onTemplateFilterCategoryChange() {
    const catSel  = document.getElementById('tmpl_filter_category');
    const prodSel = document.getElementById('tmpl_filter_product');
    if (!catSel || !prodSel) return;

    const prev = prodSel.value;
    prodSel.innerHTML = '<option value="all">All Products</option>';

    let products = [];
    if (catSel.value === 'all') {
        Object.keys(window.__templateProductsByCategory).forEach(function (c) {
            products = products.concat(window.__templateProductsByCategory[c]);
        });
    } else {
        products = (window.__templateProductsByCategory[catSel.value] || []).slice();
    }
    products.sort();

    products.forEach(function (p) {
        const opt = document.createElement('option');
        opt.value = p;
        opt.textContent = p;
        prodSel.appendChild(opt);
    });

    if (prev !== 'all' && products.indexOf(prev) !== -1) {
        prodSel.value = prev;
    } else {
        prodSel.value = 'all';
    }

    applyTemplateFilter();
}

function applyTemplateFilter() {
    const service  = document.getElementById('tmpl_filter_service')?.value  || 'all';
    const category = document.getElementById('tmpl_filter_category')?.value || 'all';
    const product  = document.getElementById('tmpl_filter_product')?.value  || 'all';
    const roof     = document.getElementById('tmpl_filter_roof')?.value     || 'all';

    const tbody = document.getElementById('templatesBody');
    if (!tbody) return;

    let visible = 0;
    let total   = 0;

    tbody.querySelectorAll('.template-row').forEach(function (row) {
        total++;
        const s = row.dataset.serviceType      || '';
        const c = row.dataset.productCategory  || '';
        const p = row.dataset.roofingProduct   || '';
        const r = row.dataset.roofType         || '';

        const show = (service  === 'all' || s === service)
                  && (category === 'all' || c === category)
                  && (product  === 'all' || p === product)
                  && (roof     === 'all' || r === roof);

        row.style.display = show ? '' : 'none';
        if (show) visible++;
    });

    const noRes = document.getElementById('templateNoResultsRow');
    if (noRes) noRes.style.display = (visible === 0 && total > 0) ? '' : 'none';

    const info = document.getElementById('templateCountInfo');
    if (info) info.textContent = 'Showing ' + visible + ' of ' + total + ' templates';
}

document.addEventListener('DOMContentLoaded', function () {
    onTemplateFilterCategoryChange();
});
</script>