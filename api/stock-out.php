<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/session.php';
require_auth();
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/constants.php';

$errors = [];
$values = [
    'medicine_name' => '',
    'batch_no' => '',
    'quantity' => '',
    'released_date' => date('Y-m-d'),
    'reason' => '',
    'remarks' => '',
];

$medicines = [];
$batches = [];
$history = [];
$loadError = '';

try {
    $pdo = get_db_connection();
    $medicines = $pdo->query('SELECT id, name, unit FROM medicines ORDER BY name')->fetchAll();
    $batches = $pdo->query(
        'SELECT b.id, b.medicine_id, b.batch_no, b.quantity, m.name AS medicine_name, m.unit
         FROM medicine_batches b
         JOIN medicines m ON m.id = b.medicine_id
         WHERE b.quantity > 0
         ORDER BY m.name, b.batch_no'
    )->fetchAll();
    $history = $pdo->query(
        'SELECT a.created_at, a.medicine_id, a.quantity, a.note, a.performed_by, m.name
         FROM inventory_activities a
         LEFT JOIN medicines m ON m.id = a.medicine_id
          WHERE a.activity_type = \'stock_out\' AND a.medicine_id IS NOT NULL
          ORDER BY a.id DESC
         LIMIT 6'
    )->fetchAll();
} catch (Throwable $exception) {
    error_log('Stock out load error: ' . $exception->getMessage());
    $loadError = 'Unable to load stock-out data right now. Please try again later.';
}

$medicineUnits = [];
foreach ($medicines as $medicine) {
    $medicineUnits[(string)$medicine['name']] = ucfirst((string)$medicine['unit']);
}

