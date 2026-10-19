<?php
/**
 * task_filter.php
 *
 * Generates the predefined project task checklist when a client
 * approves a quotation. Applies the business filter rules to decide
 * which of the 19 predefined tasks belong to the approved scope.
 *
 * No DDL, no templating table, no auto-migration. Just a data array
 * and one entry-point function: generateProjectTasksForApprovedQuotation().
 */

// =====================================================================
// MASTER DEFINITIONS — 19 tasks
//
// Each task has:
//   - task_code      stable identifier, used for the unique key + lookups
//   - task_name      short label shown in the UI
//   - description    full working text shown to the foreman
//   - is_mandatory   true = blocks project completion if not done
//   - sequence       sort order (10, 20, 30 …)
//   - evaluator      function($ctx): bool — return true to include the task
//
// $ctx is the shape returned by buildTaskFilterContext():
//   [ 'facts' => [...], 'inspection' => [...]|null, 'roof_category' => 'Gable Roof', ... ]
// =====================================================================
function getPredefinedTaskDefinitions(): array
{
    return [
        [
            'task_code'    => 'site_prep',
            'task_name'    => 'Site Preparation',
            'description'  => 'Establish perimeter safety barriers, prepare staging area, verify electrical clearance, and ensure safe access to work zones.',
            'is_mandatory' => true,
            'sequence'     => 10,
            'evaluator'    => fn(array $ctx) => true,   // Always kept (§4)
        ],
        [
            'task_code'    => 'mat_delivery',
            'task_name'    => 'Material Delivery and Inspection',
            'description'  => 'Receive, verify quantities and specifications, and inspect all approved materials and accessories upon delivery.',
            'is_mandatory' => true,
            'sequence'     => 20,
            'evaluator'    => fn(array $ctx) =>
                $ctx['facts']['has_material_total'] > 0
                || $ctx['facts']['has_delivery_charge'],
        ],
        [
            'task_code'    => 'roof_structure_prep',
            'task_name'    => 'Roof Structure Preparation',
            'description'  => 'Inspect, align, and prepare underlying purlins, rafters, and structural framing to ensure proper anchoring support.',
            'is_mandatory' => false,
            'sequence'     => 30,
            // Remove only when the inspection explicitly confirms the
            // site is already ready. Any other value (or missing data)
            // keeps the task, per §2.
            'evaluator'    => function (array $ctx) {
                if (!$ctx['has_inspection']) return true;   // §2 — no data, keep
                $prep = strtolower(trim($ctx['inspection']['site_preparation'] ?? ''));
                return $prep !== 'ready';
            },
        ],
        [
            'task_code'    => 'roof_sheet_install',
            'task_name'    => 'Roofing Sheet Installation',
            'description'  => 'Position, square, and lay roofing sheets according to specified profile alignment, slope directions, and designated overlaps.',
            'is_mandatory' => true,
            'sequence'     => 40,
            'evaluator'    => fn(array $ctx) => $ctx['facts']['has_roofing_sheets'],
        ],
        [
            'task_code'    => 'roof_sheet_fasten',
            'task_name'    => 'Roofing Sheet Fastening',
            'description'  => 'Securely fasten and anchor roofing sheets using approved self-drilling screws with EPDM weather-sealing washers at specified intervals.',
            'is_mandatory' => true,
            'sequence'     => 50,
            'evaluator'    => fn(array $ctx) =>
                $ctx['facts']['has_roofing_sheets'] || $ctx['facts']['has_fasteners'],
        ],
        [
            'task_code'    => 'ridge_cap_install',
            'task_name'    => 'Ridge Cap Installation',
            'description'  => 'Align, overlap, and fasten ridge caps along the roof apex ensuring weather tightness and proper ventilation gaps.',
            'is_mandatory' => false,
            'sequence'     => 60,
            'evaluator'    => fn(array $ctx) =>
                $ctx['facts']['has_ridge_cap']
                || in_array($ctx['roof_category'], ['Gable Roof', 'Hip Roof'], true),
        ],
        [
            'task_code'    => 'valley_flashing_install',
            'task_name'    => 'Valley Flashing Installation',
            'description'  => 'Install valley flashing along internal roof intersections with proper underlay and drainage channels.',
            'is_mandatory' => false,
            'sequence'     => 70,
            'evaluator'    => fn(array $ctx) =>
                $ctx['facts']['has_valley_flashing']
                || $ctx['roof_category'] === 'Hip Roof',
        ],
        [
            'task_code'    => 'eaves_flashing_install',
            'task_name'    => 'Eaves Flashing Installation',
            'description'  => 'Install eaves flashing along lower drip lines to guide water runoff away from fascia and soffit.',
            'is_mandatory' => false,
            'sequence'     => 80,
            'evaluator'    => fn(array $ctx) => $ctx['facts']['has_eaves_flashing'],
        ],
        [
            'task_code'    => 'barge_flashing_install',
            'task_name'    => 'Barge/Side Flashing Installation',
            'description'  => 'Install barge and side flashing along gable verge ends to seal roof edges against wind-driven rain penetration.',
            'is_mandatory' => false,
            'sequence'     => 90,
            'evaluator'    => fn(array $ctx) => $ctx['facts']['has_barge_flashing'],
        ],
        [
            'task_code'    => 'sidewall_flashing_install',
            'task_name'    => 'Sidewall/Endwall Flashing Installation',
            'description'  => 'Install counter and step flashing at transitions between roof slopes and abutting walls or parapets.',
            'is_mandatory' => false,
            'sequence'     => 100,
            'evaluator'    => fn(array $ctx) => $ctx['facts']['has_sidewall_flashing'],
        ],
        [
            'task_code'    => 'closure_strip_install',
            'task_name'    => 'Closure Strip Installation',
            'description'  => 'Fit profiled foam or metal closure strips under ridge caps and eaves to prevent pest and moisture intrusion.',
            'is_mandatory' => false,
            'sequence'     => 110,
            'evaluator'    => fn(array $ctx) => $ctx['facts']['has_closure_strip'],
        ],
        [
            'task_code'    => 'gutter_install',
            'task_name'    => 'Gutter Installation',
            'description'  => 'Mount, align, and seal roof gutters with proper slope pitch toward downspout outlets.',
            'is_mandatory' => false,
            'sequence'     => 120,
            'evaluator'    => fn(array $ctx) => $ctx['facts']['has_gutter'],
        ],
        [
            'task_code'    => 'downspout_install',
            'task_name'    => 'Downspout Installation',
            'description'  => 'Install rainwater downspouts, elbows, and secure wall pipe brackets connected to drainage collection points.',
            'is_mandatory' => false,
            'sequence'     => 130,
            'evaluator'    => fn(array $ctx) => $ctx['facts']['has_downspout'],
        ],
        [
            'task_code'    => 'sealant_waterproofing',
            'task_name'    => 'Sealant/Waterproofing',
            'description'  => 'Apply high-grade polyurethane sealant and butyl tape to all joints, penetrations, flashing laps, and fasteners.',
            'is_mandatory' => true,
            'sequence'     => 140,
            // Effectively always-on for any real roof, per §2.
            'evaluator'    => fn(array $ctx) => true,
        ],
        [
            'task_code'    => 'final_roof_inspection',
            'task_name'    => 'Final Roof Inspection',
            'description'  => 'Perform thorough quality and safety inspection of completed roof installation, flashing, fasteners, and alignment.',
            'is_mandatory' => true,
            'sequence'     => 150,
            'evaluator'    => fn(array $ctx) => true,   // Always kept (§4)
        ],
        [
            'task_code'    => 'rectification_punch_list',
            'task_name'    => 'Rectification/Punch List',
            'description'  => 'Document, review, and rectify any punch list deficiencies identified during final inspection.',
            'is_mandatory' => false,
            'sequence'     => 160,
            // Always generated; foreman/admin marks Not Applicable later
            // if the final inspection confirms no items. (§5)
            'evaluator'    => fn(array $ctx) => true,
        ],
        [
            'task_code'    => 'site_cleanup',
            'task_name'    => 'Site Cleanup',
            'description'  => 'Clean site grounds, sweep roof surfaces, perform magnetic sweep for dropped fasteners, and remove scrap debris.',
            'is_mandatory' => true,
            'sequence'     => 170,
            'evaluator'    => fn(array $ctx) => true,   // Always kept (§4)
        ],
        [
            'task_code'    => 'final_project_verification',
            'task_name'    => 'Final Project Verification',
            'description'  => 'Conduct final project walk-through and verification with foreman and client sign-off.',
            'is_mandatory' => true,
            'sequence'     => 180,
            'evaluator'    => fn(array $ctx) => true,   // Always kept (§4)
        ],
        [
            'task_code'    => 'project_completion',
            'task_name'    => 'Project Completion',
            'description'  => 'Formal closeout of project records, documentation, and client handover.',
            'is_mandatory' => true,
            'sequence'     => 190,
            'evaluator'    => fn(array $ctx) => true,   // Always kept (§4)
        ],
    ];
}