$batchesByMedicine = [];
foreach ($batches as $batch) {
    $batchesByMedicine[(string)$batch['medicine_name']][] = [
        'batch_no' => (string)$batch['batch_no'],
        'available' => (int)$batch['quantity'],
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($values as $key => $default) {
        $values[$key] = trim((string)($_POST[$key] ?? $default));
    }

    if (!csrf_valid($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please submit the form again.';
    }

    $selected = null;
    foreach ($medicines as $medicine) {
        if (strcasecmp((string)$medicine['name'], $values['medicine_name']) === 0) {
            $selected = $medicine;
            break;
        }
    }
    if ($values['medicine_name'] === '') {
        $errors[] = 'Please select a medicine.';
    } elseif (!$selected) {
        $errors[] = 'Please select a medicine from the list.';
    }

    $selectedBatch = null;
    if ($selected && $values['batch_no'] === '') {
        $errors[] = 'Batch number is required.';
    } elseif ($selected && mb_strlen($values['batch_no']) > 60) {
        $errors[] = 'Batch number must be 60 characters or fewer.';
    } elseif ($selected) {
        foreach ($batches as $batch) {
            if ((int)$batch['medicine_id'] === (int)$selected['id']
                && strcasecmp((string)$batch['batch_no'], $values['batch_no']) === 0) {
                $selectedBatch = $batch;
                break;
            }
        }
        if (!$selectedBatch) {
            $errors[] = 'This batch does not exist for the selected medicine.';
        }
    }

    if ($values['quantity'] === '' || !ctype_digit($values['quantity']) || (int)$values['quantity'] <= 0) {
        $errors[] = 'Quantity released must be a positive whole number.';
    } elseif ($selectedBatch && (int)$values['quantity'] > (int)$selectedBatch['quantity']) {
        $errors[] = 'Only ' . number_format((int)$selectedBatch['quantity']) . ' unit(s) are available in this batch.';
    }

    $releasedValid = DateTime::createFromFormat('Y-m-d', $values['released_date']) !== false;
    if ($values['released_date'] === '' || !$releasedValid) {
        $errors[] = 'Please enter a valid date released.';
    }

    if (!isset(STOCK_OUT_REASONS[$values['reason']])) {
        $errors[] = 'Please select a reason for the stock-out.';
    }

    if (mb_strlen($values['remarks']) > 255) {
        $errors[] = 'Remarks must be 255 characters or fewer.';
    }

    if (!$errors && $selected && $selectedBatch) {
        try {
            $pdo = get_db_connection();
            $quantity = (int)$values['quantity'];
            $reasonLabel = STOCK_OUT_REASONS[$values['reason']];

            $pdo->beginTransaction();

            $deduct = $pdo->prepare(
                'UPDATE medicine_batches SET quantity = quantity - :deduct_qty
                 WHERE id = :id AND quantity >= :available_qty'
            );
            $deduct->execute([
                'deduct_qty' => $quantity,
                'available_qty' => $quantity,
                'id' => (int)$selectedBatch['id'],
            ]);

            if ($deduct->rowCount() !== 1) {
                throw new RuntimeException('Insufficient stock available for this batch.');
            }

            $note = 'Batch ' . $values['batch_no'] . ' - ' . strtolower($reasonLabel);
            if ($values['remarks'] !== '') {
                $note .= ' - ' . $values['remarks'];
            }

            $activity = $pdo->prepare(
                'INSERT INTO inventory_activities (activity_type, medicine_id, quantity, note, performed_by)
                 VALUES (:type, :medicine_id, :quantity, :note, :performed_by)'
            );
            $activity->execute([
                'type' => 'stock_out',
                'medicine_id' => (int)$selected['id'],
                'quantity' => -$quantity,
                'note' => mb_substr($note, 0, 255),
                'performed_by' => (string)($_SESSION['full_name'] ?? 'Clinic Personnel'),
            ]);

            create_notification(
                $pdo,
                $quantity . ' ' . $selected['unit'] . '(s) of ' . $selected['name']
                    . ' (batch ' . $values['batch_no'] . ') were recorded as stock out: ' . strtolower($reasonLabel) . '.'
            );

            $pdo->commit();

            set_flash('success', number_format($quantity) . ' ' . ucfirst((string)$selected['unit']) . '(s) of "' . $selected['name'] . '" were recorded as stock out.');
            header('Location: stock-out.php');
            exit;
        } catch (Throwable $exception) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Stock out error: ' . $exception->getMessage());
            $errors[] = 'The stock-out record could not be saved. Please check the available quantity and try again.';
        }
    }
}

$flash = take_flash('success');
$identity = session_identity();
render_page_start('Stock Out', 'stock-out', $identity['full_name'], unread_notifications_count(), 'Record medicines dispensed or removed from the clinic.');
?>
    <main class="content">
      <?php if ($flash !== ''): ?>
        <div class="alert success" role="status"><?php echo e($flash); ?></div>
      <?php endif; ?>

      <?php if ($loadError !== ''): ?>
        <div class="alert" role="alert"><?php echo e($loadError); ?></div>
      <?php endif; ?>

      <?php if ($errors): ?>
        <div class="alert" role="alert">
          <ul class="alert-list">
            <?php foreach ($errors as $error): ?>
              <li><?php echo e($error); ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>

      <section class="panel form-panel">
        <div class="panel-head">
          <h2>Stock-Out Details</h2>
        </div>
        <form method="POST" id="stockOutForm" novalidate>
          <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">

          <div class="form-grid">
            <div class="field">
              <label for="medicine_name">Select Medicine <span class="req">*</span></label>
              <input type="text" id="medicine_name" name="medicine_name" list="medicineOptions" maxlength="150" placeholder="Type to search medicine..." value="<?php echo e($values['medicine_name']); ?>" autocomplete="off" required>
              <datalist id="medicineOptions">
                <?php foreach ($medicines as $medicine): ?>
                  <option value="<?php echo e($medicine['name']); ?>"></option>
                <?php endforeach; ?>
              </datalist>
            </div>

            <div class="field">
              <label for="unitDisplay">Unit of Measurement</label>
              <input type="text" id="unitDisplay" value="" readonly tabindex="-1" placeholder="Auto-filled from medicine">
              <span class="help-inline">Automatically retrieved from the selected medicine.</span>
            </div>

            <div class="field">
              <label for="batch_no">Batch Number <span class="req">*</span></label>
              <input type="text" id="batch_no" name="batch_no" list="batchOptions" maxlength="60" placeholder="Select a batch first" value="<?php echo e($values['batch_no']); ?>" autocomplete="off" required>
              <datalist id="batchOptions"></datalist>
              <span class="help-inline" id="availableQty">Choose a medicine to see available batches.</span>
            </div>

            <div class="field">
              <label for="quantity">Quantity Released <span class="req">*</span></label>
              <input type="number" id="quantity" name="quantity" min="1" step="1" placeholder="e.g. 10" value="<?php echo e($values['quantity']); ?>" required>
            </div>

            <div class="field">
              <label for="released_date">Date Released</label>
              <input type="date" id="released_date" name="released_date" value="<?php echo e($values['released_date']); ?>">
            </div>

            <div class="field">
              <label for="reason">Reason <span class="req">*</span></label>
              <select id="reason" name="reason" required>
                <option value="">Select a reason</option>
                <?php foreach (STOCK_OUT_REASONS as $key => $label): ?>
                  <option value="<?php echo e($key); ?>"<?php echo $values['reason'] === $key ? ' selected' : ''; ?>><?php echo e($label); ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="field full">
              <label for="remarks">Remarks <span class="muted small">(optional)</span></label>
              <textarea id="remarks" name="remarks" rows="3" maxlength="255" placeholder="Add a short note about this stock-out transaction"><?php echo e($values['remarks']); ?></textarea>
            </div>
          </div>

          <div class="form-actions">
            <button type="submit" class="btn primary"><?php echo icon('stock-out'); ?> Save Stock Out</button>
            <button type="reset" class="btn">Clear Form</button>
            <a class="btn" href="medicine-inventory.php">Cancel</a>
          </div>
        </form>
      </section>

      <section class="panel">
        <div class="panel-head">
          <h2>Recent Stock-Out History</h2>
          <span class="badge teal"><?php echo number_format(count($history)); ?> recent</span>
        </div>
        <?php if (!$history): ?>
          <div class="empty-state">
            <span class="empty-icon"><?php echo icon('stock-out'); ?></span>
            <h3>No stock-out records yet</h3>
            <p>Released, disposed, and adjusted stock will appear here.</p>
          </div>
        <?php else: ?>
          <div class="table-wrap">
            <table class="data">
              <thead>
                <tr><th>Date</th><th>Medicine</th><th>Quantity</th><th>Details</th><th>Recorded By</th></tr>
              </thead>
              <tbody>
                <?php foreach ($history as $entry): ?>
                  <tr>
                    <td><?php echo date('M j, Y g:i A', strtotime((string)$entry['created_at'])); ?></td>
                    <td class="strong"><?php if ($entry['medicine_id'] !== null && $entry['name'] !== null): ?><a class="table-link" href="medicine-view.php?id=<?php echo (int)$entry['medicine_id']; ?>#batches"><?php echo e($entry['name']); ?></a><?php else: ?><span class="muted">&mdash;</span><?php endif; ?></td>
                    <td class="strong"><?php echo number_format(abs((int)$entry['quantity'])); ?></td>
                    <td><?php echo e((string)$entry['note']); ?></td>
                    <td><?php echo e((string)$entry['performed_by']); ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </section>
    </main>

    <script>
      (function () {
        var units = <?php echo json_encode($medicineUnits, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
        var batchesByMedicine = <?php echo json_encode($batchesByMedicine, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
        var nameInput = document.getElementById('medicine_name');
        var unitInput = document.getElementById('unitDisplay');
        var batchInput = document.getElementById('batch_no');
        var batchOptions = document.getElementById('batchOptions');
        var availableQty = document.getElementById('availableQty');
        var form = document.getElementById('stockOutForm');
        if (!nameInput || !unitInput || !batchInput || !batchOptions) return;

        function matchingMedicine() {
          var key = nameInput.value.trim().toLowerCase();
          var found = null;
          Object.keys(units).forEach(function (name) {
            if (name.toLowerCase() === key) found = name;
          });
          return found;
        }

        function rebuildBatches(medicine) {
          batchOptions.innerHTML = '';
          var list = medicine ? (batchesByMedicine[medicine] || []) : [];
          list.forEach(function (item) {
            var option = document.createElement('option');
            option.value = item.batch_no;
            batchOptions.appendChild(option);
          });
          if (availableQty) {
            availableQty.textContent = medicine
              ? (list.length ? 'Available: ' + list.map(function (i) { return i.batch_no + ' (' + i.available + ')'; }).join(', ')
                             : 'No batches with available stock for this medicine.')
              : 'Choose a medicine to see available batches.';
          }
        }

        function sync() {
          var medicine = matchingMedicine();
          unitInput.value = medicine ? units[medicine] : '';
          rebuildBatches(medicine);
        }

        nameInput.addEventListener('input', sync);
        nameInput.addEventListener('change', sync);
        batchInput.addEventListener('input', function () {
          var medicine = matchingMedicine();
          var list = medicine ? (batchesByMedicine[medicine] || []) : [];
          var match = null;
          list.forEach(function (i) { if (i.batch_no.toLowerCase() === batchInput.value.trim().toLowerCase()) match = i; });
          if (availableQty) {
            availableQty.textContent = match
              ? 'Available in this batch: ' + match.available
              : (medicine ? 'Select a batch from the list for this medicine.' : 'Choose a medicine to see available batches.');
          }
        });
        if (form) form.addEventListener('reset', function () { setTimeout(sync, 0); });
        sync();
      })();
    </script>
<?php render_page_end(); ?>