// =====================================================================
// MATERIAL FACT DETECTION
//
// Reads the quotation's materials, joins to the master catalog to get
// the structured `general_category` / `product_category`, and flags
// which predefined tasks are triggered by scope.
//
// Falls back to the snapshot columns on quotation_materials if the
// master row was deleted (material_id has no FK).
// =====================================================================
function detectMaterialFacts(PDO $pdo, int $quotationId): array
{
    $facts = [
        'has_material_total'    => 0,
        'has_roofing_sheets'    => false,
        'has_fasteners'         => false,
        'has_ridge_cap'         => false,
        'has_valley_flashing'   => false,
        'has_eaves_flashing'    => false,
        'has_barge_flashing'    => false,
        'has_sidewall_flashing' => false,
        'has_closure_strip'     => false,
        'has_gutter'            => false,
        'has_downspout'         => false,
        'has_sealant'           => false,
        'has_delivery_charge'   => false,
    ];

    // Pull quotation materials + master catalog in one query.
    $stmt = $pdo->prepare("
        SELECT
            qm.item_product,
            qm.material_type        AS qm_material_type,
            m.material_type         AS master_material_type,
            m.product_category      AS master_product_category,
            m.general_category      AS master_general_category
        FROM quotation_materials qm
        LEFT JOIN materials m ON m.id = qm.material_id
        WHERE qm.quotation_id = ?
    ");
    $stmt->execute([$quotationId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $facts['has_material_total'] = count($rows);

    foreach ($rows as $row) {
        // Prefer the master catalog; fall back to the snapshot column.
        $type    = strtolower($row['master_material_type'] ?? $row['qm_material_type'] ?? '');
        $prodCat = strtolower(trim($row['master_product_category'] ?? ''));
        $genCat  = strtolower(trim($row['master_general_category'] ?? ''));
        $name    = strtolower($row['item_product'] ?? '');

        // Roofing sheets: main component, not a ceiling panel.
        if ($type === 'main' && $prodCat !== 'ceilings') {
            $facts['has_roofing_sheets'] = true;
        }

        // Fasteners
        if ($genCat === 'fasteners') {
            $facts['has_fasteners'] = true;
        }

        // Roofing Accessories — distinguish by product name within the category
        if ($genCat === 'roofing accessories') {
            if (preg_match('/\bridge\s*cap\b/', $name))       $facts['has_ridge_cap']         = true;
            if (preg_match('/\bvalley\b/', $name))            $facts['has_valley_flashing']   = true;
            if (preg_match('/\beave/i', $name))               $facts['has_eaves_flashing']    = true;
            if (preg_match('/\bbarge\b/', $name))             $facts['has_barge_flashing']    = true;
            if (preg_match('/sidewall|endwall|side\s*flashing/', $name))
                                                              $facts['has_sidewall_flashing'] = true;
            if (preg_match('/\bclosure\s*strip\b/', $name))   $facts['has_closure_strip']     = true;
        }

        // Drainage — gutter vs downspout
        if ($genCat === 'drainage') {
            if (preg_match('/downspout|downpipe|\belbow\b/', $name)) {
                $facts['has_downspout'] = true;
            }
            if (preg_match('/\bgutter\b|\bbracket\b/', $name)) {
                $facts['has_gutter'] = true;
            }
        }

        // Sealants — also catch by name for robustness
        if ($genCat === 'sealants'
            || preg_match('/\b(sealant|butyl|waterproof|silicone|mastic)\b/', $name)) {
            $facts['has_sealant'] = true;
        }
    }

    // Delivery: any nonzero delivery charge means a delivery leg exists.
    $stmt = $pdo->prepare("SELECT delivery_charges FROM quotations WHERE id = ?");
    $stmt->execute([$quotationId]);
    $facts['has_delivery_charge'] = ((float)$stmt->fetchColumn()) > 0;

    return $facts;
}


// =====================================================================
// CONTEXT BUILDER
// =====================================================================
function buildTaskFilterContext(PDO $pdo, int $projectId, int $quotationId): ?array
{
    $stmt = $pdo->prepare("SELECT * FROM quotations WHERE id = ? LIMIT 1");
    $stmt->execute([$quotationId]);
    $quotation = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$quotation) return null;

    $stmt = $pdo->prepare("SELECT * FROM projects WHERE id = ? LIMIT 1");
    $stmt->execute([$projectId]);
    $project = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$project) return null;

    // Inspection is optional — its absence must never crash the filter.
    $inspection = null;
    if (!empty($quotation['inspection_id'])) {
        $stmt = $pdo->prepare("SELECT * FROM site_inspections WHERE id = ? LIMIT 1");
        $stmt->execute([(int)$quotation['inspection_id']]);
        $inspection = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    return [
        'project'        => $project,
        'quotation'      => $quotation,
        'inspection'     => $inspection,
        'has_inspection' => $inspection !== null,
        'facts'          => detectMaterialFacts($pdo, $quotationId),
        'roof_category'  => $inspection['roofing_category'] ?? '',
    ];
}


// =====================================================================
// MAIN ENTRY POINT
//
// Idempotent: if the project already has predefined tasks, returns
// early without touching the database.
//
// Fails safe: if an evaluator throws, the task is kept (§2).
// =====================================================================
function generateProjectTasksForApprovedQuotation(
    PDO $pdo,
    int $projectId,
    int $quotationId
): array {
    // 1. Skip if we've already generated tasks for this project.
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM project_tasks
        WHERE project_id = ? AND task_code IS NOT NULL
    ");
    $stmt->execute([$projectId]);
    if ((int)$stmt->fetchColumn() > 0) {
        return [
            'success'  => true,
            'inserted' => 0,
            'message'  => 'Predefined tasks already exist for this project.',
        ];
    }

    // 2. Build the filter context.
    $ctx = buildTaskFilterContext($pdo, $projectId, $quotationId);
    if ($ctx === null) {
        return [
            'success'  => false,
            'inserted' => 0,
            'message'  => "Quotation {$quotationId} or project {$projectId} not found.",
        ];
    }

    // 3. Evaluate each task.
    $applicable = [];
    foreach (getPredefinedTaskDefinitions() as $def) {
        try {
            $include = (bool)($def['evaluator'])($ctx);
        } catch (Throwable $e) {
            error_log("Task evaluator failed for {$def['task_code']}: " . $e->getMessage());
            $include = true;   // Fail-safe: never silently drop a task we can't decide
        }
        if ($include) $applicable[] = $def;
    }

    if (empty($applicable)) {
        return [
            'success'  => false,
            'inserted' => 0,
            'message'  => 'No applicable tasks for this scope.',
        ];
    }

    // 4. Insert inside a transaction so a partial write can't happen.
    $pdo->beginTransaction();
    try {
        $insert = $pdo->prepare("
            INSERT INTO project_tasks
                (project_id, task_code, task_name, description,
                 status, is_mandatory, sequence, is_completed)
            VALUES
                (:project_id, :task_code, :task_name, :description,
                 'Pending', :is_mandatory, :sequence, 0)
        ");
        foreach ($applicable as $def) {
            $insert->execute([
                ':project_id'   => $projectId,
                ':task_code'    => $def['task_code'],
                ':task_name'    => $def['task_name'],
                ':description'  => $def['description'],
                ':is_mandatory' => $def['is_mandatory'] ? 1 : 0,
                ':sequence'     => (int)$def['sequence'],
            ]);
        }

        // Recompute the project's progress using the same function the
        // rest of the app uses, so nothing drifts.
        if (function_exists('updateProjectProgress')) {
            updateProjectProgress($pdo, $projectId);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    return [
        'success'  => true,
        'inserted' => count($applicable),
        'message'  => count($applicable) . ' tasks generated for project ' . $projectId . '.',
    ];
}